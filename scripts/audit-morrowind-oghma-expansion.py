#!/usr/bin/env python3
"""Audit official TES3 dialogue topics for a larger Morrowind Oghma catalog."""

from __future__ import annotations

import argparse
import csv
from collections import Counter
import json
from pathlib import Path
import re
import struct
from typing import Any, Iterable


DEFAULT_DATA_DIR = Path(r"C:\Program Files (x86)\Steam\steamapps\common\Morrowind\Data Files")
SCRIPT_DIR = Path(__file__).resolve().parent
RESOURCE_DIR = SCRIPT_DIR.parent / "resources" / "oghma" / "morrowind-official"
DEFAULT_SEEDS = RESOURCE_DIR / "topic-seeds.json"
DEFAULT_CATALOG = RESOURCE_DIR / "catalogs" / "morrowind-official-3e427-v2" / "articles.json"
CONTENT_FILES = ("Morrowind.esm", "Tribunal.esm", "Bloodmoon.esm")
INDEXED_RECORD_TYPES = {
    b"NPC_": "actor",
    b"CREA": "actor",
    b"WEAP": "item",
    b"ARMO": "item",
    b"CLOT": "item",
    b"MISC": "item",
    b"BOOK": "item",
    b"ALCH": "item",
    b"SPEL": "spell",
    b"FACT": "faction",
    b"CELL": "place",
    b"REGN": "place",
}
GENERIC_DIALOGUE = {
    "advancement", "assignment", "background", "business", "duties", "favor", "goodbye",
    "latest rumors", "little advice", "little secret", "my trade", "orders", "report",
    "services", "someone in particular", "specific place", "talk", "travel together", "work",
}
CONVERSATION_PATTERN = re.compile(
    r"\b(?:about my|accompany you|aid me|bring me|do you|find me|get me|give me|help me|hire|"
    r"join(?:ing)?|meet me|my friend|my family|need (?:a|some)|share a|talk to|tell me|want to|"
    r"what do you|who are you|you must|your help)\b",
    re.IGNORECASE,
)
TASK_PATTERN = re.compile(
    r"\b(?:assignment|chores?|debts?|documents?|donate|dues|errand|escort|evidence|"
    r"investigate|payment|pledge|promotion|report|shipment|smuggl|stolen|taxes|training)\b",
    re.IGNORECASE,
)
MECHANIC_PATTERN = re.compile(
    r"\b(?:acrobatics|agility|armorer|athletics|attribute|block|blunt weapon|"
    r"character class|destruction skill|enchant skill|endurance|hand-to-hand|heavy armor|"
    r"intelligence|light armor|long blade|luck|marksman|medium armor|mercantile|"
    r"minor skill|mysticism skill|personality|security skill|short blade|skill|sneak skill|"
    r"spear skill|speed|strength|unarmored|willpower)\b",
    re.IGNORECASE,
)


def read_json(path: Path) -> Any:
    raw = path.read_bytes()
    if raw.startswith((b"\xff\xfe", b"\xfe\xff", b"\xff\xfe\x00\x00", b"\x00\x00\xfe\xff")):
        raise ValueError(f"File is not UTF-8: {path}")
    return json.loads(raw.decode("utf-8-sig"))


def write_json(path: Path, payload: Any) -> None:
    path.write_text(json.dumps(payload, indent=2, ensure_ascii=False) + "\n", encoding="utf-8", newline="\n")


def decode_text(raw: bytes) -> str:
    return raw.rstrip(b"\0").decode("cp1252", errors="replace").strip()


def normalized(value: str) -> str:
    return re.sub(r"[^a-z0-9]+", "", value.casefold())


def slug(value: str) -> str:
    return re.sub(r"[^a-z0-9]+", "_", value.casefold()).strip("_")


def iter_subrecords(body: bytes) -> Iterable[tuple[bytes, bytes]]:
    position = 0
    while position + 8 <= len(body):
        kind = body[position:position + 4]
        size = struct.unpack_from("<I", body, position + 4)[0]
        start = position + 8
        end = start + size
        if end > len(body):
            raise ValueError(f"TES3 subrecord {kind!r} extends past its record")
        yield kind, body[start:end]
        position = end
    if position != len(body):
        raise ValueError("TES3 record ended with an incomplete subrecord header")


def iter_records(path: Path) -> Iterable[tuple[bytes, bytes]]:
    raw = path.read_bytes()
    position = 0
    while position + 16 <= len(raw):
        kind = raw[position:position + 4]
        size = struct.unpack_from("<I", raw, position + 4)[0]
        start = position + 16
        end = start + size
        if end > len(raw):
            raise ValueError(f"TES3 record in {path.name} extends past the file")
        yield kind, raw[start:end]
        position = end
    if position != len(raw):
        raise ValueError(f"TES3 file ended with an incomplete record header: {path}")


def record_fields(body: bytes) -> dict[bytes, bytes]:
    fields: dict[bytes, bytes] = {}
    for kind, value in iter_subrecords(body):
        fields.setdefault(kind, value)
    return fields


def existing_lookup(seeds: list[dict[str, Any]]) -> dict[str, str]:
    result: dict[str, str] = {}
    for seed in seeds:
        topic = str(seed["topic"])
        for value in [topic, str(seed["title"]), *[str(alias) for alias in seed.get("aliases", [])]]:
            key = normalized(value)
            if key:
                result.setdefault(key, topic)
    return result


def collect_official_inventory(data_dir: Path) -> tuple[dict[str, dict[str, Any]], dict[str, list[dict[str, str]]]]:
    topics: dict[str, dict[str, Any]] = {}
    winning_records: dict[str, dict[str, str]] = {}
    for content_file in CONTENT_FILES:
        path = data_dir / content_file
        if not path.is_file():
            raise FileNotFoundError(f"Required official content file is missing: {path}")
        active_topic: str | None = None
        for record_type, body in iter_records(path):
            if record_type == b"DIAL":
                fields = record_fields(body)
                title = decode_text(fields.get(b"NAME", b""))
                dialogue_type = fields.get(b"DATA", b"\xff")[:1]
                active_topic = title.casefold() if title and dialogue_type == b"\x00" else None
                if active_topic is not None:
                    row = topics.setdefault(active_topic, {"title": title, "response_count": 0, "sources": set()})
                    row["sources"].add(content_file)
                continue
            if record_type == b"INFO" and active_topic is not None:
                topics[active_topic]["response_count"] += 1
                continue
            record_kind = INDEXED_RECORD_TYPES.get(record_type)
            if record_kind is None:
                continue
            fields = record_fields(body)
            record_id = decode_text(fields.get(b"NAME", b""))
            if not record_id:
                continue
            record_key = f"{record_type.decode('ascii')}|{record_id}".casefold()
            if b"DELE" in fields:
                winning_records.pop(record_key, None)
                continue
            display_name = decode_text(fields.get(b"FNAM", b""))
            if record_type == b"CELL":
                display_name = record_id
            if display_name:
                winning_records[record_key] = {
                    "content_file": content_file,
                    "record_type": record_type.decode("ascii"),
                    "record_id": record_id,
                    "display_name": display_name,
                    "record_kind": record_kind,
                }
    named_records: dict[str, list[dict[str, str]]] = {}
    for record in winning_records.values():
        named_records.setdefault(normalized(record["display_name"]), []).append(record)
    for records in named_records.values():
        records.sort(key=lambda row: (CONTENT_FILES.index(row["content_file"]), row["record_type"], row["record_id"].casefold()))
    return topics, named_records


def candidate_band(title: str, record_kinds: set[str]) -> tuple[str, str]:
    lowered = title.casefold().strip()
    if "actor" in record_kinds:
        return "named_actor_review", "Matches an official actor or creature name; only major figures belong in Oghma."
    if "item" in record_kinds:
        return "item_review", "Matches an official item or book name; only culturally important objects belong in Oghma."
    if "spell" in record_kinds:
        return "spell_review", "Matches an official spell name; prefer magic traditions over routine spells."
    if "faction" in record_kinds:
        return "faction_review", "Matches an official faction name."
    if "place" in record_kinds:
        return "place_review", "Matches an official cell or region name; prefer notable public locations."
    if lowered in GENERIC_DIALOGUE or CONVERSATION_PATTERN.search(title) or TASK_PATTERN.search(title):
        return "conversation_excluded", "Looks like conversation, service, or quest-task phrasing rather than stable knowledge."
    if MECHANIC_PATTERN.search(title):
        return "mechanic_excluded", "Looks like a game-mechanic or skill topic rather than in-world knowledge."
    if len(title) > 80 or len(title.split()) > 10:
        return "conversation_excluded", "Topic title is shaped like a dialogue sentence rather than an encyclopedia subject."
    return "lore_review", "Unmatched official dialogue topic requiring lore/category review."


def candidate_score(response_count: int, sources: list[str], band: str) -> int:
    score = min(response_count, 100)
    if len(sources) > 1:
        score += 20 * (len(sources) - 1)
    score += {"faction_review": 25, "place_review": 20, "lore_review": 15}.get(band, 0)
    return score


def markdown_report(summary: dict[str, Any], rows: list[dict[str, Any]]) -> str:
    lines = [
        "# Morrowind Oghma expansion audit",
        "",
        "This report inventories official regular dialogue topics without generating prose or changing the active catalog.",
        "",
        "## Summary",
        "",
        f"- Official regular dialogue topics: **{summary['official_dialogue_topics']}**",
        f"- Existing curated seed topics: **{summary['existing_seed_topics']}**",
        f"- Existing generated catalog rows: **{summary['existing_catalog_rows']}**",
        f"- Dialogue topics already covered by a topic/title/alias: **{summary['covered_dialogue_topics']}**",
        f"- Uncovered dialogue topics requiring review: **{summary['uncovered_dialogue_topics']}**",
        "",
        "## Review bands",
        "",
        "| Band | Count | Meaning |",
        "|---|---:|---|",
    ]
    descriptions = {
        "faction_review": "Official faction-name matches.",
        "place_review": "Official cell or region-name matches.",
        "lore_review": "Other dialogue subjects that may represent stable lore.",
        "named_actor_review": "Official actors/creatures; retain major figures only.",
        "item_review": "Official items/books; retain significant artifacts or texts only.",
        "spell_review": "Official spells; retain traditions or notable magic only.",
        "conversation_excluded": "Likely conversational, service, or quest-task noise.",
        "mechanic_excluded": "Likely skill/mechanics terminology.",
    }
    for band, count in summary["band_counts"].items():
        lines.append(f"| `{band}` | {count} | {descriptions.get(band, '')} |")
    lines.extend(["", "## Highest-signal uncovered candidates", "", "| Score | Responses | Band | Topic | Sources |", "|---:|---:|---|---|---|"])
    reviewable = [row for row in rows if row["band"] not in {"conversation_excluded", "mechanic_excluded"}]
    for row in reviewable[:150]:
        title = str(row["title"]).replace("|", "\\|")
        lines.append(f"| {row['score']} | {row['response_count']} | `{row['band']}` | {title} | {', '.join(row['sources'])} |")
    lines.extend([
        "",
        "## Next gate",
        "",
        "Review and classify the candidate pools into an expanded seed inventory before running GLM generation. "
        "Do not generate every raw dialogue topic: NPC biographies, Description Manager, quests, and ordinary dialogue already own those domains.",
        "",
    ])
    return "\n".join(lines)


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--data-dir", type=Path, default=DEFAULT_DATA_DIR)
    parser.add_argument("--seeds", type=Path, default=DEFAULT_SEEDS)
    parser.add_argument("--catalog", type=Path, default=DEFAULT_CATALOG)
    parser.add_argument("--output-dir", type=Path, required=True)
    return parser.parse_args()


def main() -> int:
    args = parse_args()
    seed_document = read_json(args.seeds)
    seeds = seed_document.get("topics")
    if not isinstance(seeds, list):
        raise ValueError("Seed document does not contain a topics array")
    catalog = read_json(args.catalog)
    if not isinstance(catalog, list):
        raise ValueError("Catalog is not an article array")
    lookup = existing_lookup(seeds)
    dialogue_topics, named_records = collect_official_inventory(args.data_dir)
    rows: list[dict[str, Any]] = []
    covered = 0
    for topic in dialogue_topics.values():
        title = str(topic["title"])
        existing_topic = lookup.get(normalized(title))
        if existing_topic is not None:
            covered += 1
            continue
        sources = sorted(topic["sources"], key=CONTENT_FILES.index)
        record_matches = named_records.get(normalized(title), [])
        band, reason = candidate_band(title, {record["record_kind"] for record in record_matches})
        rows.append({
            "topic": slug(title),
            "title": title,
            "response_count": int(topic["response_count"]),
            "sources": sources,
            "band": band,
            "score": candidate_score(int(topic["response_count"]), sources, band),
            "reason": reason,
            "record_matches": record_matches,
        })
    rows.sort(key=lambda row: (-int(row["score"]), -int(row["response_count"]), str(row["title"]).casefold()))
    band_counts = dict(sorted(Counter(str(row["band"]) for row in rows).items()))
    summary = {
        "format": "almsivi.morrowind-oghma-expansion-audit.v1",
        "official_content_files": list(CONTENT_FILES),
        "official_dialogue_topics": len(dialogue_topics),
        "existing_seed_topics": len(seeds),
        "existing_catalog_rows": len(catalog),
        "covered_dialogue_topics": covered,
        "uncovered_dialogue_topics": len(rows),
        "band_counts": band_counts,
    }
    args.output_dir.mkdir(parents=True, exist_ok=True)
    write_json(args.output_dir / "summary.json", summary)
    write_json(args.output_dir / "candidates.json", {"summary": summary, "candidates": rows})
    with (args.output_dir / "candidates.csv").open("w", encoding="utf-8-sig", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=["score", "response_count", "band", "topic", "title", "sources", "record_matches", "reason"])
        writer.writeheader()
        for row in rows:
            writer.writerow({**row, "sources": ",".join(row["sources"]), "record_matches": json.dumps(row["record_matches"], ensure_ascii=False)})
    (args.output_dir / "review.md").write_text(markdown_report(summary, rows), encoding="utf-8", newline="\n")
    print(json.dumps(summary, indent=2))
    print(f"Review: {args.output_dir / 'review.md'}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
