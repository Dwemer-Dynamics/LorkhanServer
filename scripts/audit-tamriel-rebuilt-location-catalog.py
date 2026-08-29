#!/usr/bin/env python3
"""Audit a Tamriel Rebuilt location run and its merged Oghma catalog."""

from __future__ import annotations

import argparse
from collections import Counter
import hashlib
import json
from pathlib import Path
import re
import runpy
from typing import Any


ROOT = Path(__file__).resolve().parents[1]


def read_json(path: Path) -> Any:
    return json.loads(path.read_text(encoding="utf-8-sig"))


def sha256(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def alias_key(value: str) -> str:
    return re.sub(r"[^a-z0-9]+", "", value.casefold())


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--base", type=Path, required=True)
    parser.add_argument("--catalog", type=Path, required=True)
    parser.add_argument("--reviewed", type=Path, required=True)
    parser.add_argument("--seeds", type=Path, required=True)
    parser.add_argument("--coverage", type=Path, required=True)
    parser.add_argument("--curation", type=Path, required=True)
    parser.add_argument("--ontology", type=Path, required=True)
    parser.add_argument("--output-dir", type=Path, required=True)
    return parser.parse_args()


def main() -> int:
    args = parse_args()
    generator = runpy.run_path(str(ROOT / "scripts" / "run-morrowind-oghma-preflight.py"))
    ontology = read_json(args.ontology)
    base_articles = read_json(args.base / "articles.json")
    catalog_articles_path = args.catalog / "articles.json"
    catalog_articles = read_json(catalog_articles_path)
    manifest = read_json(args.catalog / "manifest.json")
    reviewed_manifest = read_json(args.reviewed / "manifest.json")
    seeds = read_json(args.seeds).get("topics", [])
    coverage = read_json(args.coverage).get("summary", {})
    curation = read_json(args.curation)
    errors: list[str] = []

    base_by_topic = {str(row.get("topic", "")): row for row in base_articles}
    catalog_by_topic = {str(row.get("topic", "")): row for row in catalog_articles}
    seed_by_topic = {str(row.get("topic", "")): row for row in seeds}
    reviewed_by_topic: dict[str, dict[str, Any]] = {}
    evidence_revision_count = 0
    common_count = unknown_count = 0
    for topic, seed in seed_by_topic.items():
        result_path = args.reviewed / "records" / topic / "result.json"
        if not result_path.is_file():
            errors.append(f"missing reviewed result: {topic}")
            continue
        result = read_json(result_path)
        article = result.get("article") or {}
        source = result.get("source") or {}
        identity = source.get("identity") or seed
        if result.get("status") != "complete":
            errors.append(f"incomplete reviewed result: {topic}")
            continue
        reviewed_by_topic[topic] = article
        errors.extend(f"{topic}: {error}" for error in generator["validate_article"](article, identity, ontology))
        if article.get("aliases", []) != seed.get("aliases", []):
            errors.append(f"reviewed aliases differ from locked seed: {topic}")
        uesp = source.get("uesp") or {}
        pages = uesp.get("pages") or []
        if uesp.get("status") != "found" or not pages:
            errors.append(f"missing locked UESP evidence: {topic}")
        elif pages[0].get("revision_id") != seed.get("uesp_revision_id"):
            errors.append(f"UESP revision mismatch: {topic}")
        else:
            evidence_revision_count += 1
        if seed.get("basic_mode") == "unknown":
            unknown_count += 1
            expected = f"You do not know where {seed['title']} is."
            if article.get("topic_desc_basic") != expected or article.get("knowledge_class_basic") != ["common"]:
                errors.append(f"unknown basic mismatch: {topic}")
        elif seed.get("basic_mode") == "common":
            common_count += 1
            basic = str(article.get("topic_desc_basic") or "")
            if (not basic or basic.startswith("You do not know where ")
                    or article.get("knowledge_class_basic") != ["common"]):
                errors.append(f"common basic mismatch: {topic}")

    existing_coverage = curation.get("existing_catalog_coverage") or []
    covered_topics = {str(row.get("topic", "")) for row in existing_coverage}
    if len(covered_topics) != len(existing_coverage):
        errors.append("duplicate existing catalog coverage mappings")
    for mapping in existing_coverage:
        topic = str(mapping.get("topic", ""))
        existing_topic = str(mapping.get("existing_topic", ""))
        reviewed = reviewed_by_topic.get(topic)
        existing = base_by_topic.get(existing_topic)
        if reviewed is None or existing is None:
            errors.append(f"invalid existing catalog coverage mapping: {topic} / {existing_topic}")
            continue
        reviewed_keys = {alias_key(str(reviewed.get("title", ""))), alias_key(topic)}
        existing_keys = {
            alias_key(existing_topic), alias_key(str(existing.get("title", ""))),
            *(alias_key(str(value)) for value in existing.get("aliases", [])),
        }
        if reviewed_keys.isdisjoint(existing_keys):
            errors.append(f"existing catalog coverage subjects differ: {topic} / {existing_topic}")
    emitted_by_topic = {
        topic: row for topic, row in reviewed_by_topic.items() if topic not in covered_topics
    }
    overlap = sorted(set(base_by_topic) & set(emitted_by_topic))
    unsafe_overlap = [topic for topic in overlap if base_by_topic[topic].get("mod_source") != "TR_Mainland.esm"]
    if unsafe_overlap:
        errors.append("non-TR replacement topics: " + ", ".join(unsafe_overlap))
    expected_topics = set(base_by_topic) | set(emitted_by_topic)
    if set(catalog_by_topic) != expected_topics:
        errors.append("merged catalog topic coverage mismatch")
    dropped = {(row["topic"], row["alias"]) for row in manifest.get("dropped_alias_collisions", [])}
    for topic, source in {**base_by_topic, **emitted_by_topic}.items():
        final = catalog_by_topic.get(topic)
        if final is None:
            continue
        for field, value in source.items():
            if field == "aliases":
                expected_aliases = [alias for alias in value if (topic, alias) not in dropped]
                if final.get(field, []) != expected_aliases:
                    errors.append(f"unexpected alias change: {topic}")
            elif final.get(field) != value:
                errors.append(f"unexpected field change: {topic}.{field}")

    aliases: dict[str, str] = {}
    for row in catalog_articles:
        for value in [row["topic"], *row.get("aliases", [])]:
            key = alias_key(str(value))
            owner = aliases.get(key)
            if owner is not None and owner != row["topic"]:
                errors.append(f"alias collision: {owner} / {row['topic']} / {value}")
            aliases[key] = row["topic"]

    checks = {
        "catalog_articles_sha256": sha256(catalog_articles_path) == manifest.get("articles_sha256"),
        "base_coverage_sha256": sha256(args.base / "articles.json") == coverage.get("base_catalog_sha256"),
        "review_complete": reviewed_manifest.get("completed_count") == len(seeds) and reviewed_manifest.get("failed_count") == 0,
        "coverage_count": coverage.get("accepted_count") == len(seeds),
        "manifest_row_count": manifest.get("row_count") == len(catalog_articles),
        "manifest_location_count": manifest.get("tamriel_rebuilt_location_row_count") == len(emitted_by_topic),
        "manifest_source_subject_count": manifest.get("tamriel_rebuilt_location_source_subject_count") == len(seeds),
        "complete_subject_coverage": len(emitted_by_topic) + len(covered_topics) == len(seeds),
    }
    errors.extend(f"failed check: {name}" for name, passed in checks.items() if not passed)
    report = {
        "format": "lorkhan.tamriel-rebuilt-location-catalog-review.v1",
        "catalog_version": manifest.get("catalog_version"),
        "errors": sorted(set(errors)),
        "checks": checks,
        "base_rows": len(base_articles),
        "catalog_rows": len(catalog_articles),
        "location_source_subjects": len(seeds),
        "location_rows": len(emitted_by_topic),
        "location_covered_by_existing": len(covered_topics),
        "location_added": len(emitted_by_topic) - len(overlap),
        "location_replaced": len(overlap),
        "common_basic_count": common_count,
        "unknown_basic_count": unknown_count,
        "revision_locked_evidence_count": evidence_revision_count,
        "category_counts": dict(sorted(Counter(row["category"] for row in reviewed_by_topic.values()).items())),
        "alias_key_count": len(aliases),
        "recorded_generation_cost": (reviewed_manifest.get("usage") or {}).get("recorded_cost"),
        "generation_cost_accounting_complete": (reviewed_manifest.get("usage") or {}).get("accounting_complete"),
        "untracked_provider_response_count": (reviewed_manifest.get("usage") or {}).get("untracked_provider_response_count"),
    }
    args.output_dir.mkdir(parents=True, exist_ok=True)
    (args.output_dir / "location-review.json").write_text(
        json.dumps(report, ensure_ascii=False, indent=2) + "\n", encoding="utf-8", newline="\n"
    )
    lines = [
        "# Tamriel Rebuilt location catalog review", "",
        f"- Catalog: `{report['catalog_version']}`",
        f"- Final rows: **{report['catalog_rows']}**",
        f"- Source location subjects: **{report['location_source_subjects']}**",
        f"- Emitted location rows: **{report['location_rows']}** ({report['location_added']} added, {report['location_replaced']} replaced)",
        f"- Covered by existing canonical articles: **{report['location_covered_by_existing']}**",
        f"- Basics: **{common_count} common**, **{unknown_count} ignorance fallback**",
        f"- Revision-locked UESP evidence: **{evidence_revision_count}/{len(seeds)}**",
        f"- Validation errors: **{len(report['errors'])}**", "",
        "## Validation errors", "",
        *(["- None."] if not report["errors"] else [f"- {error}" for error in report["errors"]]),
    ]
    (args.output_dir / "location-review.md").write_text("\n".join(lines) + "\n", encoding="utf-8", newline="\n")
    print(json.dumps(report, ensure_ascii=False, indent=2))
    return 0 if not errors else 2


if __name__ == "__main__":
    raise SystemExit(main())
