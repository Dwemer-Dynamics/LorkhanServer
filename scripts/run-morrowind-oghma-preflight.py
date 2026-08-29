#!/usr/bin/env python3
"""Build a durable lore-first Morrowind Oghma review catalog."""

from __future__ import annotations

import argparse
from concurrent.futures import ThreadPoolExecutor, as_completed
import csv
from difflib import SequenceMatcher
import hashlib
import html
import json
import os
from pathlib import Path
import re
import struct
import threading
import time
from typing import Any, Iterable
from urllib.parse import quote

import requests


FORMAT_VERSION = "lorkhan.morrowind-oghma-preflight.v1"
GENERATION_RULESET = "morrowind-oghma-static-3e427-v5"
DEFAULT_DATA_DIR = Path(r"C:\Program Files (x86)\Steam\steamapps\common\Morrowind\Data Files")
DEFAULT_MODEL = "z-ai/glm-5.1"
OPENROUTER_URL = "https://openrouter.ai/api/v1/chat/completions"
UESP_API_URL = "https://en.uesp.net/w/api.php"
CONTENT_FILES = ("Morrowind.esm", "Tribunal.esm", "Bloodmoon.esm")
SCRIPT_DIR = Path(__file__).resolve().parent
RESOURCE_DIR = SCRIPT_DIR.parent / "resources" / "oghma" / "morrowind-official"
DEFAULT_SEEDS = RESOURCE_DIR / "topic-seeds.json"
DEFAULT_ONTOLOGY = RESOURCE_DIR / "ontology.json"
RECORD_TYPES = {
    b"NPC_", b"CREA", b"WEAP", b"ARMO", b"CLOT", b"MISC", b"BOOK", b"CELL", b"REGN",
    b"INGR", b"SPEL", b"RACE", b"FACT",
}

SYSTEM_PROMPT = """You write concise, source-grounded Morrowind encyclopedia entries for the CHIM Oghma Infinium system.

Return only the required JSON object. Oghma is about knowledge and lore. It is not an item-description catalog,
NPC biography catalog, quest guide, walkthrough, or database dump. The supplied topic inventory is curated: only
important historical figures and culturally or historically important artifacts are included. Do not expand the
scope to ordinary people, generic equipment, routine spells, consumables, or minor quest objects.

The temporal anchor is 3E 427 at the start of Morrowind, before the Nerevarine's actions. Write in-world,
present-tense reference prose suitable for that new playthrough. Never include Fourth Era events, the Red Year,
the New Temple, the fall or dissolution of the Tribunal, later political replacements, or future quest outcomes. Use
the supplied official identity and source evidence conservatively. Do not mention games, players, quests, stages,
records, form IDs, files, databases, wikis, UESP, prompts, language models, statistics, levels, mechanics, or source
material. Do not reproduce book or dialogue passages. Do not invent disputed claims, secret motives, relationships,
appearance, ownership, outcomes, or prophecy fulfillment. When accounts disagree, state the uncertainty briefly.
Use only affirmative facts present in the supplied evidence. Do not extrapolate titles, political status, exact rules,
numeric ranges, named people, landmarks, history, or social customs that the evidence does not directly establish.
If official dialogue is tied to an errand or dispute, extract only stable encyclopedic knowledge. Never narrate a
one-time request, theft, commercial scheme, investigation, missing person, delivery, payment, current plan, or its
ordinary participants. Do not name ordinary NPCs unless the locked subject itself is a reviewed major figure.

The advanced article must explain the subject's identity, significance, and stable context in no more than 150 words.
The supplied basic_knowledge_mode controls location basics. For "unknown", use an ignorance fallback in the exact
form "You do not know where <title> is." and assign it only to common knowledge. For "common", provide a short,
factual summary of the place's broadly known identity and general location, assigned only to common knowledge.
Never move restricted details into either basic form. For "auto", follow the ordinary lore rule and leave basic empty
unless broad public knowledge is directly supported. A factual basic account may be no more than 65 words. Short,
complete advanced articles are valid when evidence is sparse; never pad either field.
The basic article must not be a clipped copy of the advanced article. If it is empty, return an empty basic class list.
No access class may appear in both the advanced and basic class lists. Return only the supplied seed aliases; do not
invent spelling variants or additional aliases. Return 2-12 concise search tags supported by the locked evidence.
Select knowledge access classes only from the supplied seed and profile classes; do not add inferred classes. Select
exactly the supplied category.

Always return full advanced prose, including on a repair. Return the basic description as an empty string when the
subject is not common knowledge. Never return null, None, a refusal,
an apology, or a placeholder. Avoid forbidden out-of-world phrases; an in-world subject name containing an otherwise
ordinary word remains valid.
"""

ARTICLE_SCHEMA = {
    "type": "object",
    "additionalProperties": False,
    "properties": {
        "topic_desc": {"type": "string", "maxLength": 1600},
        "topic_desc_basic": {"type": "string", "maxLength": 800},
        "aliases": {"type": "array", "items": {"type": "string", "maxLength": 100}, "maxItems": 8},
        "knowledge_class": {"type": "array", "items": {"type": "string", "maxLength": 64}, "maxItems": 12},
        "knowledge_class_basic": {"type": "array", "items": {"type": "string", "maxLength": 64}, "maxItems": 12},
        "tags": {"type": "array", "items": {"type": "string", "maxLength": 80}, "minItems": 2, "maxItems": 12},
        "category": {"type": "string", "maxLength": 64},
    },
    "required": ["topic_desc", "topic_desc_basic", "aliases", "knowledge_class", "knowledge_class_basic", "tags", "category"],
}

FORBIDDEN = re.compile(
    r"\b(?:video\s+game|the\s+player(?:s|'s)?|player(?:s|'s)?\s+(?:can|must|should|may|character|actions?|choices?|inventory)|"
    r"quest(?:line)?|form\s*id|game\s+file|database|wiki|UESP|prompt|"
    r"language\s+model|game\s+mechanics?|character\s+level|stat(?:istic)?s?|hit\s+points?|armor\s+rating|"
    r"inventory\s+(?:menu|screen)|walkthrough)\b",
    re.IGNORECASE,
)
POST_GAME = re.compile(
    r"\b(?:4E\s*\d*|Fourth\s+Era|Red\s+Year|New\s+Temple|House\s+Sadras|"
    r"Tribunal(?:'s)?\s+(?:fall|collapse|dissolution)|recalled?\s+(?:him\s+)?to\s+the\s+Imperial\s+City)\b",
    re.IGNORECASE,
)
TAG_FORBIDDEN = re.compile(
    r"\b(?:Tamriel\s+Rebuilt|UESP|wiki|mod(?:ification)?|console|record\s+ID|quest|the\s+player)\b",
    re.IGNORECASE,
)


def utc_timestamp() -> str:
    return time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime())


def atomic_json(path: Path, payload: Any) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    temporary = path.with_name(path.name + ".tmp")
    temporary.write_text(json.dumps(payload, indent=2, ensure_ascii=False) + "\n", encoding="utf-8", newline="\n")
    os.replace(temporary, path)


def atomic_text(path: Path, payload: str, bom: bool = False) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    temporary = path.with_name(path.name + ".tmp")
    temporary.write_text(payload, encoding="utf-8-sig" if bom else "utf-8", newline="")
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
        raise RuntimeError(f"Another Oghma process owns the run lock: {run_dir}") from error


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


def read_json(path: Path) -> Any:
    raw = path.read_bytes()
    if raw.startswith((b"\xff\xfe", b"\xfe\xff", b"\xff\xfe\x00\x00", b"\x00\x00\xfe\xff")):
        raise ValueError(f"File is not UTF-8: {path}")
    return json.loads(raw.decode("utf-8-sig"))


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
            raise ValueError(f"TES3 subrecord {kind!r} extends past its record")
        yield kind, body[start:end]
        position = end
    if position != len(body):
        raise ValueError("TES3 record ended with an incomplete subrecord header")


def extract_records(
    data_dir: Path, content_files: tuple[str, ...] = CONTENT_FILES,
) -> tuple[dict[str, dict[str, Any]], dict[str, str]]:
    winners: dict[str, dict[str, Any]] = {}
    hashes: dict[str, str] = {}
    for content_file in content_files:
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
                raise ValueError(f"TES3 record in {content_file} extends past the file")
            position = end
            if record_type not in RECORD_TYPES:
                continue
            fields: dict[bytes, bytes] = {}
            deleted = False
            for kind, value in iter_subrecords(raw[start:end]):
                fields.setdefault(kind, value)
                deleted = deleted or kind == b"DELE"
            record_id = decode_text(fields.get(b"NAME", b""))
            if not record_id:
                continue
            key = f"{record_type.decode('ascii')}|{record_id}".casefold()
            if deleted:
                winners.pop(key, None)
            else:
                record = {
                    "content_file": content_file,
                    "record_type": record_type.decode("ascii"),
                    "record_id": record_id,
                    "display_name": decode_text(fields.get(b"FNAM", b"")) or record_id,
                }
                if record_type == b"BOOK":
                    record["source_text"] = decode_text(fields.get(b"TEXT", b""))
                winners[key] = record
        if position != len(raw):
            raise ValueError(f"TES3 file ended with an incomplete record header: {path}")
    return winners, hashes


# Dialogue responses are first-party evidence for expansion topics that do not have a dedicated UESP page.
def extract_dialogue_evidence(
    data_dir: Path, content_files: tuple[str, ...] = CONTENT_FILES,
) -> dict[str, dict[str, Any]]:
    topics: dict[str, dict[str, Any]] = {}
    winning_responses: dict[str, tuple[str, str, str]] = {}
    for content_file in content_files:
        raw = (data_dir / content_file).read_bytes()
        position = 0
        active_topic = ""
        while position + 16 <= len(raw):
            record_type = raw[position:position + 4]
            size = struct.unpack_from("<I", raw, position + 4)[0]
            start = position + 16
            end = start + size
            if end > len(raw):
                raise ValueError(f"TES3 record in {content_file} extends past the file")
            position = end
            fields: dict[bytes, bytes] = {}
            deleted = False
            for kind, value in iter_subrecords(raw[start:end]):
                fields.setdefault(kind, value)
                deleted = deleted or kind == b"DELE"
            if record_type == b"DIAL":
                title = decode_text(fields.get(b"NAME", b""))
                dialogue_type = fields.get(b"DATA", b"\xff")[:1]
                active_topic = title if title and dialogue_type == b"\x00" else ""
                if active_topic:
                    key = re.sub(r"[^a-z0-9]+", "", active_topic.casefold())
                    topics.setdefault(key, {"title": active_topic, "sources": set()})["sources"].add(content_file)
                continue
            if record_type != b"INFO" or not active_topic:
                continue
            response_id = decode_text(fields.get(b"INAM", b""))
            if not response_id:
                continue
            winner_key = response_id.casefold()
            if deleted:
                winning_responses.pop(winner_key, None)
                continue
            response = decode_text(fields.get(b"NAME", b""))
            if response:
                winning_responses[winner_key] = (active_topic, content_file, response)
    for active_topic, content_file, response in winning_responses.values():
        key = re.sub(r"[^a-z0-9]+", "", active_topic.casefold())
        row = topics.setdefault(key, {"title": active_topic, "sources": set()})
        row["sources"].add(content_file)
        row.setdefault("responses", []).append(response)
    result: dict[str, dict[str, Any]] = {}
    for key, row in topics.items():
        responses = unique_strings(row.get("responses", []))
        result[key] = {
            "title": row["title"],
            "sources": sorted(row["sources"], key=content_files.index),
            "response_count": len(responses),
            "responses": responses,
        }
    return result


def slug(value: str) -> str:
    return re.sub(r"[^a-z0-9]+", "_", value.casefold()).strip("_")


def word_count(value: str) -> int:
    return len(re.findall(r"[\w’'-]+", value, flags=re.UNICODE))


def unique_strings(values: Any) -> list[str]:
    if not isinstance(values, list):
        return []
    result: list[str] = []
    seen: set[str] = set()
    for value in values:
        text = re.sub(r"\s+", " ", str(value)).strip().strip(",")
        key = re.sub(r"[^a-z0-9]+", "", text.casefold())
        if text and key and key not in seen:
            seen.add(key)
            result.append(text)
    return result


def resolve_topic_links(topic: dict[str, Any], records: dict[str, dict[str, Any]]) -> list[dict[str, Any]]:
    resolved: list[dict[str, Any]] = []
    for link in topic.get("record_links", []):
        key = f"{link.get('record_type', '')}|{link.get('record_id', '')}".casefold()
        record = records.get(key)
        if record is None:
            raise ValueError(f"{topic['topic']} record link was not found in official winning records: {key}")
        resolved.append({key: value for key, value in record.items() if key != "source_text"})
    return resolved


# Book sources are evidence links, not identities for the knowledge subject itself.
def resolve_book_sources(topic: dict[str, Any], records: dict[str, dict[str, Any]]) -> list[dict[str, Any]]:
    resolved: list[dict[str, Any]] = []
    seen: set[str] = set()
    for record_id in topic.get("book_sources", []):
        key = f"BOOK|{record_id}".casefold()
        record = records.get(key)
        if record is None:
            raise ValueError(f"{topic['topic']} book source was not found in official winning records: {record_id}")
        if key in seen:
            raise ValueError(f"{topic['topic']} repeats official book source: {record_id}")
        seen.add(key)
        source_text = str(record.get("source_text", "")).strip()
        if not source_text:
            raise ValueError(f"{topic['topic']} official book source has no text: {record_id}")
        resolved.append(dict(record))
    return resolved


def validate_seed_document(document: Any, ontology: dict[str, Any], records: dict[str, dict[str, Any]]) -> list[dict[str, Any]]:
    if not isinstance(document, dict) or not isinstance(document.get("topics"), list):
        raise ValueError("Topic seed file must contain a topics array")
    allowed_categories = set(ontology["categories"])
    allowed_classes = set(ontology["knowledge_classes"])
    allowed_profiles = set(ontology["profiles"])
    topics: list[dict[str, Any]] = []
    seen_topics: set[str] = set()
    canonical_owners: dict[str, str] = {}
    for index, raw in enumerate(document["topics"], 1):
        topic = str(raw.get("topic", "")).strip() if isinstance(raw, dict) else ""
        canonical_key = re.sub(r"[^a-z0-9]+", "", topic.casefold())
        if not topic or topic != slug(topic) or canonical_key in canonical_owners:
            raise ValueError(f"Topic seed {index} has an invalid or duplicate canonical topic: {topic}")
        canonical_owners[canonical_key] = topic
    alias_owner = dict(canonical_owners)
    for index, raw in enumerate(document["topics"], 1):
        if not isinstance(raw, dict):
            raise ValueError(f"Topic seed {index} is not an object")
        topic = str(raw.get("topic", "")).strip()
        title = str(raw.get("title", "")).strip()
        category = str(raw.get("category", "")).strip()
        profile = str(raw.get("profile", "")).strip()
        if not title or category not in allowed_categories or profile not in allowed_profiles:
            raise ValueError(f"Topic {topic} has invalid title, category, or profile")
        classes = unique_strings(raw.get("classes", []))
        if any(value not in allowed_classes for value in classes):
            raise ValueError(f"Topic {topic} has a knowledge class outside the ontology")
        aliases = unique_strings(raw.get("aliases", []))
        canonical_key = re.sub(r"[^a-z0-9]+", "", topic.casefold())
        for alias in aliases:
            key = re.sub(r"[^a-z0-9]+", "", alias.casefold())
            if key == canonical_key:
                continue
            owner = alias_owner.get(key)
            if owner is not None and owner != topic:
                raise ValueError(f"Alias {alias!r} for {topic} collides with {owner}")
            alias_owner[key] = topic
        row = dict(raw)
        basic_mode = str(raw.get("basic_mode", "auto")).strip().casefold()
        if basic_mode not in {"auto", "common", "unknown"}:
            raise ValueError(f"Topic {topic} has an invalid basic_mode")
        row["basic_mode"] = basic_mode
        mod_source = str(raw.get("mod_source", "")).strip()
        if mod_source and not re.fullmatch(r"[^/\\\x00]{1,256}\.(?:esm|esp|omwaddon)", mod_source, re.IGNORECASE):
            raise ValueError(f"Topic {topic} has an invalid mod_source")
        row["mod_source"] = mod_source or None
        row["aliases"] = aliases
        row["classes"] = classes
        row["resolved_records"] = resolve_topic_links(row, records)
        row["resolved_book_sources"] = resolve_book_sources(row, records)
        topics.append(row)
        seen_topics.add(topic)
    return topics


def stable_selection(topics: list[dict[str, Any]], size: int) -> list[dict[str, Any]]:
    if size < 1 or size > len(topics):
        raise ValueError(f"Selection size must be between 1 and {len(topics)}")
    categories: dict[str, list[dict[str, Any]]] = {}
    for row in topics:
        categories.setdefault(row["category"], []).append(row)
    selected: dict[str, dict[str, Any]] = {}
    for category in sorted(categories):
        preferred = [row for row in categories[category] if row.get("preflight")]
        choices = preferred or categories[category]
        row = sorted(choices, key=lambda item: item["topic"])[0]
        selected[row["topic"]] = row
        if len(selected) >= size:
            return list(selected.values())
    preferred = [row for row in topics if row.get("preflight")]
    fill = preferred + sorted(topics, key=lambda row: hashlib.sha256(row["topic"].encode("utf-8")).hexdigest())
    for row in fill:
        selected.setdefault(row["topic"], row)
        if len(selected) >= size:
            break
    return list(selected.values())


def excluded_topics(path: Path | None) -> set[str]:
    if path is None:
        return set()
    document = read_json(path)
    rows = document.get("selection") if isinstance(document, dict) else None
    if not isinstance(rows, list):
        raise ValueError("Excluded selection must contain a selection array")
    topics = {str(row.get("topic", "")).strip() for row in rows if isinstance(row, dict)}
    if "" in topics or len(topics) != len(rows):
        raise ValueError("Excluded selection contains an invalid or duplicate topic")
    return topics


def selection_document(topics: list[dict[str, Any]], hashes: dict[str, str], ontology_sha: str, seeds_sha: str) -> dict[str, Any]:
    return {
        "format": FORMAT_VERSION,
        "created_at_utc": utc_timestamp(),
        "selected_count": len(topics),
        "official_content_sha256": hashes,
        "ontology_sha256": ontology_sha,
        "topic_seeds_sha256": seeds_sha,
        "selection": topics,
    }


def uesp_search(session: requests.Session, topic: dict[str, Any], cache_dir: Path, refresh: bool) -> dict[str, Any]:
    cache_dir.mkdir(parents=True, exist_ok=True)
    cache_path = cache_dir / f"{topic['topic']}.json"
    if cache_path.is_file() and not refresh:
        cached = read_json(cache_path)
        requested_revision = topic.get("uesp_revision_id")
        if requested_revision is None or cached.get("requested_revision_id") == requested_revision:
            return cached
    requested_revision = topic.get("uesp_revision_id")
    explicit_titles = set(topic.get("uesp_titles", []))
    if requested_revision is not None:
        response = session.get(UESP_API_URL, params={
            "action": "query", "revids": int(requested_revision), "prop": "info|revisions",
            "inprop": "url", "rvprop": "ids|timestamp", "format": "json", "formatversion": 2,
        }, timeout=30)
        response.raise_for_status()
        pages = response.json().get("query", {}).get("pages", [])
        if len(pages) != 1 or pages[0].get("missing"):
            result = {"status": "not-found", "queries": [], "pages": [],
                      "requested_revision_id": requested_revision}
            atomic_json(cache_path, result)
            return result
        page = pages[0]
        if explicit_titles and str(page.get("title", "")) not in explicit_titles:
            raise ValueError(f"UESP revision {requested_revision} does not belong to the locked page title")
        parsed = session.get(UESP_API_URL, params={
            "action": "parse", "oldid": int(requested_revision), "prop": "text",
            "format": "json", "formatversion": 2,
        }, timeout=30)
        parsed.raise_for_status()
        markup = str(parsed.json().get("parse", {}).get("text", ""))
        markup = re.sub(r"<(script|style)[^>]*>.*?</\1>", " ", markup, flags=re.IGNORECASE | re.DOTALL)
        evidence = html.unescape(re.sub(r"\s+", " ", re.sub(r"<[^>]+>", " ", markup))).strip()[:8000]
        revision = (page.get("revisions") or [{}])[0]
        selected_pages = [] if len(evidence) < 20 else [{
            "title": page.get("title"), "page_id": page.get("pageid"),
            "revision_id": revision.get("revid"), "revision_timestamp": revision.get("timestamp"),
            "url": page.get("fullurl"),
            "license": "UESP content; see source page for license and attribution", "evidence": evidence,
        }]
        result = {
            "status": "found" if selected_pages else "not-found", "queries": [], "pages": selected_pages,
            "requested_revision_id": requested_revision,
        }
        atomic_json(cache_path, result)
        return result
    queries = [f"Morrowind:{topic['title']}", f"Lore:{topic['title']}"]
    candidate_titles = {f"Morrowind:{topic['title']}", f"Lore:{topic['title']}", *explicit_titles}
    for query in queries:
        response = session.get(UESP_API_URL, params={
            "action": "query", "list": "search", "srsearch": query, "srnamespace": 0,
            "srlimit": 8, "format": "json", "formatversion": 2,
        }, timeout=30)
        response.raise_for_status()
        candidate_titles.update(row["title"] for row in response.json().get("query", {}).get("search", []))
    response = session.get(UESP_API_URL, params={
        "action": "query", "titles": "|".join(sorted(candidate_titles)), "redirects": 1,
        "prop": "extracts|info|revisions", "explaintext": 1, "exintro": 0,
        "inprop": "url", "rvprop": "ids|timestamp", "format": "json", "formatversion": 2,
    }, timeout=30)
    response.raise_for_status()
    aliases = [topic["title"], *topic.get("aliases", [])]

    def title_key(value: str) -> str:
        value = value.split(":", 1)[-1]
        value = re.sub(r"\s*\([^)]*\)\s*$", "", value)
        value = re.sub(r"^the\s+", "", value, flags=re.IGNORECASE)
        return re.sub(r"[^a-z0-9]+", "", value.casefold())

    target_keys = {title_key(value) for value in aliases}
    target_words = set(re.findall(r"[a-z0-9]+", topic["title"].casefold())) - {"the", "of"}

    def page_score(page: dict[str, Any]) -> int:
        if str(page.get("title", "")) in explicit_titles:
            return 200
        base_key = title_key(str(page.get("title", "")))
        base_words = set(re.findall(r"[a-z0-9]+", str(page.get("title", "")).split(":", 1)[-1].casefold())) - {"the", "of"}
        score = 100 if base_key in target_keys else (60 if target_words and target_words <= base_words else 0)
        if str(page.get("title", "")).startswith("Lore:") and topic["category"] in {"cultures", "figures", "history", "lore", "races", "religion"}:
            score += 5
        return score

    ranked = sorted(response.json().get("query", {}).get("pages", []), key=page_score, reverse=True)
    selected_pages: list[dict[str, Any]] = []
    for page in ranked:
        extract = re.sub(r"\s+", " ", str(page.get("extract", ""))).strip()[:8000]
        if page.get("missing") or page_score(page) < 60:
            continue
        if len(extract) < 80:
            parsed = session.get(UESP_API_URL, params={
                "action": "parse", "page": page.get("title"), "prop": "text",
                "format": "json", "formatversion": 2,
            }, timeout=30)
            parsed.raise_for_status()
            markup = str(parsed.json().get("parse", {}).get("text", ""))
            markup = re.sub(r"<(script|style)[^>]*>.*?</\1>", " ", markup, flags=re.IGNORECASE | re.DOTALL)
            extract = html.unescape(re.sub(r"\s+", " ", re.sub(r"<[^>]+>", " ", markup))).strip()[:8000]
        if len(extract) < 80:
            continue
        revision = (page.get("revisions") or [{}])[0]
        selected_pages.append({
            "title": page.get("title"), "page_id": page.get("pageid"), "revision_id": revision.get("revid"),
            "revision_timestamp": revision.get("timestamp"), "url": page.get("fullurl"),
            "license": "UESP content; see source page for license and attribution", "evidence": extract,
        })
        if len(selected_pages) == 2:
            break
    if not selected_pages:
        result = {"status": "not-found", "queries": queries, "pages": []}
    else:
        result = {"status": "found", "queries": queries, "pages": selected_pages}
    atomic_json(cache_path, result)
    return result


def build_evidence(topic: dict[str, Any], uesp: dict[str, Any], dialogue: dict[str, Any] | None = None) -> str:
    lines = [
        f"canonical_topic: {topic['topic']}", f"title: {topic['title']}",
        f"required_category: {topic['category']}", f"access_profile: {topic['profile']}",
        "seed_aliases: " + ", ".join(topic.get("aliases", [])),
        "seed_classes: " + ", ".join(topic.get("classes", [])),
    ]
    if topic.get("domain_instructions"):
        lines.append("domain_instructions: " + str(topic["domain_instructions"]))
    lines.append("basic_knowledge_mode: " + str(topic.get("basic_mode", "auto")))
    for fact in topic.get("evidence_facts", []):
        lines.append("locked_evidence_fact: " + str(fact))
    if topic.get("required_phrases"):
        lines.append("required_advanced_phrases: " + " | ".join(str(value) for value in topic["required_phrases"]))
    if topic.get("forbidden_phrases"):
        lines.append("unsupported_phrases_forbidden_in_prose: " + " | ".join(str(value) for value in topic["forbidden_phrases"]))
    for record in topic.get("resolved_records", []):
        lines.append("official_record: " + json.dumps(record, ensure_ascii=False, sort_keys=True))
    book_budget = 24000
    for book in topic.get("resolved_book_sources", []):
        source_text = re.sub(r"<[^>]+>", " ", str(book.get("source_text", "")))
        source_text = re.sub(r"\s+", " ", source_text).strip()
        search_terms = unique_strings([topic["title"], *topic.get("aliases", [])])
        positions = [source_text.casefold().find(term.casefold()) for term in search_terms if len(term) >= 3]
        position = next((value for value in positions if value >= 0), 0)
        allowance = min(12000, book_budget)
        start = max(0, position - allowance // 3)
        excerpt = source_text[start:start + allowance]
        book_budget -= len(excerpt)
        lines.extend([
            "official_book: " + json.dumps({
                key: value for key, value in book.items() if key != "source_text"
            }, ensure_ascii=False, sort_keys=True),
            "official_book_evidence:",
            excerpt,
        ])
        if book_budget <= 0:
            break
    if dialogue is not None:
        lines.append("official_dialogue_sources: " + ", ".join(dialogue.get("sources", [])))
        lines.append(f"official_dialogue_response_count: {dialogue.get('response_count', 0)}")
        for response in dialogue.get("responses", [])[:16]:
            lines.append("official_dialogue_response: " + str(response))
    if uesp.get("status") == "found":
        for page in uesp["pages"]:
            lines.extend([
                f"uesp_page: {page.get('title')}", f"uesp_revision_id: {page.get('revision_id')}",
                "uesp_evidence:", str(page.get("evidence", "")),
            ])
    return "\n".join(lines)


def extract_json_object(content: Any) -> dict[str, Any]:
    text = str(content or "").strip()
    if text.startswith("```"):
        text = re.sub(r"^```(?:json)?\s*|\s*```$", "", text, flags=re.IGNORECASE | re.DOTALL)
    start, end = text.find("{"), text.rfind("}")
    if start < 0 or end < start:
        raise ValueError("provider response contains no JSON object")
    value = json.loads(text[start:end + 1])
    if not isinstance(value, dict):
        raise ValueError("provider response is not a JSON object")
    return value


class ProviderResponseError(ValueError):
    """Preserve billable usage when a successful provider response cannot be accepted."""

    def __init__(self, message: str, usage: dict[str, Any]) -> None:
        super().__init__(message)
        self.usage = usage


def provider_call(session: requests.Session, api_key: str, model: str, evidence: str, timeout: float,
                  repair: str, max_output_tokens: int) -> tuple[dict[str, Any], dict[str, Any]]:
    user = evidence + ("\n\nREWRITE THE ENTIRE JSON OBJECT TO REPAIR THESE VALIDATION ERRORS:\n" + repair if repair else "")
    started = time.monotonic()
    response = session.post(OPENROUTER_URL, headers={
        "Authorization": f"Bearer {api_key}", "Content-Type": "application/json",
        "HTTP-Referer": "https://dwemerdynamics.com/", "X-Title": "LORKHAN Morrowind Oghma Generator",
    }, json={
        "model": model, "temperature": 0.0, "max_tokens": max_output_tokens, "reasoning": {"effort": "none"},
        "messages": [{"role": "system", "content": SYSTEM_PROMPT}, {"role": "user", "content": user}],
        "response_format": {"type": "json_schema", "json_schema": {"name": "morrowind_oghma_article", "strict": True, "schema": ARTICLE_SCHEMA}},
    }, timeout=timeout)
    elapsed = time.monotonic() - started
    response.raise_for_status()
    payload = response.json()
    choice = payload["choices"][0]
    message = choice["message"]["content"]
    usage = payload.get("usage") or {}
    usage_record = {
        "elapsed_seconds": elapsed, "prompt_tokens": usage.get("prompt_tokens"),
        "completion_tokens": usage.get("completion_tokens"), "total_tokens": usage.get("total_tokens"),
        "cost": usage.get("cost"), "provider": payload.get("provider"), "model": payload.get("model") or model,
    }
    if choice.get("finish_reason") == "length":
        raise ProviderResponseError("provider response ended at the output-token limit", usage_record)
    try:
        generated = extract_json_object(message)
    except (ValueError, json.JSONDecodeError) as error:
        raise ProviderResponseError(str(error), usage_record) from error
    return generated, usage_record


def article_classes(topic: dict[str, Any], ontology: dict[str, Any], generated: dict[str, Any], field: str) -> list[str]:
    allowed = set(ontology["knowledge_classes"])
    profile = ontology["profiles"][topic["profile"]]
    defaults = list(profile["advanced" if field == "knowledge_class" else "basic"])
    if field == "knowledge_class":
        defaults.extend(topic.get("classes", []))
    result = unique_strings(defaults)
    return [value for value in result if value in allowed]


def normalize_article(topic: dict[str, Any], ontology: dict[str, Any], generated: dict[str, Any]) -> dict[str, Any]:
    aliases = unique_strings(topic.get("aliases", []))
    canonical_keys = {
        re.sub(r"[^a-z0-9]+", "", str(topic[field]).casefold()) for field in ("topic", "title")
    }
    aliases = [value for value in aliases if re.sub(r"[^a-z0-9]+", "", value.casefold()) not in canonical_keys]
    advanced_classes = article_classes(topic, ontology, generated, "knowledge_class")
    if not advanced_classes:
        advanced_classes = [{
            "alchemy": "alchemist", "creatures": "hunter", "diseases": "healer",
            "equipment": "blacksmith", "ingredients": "alchemist",
        }.get(topic["category"], "scholar")]
    basic_desc = re.sub(r"\s+", " ", str(generated.get("topic_desc_basic", ""))).strip()
    basic_classes = article_classes(topic, ontology, generated, "knowledge_class_basic") if basic_desc else []
    if topic.get("basic_mode") == "common" and basic_desc:
        # Common-mode access is a formatter contract, not a profile-specific choice.
        basic_classes = ["common"]
    if topic.get("basic_mode") == "unknown" and "common" not in advanced_classes:
        basic_desc = f"You do not know where {topic['title']} is."
        basic_classes = ["common"]
    basic_classes = [value for value in basic_classes if value not in set(advanced_classes)]
    article = {
        "topic": topic["topic"], "title": topic["title"],
        "topic_desc": re.sub(r"\s+", " ", str(generated.get("topic_desc", ""))).strip(),
        "knowledge_class": advanced_classes,
        "topic_desc_basic": basic_desc,
        "knowledge_class_basic": basic_classes,
        "tags": [
            value for value in unique_strings(generated.get("tags", []))
            if not TAG_FORBIDDEN.search(value)
        ], "category": topic["category"],
        "aliases": aliases[: int(ontology["prose"]["max_aliases"])],
        "record_links": topic.get("resolved_records", []),
    }
    if topic.get("mod_source"):
        article["mod_source"] = topic["mod_source"]
    return article


def validate_article(article: dict[str, Any], topic: dict[str, Any], ontology: dict[str, Any]) -> list[str]:
    errors: list[str] = []
    prose = ontology["prose"]
    advanced_words = word_count(article["topic_desc"])
    basic_words = word_count(article["topic_desc_basic"])
    if advanced_words < 1 or advanced_words > prose["advanced_max_words"]:
        errors.append(f"advanced article has {advanced_words} words")
    prose_key = re.sub(r"[^a-z0-9]+", "", article["topic_desc"].casefold())
    title_key = re.sub(r"[^a-z0-9]+", "", str(topic["title"]).casefold())
    if prose_key == title_key:
        errors.append("advanced article only repeats the title")
    if basic_words > prose["basic_max_words"]:
        errors.append(f"basic article has {basic_words} words")
    for field in ("topic_desc", "topic_desc_basic"):
        if "\ufffd" in article[field]:
            errors.append(f"{field} contains a replacement character")
        if re.search(
            r"\b(?:canonical_topic|required_category|access_profile|seed_aliases|seed_classes|"
            r"domain_instructions|basic_knowledge_mode|official_record|uesp_page|uesp_evidence)\b",
            article[field], re.IGNORECASE,
        ) or re.fullmatch(r"[a-z0-9]+(?:_[a-z0-9]+)+", article[field].strip(), re.IGNORECASE):
            errors.append(f"{field} contains leaked prompt or topic identifiers")
        if FORBIDDEN.search(article[field]):
            errors.append(f"{field} contains forbidden out-of-world language")
        if POST_GAME.search(article[field]):
            errors.append(f"{field} contains knowledge after the 3E 427 start-of-game baseline")
    advanced_normalized = re.sub(r"\s+", " ", article["topic_desc"].casefold()).strip()
    basic_normalized = re.sub(r"\s+", " ", article["topic_desc_basic"].casefold()).strip()
    if basic_normalized and SequenceMatcher(None, advanced_normalized, basic_normalized).ratio() >= 0.9:
        errors.append("basic article is too close to the advanced article")
    if article["category"] != topic["category"]:
        errors.append("category changed from locked inventory")
    if article.get("mod_source") != topic.get("mod_source"):
        errors.append("mod_source changed from locked inventory")
    if len(article["aliases"]) > int(prose["max_aliases"]):
        errors.append("alias count is outside the ontology bounds")
    allowed = set(ontology["knowledge_classes"])
    if not article["knowledge_class"] or any(value not in allowed for value in article["knowledge_class"]):
        errors.append("knowledge_class is empty or outside the ontology")
    if any(value not in allowed for value in article["knowledge_class_basic"]):
        errors.append("knowledge_class_basic is outside the ontology")
    if bool(article["topic_desc_basic"]) != bool(article["knowledge_class_basic"]):
        errors.append("basic prose and basic classes must either both be present or both be empty")
    if topic.get("basic_mode") == "common" and (
        not article["topic_desc_basic"] or article["knowledge_class_basic"] != ["common"]
    ):
        errors.append("common basic_mode requires factual basic prose assigned only to common")
    overlap = set(article["knowledge_class"]) & set(article["knowledge_class_basic"])
    if overlap:
        errors.append("advanced and basic classes overlap: " + ", ".join(sorted(overlap)))
    if not prose["min_tags"] <= len(article["tags"]) <= prose["max_tags"]:
        errors.append("tag count is outside the ontology bounds")
    if any(TAG_FORBIDDEN.search(value) for value in article["tags"]):
        errors.append("tags contain forbidden out-of-world language")
    advanced_folded = article["topic_desc"].casefold()
    for phrase in topic.get("required_phrases", []):
        if str(phrase).casefold() not in advanced_folded:
            errors.append(f"advanced article is missing required phrase: {phrase}")
    all_prose = f"{article['topic_desc']} {article['topic_desc_basic']}".casefold()
    for phrase in topic.get("forbidden_phrases", []):
        if str(phrase).casefold() in all_prose:
            errors.append(f"article contains unsupported phrase: {phrase}")
    return errors


def record_dir(run_dir: Path, topic: str) -> Path:
    return run_dir / "records" / topic


def valid_result(path: Path, topic: dict[str, Any], ontology: dict[str, Any]) -> tuple[bool, str]:
    try:
        document = read_json(path)
        if (document.get("status") != "complete" or document.get("topic") != topic["topic"]
                or document.get("generation_ruleset") != GENERATION_RULESET):
            return False, "status or topic mismatch"
        errors = validate_article(document["article"], topic, ontology)
        return (not errors), "; ".join(errors)
    except Exception as error:
        return False, str(error)


def attempt_cost(run_dir: Path) -> float:
    total = 0.0
    for path in (run_dir / "records").glob("*/attempts.json") if (run_dir / "records").is_dir() else []:
        try:
            for row in read_json(path).get("attempts", []):
                total += float((row.get("usage") or {}).get("cost") or 0.0)
        except Exception:
            continue
    return total


def append_attempt(path: Path, row: dict[str, Any]) -> None:
    payload = {"format": FORMAT_VERSION, "attempts": []}
    if path.is_file():
        payload = read_json(path)
    payload.setdefault("attempts", []).append(row)
    atomic_json(path, payload)


def write_combined(run_dir: Path, selected: list[dict[str, Any]], manifest: dict[str, Any]) -> None:
    rows: list[dict[str, Any]] = []
    for topic in selected:
        path = record_dir(run_dir, topic["topic"]) / "result.json"
        if path.is_file():
            document = read_json(path)
            if document.get("status") == "complete":
                rows.append(document["article"])
    combined = run_dir / "combined"
    atomic_json(combined / "articles.json", rows)
    columns = ["topic", "aliases", "topic_desc", "knowledge_class", "topic_desc_basic", "knowledge_class_basic", "tags", "category", "mod_source"]
    csv_path = combined / "oghma.csv.tmp"
    csv_path.parent.mkdir(parents=True, exist_ok=True)
    with csv_path.open("w", encoding="utf-8-sig", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=columns)
        writer.writeheader()
        for row in rows:
            writer.writerow({key: ", ".join(row[key]) if isinstance(row.get(key), list) else row.get(key, "") for key in columns})
    os.replace(csv_path, combined / "oghma.csv")
    table_rows = []
    markdown = ["| Topic | Category | Advanced | Basic | Classes |", "|---|---|---|---|---|"]
    for row in rows:
        table_rows.append("<tr>" + "".join(f"<td>{html.escape(str(value))}</td>" for value in [row["topic"], row["category"], row["topic_desc"], row["topic_desc_basic"], ", ".join(row["knowledge_class"])]) + "</tr>")
        markdown.append("| " + " | ".join(str(value).replace("|", "\\|") for value in [row["topic"], row["category"], row["topic_desc"], row["topic_desc_basic"], ", ".join(row["knowledge_class"])]) + " |")
    atomic_text(combined / "review.html", "<!doctype html><meta charset='utf-8'><title>Morrowind Oghma review</title><style>body{font:14px sans-serif;background:#151515;color:#eee}table{border-collapse:collapse}td,th{border:1px solid #555;padding:8px;vertical-align:top;max-width:500px}</style><h1>Morrowind Oghma review</h1><table><thead><tr><th>Topic</th><th>Category</th><th>Advanced</th><th>Basic</th><th>Classes</th></tr></thead><tbody>" + "".join(table_rows) + "</tbody></table>")
    atomic_text(combined / "review.md", "\n".join(markdown) + "\n")
    atomic_json(run_dir / "manifest.json", manifest)


def build_manifest(run_dir: Path, selected: list[dict[str, Any]], hashes: dict[str, str], ontology_sha: str, seeds_sha: str, model: str, max_cost: float | None, reserve: float) -> dict[str, Any]:
    items = []
    complete = evidence_complete = failed = 0
    for topic in selected:
        directory = record_dir(run_dir, topic["topic"])
        evidence = directory / "evidence.json"
        result = directory / "result.json"
        status = "pending"
        if evidence.is_file():
            evidence_complete += 1
            status = "evidence"
        if result.is_file():
            document = read_json(result)
            status = str(document.get("status", "failed"))
            if status == "complete":
                complete += 1
            else:
                failed += 1
        items.append({"topic": topic["topic"], "title": topic["title"], "category": topic["category"],
                      "mod_source": topic.get("mod_source"), "status": status})
    spent = attempt_cost(run_dir)
    untracked_provider_responses = 0
    for path in (run_dir / "records").glob("*/attempts.json") if (run_dir / "records").is_dir() else []:
        for attempt in read_json(path).get("attempts", []):
            errors = "; ".join(str(value) for value in attempt.get("errors", []))
            if not (attempt.get("usage") or {}).get("cost") and "provider response contains no JSON object" in errors:
                untracked_provider_responses += 1
    return {
        "format": FORMAT_VERSION, "updated_at_utc": utc_timestamp(), "model": model,
        "prompt_sha256": hashlib.sha256(SYSTEM_PROMPT.encode("utf-8")).hexdigest(),
        "ontology_sha256": ontology_sha, "topic_seeds_sha256": seeds_sha,
        "official_content_sha256": hashes, "selected_count": len(selected),
        "evidence_completed_count": evidence_complete, "completed_count": complete,
        "failed_count": failed, "pending_count": len(selected) - complete - failed,
        "usage": {
            "recorded_cost": spent,
            "accounting_complete": untracked_provider_responses == 0,
            "untracked_provider_response_count": untracked_provider_responses,
        },
        "budget": None if max_cost is None else {"limit": max_cost, "reserve": reserve, "spent": spent, "next_call_allowed": spent < max_cost - reserve},
        "items": items,
    }


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--run-dir", type=Path, required=True)
    parser.add_argument("--data-dir", type=Path, default=DEFAULT_DATA_DIR)
    parser.add_argument("--content-file", action="append", default=[],
                        help="Content filename in OpenMW load order; repeatable. Defaults to the three official masters.")
    parser.add_argument("--seeds", type=Path, default=DEFAULT_SEEDS)
    parser.add_argument("--ontology", type=Path, default=DEFAULT_ONTOLOGY)
    parser.add_argument("--exclude-selection", type=Path)
    parser.add_argument("--size", type=int, default=50)
    parser.add_argument("--model", default=DEFAULT_MODEL)
    parser.add_argument("--api-key-env", default="OPENROUTER_API_KEY")
    parser.add_argument("--cache-dir", type=Path, default=Path(os.environ.get("LOCALAPPDATA", Path.home())) / "LORKHAN" / "oghma-uesp-cache")
    parser.add_argument("--refresh-uesp-cache", action="store_true")
    parser.add_argument("--skip-uesp", action="store_true",
                        help="Skip UESP evidence acquisition, including revision-pinned seed pages.")
    parser.add_argument("--evidence-only", action="store_true")
    parser.add_argument("--resume", action="store_true")
    parser.add_argument("--request-timeout", type=float, default=120.0)
    parser.add_argument("--max-output-tokens", type=int, default=1800)
    parser.add_argument("--max-cost", type=float)
    parser.add_argument("--budget-reserve", type=float, default=0.10)
    parser.add_argument("--checkpoint-every", type=int, default=10)
    parser.add_argument("--workers", type=int, default=1,
                        help="Concurrent evidence/provider workers. Each topic remains independently checkpointed.")
    args = parser.parse_args()
    if args.max_cost is not None and args.budget_reserve >= args.max_cost:
        parser.error("--budget-reserve must be lower than --max-cost")
    if args.workers < 1 or args.workers > 16:
        parser.error("--workers must be between 1 and 16")
    if args.max_output_tokens < 512 or args.max_output_tokens > 8192:
        parser.error("--max-output-tokens must be between 512 and 8192")
    content_files = tuple(args.content_file or CONTENT_FILES)
    if len({value.casefold() for value in content_files}) != len(content_files):
        parser.error("--content-file values must be unique")
    args.content_files = content_files
    return args


def main() -> int:
    args = parse_args()
    lock = acquire_run_lock(args.run_dir)
    try:
        ontology_raw = args.ontology.read_bytes()
        seeds_raw = args.seeds.read_bytes()
        ontology = read_json(args.ontology)
        records, hashes = extract_records(args.data_dir, args.content_files)
        dialogue_topics = extract_dialogue_evidence(args.data_dir, args.content_files)
        topics = validate_seed_document(read_json(args.seeds), ontology, records)
        excluded = excluded_topics(args.exclude_selection)
        available_topics = [topic for topic in topics if topic["topic"] not in excluded]
        unknown_exclusions = excluded - {topic["topic"] for topic in topics}
        if unknown_exclusions:
            raise ValueError("Excluded selection contains topics outside the current inventory: " + ", ".join(sorted(unknown_exclusions)))
        selection_path = args.run_dir / "selection.json"
        ontology_sha = hashlib.sha256(ontology_raw).hexdigest()
        seeds_sha = hashlib.sha256(seeds_raw).hexdigest()
        if selection_path.is_file():
            locked = read_json(selection_path)
            if locked.get("official_content_sha256") != hashes or locked.get("ontology_sha256") != ontology_sha or locked.get("topic_seeds_sha256") != seeds_sha:
                raise ValueError("Existing Oghma selection was locked against different inputs")
            selected = locked.get("selection")
            if not isinstance(selected, list) or len(selected) != args.size:
                raise ValueError("Existing Oghma selection does not match --size")
        else:
            selected = stable_selection(available_topics, args.size)
            atomic_json(selection_path, selection_document(selected, hashes, ontology_sha, seeds_sha))
        print(f"[inventory] curated={len(topics)} excluded={len(excluded)} selected={len(selected)} official_records={len(records)}", flush=True)
        api_key = os.environ.get(args.api_key_env, "").strip()
        if not args.evidence_only and not api_key:
            raise ValueError(f"{args.api_key_env} is required unless --evidence-only is used")
        budget_lock = threading.Lock()
        budget_stopped = threading.Event()
        spent = attempt_cost(args.run_dir)

        # Preserve exact usage accounting and serialize provider calls only when a hard budget is active.
        def provider_with_budget(session: requests.Session, evidence: str, repair: str) -> tuple[dict[str, Any], dict[str, Any]] | None:
            nonlocal spent

            def request() -> tuple[dict[str, Any], dict[str, Any]]:
                return provider_call(
                    session, api_key, args.model, evidence, args.request_timeout,
                    repair, args.max_output_tokens,
                )

            if args.max_cost is not None:
                with budget_lock:
                    if spent >= args.max_cost - args.budget_reserve:
                        budget_stopped.set()
                        return None
                    try:
                        generated, usage = request()
                    except ProviderResponseError as error:
                        spent += float(error.usage.get("cost") or 0.0)
                        raise
                    spent += float(usage.get("cost") or 0.0)
                    return generated, usage
            try:
                generated, usage = request()
            except ProviderResponseError as error:
                with budget_lock:
                    spent += float(error.usage.get("cost") or 0.0)
                raise
            with budget_lock:
                spent += float(usage.get("cost") or 0.0)
            return generated, usage

        # Process one independently checkpointed topic so large catalogs can use bounded parallel provider calls.
        def process_topic(index: int, topic: dict[str, Any]) -> str:
            nonlocal spent
            if budget_stopped.is_set():
                return "budget-stop"
            session = requests.Session()
            session.headers.update({"User-Agent": "LORKHAN-Oghma-Generator/1.0 (https://dwemerdynamics.com/)"})
            directory = record_dir(args.run_dir, topic["topic"])
            directory.mkdir(parents=True, exist_ok=True)
            evidence_path = directory / "evidence.json"
            if evidence_path.is_file() and args.resume and not args.refresh_uesp_cache:
                evidence_document = read_json(evidence_path)
                uesp = evidence_document["uesp"]
                evidence = evidence_document["evidence"]
            else:
                uesp = ({"status": "skipped", "pages": []} if args.skip_uesp
                        else uesp_search(session, topic, args.cache_dir, args.refresh_uesp_cache))
                dialogue_key = re.sub(r"[^a-z0-9]+", "", str(topic["title"]).casefold())
                dialogue = None if topic.get("include_dialogue_evidence") is False else dialogue_topics.get(dialogue_key)
                evidence = build_evidence(topic, uesp, dialogue)
                evidence_document = {"format": FORMAT_VERSION, "topic": topic["topic"], "identity": topic, "official_dialogue": dialogue, "uesp": uesp, "evidence": evidence, "evidence_sha256": hashlib.sha256(evidence.encode("utf-8")).hexdigest()}
                atomic_json(evidence_path, evidence_document)
            print(f"[evidence] {index}/{len(selected)} {topic['topic']}: {uesp.get('status')}", flush=True)
            if args.evidence_only:
                return "evidence"
            result_path = directory / "result.json"
            valid, _ = valid_result(result_path, topic, ontology) if result_path.is_file() else (False, "missing")
            if args.resume and valid:
                print(f"[skip] {topic['topic']}: checkpointed", flush=True)
                return "skipped"
            errors: list[str] = []
            for attempt in range(1, 4):
                try:
                    response = provider_with_budget(session, evidence, "; ".join(errors))
                    if response is None:
                        print(f"[stop] provider budget reserve reached before {topic['topic']}", flush=True)
                        return "budget-stop"
                    generated, usage = response
                    article = normalize_article(topic, ontology, generated)
                    errors = validate_article(article, topic, ontology)
                    append_attempt(directory / "attempts.json", {"attempt": attempt, "created_at_utc": utc_timestamp(), "usage": usage, "errors": errors, "candidate": generated})
                    if not errors:
                        atomic_json(result_path, {"format": FORMAT_VERSION, "generation_ruleset": GENERATION_RULESET, "status": "complete", "topic": topic["topic"], "article": article, "source": evidence_document, "model": args.model})
                        print(f"[complete] {topic['topic']}: advanced={word_count(article['topic_desc'])} basic={word_count(article['topic_desc_basic'])}", flush=True)
                        return "complete"
                except ProviderResponseError as error:
                    errors = [str(error)]
                    append_attempt(directory / "attempts.json", {"attempt": attempt, "created_at_utc": utc_timestamp(), "usage": error.usage, "errors": errors})
                except Exception as error:
                    errors = [str(error)]
                    append_attempt(directory / "attempts.json", {"attempt": attempt, "created_at_utc": utc_timestamp(), "usage": {}, "errors": errors})
            atomic_json(result_path, {"format": FORMAT_VERSION, "status": "rejected", "topic": topic["topic"], "errors": errors})
            print(f"[quarantine] {topic['topic']}: {'; '.join(errors)}", flush=True)
            return "rejected"

        processed = 0
        with ThreadPoolExecutor(max_workers=args.workers) as executor:
            futures = {
                executor.submit(process_topic, index, topic): topic
                for index, topic in enumerate(selected, 1)
            }
            for future in as_completed(futures):
                future.result()
                processed += 1
                if processed % args.checkpoint_every == 0:
                    manifest = build_manifest(args.run_dir, selected, hashes, ontology_sha, seeds_sha, args.model, args.max_cost, args.budget_reserve)
                    write_combined(args.run_dir, selected, manifest)
                if budget_stopped.is_set():
                    for pending in futures:
                        pending.cancel()
                    break
        manifest = build_manifest(args.run_dir, selected, hashes, ontology_sha, seeds_sha, args.model, args.max_cost, args.budget_reserve)
        write_combined(args.run_dir, selected, manifest)
        print(f"[summary] selected={manifest['selected_count']} evidence={manifest['evidence_completed_count']} complete={manifest['completed_count']} failed={manifest['failed_count']} recorded_cost={manifest['usage']['recorded_cost']:.6f} accounting_complete={manifest['usage']['accounting_complete']}", flush=True)
        return 0 if args.evidence_only or manifest["completed_count"] == manifest["selected_count"] else 2
    finally:
        release_run_lock(lock)


if __name__ == "__main__":
    raise SystemExit(main())
