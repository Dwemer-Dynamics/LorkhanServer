#!/usr/bin/env python3
"""Single-worker, resumable relationship generation with durable cost reservations."""
import argparse
import hashlib
import importlib.util
import json
import math
import os
from pathlib import Path
import subprocess
import sys
import time
import uuid


def append(path, value):
    with path.open('a', encoding='utf-8') as f:
        f.write(json.dumps(value, ensure_ascii=False)+'\n')
        f.flush()
        os.fsync(f.fileno())


def save(path, value):
    temporary = path.with_suffix('.tmp')
    temporary.write_text(json.dumps(value, indent=2), encoding='utf-8')
    # Windows readers can briefly prevent replacing a status file.
    for attempt in range(20):
        try:
            os.replace(temporary, path)
            break
        except PermissionError:
            if attempt == 19:
                raise
            time.sleep(0.1)


# Resolve the existing private credential directly into memory, never a file or command argument.
def credential():
    if os.environ.get('OPENROUTER_API_KEY'):
        return os.environ['OPENROUTER_API_KEY']
    php = """<?php
require '/var/www/html/LorkhanServer/lib/Autoload.php';
$c=require '/etc/lorkhanserver/server.php';
$key=LorkhanServer\\Application\\ProviderFactory::apiKey(['api_key_env'=>'LORKHAN_LLM_OPENROUTER_API_KEY'],'LORKHAN_LLM_OPENROUTER_API_KEY',$c);
if($key==='')exit(2);echo $key;
"""
    result = subprocess.run(['wsl', '-d', 'DwemerAI4Skyrim3', '-u', 'root', '--',
                             'runuser', '-u', 'www-data', '--', 'php'],
                            input=php, text=True, encoding='utf-8', capture_output=True, timeout=30)
    if result.returncode or not result.stdout.strip():
        raise RuntimeError('private_credential_unavailable')
    return result.stdout.strip()


def main():
    p = argparse.ArgumentParser(description=__doc__)
    p.add_argument('--evidence', type=Path, required=True)
    p.add_argument('--run-dir', type=Path, required=True)
    p.add_argument('--budget', type=float, default=30)
    p.add_argument('--dry-run', action='store_true')
    p.add_argument('--remaining-after', type=Path, help='Completed prior run; assess every still-empty NPC not processed there')
    args = p.parse_args()
    if not math.isfinite(args.budget) or not 0 < args.budget <= 30:
        p.error('budget must be positive and no greater than the approved $30')
    spec = importlib.util.spec_from_file_location('backfill', Path(__file__).with_name('backfill-morrowind-biography-relationships.py'))
    m = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(m)
    builder = m.generator()
    evidence = m.load(args.evidence)
    rows = {r['npc_name']: r for r in m.load(m.CATALOG/'biographies.json')}
    prior_names, prior_cost = set(), 0.0
    discovery = None
    if args.remaining_after:
        previous = m.load(args.remaining_after/'status.json')
        if previous['status'] != 'complete':
            p.error('prior run must be complete')
        prior_records = [json.loads(x) for x in (args.remaining_after/'results.jsonl').read_text(encoding='utf-8').splitlines()]
        prior_names = {r['npc_name'] for r in prior_records}
        if len(prior_names) != previous['completed'] or any(r['status']=='quarantined' for r in prior_records):
            p.error('prior run has missing or unresolved results')
        previous_ledger = [json.loads(x) for x in (args.remaining_after/'ledger.jsonl').read_text(encoding='utf-8').splitlines()]
        latest_prior = {r['attempt_id']:r for r in previous_ledger}
        if any(r['state']=='reserved' for r in latest_prior.values()):
            p.error('prior run has unresolved billing')
        prior_cost = sum(r['charged'] for r in latest_prior.values())
        spec = importlib.util.spec_from_file_location('discovery', Path(__file__).with_name('discover-biography-relationships.py'))
        discovery = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(discovery)
        discovery.configure(m, rows)
    jobs = []
    for row in rows.values():
        if json.loads(row['relationships'] or '{}') or row['npc_name'] in prior_names:
            continue
        entries = m.evidence_for(row['refid'], evidence, rows)
        if entries or discovery:
            jobs.append((row, entries))
    if args.dry_run:
        print(json.dumps({'jobs': len(jobs), 'budget': args.budget, 'model': 'z-ai/glm-5.2', 'paid_calls': 0}))
        return
    args.run_dir.mkdir(parents=True, exist_ok=True)
    lock = (args.run_dir/'runner.lock').open('a+b')
    lock.seek(0)
    if os.name == 'nt':
        import msvcrt
        if not lock.read(1):
            lock.write(b'0'); lock.flush()
        lock.seek(0)
        msvcrt.locking(lock.fileno(), msvcrt.LK_NBLCK, 1)
    else:
        import fcntl
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
    pinned = {'evidence_sha256': hashlib.sha256(args.evidence.read_bytes()).hexdigest(),
              'catalog_sha256': hashlib.sha256((m.CATALOG/'biographies.json').read_bytes()).hexdigest(),
              'tool_sha256': hashlib.sha256(Path(m.__file__).read_bytes()).hexdigest(),
              'runner_sha256': hashlib.sha256(Path(__file__).read_bytes()).hexdigest(),
              'model': 'z-ai/glm-5.2', 'budget': args.budget, 'jobs': len(jobs)}
    if discovery:
        pinned.update(discovery_sha256=hashlib.sha256(Path(discovery.__file__).read_bytes()).hexdigest(),
                      prior_results_sha256=hashlib.sha256((args.remaining_after/'results.jsonl').read_bytes()).hexdigest(),
                      prior_cost_usd=prior_cost)
    manifest = args.run_dir/'manifest.json'
    if manifest.exists() and m.load(manifest) != pinned:
        raise RuntimeError('pinned_inputs_changed_do_not_resume')
    save(manifest, pinned)
    ledger_path, results_path = args.run_dir/'ledger.jsonl', args.run_dir/'results.jsonl'
    ledger = [json.loads(x) for x in ledger_path.read_text(encoding='utf-8').splitlines()] if ledger_path.exists() else []
    records = [json.loads(x) for x in results_path.read_text(encoding='utf-8').splitlines()] if results_path.exists() else []
    latest = {x['attempt_id']: x for x in ledger}
    spent = prior_cost + sum(x['charged'] for x in latest.values())
    done = {x['npc_name'] for x in records}
    counts = {state: sum(x['status']==state for x in records) for state in ('accepted', 'empty', 'quarantined')}
    state = {'pid': os.getpid(), 'model': pinned['model'], 'total': len(jobs), 'completed': len(done),
             'counts': counts, 'charged_or_reserved_usd': spent, 'budget_usd': args.budget}

    def status(value):
        state.update(status=value, updated_utc=time.strftime('%Y-%m-%dT%H:%M:%SZ', time.gmtime()),
                     completed=len(done), charged_or_reserved_usd=spent)
        save(args.run_dir/'status.json', state)

    if any(x['state']=='reserved' for x in latest.values()):
        status('stopped_uncertain_previous_request')
        return
    key = credential()
    status('running')
    with builder.requests.Session() as session:
        for row, entries in jobs:
            name = row['npc_name']
            if name in done:
                continue
            body = m.request_body(builder, pinned['model'], row, entries)
            if discovery:
                body = discovery.request_body(builder, pinned['model'], row)
            # OpenRouter enforces these per-million-token provider prices; no plugins or tools.
            body['provider'].update(max_price={'prompt': 1.4, 'completion': 4.4, 'request': 0}, allow_fallbacks=False)
            # UTF-8 request bytes conservatively bound text tokens, plus schema/framing headroom.
            reserve = ((len(json.dumps(body).encode('utf-8'))+8192)*1.4+1800*4.4)/1000000*2
            failure = None
            for attempt in range(2):
                if spent+reserve > args.budget:
                    status('stopped_budget_cap'); return
                attempt_id = str(uuid.uuid4())
                append(ledger_path, {'attempt_id': attempt_id, 'npc_name': name, 'state': 'reserved', 'charged': reserve})
                spent += reserve
                state['current_npc'] = name
                status('running')
                try:
                    response = session.post(builder.OPENROUTER_URL, headers={'Authorization': 'Bearer '+key},
                                            json=body, timeout=(15, 90), allow_redirects=False)
                except builder.requests.RequestException:
                    status('stopped_uncertain_request'); return
                if response.status_code != 200:
                    # Retain the full reservation on provider errors; never assume zero billing.
                    append(ledger_path, {'attempt_id': attempt_id, 'npc_name': name, 'state': 'http_error', 'charged': reserve})
                    if response.status_code in (429, 502, 503, 504) and attempt == 0:
                        time.sleep(30); continue
                    failure = 'provider_http_'+str(response.status_code)
                    if response.status_code in (401, 402, 403):
                        status(failure); return
                    break
                result = None
                validation_stage = 'response_json'
                try:
                    result = response.json()
                    cost = result.get('usage', {}).get('cost')
                    charged = float(cost) if isinstance(cost, (int, float)) and math.isfinite(cost) and cost >= 0 else reserve
                    append(ledger_path, {'attempt_id': attempt_id, 'npc_name': name, 'state': 'billed', 'charged': charged,
                                         'generation_id': result.get('id')})
                    spent += charged-reserve
                    if charged > reserve or spent > args.budget:
                        status('stopped_cost_bound_exceeded'); return
                    validation_stage = 'finish_reason'
                    if result['choices'][0].get('finish_reason') != 'stop':
                        raise ValueError('incomplete_response')
                    validation_stage = 'relationship_json'
                    raw = builder.extract_json_object(result['choices'][0]['message']['content'])['relationships']
                    if discovery:
                        validation_stage = 'target_resolution'
                        raw, entries = discovery.resolve(raw, name)
                    validation_stage = 'relationship_schema'
                    normalized = m.normalize(raw, name, entries, builder)
                except (ValueError, KeyError, TypeError, IndexError) as error:
                    # Retain bounded model output for review, never headers, credentials or prompts.
                    choices = result.get('choices', []) if isinstance(result, dict) else []
                    choice = choices[0] if choices and isinstance(choices[0], dict) else {}
                    message = choice.get('message', {})
                    content = message.get('content', '') if isinstance(message, dict) else ''
                    append(args.run_dir/'validation-errors.jsonl', {
                        'attempt_id': attempt_id, 'npc_name': name, 'stage': validation_stage,
                        'error': str(error)[:300], 'finish_reason': choice.get('finish_reason'),
                        'content': content[:12000] if isinstance(content, str) else ''})
                    failure = 'invalid_structured_response'
                    if attempt == 0:
                        body['messages'][0]['content'] += ' Previous output failed validation. Respect all text bounds and type/affinity consistency. Prefer empty output to uncertain claims.'
                        continue
                    break
                outcome = 'accepted' if normalized else 'empty'
                append(results_path, {'npc_name': name, 'record_id': row['refid'], 'status': outcome,
                                      'relationships': normalized, 'raw': raw, 'generation_id': result.get('id'),
                                      **({'discovery_evidence': entries} if discovery else {})})
                counts[outcome] += 1
                failure = None
                break
            if failure:
                append(results_path, {'npc_name': name, 'record_id': row['refid'], 'status': 'quarantined', 'reason': failure})
                counts['quarantined'] += 1
            done.add(name)
            status('running')
            time.sleep(0.5)
    status('complete')


if __name__ == '__main__':
    main()
