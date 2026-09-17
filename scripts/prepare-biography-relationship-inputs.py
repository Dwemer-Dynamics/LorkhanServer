#!/usr/bin/env python3
"""Prepare bounded, provenance-labelled relationship candidates without provider calls."""
import argparse
import collections
import hashlib
import json
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def read(path):
    return json.loads(path.read_text(encoding='utf-8'))


def main():
    p = argparse.ArgumentParser(description=__doc__)
    p.add_argument('--scope', type=Path, required=True)
    p.add_argument('--sources', type=Path, required=True)
    p.add_argument('--output', type=Path, required=True)
    p.add_argument('--catalog', type=Path, default=ROOT/'data/biographies/morrowind-official')
    args = p.parse_args()
    if args.output.exists():
        p.error('output directory must be new')
    scope = read(args.scope)
    catalog = args.catalog/'biographies.json'
    if hashlib.sha256(catalog.read_bytes()).hexdigest() != scope['catalog_sha256']:
        p.error('catalog changed since scope audit')
    rows = {r['npc_name']: r for r in read(catalog)}
    by_ref = {r['refid'].casefold(): r for r in rows.values()}
    aliases = collections.defaultdict(set)
    for item in read(args.catalog/'manifest.json')['items']:
        aliases[item['display_name'].casefold()].add(by_ref[item['record_id'].casefold()]['npc_name'])
    unique = {name: next(iter(keys)) for name, keys in aliases.items() if len(keys) == 1 and len(name) >= 5}
    pattern = re.compile(r'(?<!\w)(?:'+'|'.join(re.escape(n) for n in sorted(unique, key=len, reverse=True))+r')(?!\w)', re.I)
    sources = {r['npc_name']: r for r in (json.loads(line) for line in args.sources.read_text(encoding='utf-8').splitlines())}
    evidence, coverage, counts = {}, [], collections.Counter()
    for item in scope['items']:
        if item['status'] != 'eligible_empty':
            continue
        row = rows[item['npc_name']]
        if row['refid'] != item['record_id'] or json.loads(row['relationships'] or '{}'):
            raise ValueError('scope changed or populated relationships encountered')
        candidates = collections.defaultdict(list)
        source = sources.get(row['npc_name'])
        if source:
            if source['record_id'] != row['refid'] or source['content_file'] != item['content_file']:
                raise ValueError('source identity mismatch')
            for passage in source['excerpts']:
                for target in passage['mentioned_npcs']:
                    if target in rows and target != row['npc_name']:
                        candidates[target].append(('verified_source', source['source'], passage['text']))
        # Existing generated prose is lower-confidence context, never independent lore proof.
        for field in ('npc_static_bio', 'personality', 'occupation', 'goals'):
            value = row.get(field, '')
            for match in pattern.finditer(value):
                target = unique.get(match.group().casefold())
                if target and target != row['npc_name']:
                    start = max(0, value.rfind('.', 0, match.start())+1)
                    end = value.find('.', match.end())
                    excerpt = value[start:end+1 if end >= 0 else len(value)].strip()[:1200]
                    candidates[target].append(('existing_generated_bio', f'catalog:{row["npc_name"]}:{field}', excerpt))
        # Prioritize source-backed candidates; bounded overflow remains visible in the audit.
        candidates = {target: entries for target, entries in candidates.items()
                      if target.casefold() not in {'player', 'the player', 'nerevarine', 'the nerevarine'}}
        ordered = sorted(candidates, key=lambda target: (not any(x[0]=='verified_source' for x in candidates[target]), target))
        entries = []
        for target in ordered[:6]:
            parts, seen = [], set()
            for kind, origin, excerpt in candidates[target]:
                if excerpt in seen:
                    continue
                seen.add(excerpt)
                part = f'[{kind}; {origin}] {excerpt}'
                if sum(len(x)+2 for x in parts)+len(part) > 3800:
                    continue
                parts.append(part)
            if parts:
                entries.append({'target': target, 'source': candidates[target][0][1],
                                'excerpt': '\n\n'.join(parts)})
        if entries:
            evidence[row['refid']] = entries
            state = 'source_backed_candidates' if any(x[0]=='verified_source' for v in candidates.values() for x in v) else 'bio_only_candidates'
        else:
            state = 'leave_empty_no_named_candidates'
        counts[state] += 1
        coverage.append({'npc_name': row['npc_name'], 'record_id': row['refid'], 'content_file': item['content_file'],
                         'status': state, 'selected_targets': [e['target'] for e in entries],
                         'deferred_targets': ordered[6:], 'approved_for_import': False})
    args.output.mkdir(parents=True)
    (args.output/'evidence.json').write_text(json.dumps(evidence, indent=2), encoding='utf-8')
    (args.output/'coverage.json').write_text(json.dumps(coverage, indent=2), encoding='utf-8')
    summary = {'eligible': len(coverage), 'counts': dict(counts), 'generation_candidates': len(evidence),
               'deferred_target_count': sum(len(x['deferred_targets']) for x in coverage),
               'paid_calls': 0, 'model': 'z-ai/glm-5.2', 'approved_budget_usd': scope['approved_budget_usd'],
               'catalog_sha256': scope['catalog_sha256'],
               'sources_sha256': hashlib.sha256(args.sources.read_bytes()).hexdigest(),
               'policy': 'Candidates require semantic validation. Bio-only claims are provisional; no automatic live import.'}
    (args.output/'summary.json').write_text(json.dumps(summary, indent=2), encoding='utf-8')
    print(json.dumps(summary, indent=2))


if __name__ == '__main__':
    main()
