#!/usr/bin/env python3
"""Validate a completed run and stage a CHIM catalog plus guarded SQL; never import."""
import argparse
import collections
import hashlib
import importlib.util
import json
from pathlib import Path


def main():
    p = argparse.ArgumentParser(description=__doc__)
    p.add_argument('--run-dir', type=Path, required=True)
    p.add_argument('--evidence', type=Path, required=True)
    p.add_argument('--output', type=Path, required=True)
    p.add_argument('--catalog-version', required=True)
    p.add_argument('--exclude', type=Path, help='Reviewed JSON mapping of NPC key to exclusion reason')
    p.add_argument('--review', type=Path, help='Reviewed corrections keyed by NPC, each with reason and raw relationships')
    args = p.parse_args()
    spec = importlib.util.spec_from_file_location('backfill', Path(__file__).with_name('backfill-morrowind-biography-relationships.py'))
    m = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(m)
    run = m.load(args.run_dir/'manifest.json')
    status = m.load(args.run_dir/'status.json')
    if status['status'] != 'complete' or status['completed'] != run['jobs']:
        p.error('run is not complete')
    if args.output.exists():
        p.error('output directory must be new')
    for path, expected in [(args.evidence, run['evidence_sha256']),
                           (m.CATALOG/'biographies.json', run['catalog_sha256']),
                           (Path(m.__file__), run['tool_sha256'])]:
        if hashlib.sha256(path.read_bytes()).hexdigest() != expected:
            p.error('pinned input changed: '+path.name)
    biographies = m.load(m.CATALOG/'biographies.json')
    manifest = m.load(m.CATALOG/'manifest.json')
    if args.catalog_version == manifest['catalog_version']:
        p.error('catalog version must be new')
    rows = {r['npc_name']: r for r in biographies}
    evidence = m.load(args.evidence)
    jobs = {r['npc_name'] for r in biographies if not json.loads(r['relationships'] or '{}') and evidence.get(r['refid'])}
    records = [json.loads(x) for x in (args.run_dir/'results.jsonl').read_text(encoding='utf-8').splitlines()]
    if len(records) != len(jobs) or {x['npc_name'] for x in records} != jobs:
        p.error('missing, duplicate or unexpected terminal records')
    ledger = [json.loads(x) for x in (args.run_dir/'ledger.jsonl').read_text(encoding='utf-8').splitlines()]
    attempts = {x['attempt_id']: x for x in ledger}
    if any(x['state']=='reserved' for x in attempts.values()):
        p.error('unreconciled request reservation')
    charged = sum(x['charged'] for x in attempts.values())
    if charged > min(30, run['budget']) or abs(charged-status['charged_or_reserved_usd']) > 0.000001:
        p.error('budget accounting mismatch')
    excluded = m.load(args.exclude) if args.exclude else {}
    if not isinstance(excluded, dict) or not set(excluded) <= jobs:
        p.error('invalid review exclusions')
    review = m.load(args.review) if args.review else {}
    if not isinstance(review, dict) or not set(review) <= jobs or set(review) & set(excluded):
        p.error('invalid or conflicting review corrections')
    for correction in review.values():
        if not isinstance(correction, dict) or set(correction) != {'reason', 'raw'} or not isinstance(correction['reason'], str) or not correction['reason'].strip():
            p.error('review corrections require a reason and raw relationships')
    builder = m.generator()
    changes = []
    counts = collections.Counter()
    for item in records:
        counts[item['status']] += 1
        row = rows[item['npc_name']]
        if item['record_id'] != row['refid'] or json.loads(row['relationships'] or '{}'):
            p.error('nonempty or mismatched source row')
        if item['status'] == 'quarantined':
            if item['npc_name'] in review:
                p.error('quarantined output cannot be corrected without a validated terminal record')
            continue
        if item['status'] not in ('accepted', 'empty'):
            p.error('unexpected terminal status')
        normalized = m.normalize(item['raw'], item['npc_name'], evidence[row['refid']], builder)
        if normalized != item['relationships'] or bool(normalized) != (item['status']=='accepted'):
            p.error('stored output does not match validation')
        if item['npc_name'] in review:
            normalized = m.normalize(review[item['npc_name']]['raw'], item['npc_name'], evidence[row['refid']], builder)
        if not normalized or item['npc_name'] in excluded:
            continue
        row['relationships'] = json.dumps(normalized, ensure_ascii=False, separators=(',', ':'))
        changes.append((row['npc_name'], row['refid'], row['relationships']))
    if dict(counts) != {k:v for k,v in status['counts'].items() if v}:
        p.error('status counts do not match results')
    encoded = (json.dumps(biographies, ensure_ascii=False, indent=2)+'\n').encode('utf-8')
    manifest['catalog_version'] = args.catalog_version
    manifest['biographies_sha256'] = hashlib.sha256(encoded).hexdigest()
    manifest['relationship_backfill'] = {'mode': 'empty_factory_only', 'model': run['model'],
        'changed_rows': len(changes), 'input_catalog_sha256': run['catalog_sha256'],
        'results_sha256': hashlib.sha256((args.run_dir/'results.jsonl').read_bytes()).hexdigest(),
        'charged_or_reserved_usd': charged, 'review_exclusions': excluded,
        'review_corrections': review}
    # SQL is an optional data-only backfill; it deliberately never replaces whole bio rows.
    quote = lambda value: "'"+value.replace("'", "''")+"'"
    sql = ["-- Generated relationship backfill; preserves populated/custom biographies.",
           'BEGIN;', 'SET LOCAL standard_conforming_strings=on;',
           'LOCK TABLE public.bio_templates, public.bio_templates_custom IN SHARE ROW EXCLUSIVE MODE;']
    for name, refid, relationships in changes:
        sql.append('UPDATE public.bio_templates b SET relationships='+quote(relationships)
                   +' WHERE b.npc_name='+quote(name)+' AND lower(b.refid)=lower('+quote(refid)+')'
                   +" AND COALESCE(NULLIF(btrim(b.relationships),''),'{}')::jsonb='{}'::jsonb"
                   +' AND NOT EXISTS (SELECT 1 FROM public.bio_templates_custom c WHERE c.npc_name=b.npc_name);')
    sql.append('COMMIT;')
    args.output.mkdir(parents=True)
    (args.output/'biographies.json').write_bytes(encoded)
    (args.output/'manifest.json').write_text(json.dumps(manifest, indent=2)+'\n', encoding='utf-8')
    (args.output/'catalog-version.txt').write_text(args.catalog_version+'\n', encoding='utf-8')
    (args.output/'relationships-backfill.sql').write_text('\n'.join(sql)+'\n', encoding='utf-8')
    print(json.dumps({'validated': len(records), 'changed_rows': len(changes), 'counts': dict(counts),
                      'excluded': len(excluded), 'charged_usd': charged, 'live_import': False}, indent=2))


if __name__ == '__main__':
    main()
