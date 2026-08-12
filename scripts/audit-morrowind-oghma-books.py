#!/usr/bin/env python3
"""Audit official Morrowind books for uncovered static Oghma lore sources."""

from __future__ import annotations

import argparse
import csv
import hashlib
import importlib.util
import json
from pathlib import Path
import re
from typing import Any


ROOT = Path(__file__).resolve().parents[1]
DEFAULT_CATALOG = ROOT / "resources" / "oghma" / "morrowind-official" / "catalogs" / "morrowind-official-3e427-v3"
DEFAULT_OUTPUT = ROOT / "build" / "oghma-v4-book-audit"
DOCUMENT_TITLE = re.compile(
    r"\b(?:contract|deed|directions?|journal|letter|list|log|note|notice|orders?|pass|report|writ)\b",
    re.IGNORECASE,
)
LORE_TITLE = re.compile(
    r"\b(?:aedra|akavir|ancestor|anuad|arcturian|barenziah|daedra|dragon|dwemer|empire|faith|gods?|"
    r"history|lore|morrowind|nerevar|oblivion|origin|prayer|religion|saint|sermon|sithis|skaal|"
    r"tamriel|temple|tribunal|vivec|vvardenfell|wulfharth)\b",
    re.IGNORECASE,
)


def load_generator() -> Any:
    path = ROOT / "scripts" / "run-morrowind-oghma-preflight.py"
    spec = importlib.util.spec_from_file_location("almsivi_oghma_generator", path)
    if spec is None or spec.loader is None:
        raise RuntimeError("Could not load the Oghma generator")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def read_json(path: Path) -> Any:
    return json.loads(path.read_text(encoding="utf-8-sig"))


def write_json(path: Path, payload: Any) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(payload, ensure_ascii=False, indent=2) + "\n", encoding="utf-8", newline="\n")


def key(value: str) -> str:
    return re.sub(r"[^a-z0-9]+", "", value.casefold())


def plain_book_text(value: str) -> str:
    value = re.sub(r"<[^>]+>", " ", value)
    return re.sub(r"\s+", " ", value).strip()


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--catalog", type=Path, default=DEFAULT_CATALOG)
    parser.add_argument("--data-dir", type=Path)
    parser.add_argument("--output-dir", type=Path, default=DEFAULT_OUTPUT)
    return parser.parse_args()


def main() -> int:
    args = parse_args()
    generator = load_generator()
    records, hashes = generator.extract_records(args.data_dir or generator.DEFAULT_DATA_DIR)
    articles = read_json(args.catalog / "articles.json")
    coverage: dict[str, str] = {}
    for article in articles:
        for value in [article.get("topic", ""), article.get("title", ""), *article.get("aliases", [])]:
            normalized = key(str(value))
            if normalized:
                coverage.setdefault(normalized, str(article["topic"]))

    books: list[dict[str, Any]] = []
    seen_text: dict[str, str] = {}
    for record in records.values():
        if record["record_type"] != "BOOK":
            continue
        source_text = plain_book_text(str(record.get("source_text", "")))
        words = len(re.findall(r"[\w'-]+", source_text, flags=re.UNICODE))
        text_sha = hashlib.sha256(source_text.encode("utf-8")).hexdigest() if source_text else ""
        title = str(record["display_name"])
        title_key = key(title)
        duplicate_of = seen_text.get(text_sha, "") if text_sha else ""
        if text_sha and not duplicate_of:
            seen_text[text_sha] = str(record["record_id"])
        substantial = words >= 80 and "scroll" not in str(record["record_id"]).casefold()
        score = (3 if substantial else 0) + (3 if LORE_TITLE.search(title) else 0)
        if DOCUMENT_TITLE.search(title):
            score -= 4
        if duplicate_of:
            score -= 2
        books.append({
            "content_file": record["content_file"],
            "record_id": record["record_id"],
            "title": title,
            "word_count": words,
            "text_sha256": text_sha,
            "duplicate_text_of": duplicate_of or None,
            "covered_topic": coverage.get(title_key),
            "substantial": substantial,
            "review_score": score,
        })
    books.sort(key=lambda row: (-row["review_score"], str(row["title"]).casefold(), str(row["record_id"]).casefold()))
    candidates = [row for row in books if row["review_score"] >= 3 and not row["covered_topic"]]

    args.output_dir.mkdir(parents=True, exist_ok=True)
    payload = {
        "format": "almsivi.morrowind-oghma-book-audit.v1",
        "baseline_catalog": args.catalog.name,
        "baseline_rows": len(articles),
        "official_content_sha256": hashes,
        "winning_book_records": len(books),
        "substantial_book_records": sum(bool(row["substantial"]) for row in books),
        "exact_title_covered": sum(bool(row["covered_topic"]) for row in books),
        "review_candidates": len(candidates),
        "books": books,
    }
    write_json(args.output_dir / "audit.json", payload)
    columns = ["content_file", "record_id", "title", "word_count", "text_sha256", "duplicate_text_of",
               "covered_topic", "substantial", "review_score"]
    with (args.output_dir / "audit.csv").open("w", encoding="utf-8-sig", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=columns, lineterminator="\n")
        writer.writeheader()
        writer.writerows(books)
    lines = [
        "# Morrowind Oghma official-book source audit", "",
        f"- Baseline catalog: `{args.catalog.name}` ({len(articles)} articles)",
        f"- Winning official BOOK records: **{len(books)}**",
        f"- Substantial BOOK records: **{sum(bool(row['substantial']) for row in books)}**",
        f"- Exact title matches already covered: **{sum(bool(row['covered_topic']) for row in books)}**",
        f"- Heuristic review candidates: **{len(candidates)}**", "",
        "The audit stores record metadata and hashes only. Official book text remains in the local ESM files and is not copied into repository artifacts.", "",
        "## Highest-priority uncovered sources", "",
        "| Source | Record ID | Title | Words | Score |", "|---|---|---|---:|---:|",
    ]
    for row in candidates[:150]:
        lines.append(f"| {row['content_file']} | `{row['record_id']}` | {row['title']} | {row['word_count']} | {row['review_score']} |")
    (args.output_dir / "audit.md").write_text("\n".join(lines) + "\n", encoding="utf-8", newline="\n")
    print(json.dumps({key: payload[key] for key in ("baseline_rows", "winning_book_records", "substantial_book_records", "exact_title_covered", "review_candidates")}, indent=2))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
