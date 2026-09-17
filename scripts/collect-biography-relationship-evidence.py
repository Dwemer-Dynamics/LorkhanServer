#!/usr/bin/env python3
"""Collect identity-verified cached sources; never generate or import relationships."""
import argparse
import collections
import hashlib
import json
import re
from pathlib import Path
from urllib.parse import unquote, quote

from bs4 import BeautifulSoup

ROOT = Path(__file__).resolve().parents[1]
NAMESPACES = {'Morrowind': 'Morrowind.esm', 'Tribunal': 'Tribunal.esm',
              'Bloodmoon': 'Bloodmoon.esm', 'Tamriel Rebuilt': 'TR_Mainland.esm'}


def read(path):
    return json.loads(path.read_text(encoding='utf-8'))


def text(tag):
    return re.sub(r'\s+', ' ', tag.get_text(' ', strip=True)).strip()


# Accept an NPC page only when its infobox contains an exact catalog record ID.
def identity(parsed, known):
    title = parsed.get('title', '')
    content = NAMESPACES.get(title.split(':', 1)[0])
    if not content or not isinstance(parsed.get('revid'), int):
        return None
    html = parsed.get('text') or ''
    # Most redirected town pages have no NPC infobox; avoid parsing those large pages.
    if not re.search(r'<table\b[^>]*\bclass=["\'][^"\']*\binfobox\b', html):
        return None
    soup = BeautifulSoup(html, 'html.parser')
    boxes = soup.select('table.infobox')
    if len(boxes) != 1:
        return None
    heading = boxes[0].find('th')
    small = heading.find('small') if heading else None
    match = re.search(r'\(([^()]+)\)', text(small)) if small else None
    key = (content.casefold(), match.group(1).strip().casefold()) if match else None
    return (key, soup) if key in known else None


def main():
    p = argparse.ArgumentParser(description=__doc__)
    p.add_argument('--scope', type=Path, required=True)
    p.add_argument('--cache', type=Path, action='append', required=True)
    p.add_argument('--output', type=Path, required=True)
    p.add_argument('--catalog', type=Path, default=ROOT/'data/biographies/morrowind-official')
    args = p.parse_args()
    if args.output.exists():
        p.error('output must be a new directory; preserve previous evidence runs')
    scope = read(args.scope)
    catalog_path = args.catalog/'biographies.json'
    if hashlib.sha256(catalog_path.read_bytes()).hexdigest() != scope['catalog_sha256']:
        p.error('catalog changed since scope audit; refresh scope first')
    known = {(x['content_file'].casefold(), x['record_id'].casefold()): x
             for x in read(args.catalog/'manifest.json')['items']}
    rows = {x['refid'].casefold(): x for x in read(catalog_path)}
    pages, title_keys = {}, collections.defaultdict(set)
    diagnostics = collections.Counter()
    for cache in args.cache:
        if not cache.is_dir():
            p.error(f'cache directory unavailable: {cache}')
        for path in sorted(cache.glob('*.json')):
            diagnostics['cache_files'] += 1
            try:
                payload = read(path)
                parsed = payload.get('parse', {})
                matched = identity(parsed, known)
            except (ValueError, TypeError, AttributeError):
                diagnostics['invalid_cache'] += 1
                continue
            if not matched:
                diagnostics['unverified_or_non_npc_page'] += 1
                continue
            key, _ = matched
            title = parsed['title'].replace('_', ' ').casefold()
            title_keys[title].add(key)
            prior = pages.get(key)
            if not prior or parsed['revid'] > prior['parsed']['revid']:
                pages[key] = {'parsed': parsed, 'path': str(path),
                              'sha256': hashlib.sha256(path.read_bytes()).hexdigest()}
    names = collections.defaultdict(set)
    for key, entry in known.items():
        names[entry['display_name'].casefold()].add(key)
    # Plain dialogue often lacks wiki links. Only unambiguous full names are candidates.
    exact_names = {name: next(iter(keys)) for name, keys in names.items()
                   if len(keys) == 1 and len(name) >= 5 and next(iter(keys)) in pages}
    name_pattern = re.compile(r'(?<!\w)(?:'+'|'.join(re.escape(n) for n in sorted(exact_names, key=len, reverse=True))+r')(?!\w)', re.IGNORECASE) if exact_names else None
    args.output.mkdir(parents=True)
    counts, by_content = collections.Counter(), collections.defaultdict(collections.Counter)
    with (args.output/'sources.jsonl').open('w', encoding='utf-8') as sources, \
         (args.output/'coverage.jsonl').open('w', encoding='utf-8') as coverage:
        for item in scope['items']:
            if item['status'] != 'eligible_empty':
                continue
            key = (item['content_file'].casefold(), item['record_id'].casefold())
            if key not in known or rows[key[1]]['npc_name'] != item['npc_name']:
                raise ValueError('scope identity does not match catalog')
            page = pages.get(key)
            status = 'source_unavailable_or_unverified'
            excerpts, seen = [], set()
            if page:
                parsed = page['parsed']
                soup = BeautifulSoup(parsed['text'], 'html.parser')
                for tag in soup.select('table,.thumb,.toc,.mw-editsection,script,style'):
                    tag.decompose()
                root = soup.select_one('.mw-parser-output') or soup
                for block in root.find_all(['p', 'dd']):
                    value = text(block)
                    if not 30 <= len(value) <= 1600 or value in seen:
                        continue
                    seen.add(value)
                    targets = set()
                    if name_pattern:
                        for match in name_pattern.finditer(value):
                            target = exact_names.get(match.group().casefold())
                            if target and target != key:
                                targets.add(rows[target[1]]['npc_name'])
                    for link in block.find_all('a', href=True):
                        href = link['href']
                        if not href.startswith('/wiki/') or '#' in href:
                            continue
                        title = unquote(href[6:]).replace('_', ' ').casefold()
                        matches = title_keys.get(title, set())
                        if len(matches) == 1:
                            target = next(iter(matches))
                            if target != key:
                                targets.add(rows[target[1]]['npc_name'])
                    excerpts.append({'text': value, 'mentioned_npcs': sorted(targets),
                                     'relationship_review_required': True})
                    if len(excerpts) >= 40:
                        break
                status = 'verified_source_with_mentions' if any(x['mentioned_npcs'] for x in excerpts) else 'verified_source_no_resolved_mentions'
                sources.write(json.dumps({'npc_name': item['npc_name'], 'record_id': item['record_id'],
                    'content_file': item['content_file'], 'source': 'https://en.uesp.net/w/index.php?title='+quote(parsed['title'])+'&oldid='+str(parsed['revid']),
                    'cache_path': page['path'], 'cache_sha256': page['sha256'],
                    'revision_id': parsed['revid'], 'excerpts': excerpts,
                    'status': status, 'approved_for_generation': False}, ensure_ascii=False)+'\n')
            counts[status] += 1
            by_content[item['content_file']][status] += 1
            coverage.write(json.dumps({**item, 'evidence_status': status, 'excerpt_count': len(excerpts)})+'\n')
    summary = {'eligible': sum(counts.values()), 'coverage': dict(counts),
               'by_content_file': dict(by_content), 'cache_audit': dict(diagnostics),
               'verified_npc_pages': len(pages), 'network_requests': 0, 'paid_requests': 0,
               'scope_sha256': hashlib.sha256(args.scope.read_bytes()).hexdigest(),
               'tool_sha256': hashlib.sha256(Path(__file__).read_bytes()).hexdigest(),
               'notice': 'Mentions are not relationships. Sources require claim and quest-state review before generation. Missing sources do not prove no relationships.'}
    (args.output/'summary.json').write_text(json.dumps(summary, indent=2)+'\n', encoding='utf-8')
    print(json.dumps(summary, indent=2))


if __name__ == '__main__':
    main()
