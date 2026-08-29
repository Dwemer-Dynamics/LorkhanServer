#!/usr/bin/env python3
"""Generate a durable, review-only Morrowind item-description preflight."""

from __future__ import annotations

import argparse
import concurrent.futures
import csv
import hashlib
import html
import json
import os
from pathlib import Path
import re
import struct
import sys
import time
from typing import Any, Iterable
from urllib.parse import quote

import requests


FORMAT_VERSION = "lorkhan.morrowind-item-description-preflight.v1"
DEFAULT_DATA_DIR = Path(r"C:\Program Files (x86)\Steam\steamapps\common\Morrowind\Data Files")
DEFAULT_MODEL = "z-ai/glm-5.1"
OPENROUTER_URL = "https://openrouter.ai/api/v1/chat/completions"
UESP_API_URL = "https://en.uesp.net/w/api.php"
CONTENT_FILES = ("Morrowind.esm", "Tribunal.esm", "Bloodmoon.esm")
ITEM_TYPES = (b"ALCH", b"APPA", b"ARMO", b"BOOK", b"CLOT", b"INGR", b"LIGH", b"LOCK", b"MISC", b"PROB", b"REPA", b"WEAP")
TYPE_NAMES = {
    b"ALCH": "potion", b"APPA": "alchemy apparatus", b"ARMO": "armor", b"BOOK": "book or scroll",
    b"CLOT": "clothing", b"INGR": "ingredient", b"LIGH": "light source", b"LOCK": "lockpick",
    b"MISC": "miscellaneous object", b"PROB": "probe", b"REPA": "repair tool", b"WEAP": "weapon",
}
WEAPON_TYPES = {
    0: "one-handed short blade", 1: "one-handed long blade", 2: "two-handed long blade",
    3: "one-handed blunt weapon", 4: "two-handed blunt weapon", 5: "two-handed staff",
    6: "two-handed spear", 7: "one-handed axe", 8: "two-handed axe", 9: "bow",
    10: "crossbow", 11: "thrown weapon", 12: "arrow", 13: "bolt",
}
ARMOR_TYPES = {
    0: "helmet", 1: "cuirass", 2: "left pauldron", 3: "right pauldron", 4: "greaves",
    5: "boots", 6: "left gauntlet", 7: "right gauntlet", 8: "shield", 9: "left bracer", 10: "right bracer",
}
CLOTHING_TYPES = {
    0: "pants", 1: "shoes", 2: "shirt", 3: "belt", 4: "robe", 5: "right glove",
    6: "left glove", 7: "skirt", 8: "ring", 9: "amulet",
}
APPARATUS_TYPES = {0: "mortar and pestle", 1: "alembic", 2: "calcinator", 3: "retort"}

SYSTEM_PROMPT = """You write compact physical item descriptions for Morrowind in the established CHIM catalog style.

Return only the required JSON object. Write exactly one sentence containing 13 to 22 words. Describe the
item's visible material, shape, construction, condition, color, or ornamentation. Use plain, concrete prose
that helps a character recognize the object. Do not write literary narration. Do not begin with "This item".

Use the authoritative display name and item category as evidence. Model and icon stems are weak visual hints:
interpret them conservatively and never repeat paths, filenames, codes, abbreviations, or record identifiers.
UESP text is optional supporting evidence and may contain markup or mechanics; extract only directly supported
physical details. Never mention games, players, inventories, records, files, quests, locations, ownership,
rarity, monetary value, weight, damage, armor rating, durability, quality, charges, uses, enchantment mechanics,
attribute changes, skill changes, effect magnitudes, probabilities, or other statistics. Do not claim a magical
effect unless the evidence explicitly describes a visible magical appearance. Do not invent provenance, makers,
history, age, damage, wear, colors, materials, or ornamentation merely to fill space. Avoid repeating the display
name when a direct physical description is clearer.
"""

DESCRIPTION_SCHEMA = {
    "type": "object",
    "additionalProperties": False,
    "properties": {"description": {"type": "string", "maxLength": 240}},
    "required": ["description"],
}

FORBIDDEN = re.compile(
    r"\b(?:game|player|inventory|record|file|quest|level|value|worth|weighs?|damage|armor rating|durability|"
    r"quality|charges?|uses?|skill|attribute|magnitude|probability|chance|seconds?|points?|UESP|wiki|model|icon)\b",
    re.IGNORECASE,
)


def utc_timestamp() -> str:
    return time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime())


def atomic_json(path: Path, payload: Any) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    temporary = path.with_name(path.name + ".tmp")
    temporary.write_text(json.dumps(payload, indent=2, ensure_ascii=False) + "\n", encoding="utf-8", newline="\n")
    os.replace(temporary, path)


def decode_text(raw: bytes) -> str:
    return raw.rstrip(b"\0").decode("cp1252", errors="replace").strip()


def iter_subrecords(body: bytes) -> Iterable[tuple[bytes, bytes]]:
    position = 0
    while position + 8 <= len(body):
        kind = body[position:position + 4]
        size = struct.unpack_from("<I", body, position + 4)[0]
        start = position + 8
        end = start + size
        if end > len(body):
            raise ValueError(f"TES3 subrecord {kind!r} extends past its parent record")
        yield kind, body[start:end]
        position = end
    if position != len(body):
        raise ValueError("TES3 record ended with an incomplete subrecord header")


def unpack_int(raw: bytes, offset: int, size: int = 4) -> int | None:
    if len(raw) < offset + size:
        return None
    formats = {2: "<h", 4: "<i"}
    return int(struct.unpack_from(formats[size], raw, offset)[0])


def item_subtype(record_type: bytes, fields: dict[bytes, bytes]) -> str:
    if record_type == b"WEAP":
        code = unpack_int(fields.get(b"WPDT", b""), 8, 2)
        return WEAPON_TYPES.get(code, "weapon")
    if record_type == b"ARMO":
        code = unpack_int(fields.get(b"AODT", b""), 0)
        return ARMOR_TYPES.get(code, "armor")
    if record_type == b"CLOT":
        code = unpack_int(fields.get(b"CTDT", b""), 0)
        return CLOTHING_TYPES.get(code, "clothing")
    if record_type == b"APPA":
        code = unpack_int(fields.get(b"AADT", b""), 0)
        return APPARATUS_TYPES.get(code, "alchemy apparatus")
    if record_type == b"BOOK":
        scroll = unpack_int(fields.get(b"BKDT", b""), 8)
        return "scroll" if scroll else "book"
    return TYPE_NAMES[record_type]


def parse_item(record_type: bytes, body: bytes, content_file: str) -> dict[str, Any] | None:
    fields: dict[bytes, bytes] = {}
    deleted = False
    for kind, value in iter_subrecords(body):
        fields.setdefault(kind, value)
        deleted = deleted or kind == b"DELE"
    record_id = decode_text(fields.get(b"NAME", b""))
    if not record_id:
        return None
    if deleted:
        return {"record_id": record_id, "deleted": True}
    display_name = decode_text(fields.get(b"FNAM", b""))
    return {
        "record_id": record_id,
        "display_name": display_name,
        "content_file": content_file,
        "record_type": record_type.decode("ascii"),
        "category": TYPE_NAMES[record_type],
        "subtype": item_subtype(record_type, fields),
        "model_hint": decode_text(fields.get(b"MODL", b"")),
        "icon_hint": decode_text(fields.get(b"ITEX", b"")),
        "script_id": decode_text(fields.get(b"SCRI", b"")),
        "deleted": False,
    }


def extract_items(data_dir: Path) -> tuple[list[dict[str, Any]], dict[str, str]]:
    winners: dict[str, dict[str, Any]] = {}
    hashes: dict[str, str] = {}
    for content_file in CONTENT_FILES:
        path = data_dir / content_file
        if not path.is_file():
            raise FileNotFoundError(f"Required official content file is missing: {path}")
        raw = path.read_bytes()
        hashes[content_file] = hashlib.sha256(raw).hexdigest()
        position = 0
        while position + 16 <= len(raw):
            record_type = raw[position:position + 4]
            size = struct.unpack_from("<I", raw, position + 4)[0]
            start = position + 16
            end = start + size
            if end > len(raw):
                raise ValueError(f"TES3 record in {content_file} extends past the end of the file")
            position = end
            if record_type not in ITEM_TYPES:
                continue
            item = parse_item(record_type, raw[start:end], content_file)
            if item is None:
                continue
            key = item["record_id"].casefold()
            if item["deleted"]:
                winners.pop(key, None)
            else:
                winners[key] = item
        if position != len(raw):
            raise ValueError(f"TES3 file ended with an incomplete record header: {path}")
    catalog = sorted(
        (row for row in winners.values() if row["display_name"]),
        key=lambda row: (row["display_name"].casefold(), row["record_id"].casefold()),
    )
    return catalog, hashes


def sample_selection(catalog: list[dict[str, Any]], size: int) -> list[dict[str, Any]]:
    if size < len(ITEM_TYPES):
        raise ValueError(f"Sample size must be at least {len(ITEM_TYPES)}")
    selected: dict[str, dict[str, Any]] = {}
    categories: dict[str, list[str]] = {}

    def order(rows: Iterable[dict[str, Any]]) -> list[dict[str, Any]]:
        return sorted(rows, key=lambda row: hashlib.sha256(
            f"{row['content_file']}|{row['record_id']}".casefold().encode("utf-8")
        ).hexdigest())

    def add(label: str, rows: Iterable[dict[str, Any]], count: int) -> None:
        added = 0
        for row in order(rows):
            key = f"{row['content_file']}|{row['record_id']}".casefold()
            if label not in categories.setdefault(key, []):
                categories[key].append(label)
            if key in selected:
                continue
            selected[key] = row
            added += 1
            if added >= count or len(selected) >= size:
                break

    for record_type in (kind.decode("ascii") for kind in ITEM_TYPES):
        add(f"type:{record_type}", (row for row in catalog if row["record_type"] == record_type), 2)
    add("content:Tribunal.esm", (row for row in catalog if row["content_file"] == "Tribunal.esm"), 5)
    add("content:Bloodmoon.esm", (row for row in catalog if row["content_file"] == "Bloodmoon.esm"), 5)

    name_groups: dict[str, list[dict[str, Any]]] = {}
    for row in catalog:
        name_groups.setdefault(row["display_name"].casefold(), []).append(row)
    duplicate_rows = [row for group in name_groups.values() if len(group) > 1 for row in group]
    add("duplicate-display-name", duplicate_rows, 6)
    add("scripted-item", (row for row in catalog if row["script_id"]), 4)
    add("deterministic-diversity-fill", catalog, size - len(selected))
    if len(selected) != size:
        raise ValueError(f"Unable to construct {size} distinct sample records")
    result: list[dict[str, Any]] = []
    for key, row in selected.items():
        selected_row = dict(row)
        selected_row["selection_categories"] = categories[key]
        result.append(selected_row)
    return result


def load_or_create_selection(
    run_dir: Path, catalog: list[dict[str, Any]], hashes: dict[str, str], size: int,
) -> list[dict[str, Any]]:
    path = run_dir / "selection.json"
    if path.is_file():
        document = json.loads(path.read_text(encoding="utf-8"))
        if document.get("official_content_sha256") != hashes:
            raise ValueError("Existing selection was created from different official ESM files")
        selection = document.get("selection")
        if not isinstance(selection, list) or len(selection) != size:
            raise ValueError("Existing selection does not match the requested size")
        return selection
    selection = sample_selection(catalog, size)
    atomic_json(path, {
        "format": FORMAT_VERSION,
        "created_at_utc": utc_timestamp(),
        "official_content_sha256": hashes,
        "official_named_item_count": len(catalog),
        "selected_count": len(selection),
        "selection": selection,
    })
    return selection


def safe_component(value: str) -> str:
    cleaned = re.sub(r"[^A-Za-z0-9._-]+", "_", value).strip("._") or "record"
    suffix = hashlib.sha256(value.casefold().encode("utf-8")).hexdigest()[:10]
    return f"{cleaned[:80]}-{suffix}"


def cache_path(cache_dir: Path, item: dict[str, Any]) -> Path:
    key = f"{item['content_file']}|{item['record_id']}"
    return cache_dir / f"{hashlib.sha256(key.casefold().encode('utf-8')).hexdigest()}.json"


def fetch_uesp(session: requests.Session, cache_dir: Path, item: dict[str, Any], timeout: float) -> dict[str, Any]:
    path = cache_path(cache_dir, item)
    if path.is_file():
        return json.loads(path.read_text(encoding="utf-8"))
    cache_dir.mkdir(parents=True, exist_ok=True)
    result: dict[str, Any] = {"status": "not-found"}
    search_terms = [f'"{item["record_id"]}"', f'"{item["display_name"]}" Morrowind']
    for term in search_terms:
        response = session.get(UESP_API_URL, params={
            "action": "query", "format": "json", "list": "search", "srsearch": term,
            "srnamespace": 0, "srlimit": 5, "utf8": 1,
        }, timeout=timeout)
        response.raise_for_status()
        for match in response.json().get("query", {}).get("search", []):
            page_id = match.get("pageid")
            page = session.get(UESP_API_URL, params={
                "action": "query", "format": "json", "prop": "revisions", "pageids": page_id,
                "rvprop": "ids|content", "rvslots": "main", "formatversion": 2,
            }, timeout=timeout)
            page.raise_for_status()
            pages = page.json().get("query", {}).get("pages", [])
            if not pages or not pages[0].get("revisions"):
                continue
            revision = pages[0]["revisions"][0]
            content = revision.get("slots", {}).get("main", {}).get("content", "")
            folded = content.casefold()
            needle = item["record_id"].casefold()
            if needle not in folded:
                continue
            offset = folded.index(needle)
            snippet = content[max(0, offset - 1200):offset + 1800]
            result = {
                "status": "exact-record-id",
                "title": pages[0].get("title"),
                "page_id": pages[0].get("pageid"),
                "revision_id": revision.get("revid"),
                "url": f"https://en.uesp.net/wiki/{quote(str(pages[0].get('title') or '').replace(' ', '_'), safe=':_/')}",
                "license": "UESP content, CC BY-SA",
                "snippet": re.sub(r"\s+", " ", snippet).strip()[:3000],
            }
            break
        if result["status"] == "exact-record-id":
            break
    atomic_json(path, result)
    return result


def evidence_text(item: dict[str, Any], uesp: dict[str, Any]) -> str:
    model_stem = Path(item["model_hint"].replace("\\", "/")).stem if item["model_hint"] else ""
    icon_stem = Path(item["icon_hint"].replace("\\", "/")).stem if item["icon_hint"] else ""
    lines = [
        f"display_name: {item['display_name']}",
        f"content_file: {item['content_file']}",
        f"record_id: {item['record_id']}",
        f"category: {item['category']}",
        f"subtype: {item['subtype']}",
        f"model_stem_hint: {model_stem}",
        f"icon_stem_hint: {icon_stem}",
    ]
    if uesp.get("status") == "exact-record-id":
        lines.append(f"uesp_page: {uesp.get('title')}")
        lines.append(f"uesp_record_context: {uesp.get('snippet')}")
    return "\n".join(lines)[:7000]


def extract_json_object(content: Any) -> dict[str, Any]:
    if isinstance(content, list):
        content = "\n".join(str(part.get("text") or "") for part in content if isinstance(part, dict))
    if not isinstance(content, str) or not content.strip():
        raise ValueError("OpenRouter response did not contain text")
    candidate = re.sub(r"^```(?:json)?\s*|\s*```$", "", content.strip(), flags=re.IGNORECASE)
    decoded = json.loads(candidate)
    if not isinstance(decoded, dict):
        raise ValueError("OpenRouter response JSON was not an object")
    return decoded


def call_glm(
    session: requests.Session, api_key: str, model: str, evidence: str, timeout: float,
    telemetry: dict[str, Any], repair: str = "",
) -> dict[str, Any]:
    user_content = "Source evidence:\n" + evidence + "\n\nReturn the item-description JSON now."
    if repair:
        user_content += "\n\nRepair the prior output using these requirements:\n" + repair
    telemetry["logical_calls"] = int(telemetry.get("logical_calls") or 0) + 1
    last_error: Exception | None = None
    for attempt in range(2):
        started = time.perf_counter()
        telemetry["http_attempts"] = int(telemetry.get("http_attempts") or 0) + 1
        try:
            response = session.post(OPENROUTER_URL, headers={
                "Authorization": f"Bearer {api_key}", "Content-Type": "application/json",
                "HTTP-Referer": "https://dwemerdynamics.com", "X-Title": "LORKHAN Morrowind Item Description Builder",
            }, json={
                "model": model, "temperature": 0.0, "max_tokens": 2000, "reasoning": {"effort": "none"},
                "provider": {"require_parameters": True},
                "response_format": {
                    "type": "json_schema",
                    "json_schema": {"name": "morrowind_item_description", "strict": True, "schema": DESCRIPTION_SCHEMA},
                },
                "plugins": [{"id": "response-healing"}],
                "messages": [{"role": "system", "content": SYSTEM_PROMPT}, {"role": "user", "content": user_content}],
            }, timeout=timeout)
            response.raise_for_status()
            payload = response.json()
            usage = payload.get("usage") if isinstance(payload.get("usage"), dict) else {}
            for key in ("prompt_tokens", "completion_tokens", "total_tokens", "cost"):
                value = usage.get(key)
                if isinstance(value, (int, float)):
                    telemetry[key] = telemetry.get(key, 0) + value
            if payload.get("provider"):
                telemetry["provider"] = payload["provider"]
            choices = payload.get("choices") or []
            if not choices:
                raise ValueError("OpenRouter returned no choices")
            choice = choices[0]
            message = choice.get("message") or {}
            content = message.get("content")
            if not isinstance(content, (str, list)) or not content:
                field_lengths = {
                    key: len(value) if isinstance(value, (str, list, dict)) else 0
                    for key, value in message.items() if key != "content"
                }
                raise ValueError(
                    "OpenRouter response did not contain text "
                    f"(finish_reason={choice.get('finish_reason')!r}, message_fields={field_lengths})"
                )
            return extract_json_object(content)
        except (requests.RequestException, ValueError, json.JSONDecodeError) as error:
            last_error = error
            if attempt == 0:
                time.sleep(1)
        finally:
            telemetry["request_seconds"] = round(
                float(telemetry.get("request_seconds") or 0) + time.perf_counter() - started, 3,
            )
    raise RuntimeError(f"GLM structured-output request failed after retry: {last_error}")


def word_count(value: str) -> int:
    return len(re.findall(r"\b[\w'-]+\b", value, flags=re.UNICODE))


def validate_description(value: Any) -> tuple[str, list[str]]:
    text = re.sub(r"\s+", " ", str(value or "")).strip()
    violations: list[str] = []
    words = word_count(text)
    if not 13 <= words <= 22:
        violations.append(f"use 13-22 words; received {words}")
    if len(re.findall(r"[.!?](?:[\"')\]]+)?(?:\s|$)", text)) != 1 or not re.search(r"[.!?][\"')\]]?$", text):
        violations.append("write exactly one complete sentence")
    if text.casefold().startswith("this item"):
        violations.append('do not begin with "This item"')
    forbidden = sorted({match.group(0).casefold() for match in FORBIDDEN.finditer(text)})
    if forbidden:
        violations.append("remove mechanics or metadata terms: " + ", ".join(forbidden))
    if len(text) > 240:
        violations.append("keep the description below 240 characters")
    return text, violations


def result_path(run_dir: Path, item: dict[str, Any]) -> Path:
    key = f"{item['content_file']}|{item['record_id']}"
    return run_dir / "records" / safe_component(key) / "result.json"


def load_attempts(record_dir: Path) -> list[dict[str, Any]]:
    path = record_dir / "attempts.json"
    try:
        document = json.loads(path.read_text(encoding="utf-8"))
        attempts = document.get("attempts")
        return attempts if isinstance(attempts, list) else []
    except (OSError, ValueError, TypeError, json.JSONDecodeError):
        return []


def append_attempt(record_dir: Path, attempt: dict[str, Any]) -> None:
    attempts = load_attempts(record_dir)
    attempts.append(attempt)
    atomic_json(record_dir / "attempts.json", {
        "format": FORMAT_VERSION, "updated_at_utc": utc_timestamp(), "attempts": attempts,
    })


def valid_result(path: Path, item: dict[str, Any]) -> tuple[bool, str]:
    try:
        document = json.loads(path.read_text(encoding="utf-8"))
        row = document["row"]
        if row.get("plugin", "").casefold() != item["content_file"].casefold():
            return False, "content file mismatch"
        if row.get("baseid", "").casefold() != item["record_id"].casefold():
            return False, "record ID mismatch"
        _, violations = validate_description(row.get("description"))
        return (not violations, "; ".join(violations))
    except (OSError, ValueError, TypeError, KeyError, json.JSONDecodeError) as error:
        return False, str(error)


def regeneration_hint(record_dir: Path) -> str:
    path = record_dir / "regeneration-hint.txt"
    try:
        hint = re.sub(r"\s+", " ", path.read_text(encoding="utf-8")).strip()
        return hint[:1000]
    except OSError:
        return ""


def aggregate(run_dir: Path, selection: list[dict[str, Any]], hashes: dict[str, str], model: str) -> dict[str, Any]:
    rows: list[dict[str, str]] = []
    items: list[dict[str, Any]] = []
    usage: dict[str, float] = {}
    for item in selection:
        path = result_path(run_dir, item)
        valid, error = valid_result(path, item)
        document = json.loads(path.read_text(encoding="utf-8")) if path.is_file() else {}
        if valid:
            rows.append(document["row"])
        attempts = load_attempts(path.parent)
        telemetry_rows = [attempt.get("telemetry", {}) for attempt in attempts if isinstance(attempt, dict)]
        if not telemetry_rows:
            telemetry_rows = [document.get("generation", {}).get("telemetry", {})]
        for telemetry in telemetry_rows:
            if not isinstance(telemetry, dict):
                continue
            for key in ("logical_calls", "http_attempts", "prompt_tokens", "completion_tokens", "total_tokens", "cost", "request_seconds"):
                value = telemetry.get(key)
                if isinstance(value, (int, float)):
                    usage[key] = usage.get(key, 0) + value
        items.append({
            "record_id": item["record_id"], "display_name": item["display_name"],
            "content_file": item["content_file"], "record_type": item["record_type"],
            "status": "complete" if valid else "pending", "error": None if valid else error,
        })
    manifest = {
        "format": FORMAT_VERSION, "updated_at_utc": utc_timestamp(), "model": model,
        "prompt_sha256": hashlib.sha256(SYSTEM_PROMPT.encode("utf-8")).hexdigest(),
        "official_content_sha256": hashes, "selected_count": len(selection), "completed_count": len(rows),
        "pending_count": len(selection) - len(rows), "usage": usage, "items": items,
    }
    atomic_json(run_dir / "manifest.json", manifest)
    combined = run_dir / "combined"
    combined.mkdir(parents=True, exist_ok=True)
    atomic_json(combined / "descriptions.json", rows)
    with (combined / "descriptions.csv").open("w", encoding="utf-8-sig", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=["plugin", "baseid", "name", "description"])
        writer.writeheader()
        writer.writerows(rows)
    table_rows = "\n".join(
        "<tr>" + "".join(f"<td>{html.escape(str(row[field]))}</td>" for field in ("plugin", "baseid", "name", "description")) + "</tr>"
        for row in rows
    )
    (combined / "review.html").write_text(
        "<!doctype html><meta charset=\"utf-8\"><title>Morrowind item descriptions - 50 item review</title>"
        "<style>body{font:15px system-ui;background:#161616;color:#eee;margin:24px}table{border-collapse:collapse;width:100%}"
        "th,td{border:1px solid #555;padding:8px;text-align:left;vertical-align:top}th{color:#f27c11;background:#252525}"
        "tr:nth-child(even){background:#202020}td:nth-child(1),td:nth-child(2){white-space:nowrap}</style>"
        f"<h1>Morrowind item descriptions</h1><p>{len(rows)} of {len(selection)} validated review descriptions.</p>"
        "<table><thead><tr><th>Content file</th><th>Record ID</th><th>Name</th><th>Description</th></tr></thead>"
        f"<tbody>{table_rows}</tbody></table>", encoding="utf-8", newline="\n",
    )
    markdown = [
        "# Morrowind item-description review", "", f"Validated: {len(rows)} / {len(selection)}", "",
        "| Content file | Record ID | Name | Description |", "|---|---|---|---|",
    ]
    for row in rows:
        values = [str(row[field]).replace("|", "\\|").replace("\n", " ") for field in ("plugin", "baseid", "name", "description")]
        markdown.append("| " + " | ".join(values) + " |")
    (combined / "review.md").write_text("\n".join(markdown) + "\n", encoding="utf-8", newline="\n")
    return manifest


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Generate a review-only Morrowind item-description sample.")
    parser.add_argument("--data-dir", type=Path, default=DEFAULT_DATA_DIR)
    parser.add_argument("--run-dir", type=Path, required=True)
    parser.add_argument("--size", type=int, default=50)
    parser.add_argument("--model", default=DEFAULT_MODEL)
    parser.add_argument("--api-key-env", default="OPENROUTER_API_KEY")
    parser.add_argument("--cache-dir", type=Path, default=Path.home() / ".cache" / "lorkhanserver" / "uesp-morrowind-items")
    parser.add_argument("--timeout", type=float, default=90)
    parser.add_argument("--max-cost", type=float, default=5.0)
    parser.add_argument("--workers", type=int, default=1)
    parser.add_argument("--resume", action="store_true")
    parser.add_argument("--evidence-only", action="store_true")
    parser.add_argument("--skip-uesp", action="store_true")
    args = parser.parse_args()
    if args.size < len(ITEM_TYPES):
        parser.error(f"--size must be at least {len(ITEM_TYPES)}")
    if args.max_cost <= 0:
        parser.error("--max-cost must be greater than zero")
    if not 1 <= args.workers <= 64:
        parser.error("--workers must be between 1 and 64")
    return args


def main() -> int:
    args = parse_args()
    api_key = os.getenv(args.api_key_env, "").strip()
    if not args.evidence_only and not api_key:
        raise RuntimeError(f"Generation requires the {args.api_key_env} environment variable")
    args.run_dir.mkdir(parents=True, exist_ok=True)
    catalog, hashes = extract_items(args.data_dir)
    selection = load_or_create_selection(args.run_dir, catalog, hashes, args.size)
    manifest = aggregate(args.run_dir, selection, hashes, args.model)
    spent = float(manifest["usage"].get("cost") or 0)
    failures = 0

    def generate_item(index: int, item: dict[str, Any]) -> tuple[int, float]:
        path = result_path(args.run_dir, item)
        valid, _ = valid_result(path, item)
        if args.resume and valid:
            print(f"[skip] {index}/{len(selection)} {item['display_name']}: complete", flush=True)
            return 0, 0.0
        record_dir = path.parent
        record_dir.mkdir(parents=True, exist_ok=True)
        telemetry: dict[str, Any] = {}
        session = requests.Session()
        session.headers.update({"User-Agent": "LORKHAN item-description authoring tool/1.0 (https://dwemerdynamics.com)"})
        try:
            if args.skip_uesp:
                uesp = {"status": "skipped"}
            else:
                print(f"[evidence] {index}/{len(selection)} {item['display_name']} ({item['record_id']})", flush=True)
                uesp = fetch_uesp(session, args.cache_dir, item, args.timeout)
            evidence = evidence_text(item, uesp)
            if args.evidence_only:
                atomic_json(record_dir / "evidence.json", {"identity": item, "uesp": uesp, "evidence": evidence})
                return 0, 0.0
            candidate: dict[str, Any] = {}
            violations: list[str] = []
            for repair_attempt in range(3):
                repair = regeneration_hint(record_dir) if repair_attempt == 0 else ""
                if violations:
                    repair = "; ".join(violations) + f". Prior description: {candidate.get('description', '')}"
                candidate = call_glm(session, api_key, args.model, evidence, args.timeout, telemetry, repair)
                description, violations = validate_description(candidate.get("description"))
                candidate["description"] = description
                if not violations:
                    break
            if violations:
                raise ValueError("; ".join(violations))
            document = {
                "format": FORMAT_VERSION, "generated_at_utc": utc_timestamp(), "identity": item,
                "uesp": {key: value for key, value in uesp.items() if key != "snippet"},
                "row": {
                    "plugin": item["content_file"], "baseid": item["record_id"],
                    "name": item["display_name"], "description": candidate["description"],
                },
                "generation": {"model": args.model, "telemetry": telemetry},
            }
            atomic_json(path, document)
            append_attempt(record_dir, {
                "finished_at_utc": utc_timestamp(), "status": "complete", "telemetry": telemetry,
            })
            print(
                f"[complete] {item['record_id']}: words={word_count(candidate['description'])} "
                f"calls={telemetry.get('logical_calls')} cost={float(telemetry.get('cost') or 0):.6f}", flush=True,
            )
            return 0, float(telemetry.get("cost") or 0)
        except (OSError, ValueError, RuntimeError, requests.RequestException, json.JSONDecodeError) as error:
            append_attempt(record_dir, {
                "finished_at_utc": utc_timestamp(), "status": "quarantined", "error": str(error),
                "telemetry": telemetry,
            })
            atomic_json(record_dir / "rejected.json", {
                "format": FORMAT_VERSION, "generated_at_utc": utc_timestamp(), "identity": item,
                "error": str(error), "generation": {"model": args.model, "telemetry": telemetry},
            })
            print(f"[quarantine] {item['record_id']}: {error}", flush=True)
            return 1, float(telemetry.get("cost") or 0)
        finally:
            session.close()

    pending = [
        (index, item) for index, item in enumerate(selection, start=1)
        if not (args.resume and valid_result(result_path(args.run_dir, item), item)[0])
    ]
    for offset in range(0, len(pending), args.workers):
        if spent >= args.max_cost:
            print(f"[stop] provider cost cap reached: {spent:.6f} / {args.max_cost:.2f}", flush=True)
            break
        batch = pending[offset:offset + args.workers]
        if args.workers == 1:
            results = [generate_item(*entry) for entry in batch]
        else:
            with concurrent.futures.ThreadPoolExecutor(max_workers=args.workers) as executor:
                results = list(executor.map(lambda entry: generate_item(*entry), batch))
        for item_failures, item_cost in results:
            failures += item_failures
            spent += item_cost
        manifest = aggregate(args.run_dir, selection, hashes, args.model)
    manifest = aggregate(args.run_dir, selection, hashes, args.model)
    print(
        f"[summary] catalog={len(catalog)} selected={manifest['selected_count']} complete={manifest['completed_count']} "
        f"pending={manifest['pending_count']} cost={float(manifest['usage'].get('cost') or 0):.6f} run_dir={args.run_dir}",
        flush=True,
    )
    if args.evidence_only:
        return 0
    return 1 if failures or manifest["pending_count"] else 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except (FileNotFoundError, RuntimeError, ValueError) as error:
        print(f"error: {error}", file=sys.stderr)
        raise SystemExit(1)
