#!/usr/bin/env python3
"""Merge a reviewed Tamriel Rebuilt run into an existing Oghma factory catalog."""

from __future__ import annotations

import argparse
from collections import Counter
import hashlib
import json
from pathlib import Path
import re
from typing import Any


ROOT = Path(__file__).resolve().parents[1]
DEFAULT_BASE = ROOT / "resources" / "oghma" / "morrowind-official" / "catalogs" / "morrowind-official-3e427-v5.15"


def read_json(path: Path) -> Any:
    return json.loads(path.read_text(encoding="utf-8-sig"))


def write_json(path: Path, value: Any) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(value, ensure_ascii=False, indent=2) + "\n", encoding="utf-8", newline="\n")


def sha256(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def alias_key(value: str) -> str:
    return re.sub(r"[^a-z0-9]+", "", value.casefold())


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--base", type=Path, default=DEFAULT_BASE)
    parser.add_argument("--reviewed", type=Path, required=True)
    parser.add_argument("--seeds", type=Path, required=True)
    parser.add_argument("--coverage", type=Path, required=True)
    parser.add_argument("--curation", type=Path, required=True)
    parser.add_argument("--output", type=Path, required=True)
    parser.add_argument("--catalog-version", required=True)
    return parser.parse_args()


def main() -> int:
    args = parse_args()
    base_articles_path = args.base / "articles.json"
    base_manifest_path = args.base / "manifest.json"
    reviewed_articles_path = args.reviewed / "combined" / "articles.json"
    reviewed_manifest_path = args.reviewed / "manifest.json"
    base_articles = read_json(base_articles_path)
    base_manifest = read_json(base_manifest_path)
    additions = read_json(reviewed_articles_path)
    reviewed_manifest = read_json(reviewed_manifest_path)
    seeds = read_json(args.seeds)
    coverage = read_json(args.coverage)
    curation = read_json(args.curation)

    if base_manifest.get("row_count") != len(base_articles):
        raise ValueError("Base catalog row count does not match its articles")
    if reviewed_manifest.get("completed_count") != len(additions) or reviewed_manifest.get("failed_count") != 0:
        raise ValueError("Reviewed Tamriel Rebuilt run is incomplete")
    if not isinstance(seeds.get("topics"), list) or len(seeds["topics"]) != len(additions):
        raise ValueError("Tamriel Rebuilt seed inventory does not match the reviewed run")
    if any(row.get("mod_source") != "TR_Mainland.esm" for row in additions):
        raise ValueError("Every Tamriel Rebuilt article must be scoped to TR_Mainland.esm")
    coverage_summary = coverage.get("summary") or {}
    if coverage_summary.get("accepted_count") != len(additions):
        raise ValueError("Tamriel Rebuilt coverage count does not match the reviewed run")
    if coverage_summary.get("base_catalog_sha256") != sha256(base_articles_path):
        raise ValueError("Tamriel Rebuilt coverage was audited against a different base catalog")
    if curation.get("format") != "lorkhan.tamriel-rebuilt-location-curation.v1":
        raise ValueError("Unsupported Tamriel Rebuilt location curation format")

    base_by_topic = {str(row.get("topic", "")): row for row in base_articles}
    addition_by_topic = {str(row.get("topic", "")): row for row in additions}
    if len(base_by_topic) != len(base_articles) or len(addition_by_topic) != len(additions):
        raise ValueError("Base or reviewed catalog contains duplicate topics")
    existing_coverage = curation.get("existing_catalog_coverage") or []
    if not isinstance(existing_coverage, list):
        raise ValueError("Existing catalog coverage must be a list")
    covered_topics: set[str] = set()
    for mapping in existing_coverage:
        if not isinstance(mapping, dict):
            raise ValueError("Existing catalog coverage contains an invalid mapping")
        topic = str(mapping.get("topic", ""))
        existing_topic = str(mapping.get("existing_topic", ""))
        if topic in covered_topics or topic not in addition_by_topic or existing_topic not in base_by_topic:
            raise ValueError(f"Invalid existing catalog coverage mapping: {mapping}")
        reviewed_keys = {alias_key(addition_by_topic[topic].get("title", "")), alias_key(topic)}
        base_row = base_by_topic[existing_topic]
        base_keys = {
            alias_key(existing_topic), alias_key(base_row.get("title", "")),
            *(alias_key(value) for value in base_row.get("aliases", [])),
        }
        if reviewed_keys.isdisjoint(base_keys):
            raise ValueError(f"Existing catalog coverage subjects differ: {topic} / {existing_topic}")
        covered_topics.add(topic)
    emitted_additions = [row for row in additions if str(row["topic"]) not in covered_topics]
    emitted_by_topic = {str(row["topic"]): row for row in emitted_additions}
    replacement_topics = sorted(set(base_by_topic) & set(emitted_by_topic))
    unsafe_replacements = [
        topic for topic in replacement_topics
        if base_by_topic[topic].get("mod_source") != "TR_Mainland.esm"
    ]
    if unsafe_replacements:
        raise ValueError(f"Tamriel Rebuilt topics collide with non-TR base rows: {unsafe_replacements}")
    merged_sources = [emitted_by_topic.get(str(row["topic"]), row) for row in base_articles]
    merged_sources.extend(row for row in emitted_additions if str(row["topic"]) not in base_by_topic)

    alias_owners: dict[str, str] = {}
    for row in merged_sources:
        topic = str(row["topic"])
        canonical = alias_key(topic)
        owner = alias_owners.get(canonical)
        if owner is not None and owner != topic:
            raise ValueError(f"Canonical topic collision between {owner} and {topic}")
        alias_owners[canonical] = topic

    dropped_aliases: list[dict[str, str]] = []
    combined = []
    for source in merged_sources:
        row = dict(source)
        kept = []
        for alias in row.get("aliases", []):
            key = alias_key(str(alias))
            owner = alias_owners.get(key)
            if owner is not None and owner != row["topic"]:
                dropped_aliases.append({"topic": row["topic"], "alias": str(alias), "owner": owner})
                continue
            alias_owners[key] = row["topic"]
            kept.append(alias)
        row["aliases"] = kept
        combined.append(row)

    args.output.mkdir(parents=True, exist_ok=True)
    articles_path = args.output / "articles.json"
    write_json(articles_path, combined)
    base_seeds_path = args.base / "tamriel-rebuilt-topic-seeds.json"
    base_seeds = read_json(base_seeds_path) if base_seeds_path.is_file() else {"topics": []}
    merged_seeds_by_topic = {
        str(topic["topic"]): topic for topic in base_seeds.get("topics", [])
    }
    for topic in seeds["topics"]:
        if str(topic["topic"]) in covered_topics:
            continue
        merged_seeds_by_topic[str(topic["topic"])] = topic
    seeds_path = args.output / "tamriel-rebuilt-topic-seeds.json"
    write_json(seeds_path, {
        "format": "lorkhan.morrowind-oghma-topic-seeds.v2",
        "topics": list(merged_seeds_by_topic.values()),
    })
    (args.output / "catalog-version.txt").write_text(args.catalog_version + "\n", encoding="utf-8", newline="\n")

    manifest = dict(base_manifest)
    manifest.pop("csv_sha256", None)
    recorded_cost = float((reviewed_manifest.get("usage") or {}).get("recorded_cost")
                          or (reviewed_manifest.get("usage") or {}).get("cost") or 0.0)
    accounting_complete = bool((reviewed_manifest.get("usage") or {}).get("accounting_complete", True))
    untracked_responses = int((reviewed_manifest.get("usage") or {}).get("untracked_provider_response_count") or 0)
    manifest.update({
        "catalog_version": args.catalog_version,
        "row_count": len(combined),
        "articles_sha256": sha256(articles_path),
        "official_content_sha256": reviewed_manifest["official_content_sha256"],
        "category_counts": dict(sorted(Counter(str(row["category"]) for row in combined).items())),
        "alias_key_count": len(alias_owners),
        "dropped_alias_collisions": [*base_manifest.get("dropped_alias_collisions", []), *dropped_aliases],
        "source_runs": [*base_manifest.get("source_runs", []), {
            "articles_sha256": sha256(reviewed_articles_path),
            "manifest_sha256": sha256(reviewed_manifest_path),
            "row_count": len(additions),
            "model": reviewed_manifest.get("model"),
            "recorded_cost": recorded_cost,
            "cost_accounting_complete": accounting_complete,
            "untracked_provider_response_count": untracked_responses,
            "mod_source": "TR_Mainland.esm",
            "added_count": len(emitted_additions) - len(replacement_topics),
            "replaced_count": len(replacement_topics),
            "covered_by_existing_count": len(covered_topics),
        }],
        "reviewed_row_count": len(combined),
        "generation_cost": float(base_manifest.get("generation_cost") or 0.0)
        + recorded_cost,
        "generation_cost_accounting_complete": bool(base_manifest.get("generation_cost_accounting_complete", True))
        and accounting_complete,
        "untracked_provider_response_count": int(base_manifest.get("untracked_provider_response_count") or 0)
        + untracked_responses,
        "tamriel_rebuilt_topic_seeds_sha256": sha256(seeds_path),
        "tamriel_rebuilt_review_manifest_sha256": sha256(reviewed_manifest_path),
        "tamriel_rebuilt_assembler_sha256": sha256(Path(__file__)),
        "tamriel_rebuilt_inventory_builder_sha256": sha256(ROOT / "scripts" / "build-tamriel-rebuilt-location-seeds.py"),
        "tamriel_rebuilt_location_coverage_sha256": sha256(args.coverage),
        "tamriel_rebuilt_location_curation_sha256": sha256(args.curation),
        "tamriel_rebuilt_row_count": sum(1 for row in combined if row.get("mod_source") == "TR_Mainland.esm"),
        "tamriel_rebuilt_location_source_subject_count": len(additions),
        "tamriel_rebuilt_location_row_count": len(emitted_additions),
        "tamriel_rebuilt_location_added_count": len(emitted_additions) - len(replacement_topics),
        "tamriel_rebuilt_location_replaced_count": len(replacement_topics),
        "tamriel_rebuilt_location_existing_catalog_coverage": existing_coverage,
        "tamriel_rebuilt_location_common_basic_count": coverage_summary.get("common_basic_count"),
        "tamriel_rebuilt_location_unknown_basic_count": coverage_summary.get("unknown_basic_count"),
        "tamriel_rebuilt_location_missing_public_page_count": coverage_summary.get("missing_public_page_count"),
        "tamriel_rebuilt_coverage": "all released unique UESP place subjects backed by TR_Mainland.esm or a reviewed public place index; redirects, indexes, deprecated regions, testing pages, and unreleased missing pages are explicitly accounted for",
    })
    manifest["editorial_overrides"] = [
        *base_manifest.get("editorial_overrides", []),
        *curation.get("manual_prose_overrides", []),
    ]
    manifest["advanced_class_counts"] = dict(sorted(Counter(
        value for row in combined for value in row.get("knowledge_class", [])
    ).items()))
    manifest["basic_class_counts"] = dict(sorted(Counter(
        value for row in combined for value in row.get("knowledge_class_basic", [])
    ).items()))
    write_json(args.output / "manifest.json", manifest)
    print(json.dumps({
        "catalog_version": args.catalog_version,
        "base_rows": len(base_articles),
        "tamriel_rebuilt_source_subjects": len(additions),
        "tamriel_rebuilt_rows": len(emitted_additions),
        "tamriel_rebuilt_added": len(emitted_additions) - len(replacement_topics),
        "tamriel_rebuilt_replaced": len(replacement_topics),
        "tamriel_rebuilt_covered_by_existing": len(covered_topics),
        "rows": len(combined),
        "dropped_aliases": len(dropped_aliases),
        "articles_sha256": manifest["articles_sha256"],
    }, indent=2))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
