#!/usr/bin/env python3
"""Build a durable lore-first Morrowind Oghma review catalog."""

from __future__ import annotations

import argparse
import csv
import hashlib
import html
import json
import os
from pathlib import Path
import re
import struct
import time
from typing import Any, Iterable
from urllib.parse import quote

import requests


FORMAT_VERSION = "almsivi.morrowind-oghma-preflight.v1"
GENERATION_RULESET = "morrowind-oghma-static-3e427-v2"
DEFAULT_DATA_DIR = Path(r"C:\Program Files (x86)\Steam\steamapps\common\Morrowind\Data Files")
DEFAULT_MODEL = "z-ai/glm-5.1"
OPENROUTER_URL = "https://openrouter.ai/api/v1/chat/completions"
UESP_API_URL = "https://en.uesp.net/w/api.php"
CONTENT_FILES = ("Morrowind.esm", "Tribunal.esm", "Bloodmoon.esm")
SCRIPT_DIR = Path(__file__).resolve().parent
RESOURCE_DIR = SCRIPT_DIR.parent / "resources" / "oghma" / "morrowind-official"
DEFAULT_SEEDS = RESOURCE_DIR / "topic-seeds.json"
DEFAULT_ONTOLOGY = RESOURCE_DIR / "ontology.json"
RECORD_TYPES = {b"NPC_", b"CREA", b"WEAP", b"ARMO", b"CLOT", b"MISC", b"BOOK"}

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
If official dialogue is tied to an errand or dispute, extract only stable encyclopedic knowledge. Never narrate a
one-time request, theft, commercial scheme, investigation, missing person, delivery, payment, current plan, or its
ordinary participants. Do not name ordinary NPCs unless the locked subject itself is a reviewed major figure.

The advanced article must explain the subject's identity, significance, and stable context in 55-150 words. The
basic article must be a separately written 18-65 word account containing only broadly available knowledge; aim for
35-55 words so it remains safely inside that hard limit. It must
not be a clipped copy of the advanced article. Return useful search aliases only when supported. Return 2-12 concise
search tags. Select knowledge access classes only from the supplied allowlist, keeping the seed classes unless the
evidence clearly supports an additional class. Select exactly the supplied category.

Always return full prose in both description fields, including on a repair. Never return null, None, a refusal,
an apology, or a placeholder. Avoid every forbidden out-of-world word literally, including the word game.
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
    r"\b(?:video\s+game|player(?:s|'s)?|quest(?:line)?|form\s*id|game\s+file|database|wiki|UESP|prompt|"
    r"language\s+model|game\s+mechanics?|character\s+level|stat(?:istic)?s?|hit\s+points?|armor\s+rating|"
    r"inventory\s+(?:menu|screen)|walkthrough)\b",
    re.IGNORECASE,
)
POST_GAME = re.compile(
    r"\b(?:4E\s*\d*|Fourth\s+Era|Red\s+Year|New\s+Temple|House\s+Sadras|"
    r"Tribunal(?:'s)?\s+(?:fall|collapse|dissolution)|recalled?\s+(?:him\s+)?to\s+the\s+Imperial\s+City)\b",
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


def extract_records(data_dir: Path) -> tuple[dict[str, dict[str, Any]], dict[str, str]]:
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
                winners[key] = {
                    "content_file": content_file,
                    "record_type": record_type.decode("ascii"),
                    "record_id": record_id,
                    "display_name": decode_text(fields.get(b"FNAM", b"")) or record_id,
                }
        if position != len(raw):
            raise ValueError(f"TES3 file ended with an incomplete record header: {path}")
    return winners, hashes


# Dialogue responses are first-party evidence for expansion topics that do not have a dedicated UESP page.
def extract_dialogue_evidence(data_dir: Path) -> dict[str, dict[str, Any]]:
    topics: dict[str, dict[str, Any]] = {}
    winning_responses: dict[str, tuple[str, str, str]] = {}
    for content_file in CONTENT_FILES:
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
            "sources": sorted(row["sources"], key=CONTENT_FILES.index),
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
        resolved.append(record)
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
        row["aliases"] = aliases
        row["classes"] = classes
        row["resolved_records"] = resolve_topic_links(row, records)
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
        return read_json(cache_path)
    queries = [f"Morrowind:{topic['title']}", f"Lore:{topic['title']}"]
    explicit_titles = set(topic.get("uesp_titles", []))
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
    for record in topic.get("resolved_records", []):
        lines.append("official_record: " + json.dumps(record, ensure_ascii=False, sort_keys=True))
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


def provider_call(session: requests.Session, api_key: str, model: str, evidence: str, timeout: float, repair: str) -> tuple[dict[str, Any], dict[str, Any]]:
    user = evidence + ("\n\nREWRITE THE ENTIRE JSON OBJECT TO REPAIR THESE VALIDATION ERRORS:\n" + repair if repair else "")
    started = time.monotonic()
    response = session.post(OPENROUTER_URL, headers={
        "Authorization": f"Bearer {api_key}", "Content-Type": "application/json",
        "HTTP-Referer": "https://dwemerdynamics.com/", "X-Title": "ALMSIVI Morrowind Oghma Generator",
    }, json={
        "model": model, "temperature": 0.0, "max_tokens": 1800, "reasoning": {"effort": "none"},
        "messages": [{"role": "system", "content": SYSTEM_PROMPT}, {"role": "user", "content": user}],
        "response_format": {"type": "json_schema", "json_schema": {"name": "morrowind_oghma_article", "strict": True, "schema": ARTICLE_SCHEMA}},
    }, timeout=timeout)
    elapsed = time.monotonic() - started
    response.raise_for_status()
    payload = response.json()
    message = payload["choices"][0]["message"]["content"]
    usage = payload.get("usage") or {}
    return extract_json_object(message), {
        "elapsed_seconds": elapsed, "prompt_tokens": usage.get("prompt_tokens"),
        "completion_tokens": usage.get("completion_tokens"), "total_tokens": usage.get("total_tokens"),
        "cost": usage.get("cost"), "provider": payload.get("provider"), "model": payload.get("model") or model,
    }


def article_classes(topic: dict[str, Any], ontology: dict[str, Any], generated: dict[str, Any], field: str) -> list[str]:
    allowed = set(ontology["knowledge_classes"])
    profile = ontology["profiles"][topic["profile"]]
    defaults = list(profile["advanced" if field == "knowledge_class" else "basic"])
    if field == "knowledge_class":
        defaults.extend(topic.get("classes", []))
    values = unique_strings(generated.get(field, []))
    result = unique_strings(defaults + values)
    return [value for value in result if value in allowed]


def normalize_article(topic: dict[str, Any], ontology: dict[str, Any], generated: dict[str, Any]) -> dict[str, Any]:
    aliases = unique_strings([*topic.get("aliases", []), *generated.get("aliases", [])])
    canonical_keys = {
        re.sub(r"[^a-z0-9]+", "", str(topic[field]).casefold()) for field in ("topic", "title")
    }
    aliases = [value for value in aliases if re.sub(r"[^a-z0-9]+", "", value.casefold()) not in canonical_keys]
    return {
        "topic": topic["topic"], "title": topic["title"],
        "topic_desc": re.sub(r"\s+", " ", str(generated.get("topic_desc", ""))).strip(),
        "knowledge_class": article_classes(topic, ontology, generated, "knowledge_class"),
        "topic_desc_basic": re.sub(r"\s+", " ", str(generated.get("topic_desc_basic", ""))).strip(),
        "knowledge_class_basic": article_classes(topic, ontology, generated, "knowledge_class_basic"),
        "tags": unique_strings(generated.get("tags", [])), "category": topic["category"],
        "aliases": aliases[: int(ontology["prose"]["max_aliases"])],
        "record_links": topic.get("resolved_records", []),
    }


def validate_article(article: dict[str, Any], topic: dict[str, Any], ontology: dict[str, Any]) -> list[str]:
    errors: list[str] = []
    prose = ontology["prose"]
    advanced_words = word_count(article["topic_desc"])
    basic_words = word_count(article["topic_desc_basic"])
    if not prose["advanced_min_words"] <= advanced_words <= prose["advanced_max_words"]:
        errors.append(f"advanced article has {advanced_words} words")
    if not prose["basic_min_words"] <= basic_words <= prose["basic_max_words"]:
        errors.append(f"basic article has {basic_words} words")
    for field in ("topic_desc", "topic_desc_basic"):
        if FORBIDDEN.search(article[field]):
            errors.append(f"{field} contains forbidden out-of-world language")
        if POST_GAME.search(article[field]):
            errors.append(f"{field} contains knowledge after the 3E 427 start-of-game baseline")
    advanced_terms = set(re.findall(r"[a-z0-9]+", article["topic_desc"].casefold()))
    basic_terms = set(re.findall(r"[a-z0-9]+", article["topic_desc_basic"].casefold()))
    if basic_terms and len(advanced_terms & basic_terms) / len(basic_terms) > 0.9:
        errors.append("basic article is too close to the advanced article")
    if article["category"] != topic["category"]:
        errors.append("category changed from locked inventory")
    if len(article["aliases"]) > int(prose["max_aliases"]):
        errors.append("alias count is outside the ontology bounds")
    allowed = set(ontology["knowledge_classes"])
    for field in ("knowledge_class", "knowledge_class_basic"):
        if not article[field] or any(value not in allowed for value in article[field]):
            errors.append(f"{field} is empty or outside the ontology")
    if not prose["min_tags"] <= len(article["tags"]) <= prose["max_tags"]:
        errors.append("tag count is outside the ontology bounds")
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
    columns = ["topic", "aliases", "topic_desc", "knowledge_class", "topic_desc_basic", "knowledge_class_basic", "tags", "category"]
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
        items.append({"topic": topic["topic"], "title": topic["title"], "category": topic["category"], "status": status})
    spent = attempt_cost(run_dir)
    return {
        "format": FORMAT_VERSION, "updated_at_utc": utc_timestamp(), "model": model,
        "prompt_sha256": hashlib.sha256(SYSTEM_PROMPT.encode("utf-8")).hexdigest(),
        "ontology_sha256": ontology_sha, "topic_seeds_sha256": seeds_sha,
        "official_content_sha256": hashes, "selected_count": len(selected),
        "evidence_completed_count": evidence_complete, "completed_count": complete,
        "failed_count": failed, "pending_count": len(selected) - complete - failed,
        "usage": {"cost": spent},
        "budget": None if max_cost is None else {"limit": max_cost, "reserve": reserve, "spent": spent, "next_call_allowed": spent < max_cost - reserve},
        "items": items,
    }


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--run-dir", type=Path, required=True)
    parser.add_argument("--data-dir", type=Path, default=DEFAULT_DATA_DIR)
    parser.add_argument("--seeds", type=Path, default=DEFAULT_SEEDS)
    parser.add_argument("--ontology", type=Path, default=DEFAULT_ONTOLOGY)
    parser.add_argument("--exclude-selection", type=Path)
    parser.add_argument("--size", type=int, default=50)
    parser.add_argument("--model", default=DEFAULT_MODEL)
    parser.add_argument("--api-key-env", default="OPENROUTER_API_KEY")
    parser.add_argument("--cache-dir", type=Path, default=Path(os.environ.get("LOCALAPPDATA", Path.home())) / "ALMSIVI" / "oghma-uesp-cache")
    parser.add_argument("--refresh-uesp-cache", action="store_true")
    parser.add_argument("--skip-uesp", action="store_true")
    parser.add_argument("--evidence-only", action="store_true")
    parser.add_argument("--resume", action="store_true")
    parser.add_argument("--request-timeout", type=float, default=120.0)
    parser.add_argument("--max-cost", type=float)
    parser.add_argument("--budget-reserve", type=float, default=0.10)
    parser.add_argument("--checkpoint-every", type=int, default=10)
    args = parser.parse_args()
    if args.max_cost is not None and args.budget_reserve >= args.max_cost:
        parser.error("--budget-reserve must be lower than --max-cost")
    return args


def main() -> int:
    args = parse_args()
    lock = acquire_run_lock(args.run_dir)
    try:
        ontology_raw = args.ontology.read_bytes()
        seeds_raw = args.seeds.read_bytes()
        ontology = read_json(args.ontology)
        records, hashes = extract_records(args.data_dir)
        dialogue_topics = extract_dialogue_evidence(args.data_dir)
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
        session = requests.Session()
        session.headers.update({"User-Agent": "ALMSIVI-Oghma-Generator/1.0 (https://dwemerdynamics.com/)"})
        api_key = os.environ.get(args.api_key_env, "").strip()
        if not args.evidence_only and not api_key:
            raise ValueError(f"{args.api_key_env} is required unless --evidence-only is used")
        processed = 0
        for index, topic in enumerate(selected, 1):
            directory = record_dir(args.run_dir, topic["topic"])
            directory.mkdir(parents=True, exist_ok=True)
            evidence_path = directory / "evidence.json"
            if evidence_path.is_file() and args.resume and not args.refresh_uesp_cache:
                evidence_document = read_json(evidence_path)
                uesp = evidence_document["uesp"]
                evidence = evidence_document["evidence"]
            else:
                uesp = {"status": "skipped", "page": None, "evidence": ""} if args.skip_uesp else uesp_search(session, topic, args.cache_dir, args.refresh_uesp_cache)
                dialogue_key = re.sub(r"[^a-z0-9]+", "", str(topic["title"]).casefold())
                dialogue = dialogue_topics.get(dialogue_key)
                evidence = build_evidence(topic, uesp, dialogue)
                evidence_document = {"format": FORMAT_VERSION, "topic": topic["topic"], "identity": topic, "official_dialogue": dialogue, "uesp": uesp, "evidence": evidence, "evidence_sha256": hashlib.sha256(evidence.encode("utf-8")).hexdigest()}
                atomic_json(evidence_path, evidence_document)
            print(f"[evidence] {index}/{len(selected)} {topic['topic']}: {uesp.get('status')}", flush=True)
            if args.evidence_only:
                continue
            result_path = directory / "result.json"
            valid, _ = valid_result(result_path, topic, ontology) if result_path.is_file() else (False, "missing")
            if args.resume and valid:
                print(f"[skip] {topic['topic']}: checkpointed", flush=True)
                continue
            if args.max_cost is not None and attempt_cost(args.run_dir) >= args.max_cost - args.budget_reserve:
                print(f"[stop] provider budget reserve reached before {topic['topic']}", flush=True)
                break
            errors: list[str] = []
            for attempt in range(1, 4):
                try:
                    generated, usage = provider_call(session, api_key, args.model, evidence, args.request_timeout, "; ".join(errors))
                    article = normalize_article(topic, ontology, generated)
                    errors = validate_article(article, topic, ontology)
                    append_attempt(directory / "attempts.json", {"attempt": attempt, "created_at_utc": utc_timestamp(), "usage": usage, "errors": errors, "candidate": generated})
                    if not errors:
                        atomic_json(result_path, {"format": FORMAT_VERSION, "generation_ruleset": GENERATION_RULESET, "status": "complete", "topic": topic["topic"], "article": article, "source": evidence_document, "model": args.model})
                        print(f"[complete] {topic['topic']}: advanced={word_count(article['topic_desc'])} basic={word_count(article['topic_desc_basic'])}", flush=True)
                        break
                except Exception as error:
                    errors = [str(error)]
                    append_attempt(directory / "attempts.json", {"attempt": attempt, "created_at_utc": utc_timestamp(), "usage": {}, "errors": errors})
            else:
                atomic_json(result_path, {"format": FORMAT_VERSION, "status": "rejected", "topic": topic["topic"], "errors": errors})
                print(f"[quarantine] {topic['topic']}: {'; '.join(errors)}", flush=True)
            processed += 1
            if processed % args.checkpoint_every == 0:
                manifest = build_manifest(args.run_dir, selected, hashes, ontology_sha, seeds_sha, args.model, args.max_cost, args.budget_reserve)
                write_combined(args.run_dir, selected, manifest)
        manifest = build_manifest(args.run_dir, selected, hashes, ontology_sha, seeds_sha, args.model, args.max_cost, args.budget_reserve)
        write_combined(args.run_dir, selected, manifest)
        print(f"[summary] selected={manifest['selected_count']} evidence={manifest['evidence_completed_count']} complete={manifest['completed_count']} failed={manifest['failed_count']} cost={manifest['usage']['cost']:.6f}", flush=True)
        return 0 if args.evidence_only or manifest["completed_count"] == manifest["selected_count"] else 2
    finally:
        release_run_lock(lock)


if __name__ == "__main__":
    raise SystemExit(main())
