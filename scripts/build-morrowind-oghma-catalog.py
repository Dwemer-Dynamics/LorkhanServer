#!/usr/bin/env python3
"""Merge reviewed Oghma runs into one deterministic, validated factory catalog."""

from __future__ import annotations

import argparse
import csv
import hashlib
import importlib.util
import json
import re
from collections import Counter
from pathlib import Path
from typing import Any

ROOT = Path(__file__).resolve().parents[1]
DEFAULT_BASE = ROOT / "resources" / "oghma" / "morrowind-official"


def load_generator() -> Any:
    path = ROOT / "scripts" / "run-morrowind-oghma-preflight.py"
    spec = importlib.util.spec_from_file_location("almsivi_oghma_generator", path)
    if spec is None or spec.loader is None:
        raise RuntimeError("Could not load the Oghma generator")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def read_json(path: Path) -> Any:
    raw = path.read_bytes()
    if raw.startswith((b"\xff\xfe", b"\xfe\xff", b"\xff\xfe\x00\x00", b"\x00\x00\xfe\xff")):
        raise ValueError(f"File is not UTF-8: {path}")
    return json.loads(raw.decode("utf-8-sig"))


def write_json(path: Path, value: Any) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(value, ensure_ascii=False, indent=2) + "\n", encoding="utf-8", newline="\n")


def sha256(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def key(value: str) -> str:
    return re.sub(r"[^a-z0-9]+", "", value.casefold())


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--reviewed", type=Path, action="append", required=True,
                        help="Directory containing combined/articles.json and manifest.json; repeat for each reviewed run")
    parser.add_argument("--output", type=Path, default=DEFAULT_BASE / "catalog")
    parser.add_argument("--catalog-version", required=True)
    parser.add_argument("--data-dir", type=Path)
    parser.add_argument("--content-file", action="append", default=[],
                        help="Content filename in OpenMW load order; repeatable. Defaults to the three official masters.")
    parser.add_argument("--seeds", type=Path, default=DEFAULT_BASE / "topic-seeds.json")
    parser.add_argument("--ontology", type=Path, default=DEFAULT_BASE / "ontology.json")
    parser.add_argument("--editorial-decisions", type=Path, default=DEFAULT_BASE / "editorial-decisions.json")
    return parser.parse_args()


def main() -> int:
    args = parse_args()
    generator = load_generator()
    ontology_path = args.ontology
    seeds_path = args.seeds
    decisions_path = args.editorial_decisions
    ontology = read_json(ontology_path)
    decisions = read_json(decisions_path)
    if decisions.get("format") != "almsivi.morrowind-oghma-editorial-decisions.v1":
        raise ValueError("Unsupported Oghma editorial decision format")
    if decisions.get("catalog_version") != args.catalog_version:
        raise ValueError("Editorial decisions target a different catalog version")
    content_files = tuple(args.content_file or generator.CONTENT_FILES)
    if len({value.casefold() for value in content_files}) != len(content_files):
        raise ValueError("--content-file values must be unique")
    records, official_hashes = generator.extract_records(args.data_dir or generator.DEFAULT_DATA_DIR, content_files)
    topics = generator.validate_seed_document(read_json(seeds_path), ontology, records)
    by_topic = {row["topic"]: row for row in topics}
    exclusions = {str(row.get("topic", "")): row for row in decisions.get("exclusions", [])}
    overrides = {str(row.get("topic", "")): row for row in decisions.get("overrides", [])}
    if "" in exclusions or "" in overrides or len(exclusions) != len(decisions.get("exclusions", [])) or len(overrides) != len(decisions.get("overrides", [])):
        raise ValueError("Editorial decisions contain an invalid or duplicate topic")
    unknown_decisions = (set(exclusions) | set(overrides)) - set(by_topic)
    conflicting_decisions = set(exclusions) & set(overrides)
    if unknown_decisions or conflicting_decisions:
        raise ValueError(f"Editorial decisions are inconsistent: {sorted(unknown_decisions or conflicting_decisions)}")
    included_topics = [row for row in topics if row["topic"] not in exclusions]

    articles: dict[str, dict[str, Any]] = {}
    seen_reviewed: set[str] = set()
    source_runs: list[dict[str, Any]] = []
    total_cost = 0.0
    for directory in args.reviewed:
        article_path = directory / "combined" / "articles.json"
        if not article_path.is_file():
            article_path = directory / "articles.json"
        manifest_path = directory / "manifest.json"
        if not article_path.is_file() or not manifest_path.is_file():
            raise FileNotFoundError(f"Reviewed run is incomplete: {directory}")
        manifest = read_json(manifest_path)
        rows = read_json(article_path)
        completed_count = manifest.get("completed_count", manifest.get("row_count"))
        failed_count = manifest.get("failed_count", 0)
        if not isinstance(rows, list) or completed_count != len(rows) or failed_count != 0:
            raise ValueError(f"Reviewed run is not complete: {directory}")
        for article in rows:
            topic = str(article.get("topic", ""))
            if topic not in by_topic or topic in seen_reviewed:
                raise ValueError(f"Unknown or duplicate reviewed topic: {topic}")
            seen_reviewed.add(topic)
            if topic in exclusions:
                continue
            article = dict(article)
            if topic in overrides:
                replacement = overrides[topic].get("article")
                if not isinstance(replacement, dict) or not replacement:
                    raise ValueError(f"Editorial override for {topic} has no article fields")
                unsupported = set(replacement) - {"aliases", "topic_desc", "knowledge_class", "topic_desc_basic", "knowledge_class_basic", "tags", "category"}
                if unsupported:
                    raise ValueError(f"Editorial override for {topic} has unsupported fields: {sorted(unsupported)}")
                article.update(replacement)
            errors = generator.validate_article(article, by_topic[topic], ontology)
            if errors:
                raise ValueError(f"Reviewed topic {topic} failed current validation: {'; '.join(errors)}")
            articles[topic] = article
        cost = float((manifest.get("usage") or {}).get("cost") or 0.0)
        total_cost += cost
        source_runs.append({
            "articles_sha256": sha256(article_path), "manifest_sha256": sha256(manifest_path),
            "row_count": len(rows), "model": manifest.get("model"), "cost": cost,
        })

    expected_topics = {row["topic"] for row in included_topics}
    missing = expected_topics - set(articles)
    extra = set(articles) - expected_topics
    if missing or extra or len(articles) != len(included_topics):
        raise ValueError(f"Catalog coverage mismatch; missing={sorted(missing)}, extra={sorted(extra)}")

    ordered = [articles[row["topic"]] for row in included_topics]
    aliases: dict[str, str] = {key(row["topic"]): row["topic"] for row in included_topics}
    dropped_aliases: list[dict[str, str]] = []
    for article in ordered:
        topic = article["topic"]
        kept: list[str] = []
        for value in article["aliases"]:
            normalized = key(value)
            owner = aliases.get(normalized)
            if owner is not None and owner != topic:
                dropped_aliases.append({"topic": topic, "alias": value, "owner": owner})
                continue
            aliases[normalized] = topic
            kept.append(value)
        article["aliases"] = kept
    args.output.mkdir(parents=True, exist_ok=True)
    articles_path = args.output / "articles.json"
    write_json(articles_path, ordered)
    csv_path = args.output / "oghma.csv"
    columns = ["topic", "aliases", "topic_desc", "knowledge_class", "topic_desc_basic",
               "knowledge_class_basic", "tags", "category", "mod_source"]
    with csv_path.open("w", encoding="utf-8-sig", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=columns, lineterminator="\n")
        writer.writeheader()
        for row in ordered:
            writer.writerow({column: ", ".join(row.get(column, [])) if isinstance(row.get(column), list) else row.get(column, "")
                             for column in columns})
    manifest = {
        "format": "almsivi.morrowind-oghma-catalog.v1",
        "catalog_version": args.catalog_version,
        "row_count": len(ordered),
        "articles_sha256": sha256(articles_path),
        "csv_sha256": sha256(csv_path),
        "ontology_sha256": sha256(ontology_path),
        "topic_seeds_sha256": sha256(seeds_path),
        "editorial_decisions_sha256": sha256(decisions_path),
        "generator_sha256": sha256(ROOT / "scripts" / "run-morrowind-oghma-preflight.py"),
        "builder_sha256": sha256(Path(__file__)),
        "official_content_sha256": official_hashes,
        "category_counts": dict(sorted(Counter(row["category"] for row in ordered).items())),
        "alias_key_count": len(aliases),
        "dropped_alias_collisions": dropped_aliases,
        "source_runs": source_runs,
        "reviewed_row_count": len(seen_reviewed),
        "editorial_exclusions": decisions["exclusions"],
        "editorial_overrides": [{"topic": row["topic"], "reason": row["reason"], "note": row["note"]} for row in decisions["overrides"]],
        "generation_cost": total_cost,
        "temporal_anchor": "3E 427",
        "excluded": ["ordinary_npcs", "generic_equipment", "consumables", "routine_spells",
                     "minor_quest_objects", "walkthroughs", "player_outcomes", "fourth_era", "dynamic_oghma"],
    }
    write_json(args.output / "manifest.json", manifest)
    (args.output / "catalog-version.txt").write_text(args.catalog_version + "\n", encoding="utf-8", newline="\n")
    print(json.dumps({"catalog_version": args.catalog_version, "rows": len(ordered),
                      "aliases": len(aliases), "cost": total_cost,
                      "articles_sha256": manifest["articles_sha256"]}, indent=2))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
