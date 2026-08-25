#!/usr/bin/env python3
"""Append a reviewed Tamriel Rebuilt run to an existing Oghma factory catalog."""

from __future__ import annotations

import argparse
from collections import Counter
import hashlib
import json
from pathlib import Path
import re
from typing import Any


ROOT = Path(__file__).resolve().parents[1]
DEFAULT_BASE = ROOT / "resources" / "oghma" / "morrowind-official" / "catalogs" / "morrowind-official-3e427-v5.14"


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

    if base_manifest.get("row_count") != len(base_articles):
        raise ValueError("Base catalog row count does not match its articles")
    if reviewed_manifest.get("completed_count") != len(additions) or reviewed_manifest.get("failed_count") != 0:
        raise ValueError("Reviewed Tamriel Rebuilt run is incomplete")
    if not isinstance(seeds.get("topics"), list) or len(seeds["topics"]) != len(additions):
        raise ValueError("Tamriel Rebuilt seed inventory does not match the reviewed run")
    if any(row.get("mod_source") != "TR_Mainland.esm" for row in additions):
        raise ValueError("Every Tamriel Rebuilt article must be scoped to TR_Mainland.esm")

    topics = {str(row.get("topic", "")) for row in base_articles}
    duplicate_topics = sorted(str(row.get("topic", "")) for row in additions if str(row.get("topic", "")) in topics)
    if duplicate_topics:
        raise ValueError(f"Tamriel Rebuilt topics collide with the base catalog: {duplicate_topics}")

    alias_owners: dict[str, str] = {}
    for row in [*base_articles, *additions]:
        topic = str(row["topic"])
        canonical = alias_key(topic)
        owner = alias_owners.get(canonical)
        if owner is not None and owner != topic:
            raise ValueError(f"Canonical topic collision between {owner} and {topic}")
        alias_owners[canonical] = topic

    dropped_aliases: list[dict[str, str]] = []
    combined = []
    for source in [*base_articles, *additions]:
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
    seeds_path = args.output / "tamriel-rebuilt-topic-seeds.json"
    write_json(seeds_path, seeds)
    (args.output / "catalog-version.txt").write_text(args.catalog_version + "\n", encoding="utf-8", newline="\n")

    manifest = dict(base_manifest)
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
            "cost": float((reviewed_manifest.get("usage") or {}).get("cost") or 0.0),
            "mod_source": "TR_Mainland.esm",
        }],
        "reviewed_row_count": len(combined),
        "generation_cost": float(base_manifest.get("generation_cost") or 0.0)
        + float((reviewed_manifest.get("usage") or {}).get("cost") or 0.0),
        "tamriel_rebuilt_topic_seeds_sha256": sha256(seeds_path),
        "tamriel_rebuilt_review_manifest_sha256": sha256(reviewed_manifest_path),
        "tamriel_rebuilt_assembler_sha256": sha256(Path(__file__)),
        "tamriel_rebuilt_row_count": len(additions),
        "tamriel_rebuilt_coverage": "curated dialogue subjects and reviewed pilot additions; not an exhaustive CELL, BOOK, or record inventory",
    })
    write_json(args.output / "manifest.json", manifest)
    print(json.dumps({
        "catalog_version": args.catalog_version,
        "base_rows": len(base_articles),
        "tamriel_rebuilt_rows": len(additions),
        "rows": len(combined),
        "dropped_aliases": len(dropped_aliases),
        "articles_sha256": manifest["articles_sha256"],
    }, indent=2))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
