#!/usr/bin/env python3
"""Produce deterministic review artifacts for a built Morrowind Oghma catalog."""

from __future__ import annotations

import argparse
from collections import Counter
from difflib import SequenceMatcher
import hashlib
import html
import importlib.util
import json
from pathlib import Path
import re
from typing import Any


ROOT = Path(__file__).resolve().parents[1]
DEFAULT_BASE = ROOT / "resources" / "oghma" / "morrowind-official"
QUEST_LIKE = re.compile(
    r"\b(?:asked to|asks? [^.!?]{0,50} to|commissioned to|current assignment|has tasked|"
    r"must (?:find|retrieve|deliver|steal|kill|report)|seeks? to (?:acquire|recover|steal)|"
    r"was sent to|were sent to|your task|the adventurer|the player)\b",
    re.IGNORECASE,
)
NEAR_DUPLICATE_RESOLUTIONS = {
    frozenset(("ashlanders", "ashlands")): "Distinct culture and geographic region; similar titles are intentional.",
    frozenset(("aldmeri", "aldmeris")): "Distinct people and mythical ancestral homeland; similar titles are intentional.",
}


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
    path.write_text(json.dumps(value, indent=2, ensure_ascii=False) + "\n", encoding="utf-8", newline="\n")


def normalized(value: str) -> str:
    return re.sub(r"[^a-z0-9]+", " ", value.casefold()).strip()


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--catalog", type=Path, required=True)
    parser.add_argument("--seeds", type=Path, required=True)
    parser.add_argument("--ontology", type=Path, required=True)
    parser.add_argument("--editorial-decisions", type=Path, default=DEFAULT_BASE / "editorial-decisions.json")
    parser.add_argument("--reviewed", type=Path, action="append", default=[])
    parser.add_argument("--output-dir", type=Path, required=True)
    return parser.parse_args()


def main() -> int:
    args = parse_args()
    generator = load_generator()
    articles_path = args.catalog / "articles.json"
    manifest_path = args.catalog / "manifest.json"
    articles = read_json(articles_path)
    manifest = read_json(manifest_path)
    ontology = read_json(args.ontology)
    decisions = read_json(args.editorial_decisions)
    if decisions.get("format") != "almsivi.morrowind-oghma-editorial-decisions.v1":
        raise ValueError("Unsupported Oghma editorial decision format")
    exclusions = {str(row.get("topic", "")) for row in decisions.get("exclusions", [])}
    records, _ = generator.extract_records(generator.DEFAULT_DATA_DIR)
    seeds = [row for row in generator.validate_seed_document(read_json(args.seeds), ontology, records) if row["topic"] not in exclusions]
    by_topic = {row["topic"]: row for row in seeds}
    errors: list[str] = []
    aliases: dict[str, str] = {}
    record_linked = 0
    for article in articles:
        topic = str(article.get("topic", ""))
        seed = by_topic.get(topic)
        if seed is None:
            errors.append(f"unknown topic: {topic}")
            continue
        errors.extend(f"{topic}: {error}" for error in generator.validate_article(article, seed, ontology))
        record_linked += bool(article.get("record_links"))
        for value in [topic, *article.get("aliases", [])]:
            key = re.sub(r"[^a-z0-9]+", "", str(value).casefold())
            owner = aliases.get(key)
            if owner is not None and owner != topic:
                errors.append(f"alias collision: {value!r} belongs to {owner} and {topic}")
            aliases[key] = topic
    if len(articles) != len(seeds):
        errors.append(f"coverage mismatch: articles={len(articles)} seeds={len(seeds)}")
    if hashlib.sha256(articles_path.read_bytes()).hexdigest() != manifest.get("articles_sha256"):
        errors.append("articles checksum mismatch")
    if hashlib.sha256(args.editorial_decisions.read_bytes()).hexdigest() != manifest.get("editorial_decisions_sha256"):
        errors.append("editorial decisions checksum mismatch")
    near_duplicates: list[dict[str, Any]] = []
    resolved_near_duplicates: list[dict[str, Any]] = []
    for index, left in enumerate(articles):
        left_title = normalized(str(left.get("title", "")))
        for right in articles[index + 1:]:
            right_title = normalized(str(right.get("title", "")))
            score = SequenceMatcher(None, left_title, right_title).ratio()
            if score >= 0.92:
                row = {"score": round(score, 3), "left": left["topic"], "right": right["topic"], "titles": [left["title"], right["title"]]}
                resolution = NEAR_DUPLICATE_RESOLUTIONS.get(frozenset((left["topic"], right["topic"])))
                if resolution is None:
                    near_duplicates.append(row)
                else:
                    resolved_near_duplicates.append(row | {"resolution": resolution})
    quest_flags = []
    for article in articles:
        fields = [field for field in ("topic_desc", "topic_desc_basic") if QUEST_LIKE.search(str(article.get(field, "")))]
        if fields:
            quest_flags.append({"topic": article["topic"], "fields": fields, "topic_desc": article["topic_desc"], "topic_desc_basic": article["topic_desc_basic"]})
    evidence = Counter()
    evidence_by_topic: dict[str, dict[str, Any]] = {}
    generation_cost = 0.0
    for directory in args.reviewed:
        generation_cost += float((read_json(directory / "manifest.json").get("usage") or {}).get("cost") or 0.0)
        for path in (directory / "records").glob("*/result.json"):
            result = read_json(path)
            source = result.get("source") or {}
            topic = str(result.get("topic", ""))
            if topic:
                evidence_by_topic[topic] = source
            evidence["result_count"] += 1
            evidence["official_dialogue"] += bool(source.get("official_dialogue"))
            evidence["uesp_found"] += (source.get("uesp") or {}).get("status") == "found"
    catalog_topics = {str(row.get("topic", "")) for row in articles}
    accepted_generated = set(evidence_by_topic) & catalog_topics
    evidence["accepted_result_count"] = len(accepted_generated)
    evidence["accepted_official_dialogue"] = sum(bool(evidence_by_topic[topic].get("official_dialogue")) for topic in accepted_generated)
    evidence["accepted_uesp_found"] = sum((evidence_by_topic[topic].get("uesp") or {}).get("status") == "found" for topic in accepted_generated)
    evidence["excluded_result_count"] = len(set(evidence_by_topic) - catalog_topics)
    if evidence["accepted_result_count"] != evidence["accepted_official_dialogue"]:
        errors.append("one or more accepted generated articles lack official dialogue evidence")
    report = {
        "format": "almsivi.morrowind-oghma-catalog-review.v2",
        "catalog_version": manifest.get("catalog_version"),
        "row_count": len(articles),
        "articles_sha256": manifest.get("articles_sha256"),
        "errors": sorted(set(errors)),
        "category_counts": dict(sorted(Counter(row["category"] for row in articles).items())),
        "knowledge_class_counts": dict(sorted(Counter(value for row in articles for value in row["knowledge_class"]).items())),
        "alias_key_count": len(aliases),
        "dropped_alias_collisions": manifest.get("dropped_alias_collisions", []),
        "record_linked_count": record_linked,
        "near_duplicate_flags": near_duplicates,
        "resolved_near_duplicates": resolved_near_duplicates,
        "quest_like_flags": quest_flags,
        "source_evidence": dict(evidence),
        "generation_cost": generation_cost,
        "reviewed_row_count": manifest.get("reviewed_row_count"),
        "editorial_exclusions": manifest.get("editorial_exclusions", []),
        "editorial_overrides": manifest.get("editorial_overrides", []),
    }
    args.output_dir.mkdir(parents=True, exist_ok=True)
    write_json(args.output_dir / "review.json", report)
    lines = [
        "# Morrowind Oghma catalog review", "",
        f"- Catalog: `{report['catalog_version']}`", f"- Rows: **{report['row_count']}**",
        f"- Articles SHA-256: `{report['articles_sha256']}`", f"- Validation errors: **{len(report['errors'])}**",
        f"- Unique topic/alias keys: **{report['alias_key_count']}**",
        f"- Exact official record-linked articles: **{report['record_linked_count']}**",
        f"- Generated source results: **{evidence['result_count']}**; accepted: **{evidence['accepted_result_count']}**; excluded: **{evidence['excluded_result_count']}**",
        f"- Accepted additions with official dialogue: **{evidence['accepted_official_dialogue']}**; supplemental UESP match: **{evidence['accepted_uesp_found']}**",
        f"- Generation cost: **${generation_cost:.6f}**", "", "## Category counts", "",
    ]
    lines.extend(f"- `{category}`: {count}" for category, count in report["category_counts"].items())
    lines.extend(["", "## Validation errors", ""])
    lines.extend(["- None."] if not errors else [f"- {error}" for error in sorted(set(errors))])
    lines.extend(["", "## Near-duplicate title flags", ""])
    lines.extend(["- None."] if not near_duplicates else [f"- {row['score']}: `{row['left']}` / `{row['right']}` ({' / '.join(row['titles'])})" for row in near_duplicates])
    lines.extend(["", "## Resolved near-duplicate titles", ""])
    lines.extend(["- None."] if not resolved_near_duplicates else [f"- {row['score']}: `{row['left']}` / `{row['right']}` — {row['resolution']}" for row in resolved_near_duplicates])
    lines.extend(["", "## Quest-like prose flags", ""])
    lines.extend(["- None."] if not quest_flags else [f"- `{row['topic']}`: {', '.join(row['fields'])}" for row in quest_flags])
    lines.extend(["", "## Dropped alias collisions", ""])
    lines.extend(["- None."] if not report["dropped_alias_collisions"] else [f"- `{row['topic']}` dropped `{row['alias']}` (owned by `{row['owner']}`)" for row in report["dropped_alias_collisions"]])
    lines.extend(["", "## Editorial exclusions", ""])
    lines.extend(["- None."] if not report["editorial_exclusions"] else [f"- `{row['topic']}` ({row['reason']}): {row['note']}" for row in report["editorial_exclusions"]])
    lines.extend(["", "## Editorial overrides", ""])
    lines.extend(["- None."] if not report["editorial_overrides"] else [f"- `{row['topic']}` ({row['reason']}): {row['note']}" for row in report["editorial_overrides"]])
    markdown = "\n".join(lines) + "\n"
    (args.output_dir / "review.md").write_text(markdown, encoding="utf-8", newline="\n")
    (args.output_dir / "review.html").write_text(
        "<!doctype html><meta charset='utf-8'><title>Morrowind Oghma review</title><style>body{font:15px sans-serif;max-width:1100px;margin:30px auto;background:#151515;color:#eee;line-height:1.5}code{color:#f27c11}pre{white-space:pre-wrap}</style><pre>" + html.escape(markdown) + "</pre>",
        encoding="utf-8", newline="\n",
    )
    print(json.dumps({key: report[key] for key in ["catalog_version", "row_count", "errors", "alias_key_count", "record_linked_count", "source_evidence", "generation_cost"]}, indent=2))
    return 0 if not errors else 2


if __name__ == "__main__":
    raise SystemExit(main())
