#!/usr/bin/env python3
"""Run a durable, stratified Morrowind biography preflight one record at a time."""

from __future__ import annotations

import argparse
import hashlib
import json
import os
from pathlib import Path
import statistics
import subprocess
import sys
import time
import types
from typing import Any


SCRIPT_DIR = Path(__file__).resolve().parent
BUILDER_PATH = SCRIPT_DIR / "build-morrowind-biographies.py"
FORMAT_VERSION = "almsivi.morrowind-biography-preflight.v1"
DEFAULT_PILOT_IDS = ("fargoth", "caius cosades", "chargen name", "ajira", "divayth fyr")


def load_builder() -> Any:
    # Execute source directly so rapid same-size edits cannot reuse stale timestamp-based bytecode.
    module = types.ModuleType("almsivi_morrowind_biographies")
    module.__file__ = str(BUILDER_PATH)
    source = BUILDER_PATH.read_text(encoding="utf-8")
    exec(compile(source, str(BUILDER_PATH), "exec"), module.__dict__)
    return module


def utc_timestamp() -> str:
    return time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime())


def atomic_json(path: Path, payload: Any) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    temporary = path.with_name(path.name + ".tmp")
    temporary.write_text(json.dumps(payload, indent=2, ensure_ascii=False) + "\n", encoding="utf-8", newline="\n")
    os.replace(temporary, path)


def acquire_run_lock(run_dir: Path) -> Any:
    run_dir.mkdir(parents=True, exist_ok=True)
    handle = (run_dir / ".preflight.lock").open("a+", encoding="utf-8")
    try:
        handle.seek(0)
        if os.name == "nt":
            import msvcrt

            if not handle.read(1):
                handle.write("\0")
                handle.flush()
            handle.seek(0)
            msvcrt.locking(handle.fileno(), msvcrt.LK_NBLCK, 1)
        else:
            import fcntl

            fcntl.flock(handle.fileno(), fcntl.LOCK_EX | fcntl.LOCK_NB)
        handle.seek(1)
        handle.truncate()
        handle.write(json.dumps({"pid": os.getpid(), "started_at_utc": utc_timestamp()}))
        handle.flush()
        return handle
    except (OSError, BlockingIOError) as error:
        handle.close()
        raise RuntimeError(f"Another preflight process already holds the run lock: {run_dir}") from error


def release_run_lock(handle: Any) -> None:
    try:
        handle.seek(0)
        if os.name == "nt":
            import msvcrt

            msvcrt.locking(handle.fileno(), msvcrt.LK_UNLCK, 1)
        else:
            import fcntl

            fcntl.flock(handle.fileno(), fcntl.LOCK_UN)
    finally:
        handle.close()


def identity(npc: dict[str, Any], categories: list[str]) -> dict[str, Any]:
    return {
        "record_id": npc["record_id"],
        "display_name": npc["display_name"],
        "content_file": npc["content_file"],
        "race_id": npc["race_id"],
        "class_id": npc["class_id"],
        "faction_id": npc["faction_id"],
        "script_id": npc["script_id"],
        "gender": npc["gender"],
        "flags": npc["flags"],
        "categories": categories,
    }


def stratified_selection(catalog: list[dict[str, Any]], size: int) -> list[dict[str, Any]]:
    if size < len(DEFAULT_PILOT_IDS):
        raise ValueError(f"Preflight size must be at least {len(DEFAULT_PILOT_IDS)}")
    by_id = {row["record_id"].casefold(): row for row in catalog}
    name_groups: dict[str, list[dict[str, Any]]] = {}
    for row in catalog:
        name_groups.setdefault(row["display_name"].casefold(), []).append(row)
    categories: dict[str, list[str]] = {}
    selected: dict[str, dict[str, Any]] = {}

    def add(category: str, candidates: list[dict[str, Any]], count: int) -> None:
        if count <= 0 or len(selected) >= size:
            return
        added = 0
        for npc in candidates:
            key = npc["record_id"].casefold()
            if category not in categories.setdefault(key, []):
                categories[key].append(category)
            if key in selected:
                continue
            selected[key] = npc
            added += 1
            if added >= count or len(selected) >= size:
                return

    pilot = [by_id[key] for key in DEFAULT_PILOT_IDS]
    add("approved-five", pilot, len(pilot))
    order = lambda row: (row["display_name"].casefold(), row["record_id"].casefold())
    add("tribunal", sorted([row for row in catalog if row["content_file"] == "Tribunal.esm"], key=order), 5)
    add("bloodmoon", sorted([row for row in catalog if row["content_file"] == "Bloodmoon.esm"], key=order), 5)

    duplicate_candidates: list[dict[str, Any]] = []
    for _, group in sorted(name_groups.items(), key=lambda item: (-len(item[1]), item[0])):
        if len(group) > 1:
            duplicate_candidates.append(sorted(group, key=lambda row: row["record_id"].casefold())[1])
    add("duplicate-display-name", duplicate_candidates, 10)
    add("essential", sorted([row for row in catalog if row["flags"]["essential"]], key=order), 5)
    add("respawning", sorted([row for row in catalog if row["flags"]["respawn"]], key=order), 5)
    add(
        "sparse-esm-identity",
        sorted([row for row in catalog if not row["faction_id"] and not row["script_id"]], key=order),
        5,
    )

    diversity_order = sorted(
        catalog,
        key=lambda row: hashlib.sha256(row["record_id"].casefold().encode("utf-8")).hexdigest(),
    )
    add("deterministic-diversity-fill", diversity_order, size - len(selected))
    if len(selected) != size:
        raise ValueError(f"Unable to construct {size} distinct preflight records")
    return [identity(npc, categories[key]) for key, npc in selected.items()]


def load_or_create_selection(
    builder: Any, run_dir: Path, data_dir: Path, size: int,
) -> tuple[list[dict[str, Any]], dict[str, dict[str, str]], dict[str, str], int]:
    catalog, actor_aliases, hashes = builder.extract_npcs(data_dir)
    selection_path = run_dir / "selection.json"
    if selection_path.is_file():
        document = json.loads(selection_path.read_text(encoding="utf-8"))
        if document.get("official_content_sha256") != hashes:
            raise ValueError("Existing preflight selection was created from different official ESM files")
        selected = document.get("selection")
        if not isinstance(selected, list) or len(selected) != size:
            raise ValueError("Existing preflight selection does not match the requested size")
        return selected, actor_aliases, hashes, len(catalog)
    selected = stratified_selection(catalog, size)
    atomic_json(
        selection_path,
        {
            "format": FORMAT_VERSION,
            "created_at_utc": utc_timestamp(),
            "catalog_count": len(catalog),
            "selected_count": len(selected),
            "official_content_sha256": hashes,
            "selection": selected,
        },
    )
    return selected, actor_aliases, hashes, len(catalog)


def record_key(builder: Any, record_id: str) -> str:
    return builder.safe_file_component(record_id).removesuffix(".json")


def child_command(
    args: argparse.Namespace, record_id: str, record_dir: Path, evidence_only: bool,
) -> list[str]:
    command = [
        sys.executable,
        str(BUILDER_PATH),
        "--data-dir", str(args.data_dir),
        "--record-id", record_id,
        "--limit", "1",
        "--cache-dir", str(args.cache_dir),
        "--timeout", str(args.request_timeout),
        "--delay", "0",
        "--force",
    ]
    if evidence_only:
        command.extend(["--dry-run", "--output", str(record_dir / "evidence.json")])
    else:
        command.extend(
            [
                "--model", args.model,
                "--api-key-env", args.api_key_env,
                "--output", str(record_dir / "review.json"),
                "--chim-output", str(record_dir / "chim.json"),
                "--relationships-output", str(record_dir / "relationships.json"),
                "--rejected-output", str(record_dir / "rejected.json"),
            ]
        )
    return command


def valid_evidence(path: Path, record_id: str) -> tuple[bool, str]:
    try:
        document = json.loads(path.read_text(encoding="utf-8"))
        results = document.get("results")
        if not isinstance(results, list) or len(results) != 1:
            return False, "evidence result count is not one"
        if str(results[0].get("identity", {}).get("record_id") or "").casefold() != record_id.casefold():
            return False, "evidence record ID does not match"
        return True, ""
    except (OSError, ValueError, TypeError, json.JSONDecodeError) as error:
        return False, str(error)


def valid_generation(
    builder: Any, record_dir: Path, record_id: str, actor_aliases: dict[str, dict[str, str]],
) -> tuple[bool, str]:
    try:
        review = json.loads((record_dir / "review.json").read_text(encoding="utf-8"))
        rows = json.loads((record_dir / "chim.json").read_text(encoding="utf-8"))
        relationships = json.loads((record_dir / "relationships.json").read_text(encoding="utf-8"))
        results = review.get("results")
        if not isinstance(results, list) or len(results) != 1 or len(rows) != 1 or len(relationships) != 1:
            return False, "generated result counts are not one"
        row = rows[0]
        if str(row.get("refid") or "").casefold() != record_id.casefold():
            return False, "generated record ID does not match"
        if list(row) != list(builder.CHIM_COLUMNS):
            return False, "CHIM column order does not match"
        profile = {field: str(row.get(field) or "") for field in builder.GENERATED_FIELDS}
        violations = builder.content_violations(profile, actor_aliases)
        if violations:
            return False, "; ".join(violations)
        expected_relationships = builder.relationship_import_rows(rows)
        if relationships != expected_relationships:
            return False, "relationship export does not match CHIM row"
        if results[0].get("template") != row:
            return False, "review template does not match CHIM row"
        return True, ""
    except (OSError, ValueError, TypeError, KeyError, json.JSONDecodeError) as error:
        return False, str(error)


def run_child(command: list[str], timeout: float) -> dict[str, Any]:
    started_at = utc_timestamp()
    started = time.perf_counter()
    try:
        completed = subprocess.run(command, capture_output=True, text=True, encoding="utf-8", timeout=timeout)
        return {
            "started_at_utc": started_at,
            "finished_at_utc": utc_timestamp(),
            "duration_seconds": round(time.perf_counter() - started, 3),
            "exit_code": completed.returncode,
            "stdout": completed.stdout,
            "stderr": completed.stderr,
        }
    except subprocess.TimeoutExpired as error:
        stdout = error.stdout.decode("utf-8", errors="replace") if isinstance(error.stdout, bytes) else error.stdout or ""
        stderr = error.stderr.decode("utf-8", errors="replace") if isinstance(error.stderr, bytes) else error.stderr or ""
        return {
            "started_at_utc": started_at,
            "finished_at_utc": utc_timestamp(),
            "duration_seconds": round(time.perf_counter() - started, 3),
            "exit_code": None,
            "stdout": stdout,
            "stderr": stderr + f"\nprocess timed out after {timeout} seconds",
        }


def generation_telemetry(record_dir: Path) -> dict[str, Any]:
    for filename in ("review.json", "rejected.json"):
        try:
            document = json.loads((record_dir / filename).read_text(encoding="utf-8"))
            generation = document["results"][0]["generation"] if filename == "review.json" else document["generation"]
            telemetry = generation.get("telemetry", {})
            if isinstance(telemetry, dict):
                return telemetry
        except (OSError, ValueError, TypeError, KeyError, IndexError, json.JSONDecodeError):
            continue
    return {}


def load_attempts(record_dir: Path) -> list[dict[str, Any]]:
    try:
        document = json.loads((record_dir / "attempts.json").read_text(encoding="utf-8"))
        attempts = document.get("attempts")
        return attempts if isinstance(attempts, list) else []
    except (OSError, ValueError, TypeError, json.JSONDecodeError):
        return []


def append_attempt(record_dir: Path, result: dict[str, Any]) -> None:
    attempts = load_attempts(record_dir)
    attempts.append(result)
    atomic_json(
        record_dir / "attempts.json",
        {"format": FORMAT_VERSION, "updated_at_utc": utc_timestamp(), "attempts": attempts},
    )


def build_manifest(
    builder: Any,
    run_dir: Path,
    selected: list[dict[str, Any]],
    hashes: dict[str, str],
    catalog_count: int,
    model: str,
    actor_aliases: dict[str, dict[str, str]],
    max_cost: float | None = None,
    budget_reserve: float = 0.0,
) -> dict[str, Any]:
    items: list[dict[str, Any]] = []
    for npc in selected:
        key = record_key(builder, npc["record_id"])
        record_dir = run_dir / "records" / key
        evidence_ok, evidence_error = valid_evidence(record_dir / "evidence.json", npc["record_id"])
        generation_ok, generation_error = valid_generation(builder, record_dir, npc["record_id"], actor_aliases)
        run_path = record_dir / "run.json"
        run = json.loads(run_path.read_text(encoding="utf-8")) if run_path.is_file() else {}
        attempts = load_attempts(record_dir)
        attempt_telemetry = [row.get("telemetry", {}) for row in attempts if isinstance(row, dict)]
        cumulative_telemetry: dict[str, float] = {}
        for telemetry in attempt_telemetry or [generation_telemetry(record_dir)]:
            if not isinstance(telemetry, dict):
                continue
            for field in (
                "logical_calls", "http_attempts", "prompt_tokens", "completion_tokens", "total_tokens",
                "cost", "request_seconds",
            ):
                value = telemetry.get(field)
                if isinstance(value, (int, float)):
                    cumulative_telemetry[field] = cumulative_telemetry.get(field, 0.0) + value
        generation_status = "complete" if generation_ok else ("quarantined" if run else "pending")
        items.append(
            {
                **npc,
                "key": key,
                "evidence_status": "complete" if evidence_ok else "pending",
                "evidence_error": None if evidence_ok else evidence_error or None,
                "generation_status": generation_status,
                "generation_error": None if generation_ok else run.get("error") or generation_error,
                "telemetry": generation_telemetry(record_dir),
                "attempt_count": len(attempts),
                "cumulative_telemetry": cumulative_telemetry,
            }
        )
    complete = [item for item in items if item["generation_status"] == "complete"]
    measured = [item for item in items if item["cumulative_telemetry"]]
    durations = [float(item["cumulative_telemetry"].get("request_seconds") or 0.0) for item in measured]
    usage: dict[str, float] = {}
    for item in measured:
        for field in ("logical_calls", "http_attempts", "prompt_tokens", "completion_tokens", "total_tokens", "cost"):
            value = item["cumulative_telemetry"].get(field)
            if isinstance(value, (int, float)):
                usage[field] = usage.get(field, 0.0) + value
    spent = float(usage.get("cost") or 0.0)
    budget = None
    if max_cost is not None:
        budget = {
            "limit": max_cost,
            "reserve": budget_reserve,
            "spent": spent,
            "remaining": max(0.0, max_cost - spent),
            "next_call_allowed": spent < max_cost - budget_reserve,
        }
    return {
        "format": FORMAT_VERSION,
        "updated_at_utc": utc_timestamp(),
        "catalog_count": catalog_count,
        "selected_count": len(selected),
        "completed_count": len(complete),
        "evidence_completed_count": sum(item["evidence_status"] == "complete" for item in items),
        "evidence_failed_count": sum(
            item["evidence_status"] != "complete"
            and (run_dir / "records" / item["key"] / "evidence-run.json").is_file()
            for item in items
        ),
        "failed_count": sum(item["generation_status"] == "quarantined" for item in items),
        "official_content_sha256": hashes,
        "builder_sha256": hashlib.sha256(BUILDER_PATH.read_bytes()).hexdigest(),
        "model": model,
        "usage": usage,
        "budget": budget,
        "usage_history_complete_count": sum(item["attempt_count"] > 0 for item in items),
        "usage_history_is_complete": all(
            item["generation_status"] == "pending" or item["attempt_count"] > 0 for item in items
        ),
        "request_seconds_median": round(statistics.median(durations), 3) if durations else None,
        "items": items,
    }


def write_combined(
    builder: Any, run_dir: Path, selected: list[dict[str, Any]], manifest: dict[str, Any],
    actor_aliases: dict[str, dict[str, str]],
) -> None:
    results: list[dict[str, Any]] = []
    rows: list[dict[str, Any]] = []
    relationships: list[dict[str, Any]] = []
    for npc in selected:
        key = record_key(builder, npc["record_id"])
        record_dir = run_dir / "records" / key
        valid, _ = valid_generation(builder, record_dir, npc["record_id"], actor_aliases)
        if not valid:
            continue
        review = json.loads((record_dir / "review.json").read_text(encoding="utf-8"))
        results.extend(review["results"])
        rows.extend(json.loads((record_dir / "chim.json").read_text(encoding="utf-8")))
        relationships.extend(json.loads((record_dir / "relationships.json").read_text(encoding="utf-8")))
    combined_dir = run_dir / "combined"
    atomic_json(
        combined_dir / "preflight-review.json",
        {
            "format": FORMAT_VERSION,
            "generated_at_utc": utc_timestamp(),
            "selected_count": len(selected),
            "completed_count": len(results),
            "results": results,
        },
    )
    atomic_json(combined_dir / "preflight-chim.json", rows)
    atomic_json(combined_dir / "preflight-relationships.json", relationships)
    atomic_json(run_dir / "manifest.json", manifest)


def parse_args(builder: Any) -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Run a durable stratified Morrowind biography preflight.")
    parser.add_argument("--run-dir", type=Path, required=True)
    parser.add_argument("--data-dir", type=Path, default=builder.DEFAULT_DATA_DIR)
    parser.add_argument("--cache-dir", type=Path, default=builder.default_cache_dir())
    parser.add_argument("--size", type=int, default=50)
    parser.add_argument("--model", default=builder.DEFAULT_MODEL)
    parser.add_argument("--api-key-env", default="OPENROUTER_API_KEY")
    parser.add_argument("--request-timeout", type=float, default=90.0)
    parser.add_argument("--process-timeout", type=float, default=900.0)
    parser.add_argument("--delay", type=float, default=0.5)
    parser.add_argument("--max-failures", type=int, default=10)
    parser.add_argument("--max-cost", type=float, help="Stop generation before recorded provider spend reaches this limit.")
    parser.add_argument(
        "--budget-reserve", type=float, default=0.10,
        help="Amount held below --max-cost so one bounded child cannot overshoot the budget. Defaults to 0.10.",
    )
    parser.add_argument("--evidence-only", action="store_true")
    parser.add_argument("--resume", action="store_true")
    args = parser.parse_args()
    if args.size < 5:
        parser.error("--size must be at least 5")
    if args.max_failures < 1:
        parser.error("--max-failures must be at least 1")
    if args.max_cost is not None and args.max_cost <= 0:
        parser.error("--max-cost must be greater than zero")
    if args.budget_reserve < 0:
        parser.error("--budget-reserve cannot be negative")
    if args.max_cost is not None and args.budget_reserve >= args.max_cost:
        parser.error("--budget-reserve must be less than --max-cost")
    return args


def run_preflight(builder: Any, args: argparse.Namespace) -> int:
    if not args.evidence_only and not os.getenv(args.api_key_env, "").strip():
        raise RuntimeError(f"Generation requires the {args.api_key_env} environment variable")
    selected, actor_aliases, hashes, catalog_count = load_or_create_selection(
        builder, args.run_dir, args.data_dir, args.size,
    )
    failures = 0
    budget_exhausted = False
    for index, npc in enumerate(selected, start=1):
        key = record_key(builder, npc["record_id"])
        record_dir = args.run_dir / "records" / key
        record_dir.mkdir(parents=True, exist_ok=True)
        if args.evidence_only:
            valid, _ = valid_evidence(record_dir / "evidence.json", npc["record_id"])
        else:
            valid, _ = valid_generation(builder, record_dir, npc["record_id"], actor_aliases)
        if args.resume and valid:
            print(f"[skip] {index}/{len(selected)} {npc['display_name']} ({npc['record_id']}): complete", flush=True)
            continue
        if not args.evidence_only and args.max_cost is not None:
            current_manifest = build_manifest(
                builder, args.run_dir, selected, hashes, catalog_count, args.model, actor_aliases,
                args.max_cost, args.budget_reserve,
            )
            if not current_manifest["budget"]["next_call_allowed"]:
                budget_exhausted = True
                print(
                    f"[stop] provider budget reached: spent={current_manifest['budget']['spent']:.6f} "
                    f"limit={args.max_cost:.2f} reserve={args.budget_reserve:.2f}",
                    flush=True,
                )
                break
        command = child_command(args, npc["record_id"], record_dir, args.evidence_only)
        print(
            f"[run] {index}/{len(selected)} {npc['display_name']} ({npc['record_id']}): "
            f"{'evidence' if args.evidence_only else 'generation'}",
            flush=True,
        )
        result = run_child(command, args.process_timeout)
        if args.evidence_only:
            valid, error = valid_evidence(record_dir / "evidence.json", npc["record_id"])
        else:
            valid, error = valid_generation(builder, record_dir, npc["record_id"], actor_aliases)
        result.update(
            {
                "format": FORMAT_VERSION,
                "record_id": npc["record_id"],
                "status": "complete" if valid else "quarantined",
                "error": (
                    result["stderr"].strip().removeprefix("error: ")
                    if not valid and result["stderr"].strip()
                    else error or None
                ),
            }
        )
        if not args.evidence_only:
            result["telemetry"] = generation_telemetry(record_dir)
            append_attempt(record_dir, result)
        atomic_json(record_dir / ("evidence-run.json" if args.evidence_only else "run.json"), result)
        if not valid:
            failures += 1
            print(f"[quarantine] {npc['record_id']}: {error}", flush=True)
            if failures >= args.max_failures:
                print(f"[stop] reached --max-failures={args.max_failures}", flush=True)
                break
        elif not args.evidence_only:
            telemetry = generation_telemetry(record_dir)
            print(
                f"[complete] {npc['record_id']}: calls={telemetry.get('logical_calls', '?')} "
                f"tokens={telemetry.get('total_tokens', '?')} cost={telemetry.get('cost', '?')}",
                flush=True,
            )
        if args.delay > 0:
            time.sleep(args.delay)
        manifest = build_manifest(
            builder, args.run_dir, selected, hashes, catalog_count, args.model, actor_aliases,
            args.max_cost, args.budget_reserve,
        )
        write_combined(builder, args.run_dir, selected, manifest, actor_aliases)
    manifest = build_manifest(
        builder, args.run_dir, selected, hashes, catalog_count, args.model, actor_aliases,
        args.max_cost, args.budget_reserve,
    )
    write_combined(builder, args.run_dir, selected, manifest, actor_aliases)
    print(
        f"[summary] selected={manifest['selected_count']} "
        f"evidence={manifest['evidence_completed_count']} evidence_failed={manifest['evidence_failed_count']} "
        f"complete={manifest['completed_count']} failed={manifest['failed_count']} "
        f"cost={float(manifest['usage'].get('cost') or 0.0):.6f} run_dir={args.run_dir}",
        flush=True,
    )
    if budget_exhausted and manifest["completed_count"] < manifest["selected_count"]:
        return 2
    return 1 if failures else 0


def main() -> int:
    builder = load_builder()
    args = parse_args(builder)
    lock_handle = acquire_run_lock(args.run_dir)
    try:
        return run_preflight(builder, args)
    finally:
        release_run_lock(lock_handle)


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except (FileNotFoundError, RuntimeError, ValueError) as error:
        print(f"error: {error}", file=sys.stderr)
        raise SystemExit(1)
