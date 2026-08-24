#!/usr/bin/env python3
"""Build reviewable CHIM-shaped biographies from official Morrowind NPC records."""

from __future__ import annotations

import argparse
import hashlib
import json
import os
import re
import struct
import sys
import time
from collections.abc import Iterable
from pathlib import Path
from typing import Any
from urllib.parse import quote

import requests
from bs4 import BeautifulSoup


DEFAULT_DATA_DIR = Path(r"C:\Program Files (x86)\Steam\steamapps\common\Morrowind\Data Files")
DEFAULT_MODEL = "z-ai/glm-5.1"
OPENROUTER_URL = "https://openrouter.ai/api/v1/chat/completions"
UESP_API_URL = "https://en.uesp.net/w/api.php"
CONTENT_FILES = ("Morrowind.esm", "Tribunal.esm", "Bloodmoon.esm")
NPC_FLAGS = {"female": 0x01, "essential": 0x02, "respawn": 0x04, "base": 0x08, "autocalc": 0x10}
RELATIONSHIP_TYPES = (
    "romantic", "platonic", "familial", "professional", "rival", "enemy", "neutral", "nemesis",
    "estranged", "transactional", "protective", "indebted", "fanatical", "mentor", "student",
    "servant", "client", "patron", "crush", "ex", "betrayed", "suspicious", "admirer", "jealous",
    "fearful", "obsessed", "awed", "contempt", "pitying", "grateful", "curious", "dismissive",
)
NEGATIVE_RELATIONSHIP_TYPES = {
    "rival", "enemy", "nemesis", "betrayed", "suspicious", "jealous", "fearful", "contempt", "dismissive",
}
FRIENDSHIP_RELATIONSHIP_TYPES = {"platonic", "romantic", "familial", "protective", "grateful"}
CAPTIVITY_INCOMPATIBLE_TYPES = {
    "romantic", "platonic", "familial", "protective", "grateful", "crush", "admirer", "mentor", "student",
}
CAPTIVITY_PATTERN = re.compile(r"\b(?:captor|captive|hostage|kidnapp\w*|ransom)\b", re.IGNORECASE)

CHIM_COLUMNS = (
    "npc_name", "oghma_knowledge_tags", "core", "npc_static_bio", "appearance", "personality",
    "relationships", "occupation", "skills", "speechstyle", "goals", "voiceid", "gender", "race", "refid",
)
GENERATED_FIELDS = (
    "oghma_knowledge_tags", "core", "npc_static_bio", "appearance", "personality",
    "relationships", "occupation", "skills", "speechstyle", "goals",
)
TEXT_LIMITS = {
    "oghma_knowledge_tags": 500, "core": 400, "npc_static_bio": 1200, "appearance": 800,
    "personality": 900, "relationships": 1600, "occupation": 700, "skills": 1000,
    "speechstyle": 700, "goals": 800,
}
STYLE_WORD_BOUNDS = {
    "core": (14, 34), "npc_static_bio": (28, 95), "appearance": (20, 65),
    "personality": (22, 75), "occupation": (18, 60), "speechstyle": (15, 55),
}
STYLE_SENTENCE_BOUNDS = {
    "core": (1, 1), "npc_static_bio": (2, 3), "appearance": (2, 3),
    "personality": (2, 3), "occupation": (1, 2), "speechstyle": (1, 2),
}
SKILL_MECHANIC_PATTERN = re.compile(
    r"\b(?:innate|racial|natural affinity|natural(?:\s+\w+){0,4}\s+ability|resistan(?:ce|t)|ancestral|night vision|demoraliz\w*|lacks?|training)\b",
    re.IGNORECASE,
)
JSON_SCHEMA: dict[str, Any] = {
    "type": "object",
    "additionalProperties": False,
    "properties": {
        **{
            field: {"type": "string", "maxLength": TEXT_LIMITS[field]}
            for field in GENERATED_FIELDS if field not in {"relationships", "skills", "goals"}
        },
        "relationships": {
            "type": "array",
            "maxItems": 6,
            "items": {
                "type": "object",
                "additionalProperties": False,
                "properties": {
                    "target": {"type": "string", "maxLength": 100},
                    "aff": {"type": "integer", "minimum": -100, "maximum": 100},
                    "type": {"type": "string", "enum": list(RELATIONSHIP_TYPES)},
                    "relation": {"type": "string", "maxLength": 120},
                    "note": {"type": "string", "maxLength": 240},
                    "best": {"type": "string", "maxLength": 240},
                    "worst": {"type": "string", "maxLength": 240},
                },
                "required": ["target", "aff", "type", "relation", "note", "best", "worst"],
            },
        },
        "skills": {"type": "array", "items": {"type": "string", "maxLength": 160}, "minItems": 4, "maxItems": 6},
        "goals": {"type": "array", "items": {"type": "string", "maxLength": 160}, "minItems": 3, "maxItems": 5},
    },
    "required": list(GENERATED_FIELDS),
}

SYSTEM_PROMPT = """You write Morrowind character biography templates in the established CHIM format.

Return only the required JSON object. Treat Tamriel and its people as real. Match the current CHIM
default biography presentation: compact, direct, third-person, present-tense reference prose. Prefer
plain factual wording over literary narration, dramatic metaphors, editorial judgment, or exhaustive detail.
Put each stable fact in the single field where it is most useful instead of repeating it across sections.

Formatting rules:
- oghma_knowledge_tags: return an empty string. ALMSIVI knowledge tags are intentionally deferred.
- core: exactly one identity sentence of 18-34 words.
- npc_static_bio: 2-3 sentences and 28-95 words covering stable background, location, affiliations, and role.
- appearance: 2-3 sentences and 20-65 words covering physical appearance only. Use precise age, hair,
  eyes, scars, posture, or condition only when the supplied evidence supports them. When evidence is
  sparse, use restrained race and build descriptors rather than inventing distinctive features.
  Never describe clothing, armor, weapons, inventory, or temporary equipment.
- personality: 2-3 practical sentences and 22-75 words describing repeatable behavior and temperament.
- relationships: a JSON array of CHIM/Dialectic relationship seeds. Each item has target, aff (-100
  to 100), one canonical type, and concise relation, note, best, and worst strings. Use empty strings
  for unsupported optional details and an empty array when no relationship has strong evidence. Use
  best and worst only for supported past experiences, never hypothetical reactions or future events.
  Targets must be named individual characters, never factions, houses, organizations, or groups.
  Choose type from the subject character's perspective and keep it consistent with relation and note.
  Rival, enemy, fearful, jealous, suspicious, contempt, and similar negative types require aff at or below 0.
  Use the exact target "Player" only for an established durable relationship at the stable baseline,
  never a potential future meeting, task, cure, or optional outcome. Never use the Nerevarine or a
  display name as a replacement key. Do not include the character themself.
- occupation: 1-2 sentences and 18-60 words describing social role and regular duties.
- skills: 4-6 distinct JSON array items of 5-18 words each. Use descriptive competence phrases like
  CHIM defaults, not bare skill names, game statistics, stock lists, or exhaustive spell lists.
  Include learned, practiced competencies only. Never list passive racial powers, resistances, vision,
  missing abilities, training-menu services, or an absence of skill as a competency.
- speechstyle: 1-2 practical sentences and 15-55 words covering tone, vocabulary, and cadence.
- goals: 3-5 distinct JSON array items of 5-16 words each, using stable motives rather than outcomes.

Evidence rules:
- The official ESM identity fields are authoritative. UESP evidence is supplementary.
- Paraphrase the evidence. Do not copy long source wording.
- Do not mention UESP, wikis, pages, HTML, databases, games, NPCs, language models, prompts, records,
  scripts, game mechanics, quest names as mechanics, quest stages, dialogue choices, or the player.
- Do not assume optional outcomes, deaths, rewards, alliances, betrayals, or completed quests.
- Do not invent a sentence, punishment, exile, indenture, or future destination that the evidence does not state.
- Describe the character at a stable beginning-of-story baseline. Omit later recalls, departures,
  promotions, cures, deaths, rewards, and completed events even when the evidence mentions them.
- Convert conditional source material into stable pre-outcome identity only when directly supported.
- Prefer present-tense identity. Do not invent unsupported family, motives, appearance, or relationships.
- Do not invent unnamed victims, disappearances, deaths, threats, or past violence to make a character dramatic.
- Do not enumerate merchant inventories, equipment, spell lists, schedules, or database-like facts.
- If evidence is sparse, stay conservative and describe what the race, class, faction, and role support.
"""
FORBIDDEN_PATTERNS = tuple(
    re.compile(pattern, re.IGNORECASE)
    for pattern in (
        r"\bUESP\b", r"\bwiki(?:pedia)?\b", r"\bHTML\b", r"\blanguage model\b",
        r"\b(?:the|this|that|system|user|generation|given|provided|original|current) prompt\b",
        r"\bprompt (?:asks|states|requests|requires|instructs|says|provides)\b",
        r"\bthe player\b", r"\bplayer character\b", r"\bNPCs?\b", r"\bquests?\b",
        r"\bgame mechanic", r"\bin the game\b", r"\bif the (?:player|Nerevarine)\b",
        r"\bdepending on (?:the|a) choice\b", r"\beventually\b", r"\btrainer\b",
        r"\bmajor skill\b", r"\bminor skill\b", r"\bonly known .{0,80}\bcur(?:e|es|ed|ing)\b",
        r"\bcapable of cur(?:e|es|ed|ing)\b",
        r"\bthose who underestimate.{0,100}\b(?:vanish|die|survive)",
        r"\b(?:innate|racial) (?:ability|abilities|protection|resistance|resilience)\b",
        r"\bancestral (?:protection|sanctuary)\b", r"\bnight vision\b", r"\bdemoraliz(?:e|es|ed|ing|ation)\b",
        r"\bexpects? (?:only )?(?:exile|indentured servitude)\b",
    )
)


def default_cache_dir() -> Path:
    return Path.home() / ".cache" / "almsiviserver" / "uesp-morrowind-npcs"


def decode_tes3_text(raw: bytes) -> str:
    return raw.rstrip(b"\0").decode("cp1252", errors="replace").strip()


def iter_subrecords(body: bytes) -> Iterable[tuple[bytes, bytes]]:
    position = 0
    while position + 8 <= len(body):
        kind = body[position : position + 4]
        size = struct.unpack_from("<I", body, position + 4)[0]
        start = position + 8
        end = start + size
        if end > len(body):
            raise ValueError(f"TES3 subrecord {kind!r} extends past its parent record")
        yield kind, body[start:end]
        position = end
    if position != len(body):
        raise ValueError("TES3 record ended with an incomplete subrecord header")


def parse_npc_record(body: bytes, content_file: str) -> dict[str, Any] | None:
    fields: dict[bytes, bytes] = {}
    for kind, value in iter_subrecords(body):
        fields.setdefault(kind, value)
    record_id = decode_tes3_text(fields.get(b"NAME", b""))
    if not record_id:
        return None
    flags = struct.unpack_from("<I", fields.get(b"FLAG", b"\0\0\0\0").ljust(4, b"\0"))[0]
    return {
        "record_id": record_id,
        "display_name": decode_tes3_text(fields.get(b"FNAM", b"")) or record_id,
        "content_file": content_file,
        "race_id": decode_tes3_text(fields.get(b"RNAM", b"")),
        "class_id": decode_tes3_text(fields.get(b"CNAM", b"")),
        "faction_id": decode_tes3_text(fields.get(b"ANAM", b"")),
        "script_id": decode_tes3_text(fields.get(b"SCRI", b"")),
        "head_id": decode_tes3_text(fields.get(b"BNAM", b"")),
        "hair_id": decode_tes3_text(fields.get(b"KNAM", b"")),
        "gender": "female" if flags & NPC_FLAGS["female"] else "male",
        "flags": {name: bool(flags & value) for name, value in NPC_FLAGS.items()},
    }


def actor_alias_index(actors: dict[str, str]) -> dict[str, dict[str, str]]:
    aliases: dict[str, dict[str, str]] = {}
    for record_id, display_name in actors.items():
        words = display_name.split()
        values = {record_id.casefold(), display_name.casefold()}
        values.update(" ".join(words[:length]).casefold() for length in range(1, len(words)))
        for value in values:
            aliases.setdefault(value, {})[record_id] = display_name
    return aliases


def extract_npcs(
    data_dir: Path, content_files: tuple[str, ...] = CONTENT_FILES,
) -> tuple[list[dict[str, Any]], dict[str, dict[str, str]], dict[str, str]]:
    winners: dict[str, dict[str, Any]] = {}
    actor_winners: dict[str, str] = {}
    hashes: dict[str, str] = {}
    for content_file in content_files:
        path = data_dir / content_file
        if not path.is_file():
            raise FileNotFoundError(f"Required official content file is missing: {path}")
        raw = path.read_bytes()
        hashes[content_file] = hashlib.sha256(raw).hexdigest()
        position = 0
        while position + 16 <= len(raw):
            kind = raw[position : position + 4]
            size = struct.unpack_from("<I", raw, position + 4)[0]
            start = position + 16
            end = start + size
            if end > len(raw):
                raise ValueError(f"TES3 record in {content_file} extends past end of file")
            position = end
            if kind not in {b"NPC_", b"CREA"}:
                continue
            if kind == b"NPC_":
                npc = parse_npc_record(raw[start:end], content_file)
                if npc is not None:
                    key = npc["record_id"].casefold()
                    winners[key] = npc
                    actor_winners[key] = npc["display_name"]
            else:
                fields: dict[bytes, bytes] = {}
                for subrecord_kind, value in iter_subrecords(raw[start:end]):
                    fields.setdefault(subrecord_kind, value)
                record_id = decode_tes3_text(fields.get(b"NAME", b""))
                if record_id:
                    actor_winners[record_id.casefold()] = decode_tes3_text(fields.get(b"FNAM", b"")) or record_id
        if position != len(raw):
            raise ValueError(f"{content_file} ended with an incomplete TES3 record header")
    catalog = sorted(winners.values(), key=lambda row: (row["display_name"].casefold(), row["record_id"].casefold()))
    return catalog, actor_alias_index(actor_winners), hashes


def safe_file_component(value: str) -> str:
    slug = re.sub(r"[^a-z0-9]+", "-", value.casefold()).strip("-")[:80] or "page"
    return f"{slug}-{hashlib.sha256(value.encode('utf-8')).hexdigest()[:12]}"


def uesp_request(session: requests.Session, params: dict[str, Any], timeout: float) -> dict[str, Any]:
    last_error: Exception | None = None
    for attempt in range(4):
        try:
            response = session.get(UESP_API_URL, params=params, timeout=timeout)
            if response.status_code == 429 or response.status_code >= 500:
                raise requests.HTTPError(f"UESP returned HTTP {response.status_code}", response=response)
            response.raise_for_status()
            payload = response.json()
            if not isinstance(payload, dict):
                raise ValueError("UESP API returned a non-object response")
            return payload
        except (requests.RequestException, ValueError) as exc:
            last_error = exc
            if attempt < 3:
                time.sleep(1.0 * (attempt + 1))
    raise RuntimeError(f"UESP request failed after retries: {last_error}")


def fetch_uesp_page(
    session: requests.Session, page_title: str, cache_dir: Path, timeout: float, refresh_cache: bool,
) -> dict[str, Any] | None:
    cache_path = cache_dir / f"{safe_file_component(page_title)}.json"
    if cache_path.is_file() and not refresh_cache:
        payload = json.loads(cache_path.read_text(encoding="utf-8"))
    else:
        payload = uesp_request(
            session,
            {
                "action": "parse", "page": page_title, "prop": "text|categories|revid", "format": "json",
                "formatversion": "2", "redirects": "1",
            },
            timeout,
        )
        cache_dir.mkdir(parents=True, exist_ok=True)
        cache_path.write_text(json.dumps(payload, ensure_ascii=False), encoding="utf-8")
    parsed = payload.get("parse")
    return parsed if isinstance(parsed, dict) else None


def parse_infobox(table: Any) -> dict[str, str]:
    result: dict[str, str] = {}
    if table is None:
        return result
    for row in table.select("tr"):
        cells = row.find_all(["th", "td"], recursive=False)
        for index in range(0, len(cells) - 1, 2):
            key = re.sub(r"\s+", " ", cells[index].get_text(" ", strip=True)).strip().casefold()
            value = re.sub(r"\s+", " ", cells[index + 1].get_text(" ", strip=True)).strip()
            if key and value:
                result[key] = value
    return result


def uesp_evidence(parsed: dict[str, Any], expected_record_id: str) -> dict[str, Any]:
    soup = BeautifulSoup(str(parsed.get("text") or ""), "html.parser")
    infobox = soup.select_one("table.infobox")
    heading = infobox.find("th") if infobox is not None else None
    record_label = heading.find("small") if heading is not None else None
    heading_text = record_label.get_text(" ", strip=True) if record_label is not None else ""
    match = re.search(r"\(([^()]+)\)", heading_text)
    page_record_id = match.group(1).strip() if match else ""
    exact_match = bool(page_record_id and page_record_id.casefold() == expected_record_id.casefold())
    paragraphs: list[str] = []
    root = soup.select_one(".mw-parser-output") or soup
    for paragraph in root.find_all("p"):
        if paragraph.find_parent(["table", "figure"]):
            continue
        text = re.sub(r"\s+", " ", paragraph.get_text(" ", strip=True)).strip()
        if len(text) < 50:
            continue
        paragraphs.append(text)
        if len(paragraphs) >= 8 or sum(len(item) for item in paragraphs) >= 6000:
            break
    title = str(parsed.get("title") or "")
    return {
        "status": "exact" if exact_match else "identity_mismatch",
        "title": title,
        "url": "https://en.uesp.net/wiki/" + quote(title.replace(" ", "_"), safe=":_()'-"),
        "page_id": parsed.get("pageid"),
        "revision_id": parsed.get("revid"),
        "record_id": page_record_id,
        "infobox": parse_infobox(infobox),
        "paragraphs": paragraphs if exact_match else [],
    }


def find_uesp_evidence(
    session: requests.Session, npc: dict[str, Any], cache_dir: Path, timeout: float, refresh_cache: bool,
) -> dict[str, Any]:
    page_title = "Morrowind:" + npc["display_name"].replace(" ", "_")
    try:
        parsed = fetch_uesp_page(session, page_title, cache_dir, timeout, refresh_cache)
    except RuntimeError as exc:
        return {"status": "error", "error": str(exc), "paragraphs": [], "infobox": {}}
    if parsed is None:
        return {"status": "not_found", "paragraphs": [], "infobox": {}}
    return uesp_evidence(parsed, npc["record_id"])


def compact_evidence(npc: dict[str, Any], uesp: dict[str, Any]) -> str:
    infobox = uesp.get("infobox") if isinstance(uesp.get("infobox"), dict) else {}
    lines = [
        "OFFICIAL ESM IDENTITY:", f"display_name: {npc['display_name']}", f"record_id: {npc['record_id']}",
        f"content_file: {npc['content_file']}", f"race_id: {npc['race_id'] or '[unknown]'}",
        f"class_id: {npc['class_id'] or '[unknown]'}", f"faction_id: {npc['faction_id'] or '[none]'}",
        f"gender: {npc['gender']}", f"essential: {str(npc['flags']['essential']).lower()}",
        f"respawn: {str(npc['flags']['respawn']).lower()}", "", f"UESP MATCH STATUS: {uesp.get('status', 'not_found')}",
    ]
    if uesp.get("status") == "exact":
        for key in ("home town", "location", "house", "race", "gender", "level", "class", "faction", "rank"):
            if infobox.get(key):
                lines.append(f"uesp_{key.replace(' ', '_')}: {infobox[key]}")
        for index, paragraph in enumerate(uesp.get("paragraphs") or [], start=1):
            lines.append(f"evidence_paragraph_{index}: {paragraph}")
    return "\n".join(lines)[:10000]


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
    session: requests.Session, *, api_key: str, model: str, evidence: str, timeout: float, repair: str = "",
    telemetry: dict[str, Any] | None = None,
) -> dict[str, Any]:
    user_content = "Source evidence:\n" + evidence + "\n\nReturn the CHIM-formatted JSON biography now."
    if repair:
        user_content += "\n\nRepair requirement:\n" + repair
    if telemetry is not None:
        telemetry["logical_calls"] = int(telemetry.get("logical_calls") or 0) + 1
    last_error: Exception | None = None
    for attempt in range(2):
        started = time.perf_counter()
        if telemetry is not None:
            telemetry["http_attempts"] = int(telemetry.get("http_attempts") or 0) + 1
        try:
            response = session.post(
                OPENROUTER_URL,
                headers={
                    "Authorization": f"Bearer {api_key}", "Content-Type": "application/json",
                    "HTTP-Referer": "https://dwemerdynamics.com", "X-Title": "ALMSIVI Morrowind Biography Builder",
                },
                json={
                    "model": model, "temperature": 0.0, "max_tokens": 2800, "reasoning": {"effort": "none"},
                    "provider": {"require_parameters": True},
                    "response_format": {
                        "type": "json_schema",
                        "json_schema": {"name": "morrowind_chim_biography", "strict": True, "schema": JSON_SCHEMA},
                    },
                    "plugins": [{"id": "response-healing"}],
                    "messages": [{"role": "system", "content": SYSTEM_PROMPT}, {"role": "user", "content": user_content}],
                },
                timeout=timeout,
            )
            response.raise_for_status()
            payload = response.json()
            if telemetry is not None:
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
            return extract_json_object((choices[0].get("message") or {}).get("content"))
        except (requests.RequestException, ValueError, json.JSONDecodeError) as exc:
            last_error = exc
            if attempt == 0:
                time.sleep(1.0)
        finally:
            if telemetry is not None:
                telemetry["request_seconds"] = round(
                    float(telemetry.get("request_seconds") or 0.0) + time.perf_counter() - started,
                    3,
                )
    raise RuntimeError(f"GLM structured-output request failed after retry: {last_error}")


def one_line(value: Any, limit: int) -> str:
    text = re.sub(r"\s+", " ", str(value or "")).strip()
    return text if len(text) <= limit else text[:limit].rsplit(" ", 1)[0]


def bullet_list(
    value: Any, limit: int, allow_empty: bool = False, reject_pattern: re.Pattern[str] | None = None,
) -> str:
    if isinstance(value, list):
        candidates = [str(item).strip() for item in value if str(item).strip()]
        text = ""
    else:
        text = str(value or "").strip()
        text = re.sub(r"\s*(?=\*\s+)", "\n", text).strip()
        candidates = [line.strip() for line in text.splitlines() if line.strip()]
    if not text and allow_empty:
        if not candidates:
            return ""
    if not isinstance(value, list) and len(candidates) <= 1:
        candidates = [part.strip() for part in re.split(r"\s*;\s*", text) if part.strip()]
    items: list[str] = []
    for candidate in candidates:
        item = re.sub(r"^[*\-•]\s*", "", candidate).strip(" .")
        if item and not (reject_pattern and reject_pattern.search(item)):
            items.append("* " + item)
    rendered = "\n".join(items[:12])
    if not rendered and not allow_empty:
        raise ValueError("required bullet-list field is empty")
    if len(rendered) > limit:
        rendered = rendered[:limit].rsplit("\n", 1)[0]
    return rendered


def relationship_map_json(
    npc_name: str, value: Any, actor_aliases: dict[str, dict[str, str]] | None = None,
) -> str:
    if not isinstance(value, list):
        raise ValueError("relationships must be a structured array")
    relationships: dict[str, dict[str, Any]] = {}
    for raw in value:
        if not isinstance(raw, dict):
            continue
        target = one_line(raw.get("target"), 100)
        if not target or target.casefold() == npc_name.casefold():
            continue
        if target.casefold() in {"the player", "player character", "the player character", "the nerevarine", "nerevarine"}:
            target = "Player"
        elif actor_aliases is not None:
            matches = actor_aliases.get(target.casefold(), {})
            if len(matches) != 1:
                continue
            target = next(iter(matches.values()))
        rel_type = one_line(raw.get("type"), 50).casefold()
        if rel_type not in RELATIONSHIP_TYPES:
            continue
        relation = one_line(raw.get("relation"), 120)
        if rel_type not in FRIENDSHIP_RELATIONSHIP_TYPES and re.search(
            r"\b(?:friend|confidant)\b", relation, re.IGNORECASE,
        ):
            rel_type = "platonic"
        if rel_type == "familial" and re.search(
            r"\b(?:guild|colleague|coworker|neighbor)\b", relation, re.IGNORECASE,
        ):
            rel_type = "professional"
        try:
            aff = max(-100, min(100, int(raw.get("aff", 0))))
        except (TypeError, ValueError):
            aff = 0
        if rel_type in NEGATIVE_RELATIONSHIP_TYPES and aff > 0:
            aff = -aff
        item: dict[str, Any] = {"aff": aff, "type": rel_type}
        for field, limit in (("relation", 120), ("note", 240), ("best", 240), ("worst", 240)):
            text = relation if field == "relation" else one_line(raw.get(field), limit)
            if text:
                item[field] = text
        description = " ".join(str(item.get(field) or "") for field in ("relation", "note", "best", "worst"))
        if rel_type in CAPTIVITY_INCOMPATIBLE_TYPES and CAPTIVITY_PATTERN.search(description):
            item["type"] = "transactional"
            item["aff"] = min(0, aff)
        if target == "Player" and re.search(
            r"\b(?:potential|future|eventual|optional|will|would|could|anyone)\b|\bwho (?:returns?|helps?|assists?)\b",
            description,
            re.IGNORECASE,
        ):
            continue
        relationships[target] = item
    return json.dumps(relationships, ensure_ascii=False, separators=(",", ":"))


def sanitize_generated(
    generated: dict[str, Any], npc_name: str, actor_aliases: dict[str, dict[str, str]] | None = None,
) -> dict[str, str]:
    missing = [field for field in GENERATED_FIELDS if field not in generated]
    if missing:
        raise ValueError("generated biography is missing fields: " + ", ".join(missing))
    clean = {
        "oghma_knowledge_tags": "",
        "core": one_line(generated["core"], TEXT_LIMITS["core"]),
        "npc_static_bio": one_line(generated["npc_static_bio"], TEXT_LIMITS["npc_static_bio"]),
        "appearance": one_line(generated["appearance"], TEXT_LIMITS["appearance"]),
        "personality": one_line(generated["personality"], TEXT_LIMITS["personality"]),
        "relationships": relationship_map_json(npc_name, generated["relationships"], actor_aliases),
        "occupation": one_line(generated["occupation"], TEXT_LIMITS["occupation"]),
        "skills": bullet_list(
            generated["skills"], TEXT_LIMITS["skills"], reject_pattern=SKILL_MECHANIC_PATTERN,
        ),
        "speechstyle": one_line(generated["speechstyle"], TEXT_LIMITS["speechstyle"]),
        "goals": bullet_list(generated["goals"], TEXT_LIMITS["goals"]),
    }
    intentionally_empty = {"oghma_knowledge_tags", "relationships"}
    if any(not clean[field] for field in GENERATED_FIELDS if field not in intentionally_empty):
        raise ValueError("generated biography contains an empty required field")
    return clean


def word_count(value: str) -> int:
    return len(re.findall(r"\b[\w'’-]+\b", value, re.UNICODE))


def sentence_count(value: str) -> int:
    normalized = re.sub(
        r"\b(?:St|Mr|Mrs|Ms|Dr|Sr|Jr)\.",
        lambda match: match.group(0)[:-1],
        value,
        flags=re.IGNORECASE,
    )
    endings = len(re.findall(r"[.!?](?=\s|$)", normalized))
    return max(1, endings) if value.strip() else 0


def content_violations(
    profile: dict[str, str], actor_aliases: dict[str, dict[str, str]] | None = None,
) -> list[str]:
    violations: list[str] = []
    for field, value in profile.items():
        for pattern in FORBIDDEN_PATTERNS:
            if pattern.search(value):
                violations.append(f"{field} matches {pattern.pattern}")
    for field, (minimum, maximum) in STYLE_WORD_BOUNDS.items():
        actual = word_count(profile[field])
        if not minimum <= actual <= maximum:
            violations.append(f"{field} has {actual} words; required {minimum}-{maximum}")
    for field, (minimum, maximum) in STYLE_SENTENCE_BOUNDS.items():
        actual = sentence_count(profile[field])
        if not minimum <= actual <= maximum:
            violations.append(f"{field} has {actual} sentences; required {minimum}-{maximum}")
    for field, minimum_items, maximum_items, minimum_words, maximum_words in (
        ("skills", 3, 6, 5, 18), ("goals", 3, 5, 4, 16),
    ):
        items = [line[2:].strip() for line in profile[field].splitlines() if line.startswith("* ")]
        if not minimum_items <= len(items) <= maximum_items:
            violations.append(f"{field} has {len(items)} bullets; required {minimum_items}-{maximum_items}")
        for index, item in enumerate(items, start=1):
            actual = word_count(item)
            if not minimum_words <= actual <= maximum_words:
                violations.append(
                    f"{field} bullet {index} has {actual} words; required {minimum_words}-{maximum_words}"
                )
    equipment = re.compile(
        r"\b(?:wears|wearing|clad|clothing|attire|garb|armor|robe|shirt|pants|boots|shoes|"
        r"weapon|sword|dagger|staff|bare[- ]chest(?:ed)?)\b",
        re.IGNORECASE,
    )
    if equipment.search(profile["appearance"]):
        violations.append("appearance contains clothing, equipment, or weapons")
    if SKILL_MECHANIC_PATTERN.search(profile["skills"]):
        violations.append("skills contains passive racial, missing-skill, or game-mechanic information")
    relationships = json.loads(profile["relationships"])
    if len(relationships) > 6:
        violations.append(f"relationships has {len(relationships)} entries; maximum is 6")
    group_target = re.compile(r"^(?:House|Clan|Guild|Temple|Imperial Legion|Stormcloaks?)\b", re.IGNORECASE)
    for target, relationship in relationships.items():
        if group_target.search(target) or target.casefold().endswith((" residents", " citizens", " workers", " crew")):
            violations.append(f"relationships target {target!r} is a group, not a named character")
        if target == "Player":
            description = " ".join(str(relationship.get(key) or "") for key in ("relation", "note", "best", "worst"))
            if re.search(
                r"\b(?:potential|future|eventual|optional|will|would|could|anyone)\b|\bwho (?:returns?|helps?|assists?)\b",
                description,
                re.IGNORECASE,
            ):
                violations.append("Player relationship describes a potential or future tie instead of an established one")
        elif actor_aliases is not None:
            matches = actor_aliases.get(target.casefold(), {})
            canonical_names = set(matches.values())
            if len(matches) != 1 or canonical_names != {target}:
                violations.append(f"relationship target {target!r} is not one unambiguous canonical actor name")
        rel_type = str(relationship.get("type") or "")
        affinity = int(relationship.get("aff") or 0)
        if rel_type in NEGATIVE_RELATIONSHIP_TYPES and affinity > 0:
            violations.append(f"relationship {target!r} has positive affinity for negative type {rel_type!r}")
        relation_text = str(relationship.get("relation") or "")
        description = " ".join(str(relationship.get(key) or "") for key in ("relation", "note", "best", "worst"))
        if rel_type in CAPTIVITY_INCOMPATIBLE_TYPES and CAPTIVITY_PATTERN.search(description):
            violations.append(f"relationship {target!r} uses incompatible type {rel_type!r} for captivity or ransom")
        if rel_type not in FRIENDSHIP_RELATIONSHIP_TYPES and re.search(
            r"\b(?:friend|confidant)\b", relation_text, re.IGNORECASE,
        ):
            violations.append(f"relationship {target!r} is described as a friend but typed as {rel_type}")
        if rel_type == "familial" and re.search(r"\b(?:guild|colleague|coworker|neighbor)\b", relation_text, re.IGNORECASE):
            violations.append(f"relationship {target!r} is a professional peer but typed as familial")
    return violations


def template_row(npc: dict[str, Any], generated: dict[str, str], uesp: dict[str, Any]) -> dict[str, Any]:
    infobox = uesp.get("infobox") if isinstance(uesp.get("infobox"), dict) else {}
    race = str(infobox.get("race") or npc["race_id"] or "").strip()
    row: dict[str, Any] = {
        "npc_name": re.sub(r"[^a-z0-9]+", "_", npc["record_id"].casefold()).strip("_"),
        **generated,
        "voiceid": None, "gender": npc["gender"], "race": race or None, "refid": npc["record_id"],
    }
    if tuple(row) != CHIM_COLUMNS:
        raise AssertionError("CHIM biography column order drifted")
    return row


def select_npcs(
    catalog: list[dict[str, Any]], requested: list[str], limit: int, record_ids: list[str] | None = None,
) -> list[dict[str, Any]]:
    if record_ids:
        by_record_id = {row["record_id"].casefold(): row for row in catalog}
        selected: list[dict[str, Any]] = []
        for value in record_ids:
            npc = by_record_id.get(value.casefold())
            if npc is None:
                raise ValueError(f"NPC record ID was not found in the official ESM catalog: {value}")
            if npc not in selected:
                selected.append(npc)
        return selected[:limit] if limit else selected
    if requested:
        lookup: dict[str, list[dict[str, Any]]] = {}
        for npc in catalog:
            for key in {npc["record_id"].casefold(), npc["display_name"].casefold()}:
                lookup.setdefault(key, []).append(npc)
        selected: list[dict[str, Any]] = []
        for value in requested:
            matches = lookup.get(value.casefold(), [])
            if not matches:
                raise ValueError(f"NPC was not found in the official ESM catalog: {value}")
            if len(matches) > 1:
                ids = ", ".join(row["record_id"] for row in matches)
                raise ValueError(f"NPC name is ambiguous; use a record ID for {value}: {ids}")
            if matches[0] not in selected:
                selected.append(matches[0])
        return selected[:limit] if limit else selected
    return catalog[:limit] if limit else catalog


def write_json(path: Path, payload: Any, force: bool) -> None:
    if path.exists() and not force:
        raise FileExistsError(f"Output already exists; use --force to replace it: {path}")
    path.parent.mkdir(parents=True, exist_ok=True)
    temporary = path.with_name(path.name + ".tmp")
    temporary.write_text(json.dumps(payload, indent=2, ensure_ascii=False) + "\n", encoding="utf-8", newline="\n")
    os.replace(temporary, path)


def load_resume_rows(
    review_path: Path, chim_path: Path, actor_aliases: dict[str, dict[str, str]] | None = None,
) -> tuple[dict[str, dict[str, Any]], dict[str, dict[str, Any]]]:
    if not review_path.is_file() or not chim_path.is_file():
        return {}, {}
    review = json.loads(review_path.read_text(encoding="utf-8"))
    chim = json.loads(chim_path.read_text(encoding="utf-8"))
    results = review.get("results") if isinstance(review, dict) else None
    if not isinstance(results, list) or not isinstance(chim, list):
        raise ValueError("resume outputs do not contain the expected review and CHIM row arrays")
    for row in chim:
        if isinstance(row, dict):
            row["oghma_knowledge_tags"] = ""
    for result in results:
        template = result.get("template") if isinstance(result, dict) else None
        if isinstance(template, dict):
            template["oghma_knowledge_tags"] = ""
    result_map = {
        str(row.get("identity", {}).get("record_id") or "").casefold(): row
        for row in results if isinstance(row, dict) and isinstance(row.get("identity"), dict)
    }
    chim_map = {
        str(row.get("refid") or "").casefold(): row
        for row in chim if isinstance(row, dict) and row.get("refid")
    }
    for key, row in list(chim_map.items()):
        try:
            relationship_seed = json.loads(str(row.get("relationships") or "{}"))
        except json.JSONDecodeError:
            relationship_seed = None
        if not isinstance(relationship_seed, dict):
            chim_map.pop(key, None)
            result_map.pop(key, None)
            continue
        normalized_seed = json.dumps(relationship_seed, ensure_ascii=False, separators=(",", ":"))
        row["relationships"] = normalized_seed
        row["skills"] = bullet_list(
            row.get("skills"), TEXT_LIMITS["skills"], reject_pattern=SKILL_MECHANIC_PATTERN,
        )
        profile = {field: str(row.get(field) or "") for field in GENERATED_FIELDS}
        if content_violations(profile, actor_aliases):
            chim_map.pop(key, None)
            result_map.pop(key, None)
            continue
        result = result_map.get(key)
        if isinstance(result, dict) and isinstance(result.get("template"), dict):
            result["template"]["relationships"] = normalized_seed
            result["template"]["skills"] = row["skills"]
    return result_map, chim_map


def relationship_import_rows(rows: list[dict[str, Any]]) -> list[dict[str, Any]]:
    results: list[dict[str, Any]] = []
    for row in rows:
        relationships = json.loads(str(row.get("relationships") or "{}"))
        if not isinstance(relationships, dict):
            raise ValueError(f"{row.get('npc_name')} relationships are not a JSON object")
        for target, relationship in relationships.items():
            if not isinstance(target, str) or not target or not isinstance(relationship, dict):
                raise ValueError(f"{row.get('npc_name')} contains an invalid relationship target")
            if relationship.get("type") not in RELATIONSHIP_TYPES:
                raise ValueError(f"{row.get('npc_name')} contains an invalid relationship type")
            if not isinstance(relationship.get("aff"), int) or not -100 <= relationship["aff"] <= 100:
                raise ValueError(f"{row.get('npc_name')} contains an invalid relationship affinity")
        results.append(
            {
                "npc_name": row.get("npc_name"),
                "status": "generated",
                "relationship_count": len(relationships),
                "relationships": relationships,
            }
        )
    return results


def review_manifest(
    *, hashes: dict[str, str], catalog_count: int, selected_count: int, results: list[dict[str, Any]],
) -> dict[str, Any]:
    return {
        "format": "almsivi.morrowind-biography-review.v1",
        "generated_at_utc": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
        "official_content_sha256": hashes,
        "official_winning_npc_count": catalog_count,
        "selected_count": selected_count,
        "completed_count": len(results),
        "results": results,
    }


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Generate CHIM-formatted Morrowind NPC biographies with GLM.")
    parser.add_argument("--data-dir", type=Path, default=DEFAULT_DATA_DIR)
    parser.add_argument("--content-file", action="append", default=[],
                        help="Content filename in OpenMW load order; repeatable. Defaults to the three official masters.")
    parser.add_argument("--npc", action="append", default=[], help="Record ID or unique display name; repeatable.")
    parser.add_argument("--record-id", action="append", default=[], help="Exact record ID; repeatable and unambiguous.")
    parser.add_argument("--limit", type=int, default=5, help="Maximum selected NPCs. Defaults to five for safe review.")
    parser.add_argument("--model", default=DEFAULT_MODEL)
    parser.add_argument("--api-key-env", default="OPENROUTER_API_KEY")
    parser.add_argument("--timeout", type=float, default=90.0)
    parser.add_argument("--delay", type=float, default=0.5)
    parser.add_argument("--cache-dir", type=Path, default=default_cache_dir())
    parser.add_argument("--refresh-uesp-cache", action="store_true")
    parser.add_argument("--skip-uesp", action="store_true",
                        help="Retained for command compatibility; remote wiki acquisition is always disabled.")
    parser.add_argument("--dry-run", action="store_true", help="Extract identities and evidence without calling GLM.")
    output_dir = Path(__file__).resolve().parent / "output"
    parser.add_argument("--output", type=Path, default=output_dir / "morrowind-biographies.json")
    parser.add_argument("--chim-output", type=Path, default=output_dir / "morrowind-biographies-chim.json")
    parser.add_argument(
        "--rejected-output", type=Path,
        help="Optional diagnostic JSON for a rejected candidate and its provider telemetry.",
    )
    parser.add_argument(
        "--relationships-output", type=Path, default=output_dir / "morrowind-relationship-metadata.json",
        help="CHIM/Dialectic relationship-import result JSON derived from the generated biography rows.",
    )
    parser.add_argument("--resume", action="store_true", help="Reuse checkpointed rows from both output files.")
    parser.add_argument(
        "--refresh-npc", action="append", default=[],
        help="With --resume, regenerate a selected record ID or display name instead of reusing it; repeatable.",
    )
    parser.add_argument("--force", action="store_true")
    args = parser.parse_args()
    if args.limit < 1:
        parser.error("--limit must be at least 1")
    content_files = tuple(args.content_file or CONTENT_FILES)
    if len({value.casefold() for value in content_files}) != len(content_files):
        parser.error("--content-file values must be unique")
    args.content_files = content_files
    return args


def main() -> int:
    args = parse_args()
    api_key = os.getenv(args.api_key_env, "").strip()
    if not args.dry_run and not api_key:
        raise RuntimeError(f"Live GLM generation requires the {args.api_key_env} environment variable")
    catalog, actor_aliases, hashes = extract_npcs(args.data_dir, args.content_files)
    selected = select_npcs(catalog, args.npc, args.limit, args.record_id)
    print(f"[esm] {len(catalog)} winning official NPC records; selected {len(selected)}")

    if not args.resume and not args.force and (
        args.output.exists() or (not args.dry_run and (args.chim_output.exists() or args.relationships_output.exists()))
    ):
        raise FileExistsError("output already exists; use --force to replace it or --resume to continue it")
    resume_results, resume_chim = (
        load_resume_rows(args.output, args.chim_output, actor_aliases)
        if args.resume and not args.dry_run else ({}, {})
    )
    checkpoint_results = dict(resume_results)
    checkpoint_chim = dict(resume_chim)

    session = requests.Session()
    session.headers.update({"User-Agent": "ALMSIVIserver biography builder/0.1 (local development)"})
    results: list[dict[str, Any]] = []
    chim_rows: list[dict[str, Any]] = []
    refresh_keys = {value.casefold() for value in args.refresh_npc}
    for index, npc in enumerate(selected, start=1):
        resume_key = npc["record_id"].casefold()
        refresh = resume_key in refresh_keys or npc["display_name"].casefold() in refresh_keys
        if not refresh and resume_key in resume_results and resume_key in resume_chim:
            results.append(resume_results[resume_key])
            chim_rows.append(resume_chim[resume_key])
            print(f"[skip] {index}/{len(selected)} {npc['display_name']}: checkpointed")
            continue
        uesp = {"status": "skipped", "paragraphs": [], "infobox": {}}
        evidence = compact_evidence(npc, uesp)
        print(f"[source] {index}/{len(selected)} {npc['display_name']} ({npc['record_id']}): UESP {uesp['status']}")
        generated: dict[str, str] | None = None
        generation_telemetry: dict[str, Any] = {}
        status = "dry_run"
        if not args.dry_run:
            try:
                raw = call_glm(
                    session, api_key=api_key, model=args.model, evidence=evidence, timeout=args.timeout,
                    telemetry=generation_telemetry,
                )
                schema_error = ""
                for schema_attempt in range(3):
                    try:
                        generated = sanitize_generated(raw, npc["display_name"], actor_aliases)
                        break
                    except ValueError as error:
                        if schema_attempt == 2:
                            raise
                        schema_error = str(error)
                    raw = call_glm(
                        session, api_key=api_key, model=args.model, evidence=evidence, timeout=args.timeout,
                        repair=f"Return every required field in the schema. Correct this validation error: {schema_error}",
                        telemetry=generation_telemetry,
                    )
                if generated is None:
                    raise ValueError(f"{npc['record_id']} did not produce a complete biography")
                violations = content_violations(generated, actor_aliases)
                for _repair_attempt in range(3):
                    if not violations:
                        break
                    repair = (
                        "Revise only the fields named by these violations and preserve every currently valid field: "
                        + "; ".join(violations)
                        + "\nCurrent candidate JSON:\n"
                        + json.dumps(generated, ensure_ascii=False, separators=(",", ":"))
                    )
                    raw = call_glm(
                        session, api_key=api_key, model=args.model, evidence=evidence, timeout=args.timeout, repair=repair,
                        telemetry=generation_telemetry,
                    )
                    try:
                        generated = sanitize_generated(raw, npc["display_name"], actor_aliases)
                    except ValueError as error:
                        violations = [f"schema validation failed during repair: {error}"]
                        continue
                    violations = content_violations(generated, actor_aliases)
                if violations:
                    raise ValueError(f"{npc['record_id']} failed content validation after repair: {violations}")
            except (RuntimeError, ValueError) as error:
                if args.rejected_output is not None:
                    write_json(
                        args.rejected_output,
                        {
                            "format": "almsivi.morrowind-biography-rejection.v1",
                            "generated_at_utc": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
                            "identity": {
                                "record_id": npc["record_id"], "display_name": npc["display_name"],
                                "content_file": npc["content_file"],
                            },
                            "source": {"uesp_status": uesp.get("status"), "uesp_url": uesp.get("url")},
                            "generation": {
                                "status": "rejected", "model": args.model,
                                "telemetry": generation_telemetry, "error": str(error),
                            },
                            "candidate": generated,
                        },
                        True,
                    )
                raise
            row = template_row(npc, generated, uesp)
            chim_rows.append(row)
            status = "glm"
            print(f"[glm] {index}/{len(selected)} {npc['display_name']}: complete")
        results.append(
            {
                "identity": {
                    "record_id": npc["record_id"], "display_name": npc["display_name"],
                    "content_file": npc["content_file"], "race_id": npc["race_id"],
                    "class_id": npc["class_id"], "faction_id": npc["faction_id"], "gender": npc["gender"],
                },
                "source": {
                    "uesp_status": uesp.get("status"), "uesp_url": uesp.get("url"),
                    "uesp_page_id": uesp.get("page_id"), "uesp_revision_id": uesp.get("revision_id"),
                    "license": "UESP Attribution-ShareAlike 2.5",
                },
                "generation": {
                    "status": status, "model": args.model if not args.dry_run else None,
                    "telemetry": generation_telemetry,
                },
                "template": chim_rows[-1] if generated is not None else None,
            }
        )
        checkpoint_results[resume_key] = results[-1]
        if generated is not None:
            checkpoint_chim[resume_key] = chim_rows[-1]
        ordered_checkpoint_results = [
            checkpoint_results[row["record_id"].casefold()]
            for row in selected if row["record_id"].casefold() in checkpoint_results
        ]
        ordered_checkpoint_chim = [
            checkpoint_chim[row["record_id"].casefold()]
            for row in selected if row["record_id"].casefold() in checkpoint_chim
        ]
        write_json(
            args.output,
            review_manifest(
                hashes=hashes, catalog_count=len(catalog), selected_count=len(selected), results=ordered_checkpoint_results,
            ),
            True,
        )
        if not args.dry_run:
            write_json(args.chim_output, ordered_checkpoint_chim, True)
            write_json(args.relationships_output, relationship_import_rows(ordered_checkpoint_chim), True)
        print(f"[checkpoint] {len(ordered_checkpoint_results)}/{len(selected)} completed")
        if not args.dry_run and index < len(selected) and args.delay > 0:
            time.sleep(args.delay)
    manifest = review_manifest(hashes=hashes, catalog_count=len(catalog), selected_count=len(selected), results=results)
    write_json(args.output, manifest, True)
    if not args.dry_run:
        write_json(args.chim_output, chim_rows, True)
        write_json(args.relationships_output, relationship_import_rows(chim_rows), True)
    print(f"[write] review: {args.output}")
    if not args.dry_run:
        print(f"[write] CHIM rows: {args.chim_output}")
        print(f"[write] relationships: {args.relationships_output}")
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except (FileNotFoundError, FileExistsError, RuntimeError, ValueError) as error:
        print(f"error: {error}", file=sys.stderr)
        raise SystemExit(1)
