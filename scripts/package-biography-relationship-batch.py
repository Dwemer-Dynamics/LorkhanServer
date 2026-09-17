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
    p.add_argument('--remaining-after', type=Path, help='Original completed run for an all-remaining discovery batch')
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
    prior_cost = 0
    if 'discovery_sha256' in run:
        if not args.remaining_after:
            p.error('discovery packaging requires --remaining-after')
        previous_results = args.remaining_after/'results.jsonl'
        if hashlib.sha256(previous_results.read_bytes()).hexdigest() != run['prior_results_sha256']:
            p.error('prior results changed')
        if hashlib.sha256(Path(__file__).with_name('discover-biography-relationships.py').read_bytes()).hexdigest() != run['discovery_sha256']:
            p.error('discovery code changed')
        previous = [json.loads(x) for x in previous_results.read_text(encoding='utf-8').splitlines()]
        previous_names = {x['npc_name'] for x in previous}
        jobs = {r['npc_name'] for r in biographies if not json.loads(r['relationships'] or '{}') and r['npc_name'] not in previous_names}
        prior_ledger = [json.loads(x) for x in (args.remaining_after/'ledger.jsonl').read_text(encoding='utf-8').splitlines()]
        prior_attempts = {x['attempt_id']:x for x in prior_ledger}
        if any(x['state']=='reserved' for x in prior_attempts.values()):
            p.error('unresolved prior billing')
        prior_cost = sum(x['charged'] for x in prior_attempts.values())
        if abs(prior_cost-run['prior_cost_usd']) > 0.000001:
            p.error('prior cost changed')
    records = [json.loads(x) for x in (args.run_dir/'results.jsonl').read_text(encoding='utf-8').splitlines()]
    if len(jobs) != run['jobs']:
        p.error('manifest job count does not match catalog scope')
    if len(records) != len(jobs) or {x['npc_name'] for x in records} != jobs:
        p.error('missing, duplicate or unexpected terminal records')
    if any(x['status']=='quarantined' for x in records):
        p.error('quarantined results must be resolved before packaging')
    ledger = [json.loads(x) for x in (args.run_dir/'ledger.jsonl').read_text(encoding='utf-8').splitlines()]
    attempts = {x['attempt_id']: x for x in ledger}
    if any(x['state']=='reserved' for x in attempts.values()):
        p.error('unreconciled request reservation')
    charged = prior_cost + sum(x['charged'] for x in attempts.values())
    if charged > min(30, run['budget']) or abs(charged-status['charged_or_reserved_usd']) > 0.000001:
        p.error('budget accounting mismatch')
    excluded = m.load(args.exclude) if args.exclude else {}
    if not isinstance(excluded, dict) or not set(excluded) <= jobs:
        p.error('invalid review exclusions')
    review = m.load(args.review) if args.review else {}
    if not isinstance(review, dict) or not set(review) <= jobs or set(review) & set(excluded):
        p.error('invalid or conflicting review corrections')
    if 'discovery_sha256' in run and any(x['status']=='accepted' and x['npc_name'] not in review and x['npc_name'] not in excluded for x in records):
        p.error('every nonempty discovery proposal requires explicit review or exclusion')
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
        if item['status'] not in ('accepted', 'empty'):
            p.error('unexpected terminal status')
        entries = item['discovery_evidence'] if 'discovery_sha256' in run else evidence[row['refid']]
        m.evidence_for(row['refid'], {row['refid']:entries}, rows)
        normalized = m.normalize(item['raw'], item['npc_name'], entries, builder)
        if normalized != item['relationships'] or bool(normalized) != (item['status']=='accepted'):
            p.error('stored output does not match validation')
        if item['npc_name'] in review:
            normalized = m.normalize(review[item['npc_name']]['raw'], item['npc_name'], entries, builder)
        if not normalized or item['npc_name'] in excluded:
            continue
        row['relationships'] = json.dumps(normalized, ensure_ascii=False, separators=(',', ':'))
        changes.append((row['npc_name'], row['refid'], row['relationships']))
    if dict(counts) != {k:v for k,v in status['counts'].items() if v}:
        p.error('status counts do not match results')
    encoded = (json.dumps(biographies, ensure_ascii=False, indent=2)+'\n').encode('utf-8')
    manifest['catalog_version'] = args.catalog_version
    manifest['biographies_sha256'] = hashlib.sha256(encoded).hexdigest()
    if 'discovery_sha256' in run and manifest.get('relationship_backfill'):
        history = list(manifest.get('relationship_backfill_history', []))
        history.append(manifest['relationship_backfill'])
        manifest['relationship_backfill_history'] = history
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
    if 'discovery_sha256' in run:
        # Keep earlier guarded updates available when installing from an older catalog.
        previous_sql = m.CATALOG/'relationships-backfill.sql'
        if previous_sql.exists():
            sql.insert(0, previous_sql.read_text(encoding='utf-8').rstrip()+'\n')
    args.output.mkdir(parents=True)
    (args.output/'biographies.json').write_bytes(encoded)
    # Catalog hashes must survive Windows generation and Git's LF normalization unchanged.
    (args.output/'manifest.json').write_text(json.dumps(manifest, indent=2)+'\n', encoding='utf-8', newline='\n')
    (args.output/'catalog-version.txt').write_text(args.catalog_version+'\n', encoding='utf-8', newline='\n')
    (args.output/'relationships-backfill.sql').write_text('\n'.join(sql)+'\n', encoding='utf-8', newline='\n')
    print(json.dumps({'validated': len(records), 'changed_rows': len(changes), 'counts': dict(counts),
                      'excluded': len(excluded), 'charged_usd': charged, 'live_import': False}, indent=2))


if __name__ == '__main__':
    main()
