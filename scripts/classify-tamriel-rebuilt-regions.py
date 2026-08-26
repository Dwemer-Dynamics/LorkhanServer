#!/usr/bin/env python3
"""Assign Tamriel Rebuilt Oghma regions and NPC knowledge classes from TES3 records."""

from __future__ import annotations

import argparse
from collections import Counter, defaultdict
import hashlib
import json
from pathlib import Path
import re
import requests
import struct
import time
from typing import Any, Iterable


FORBIDDEN_NPC_CLASSES = {"blocked", "common", "esoteric", "knowall"}
WATER_REGION_CLASSES = {"padomaic_ocean", "sea_of_ghosts"}
REGION_CLASS_OVERRIDES = {"azura's coast region": "azuras_coast"}
LEGACY_REGION_CLASSES = {"azura_s_coast": "azuras_coast"}
OPENROUTER_URL = "https://openrouter.ai/api/v1/chat/completions"
RACE_CLASSES = {
    "argonian": "argonian", "breton": "breton", "dark elf": "dunmer",
    "high elf": "altmer", "imperial": "imperial", "khajiit": "khajiit",
    "nord": "nord", "orc": "orc", "redguard": "redguard", "wood elf": "bosmer",
    "t_els_suthay": "khajiit", "t_els_cathay": "khajiit", "t_els_cathay-raht": "khajiit",
    "t_els_dagi-raht": "khajiit", "t_els_ohmes-raht": "khajiit", "t_els_ohmes": "khajiit",
    "t_els_tojay": "khajiit", "t_mw_malahk_orc": "orc",
    "t_cnq_keptu": "keptu", "t_cnq_chimeriquey": "chimeri_quey", "t_val_imga": "imga",
    "t_aka_tsaesci": "tsaesci", "t_yne_ynesai": "ynesai", "t_sky_reachman": "reachman",
    "t_hr_riverfolk": "riverfolk",
}
ROLE_CLASSES = {
    "agent": ["traveler"],
    "alchemist": ["alchemist"], "alchemist service": ["alchemist", "merchant"],
    "apothecary service": ["alchemist"],
    "archer": ["warrior"], "assassin": ["thief", "warrior"], "barbarian": ["warrior"],
    "battlemage": ["mage", "warrior"], "blacksmith": ["blacksmith"],
    "crusader": ["priest", "warrior"], "enchanter service": ["mage", "merchant"],
    "guard": ["guard", "warrior"], "healer": ["healer"], "healer service": ["healer"],
    "hunter": ["hunter"], "mage": ["mage"], "mage service": ["mage"],
    "merchant": ["merchant"], "monk": ["priest"],
    "necromancer": ["mage"], "priest": ["priest"], "rogue": ["thief"],
    "ordinator": ["guard", "warrior"], "pawnbroker": ["merchant"],
    "priest service": ["priest"], "sailor": ["sailor"], "savant": ["scholar"],
    "scout": ["traveler"], "shipmaster": ["sailor"],
    "smith": ["blacksmith"], "sorcerer": ["mage"], "spellsword": ["mage", "warrior"],
    "thief": ["thief"], "thief service": ["thief"], "trader": ["merchant"],
    "trader service": ["merchant"], "warlock": ["mage"],
    "warrior": ["warrior"], "t_glb_sailor": ["sailor"], "t_glb_scribe": ["scholar"],
}
FACTION_CLASSES = {
    "ashlanders": "ashlander", "blades": "blades", "camonna tong": "camonna_tong",
    "dark brotherhood": "dark_brotherhood", "east empire company": "east_empire_company",
    "fighters guild": "fighters_guild", "hlaalu": "house_hlaalu", "imperial cult": "imperial_cult",
    "imperial legion": "imperial_legion", "mages guild": "mages_guild", "morag tong": "morag_tong",
    "redoran": "house_redoran", "telvanni": "house_telvanni", "temple": "tribunal_temple",
    "thieves guild": "thieves_guild", "twin lamps": "twin_lamps",
    "t_mw_houseindoril": "house_indoril", "t_mw_housedres": "house_dres",
    "t_mw_shinathi": "shinathi", "t_mw_imperialnavy": "imperial_navy",
    "t_cyr_imperialnavy": "imperial_navy", "t_mw_janattasyndicate": "ja_natta_syndicate",
    "census and excise": "census_and_excise", "t_mw_ordinators": "tribunal_temple",
    "t_glb_archaeologicalsociety": "imperial_archaeological_society",
    "t_mw_clan_baluath": "baluath_clan", "t_mw_clan_orlukh": "orlukh_clan",
    "tr_fact_narsisarena": "narsis_arena", "tr_fact_syvvittong": "syvvit_tong",
}
ARTICLE_SUBJECT_CLASSES = {
    "and_we_ate_it_to_become_it": ["tsaesci"], "imga": ["imga"],
    "keptu_quey": ["keptu"], "quey": ["keptu", "chimeri_quey"],
    "reachman": ["reachman"], "riverfolk": ["riverfolk"],
    "ynesai": ["ynesai"], "ynesai_toro": ["ynesai"],
    "shinathi": ["shinathi"], "imperial_navy": ["imperial_navy"],
    "ja_natta_syndicate": ["ja_natta_syndicate"],
    "census_and_excise_office": ["census_and_excise"],
    "imperial_archaeological_society": ["imperial_archaeological_society"],
    "baluath_clan": ["baluath_clan"], "orlukh_clan": ["orlukh_clan"],
    "narsis_arena": ["narsis_arena"], "syvvit_tong": ["syvvit_tong"],
}

TR_CATEGORY_OVERRIDES = {
    "face_of_veloth": "lore",
    "the_rift": "locationother",
    "tremor_march": "locationother",
    "exodus": "locationother",
    "gratitude_s_wake": "locationother",
    "muck_rat": "nedothril",
    "noloubal_bay": "sea_of_ghosts",
    "pryai_river": "roth_roryn",
    "elfmaid": "sea_of_ghosts",
    "nordic_warhammer": "equipment",
}
TR_EXCLUDED_TOPICS = {"from_godsdamn_kragenmoor", "antecedents_of_dwemer_law"}
TEXT_REPLACEMENTS = {
    "&quot;": '"',
    "Mages Guild guild guide": "Mages Guild guide",
    "miner miner": "miner",
    "Golgorm knows": "Golgrom knows",
    "Hallls of Mzanfelech": "Halls of Mzanfelech",
}


def decode(raw: bytes) -> str:
    return raw.split(b"\0", 1)[0].decode("cp1252", errors="replace").strip()


def slug(value: str) -> str:
    value = re.sub(r"\bregion\b", "", value, flags=re.IGNORECASE)
    return re.sub(r"[^a-z0-9]+", "_", value.casefold()).strip("_")


def key(value: str) -> str:
    return re.sub(r"[^a-z0-9]+", " ", value.casefold()).strip()


def subrecords(body: bytes) -> Iterable[tuple[bytes, bytes]]:
    position = 0
    while position + 8 <= len(body):
        kind = body[position:position + 4]
        size = struct.unpack_from("<I", body, position + 4)[0]
        start = position + 8
        end = start + size
        if end > len(body):
            raise ValueError("TES3 subrecord extends past record body")
        yield kind, body[start:end]
        position = end
    if position != len(body):
        raise ValueError("TES3 record has incomplete subrecord header")


def extract_world(path: Path, npc_ids: set[str]) -> tuple[list[dict[str, Any]], dict[str, list[dict[str, str]]]]:
    raw = path.read_bytes()
    cells: list[dict[str, Any]] = []
    placements: dict[str, list[dict[str, str]]] = defaultdict(list)
    position = 0
    while position + 16 <= len(raw):
        kind = raw[position:position + 4]
        size = struct.unpack_from("<I", raw, position + 4)[0]
        start, end = position + 16, position + 16 + size
        if end > len(raw):
            raise ValueError("TES3 record extends past file end")
        position = end
        if kind != b"CELL":
            continue
        name = ""
        region = ""
        flags = 0
        references: list[str] = []
        after_reference = False
        for sub_kind, value in subrecords(raw[start:end]):
            if sub_kind == b"FRMR":
                after_reference = True
            elif sub_kind == b"NAME":
                if after_reference:
                    references.append(decode(value))
                elif not name:
                    name = decode(value)
            elif sub_kind == b"RGNN" and not after_reference:
                region = decode(value)
            elif sub_kind == b"DATA" and not after_reference and len(value) >= 4:
                flags = struct.unpack_from("<I", value)[0]
        region_class = REGION_CLASS_OVERRIDES.get(region.casefold(), slug(region)) if region else ""
        cell = {"name": name, "region_name": region, "region_class": region_class,
                "interior": bool(flags & 1), "reference_count": len(references)}
        cells.append(cell)
        for record_id in references:
            if record_id.casefold() in npc_ids:
                placements[record_id.casefold()].append({
                    "cell": name, "region": region_class, "region_name": region,
                    "basis": "exterior_cell_region" if region_class else "interior_cell",
                })
    return cells, placements


def unique(values: Iterable[str]) -> list[str]:
    result: list[str] = []
    for value in values:
        if value and value not in result:
            result.append(value)
    return result


def clean_tr_aliases(values: Iterable[str]) -> list[str]:
    """Discard scraped template fields and split malformed UESP cell-name lists."""
    aliases: list[str] = []
    for raw in values:
        value = re.sub(r"</?br\s*/?>", ";", str(raw), flags=re.IGNORECASE)
        value = re.sub(r"</?[^>]+>", "", value)
        for fragment in re.split(r"\s*;\s*", value):
            fragment = fragment.strip(" \"',")
            fragment = fragment.replace("Hallls of Mzanfelech", "Halls of Mzanfelech")
            fragment = re.sub(r"[\"']?\s*\((?:sic|exterior)\)$", "", fragment, flags=re.IGNORECASE).strip()
            if (not fragment or fragment.startswith("|") or "=" in fragment
                    or any(character in fragment for character in "<>|{}[]")):
                continue
            aliases.append(fragment)
    return unique(aliases)


def apply_tr_editorial_cleanup(catalog: list[dict[str, Any]]) -> list[dict[str, Any]]:
    """Apply reviewed TR corrections before regional classification and packaging."""
    output: list[dict[str, Any]] = []
    for original in catalog:
        topic = str(original.get("topic", ""))
        if topic in TR_EXCLUDED_TOPICS:
            continue
        row = dict(original)
        for field in ("title", "topic_desc", "topic_desc_basic"):
            value = str(row.get(field, ""))
            for old, new in TEXT_REPLACEMENTS.items():
                value = value.replace(old, new)
            row[field] = value
        if row.get("mod_source") == "TR_Mainland.esm":
            row["category"] = TR_CATEGORY_OVERRIDES.get(topic, row.get("category"))
            row["aliases"] = clean_tr_aliases(row.get("aliases", []))
            if row["category"] == "artifacts":
                row["knowledge_class"] = [value for value in row.get("knowledge_class", []) if value != "mage"]
            elif row["category"] == "ingredients":
                row["knowledge_class"] = unique([
                    value for value in row.get("knowledge_class", []) if value != "hunter"
                ] + ["healer"])
            elif topic == "nordic_warhammer":
                row["knowledge_class"] = ["blacksmith", "warrior", "nord"]
                row["tags"] = [value for value in row.get("tags", []) if str(value).casefold() != "artifact"]
            row["knowledge_class"] = unique([
                *row.get("knowledge_class", []), *ARTICLE_SUBJECT_CLASSES.get(topic, []),
            ])
            # Every subject keeps a public basic response, including explicit ignorance text.
            row["knowledge_class_basic"] = ["common"]
            row["knowledge_class"] = [value for value in row["knowledge_class"] if value != "common"]
        output.append(row)

    # The game spells the book title "Antecedants"; retain the corrected spelling as an alias.
    for row in output:
        if row.get("topic") == "antecedants_of_dwemer_law":
            row["aliases"] = unique([*row.get("aliases", []), "Antecedents of Dwemer Law"])
    return output


def apply_profile_text_cleanup(profile: dict[str, Any]) -> dict[str, Any]:
    """Correct the small reviewed typo set without otherwise rewriting biographies."""
    row = dict(profile)
    for field in ("core", "npc_static_bio", "relationships"):
        value = str(row.get(field, ""))
        for old, new in TEXT_REPLACEMENTS.items():
            value = value.replace(old, new)
        row[field] = value
    return row


def infer_named_cell_regions(cells: list[dict[str, Any]]) -> dict[str, str]:
    direct: dict[str, set[str]] = defaultdict(set)
    for cell in cells:
        if cell["name"] and cell["region_class"]:
            direct[key(cell["name"])].add(cell["region_class"])
    return {name: next(iter(regions)) for name, regions in direct.items() if len(regions) == 1}


def infer_interior_region(cell_name: str, named_regions: dict[str, str]) -> str:
    normalized = key(cell_name)
    variants = unique([normalized, re.sub(r"^(?:tr hold|tem)\s+", "", normalized).strip()])
    candidates = [(len(name), region) for name, region in named_regions.items()
                  if any(value == name or value.startswith(name + " ") for value in variants)]
    return max(candidates)[1] if candidates else ""


def infer_profile_regions(profile: dict[str, Any], place_regions: dict[str, str]) -> list[str]:
    """Use already-reviewed stable biography prose only when it names one mapped place."""
    prose = key(" ".join(str(profile.get(field, "")) for field in
                         ("core", "npc_static_bio", "occupation")))
    padded_prose = f" {prose} "
    matches: list[tuple[int, str]] = []
    for place, region in place_regions.items():
        if len(place) < 5:
            continue
        if f" {place} " in padded_prose:
            matches.append((len(place), region))
    if not matches:
        return []
    longest = max(length for length, _ in matches)
    return unique(region for length, region in matches if length == longest)


def article_region(article: dict[str, Any], regions: dict[str, str], named_regions: dict[str, str],
                   canonical_regions: dict[str, str] | None = None) -> tuple[str, str]:
    identities = [str(article.get("title", "")), *[str(v) for v in article.get("aliases", [])]]
    canonical_regions = canonical_regions or regions
    canonical_identity = unique(canonical_regions[key(value)] for value in identities
                                if key(value) in canonical_regions)
    if len(canonical_identity) == 1:
        return canonical_identity[0], "exact_esm_region_identity"
    canonical_tags = unique(canonical_regions[key(str(value))] for value in article.get("tags", [])
                            if key(str(value)) in canonical_regions)
    canonical_land = [value for value in canonical_tags if value not in WATER_REGION_CLASSES]
    canonical_matches = canonical_land or canonical_tags
    if len(canonical_matches) == 1:
        return canonical_matches[0], "explicit_esm_region_relationship"
    identity_matches = unique(regions[key(value)] for value in identities if key(value) in regions)
    if len(identity_matches) == 1:
        return identity_matches[0], "exact_place_or_region_identity"
    tag_matches = unique(regions[key(str(value))] for value in article.get("tags", [])
                         if key(str(value)) in regions)
    land_matches = [value for value in tag_matches if value not in WATER_REGION_CLASSES]
    matches = land_matches or tag_matches
    if len(matches) == 1:
        return matches[0], "explicit_region_relationship"
    for value in identities:
        inferred = infer_interior_region(value, named_regions)
        if inferred:
            return inferred, "named_cell_prefix"
    return "", "unresolved"


def classify_articles(catalog: list[dict[str, Any]], region_names: dict[str, str],
                      named_regions: dict[str, str], resolutions: dict[str, str] | None = None,
                      ) -> tuple[list[dict[str, Any]], list[dict[str, Any]], dict[str, str]]:
    """Propagate ESM regions through reviewed region, settlement, and place relationships."""
    catalog = [({**row, "aliases": clean_tr_aliases(row.get("aliases", []))}
                if row.get("mod_source") == "TR_Mainland.esm" else row) for row in catalog]
    aliases = dict(region_names)
    allowed_regions = set(region_names.values())
    for row in catalog:
        category = str(row.get("category", ""))
        if row.get("mod_source") != "TR_Mainland.esm" or category not in allowed_regions:
            continue
        for value in [str(row.get("title", "")), *[str(v) for v in row.get("aliases", [])]]:
            aliases.setdefault(key(value), category)
    resolved: dict[str, tuple[str, str]] = {}
    candidates = [row for row in catalog if row.get("mod_source") == "TR_Mainland.esm"
                  and row.get("category") in {"locations", "settlements", "regions"}]
    candidate_topics = {str(row["topic"]) for row in candidates}
    for _ in range(8):
        changed = False
        for row in candidates:
            topic = str(row["topic"])
            if topic in resolved:
                continue
            region, basis = article_region(row, aliases, named_regions, region_names)
            if not region:
                continue
            resolved[topic] = (region, basis)
            for value in [str(row.get("title", "")), *[str(v) for v in row.get("aliases", [])]]:
                normalized = key(value)
                if normalized and normalized not in aliases:
                    aliases[normalized] = region
                    changed = True
        if not changed:
            break
    for row in candidates:
        selected = (resolutions or {}).get(str(row["topic"]), "")
        if str(row["topic"]) not in resolved and selected in allowed_regions:
            resolved[str(row["topic"])] = (selected, "bounded_glm_resolution")
            aliases.setdefault(key(str(row.get("title", ""))), selected)
    for _ in range(8):
        changed = False
        for row in candidates:
            topic = str(row["topic"])
            if topic in resolved:
                continue
            region, basis = article_region(row, aliases, named_regions, region_names)
            if not region:
                continue
            resolved[topic] = (region, "propagated_after_glm:" + basis)
            for value in [str(row.get("title", "")), *[str(v) for v in row.get("aliases", [])]]:
                normalized = key(value)
                if normalized and normalized not in aliases:
                    aliases[normalized] = region
                    changed = True
        if not changed:
            break
    output: list[dict[str, Any]] = []
    audit: list[dict[str, Any]] = []
    for original in catalog:
        row = dict(original)
        if row.get("mod_source") == "TR_Mainland.esm":
            tag_keys = {key(str(value)) for value in row.get("tags", [])}
            durable_factions = []
            if "house indoril" in tag_keys or "indoril" in tag_keys:
                durable_factions.append("house_indoril")
            if "house dres" in tag_keys:
                durable_factions.append("house_dres")
            row["knowledge_class"] = unique([
                *row.get("knowledge_class", []), *durable_factions,
                *ARTICLE_SUBJECT_CLASSES.get(str(row.get("topic", "")), []),
            ])
        if str(row.get("topic", "")) in candidate_topics:
            region, basis = resolved.get(str(row["topic"]), ("", "unresolved"))
            if region:
                row["category"] = region
                row["knowledge_class"] = unique([region, *row.get("knowledge_class", [])])
            audit.append({"topic": row["topic"], "region": region, "basis": basis,
                          "status": "classified" if region else "unresolved"})
        output.append(row)
    return output, audit, aliases


def resolution_candidates(article: dict[str, Any], aliases: dict[str, str]) -> list[str]:
    values = [str(article.get("title", "")), *[str(v) for v in article.get("aliases", [])],
              *[str(v) for v in article.get("tags", [])]]
    return unique(aliases[key(value)] for value in values if key(value) in aliases)


def resolve_articles_with_glm(catalog: list[dict[str, Any]], audit: list[dict[str, Any]],
                              aliases: dict[str, str], path: Path, api_key: str, model: str,
                              max_cost: float | None) -> tuple[dict[str, str], dict[str, Any]]:
    """Resolve only physical-place ambiguities against a closed ESM-region candidate list."""
    existing: dict[str, Any] = json.loads(path.read_text(encoding="utf-8")) if path.is_file() else {}
    decisions = dict(existing.get("decisions", {}))
    usage = list(existing.get("usage", []))
    total_cost = sum(float(row.get("cost") or 0.0) for row in usage)
    unresolved = {row["topic"] for row in audit if row["status"] == "unresolved"}
    by_topic = {str(row["topic"]): row for row in catalog}
    session = requests.Session()
    for topic in sorted(unresolved):
        article = by_topic[topic]
        if article.get("category") not in {"locations", "settlements", "regions"} or topic in decisions:
            continue
        candidates = resolution_candidates(article, aliases)
        if len(candidates) < 2:
            continue
        if max_cost is not None and total_cost >= max_cost:
            raise RuntimeError(f"GLM cost ceiling reached at ${total_cost:.6f}")
        allowed = [*candidates, "abstain"]
        schema = {"type": "object", "additionalProperties": False, "properties": {
            "selected_region": {"type": "string", "enum": allowed},
            "basis": {"type": "string", "maxLength": 240},
        }, "required": ["selected_region", "basis"]}
        evidence = {
            "title": article.get("title"), "aliases": article.get("aliases", []),
            "current_article": article.get("topic_desc", ""), "relationship_terms": article.get("tags", []),
            "allowed_regions": candidates,
        }
        request = {
            "model": model, "temperature": 0.0, "max_tokens": 220, "reasoning": {"effort": "none"},
            "messages": [{"role": "system", "content": (
                "Classify one physical Tamriel Rebuilt place into the single supplied ESM gameplay region that "
                "contains it at the start of 3E 427. Nearby regions, travel destinations, historical associations, "
                "and parent administrative districts do not establish containment. Select abstain unless the supplied "
                "article directly supports exactly one candidate. Return only the required JSON object.")},
                {"role": "user", "content": json.dumps(evidence, ensure_ascii=False)}],
            "response_format": {"type": "json_schema", "json_schema": {
                "name": "tr_region_resolution", "strict": True, "schema": schema}},
        }
        last_error: Exception | None = None
        for attempt in range(3):
            try:
                response = session.post(OPENROUTER_URL, headers={
                    "Authorization": f"Bearer {api_key}", "Content-Type": "application/json",
                    "HTTP-Referer": "https://dwemerdynamics.com/", "X-Title": "ALMSIVI TR Region Classifier",
                }, json=request, timeout=90)
                response.raise_for_status()
                payload = response.json()
                content = payload["choices"][0]["message"].get("content")
                if not isinstance(content, str) or not content.strip():
                    raise ValueError("provider returned no structured content")
                result = json.loads(content)
                break
            except (requests.RequestException, KeyError, IndexError, TypeError, ValueError, json.JSONDecodeError) as error:
                last_error = error
                if attempt < 2:
                    time.sleep(2 ** attempt)
        else:
            raise RuntimeError(f"GLM resolution failed for {topic}: {last_error}")
        selected = str(result["selected_region"])
        decisions[topic] = {"selected_region": selected, "basis": str(result["basis"]),
                            "candidates": candidates}
        provider_usage = payload.get("usage") or {}
        usage_row = {"topic": topic, "model": payload.get("model") or model,
                     "provider": payload.get("provider"), "prompt_tokens": provider_usage.get("prompt_tokens"),
                     "completion_tokens": provider_usage.get("completion_tokens"),
                     "cost": provider_usage.get("cost")}
        usage.append(usage_row)
        total_cost += float(usage_row.get("cost") or 0.0)
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(json.dumps({"decisions": decisions, "usage": usage}, ensure_ascii=False, indent=2) + "\n",
                        encoding="utf-8")
    selected = {topic: str(value.get("selected_region", "")) for topic, value in decisions.items()
                if value.get("selected_region") != "abstain"}
    return selected, {"calls": len(usage), "cost": total_cost,
                      "resolved": len(selected), "abstained": sum(
                          value.get("selected_region") == "abstain" for value in decisions.values())}


def validate_outputs(original_profiles: list[dict[str, Any]], profiles: list[dict[str, Any]],
                     articles: list[dict[str, Any]], article_audit: list[dict[str, Any]],
                     ontology: dict[str, Any]) -> list[str]:
    """Enforce the shared class/category contract before classified artifacts are accepted."""
    errors: list[str] = []
    allowed_classes = set(ontology["knowledge_classes"])
    allowed_categories = set(ontology["categories"])
    if len(profiles) != len(original_profiles):
        errors.append("NPC row count changed")
    originals = {str(row["refid"]).casefold(): apply_profile_text_cleanup(row) for row in original_profiles}
    for row in profiles:
        record_id = str(row["refid"]).casefold()
        classes = unique(value.strip() for value in str(row.get("oghma_knowledge_tags", "")).split(","))
        if not classes:
            errors.append(f"NPC {row['refid']} has no knowledge class")
        unknown = set(classes) - allowed_classes
        if unknown:
            errors.append(f"NPC {row['refid']} has unknown classes: {sorted(unknown)}")
        forbidden = set(classes) & FORBIDDEN_NPC_CLASSES
        if forbidden:
            errors.append(f"NPC {row['refid']} has forbidden classes: {sorted(forbidden)}")
        original = originals.get(record_id)
        if original is None:
            errors.append(f"NPC {row['refid']} was not present in the source profiles")
        elif any(row.get(field) != original.get(field) for field in row if field != "oghma_knowledge_tags"):
            errors.append(f"NPC {row['refid']} biography prose or identity changed")
    by_topic = {str(row["topic"]): row for row in articles}
    for row in articles:
        if row.get("category") not in allowed_categories:
            errors.append(f"article {row['topic']} has unknown category {row.get('category')}")
        advanced = set(row.get("knowledge_class", []))
        basic = set(row.get("knowledge_class_basic", []))
        if not advanced or advanced - allowed_classes:
            errors.append(f"article {row['topic']} has empty or unknown advanced classes")
        if basic - allowed_classes or advanced & basic:
            errors.append(f"article {row['topic']} has invalid basic classes")
        if not str(row.get("topic_desc_basic", "")).strip() or not basic:
            errors.append(f"article {row['topic']} does not preserve mandatory basic knowledge")
    for audit in article_audit:
        if audit["status"] != "classified":
            continue
        row = by_topic[str(audit["topic"])]
        if row["category"] != audit["region"] or audit["region"] not in row["knowledge_class"]:
            errors.append(f"article {audit['topic']} does not mirror its regional category and class")
    return errors


def json_bytes(value: Any) -> bytes:
    return (json.dumps(value, ensure_ascii=False, indent=2) + "\n").encode("utf-8")


def write_packages(args: argparse.Namespace, articles: list[dict[str, Any]], profiles: list[dict[str, Any]],
                   review_results: list[dict[str, Any]], glm_summary: dict[str, Any]) -> None:
    """Write importer-ready Oghma and combined-biography packages from validated rows."""
    script_sha = hashlib.sha256(Path(__file__).read_bytes()).hexdigest()
    ontology_sha = hashlib.sha256(args.ontology.read_bytes()).hexdigest()
    if args.catalog_package_dir is not None:
        package = args.catalog_package_dir
        package.mkdir(parents=True, exist_ok=True)
        articles_raw = json_bytes(articles)
        (package / "articles.json").write_bytes(articles_raw)
        article_categories = {str(row["topic"]): str(row["category"]) for row in articles}
        seeds = json.loads(args.base_topic_seeds.read_text(encoding="utf-8"))
        seeds["topics"] = [row for row in seeds["topics"] if str(row.get("topic", "")) not in TR_EXCLUDED_TOPICS]
        for row in seeds["topics"]:
            topic = str(row["topic"])
            if topic in article_categories:
                row["category"] = article_categories[topic]
            if row.get("mod_source") == "TR_Mainland.esm":
                row["aliases"] = clean_tr_aliases(row.get("aliases", []))
        seeds_by_topic = {str(row["topic"]): row for row in seeds["topics"]}
        for article in articles:
            if article.get("mod_source") != "TR_Mainland.esm" or article["topic"] in seeds_by_topic:
                continue
            seeds["topics"].append({
                "topic": article["topic"], "title": article["title"],
                "category": article["category"], "profile": "specialist",
                "aliases": article.get("aliases", []), "classes": article.get("knowledge_class", []),
                "basic_mode": "common", "preflight": False,
                "record_links": article.get("record_links", []),
                "include_dialogue_evidence": False, "uesp_titles": [],
                "domain_instructions": "Preserve the reviewed catalog article verbatim.",
                "mod_source": "TR_Mainland.esm",
            })
        seeds["topics"].sort(key=lambda row: str(row["topic"]))
        seeds_raw = json_bytes(seeds)
        (package / "tamriel-rebuilt-topic-seeds.json").write_bytes(seeds_raw)
        base_manifest = json.loads(args.base_catalog_manifest.read_text(encoding="utf-8"))
        manifest = dict(base_manifest)
        manifest.update({
            "catalog_version": args.catalog_version, "row_count": len(articles),
            "articles_sha256": hashlib.sha256(articles_raw).hexdigest(),
            "topic_seeds_sha256": hashlib.sha256(seeds_raw).hexdigest(),
            "tamriel_rebuilt_topic_seeds_sha256": hashlib.sha256(seeds_raw).hexdigest(),
            "ontology_sha256": ontology_sha, "generator_sha256": script_sha,
            "builder_sha256": script_sha,
            "editorial_decisions_sha256": hashlib.sha256(args.editorial_decisions.read_bytes()).hexdigest(),
            "assembly_method": "v5.17-plus-esm-regional-classification",
            "category_counts": dict(sorted(Counter(str(row["category"]) for row in articles).items())),
            "regional_classification": {
                "ruleset": "tr-mainland-esm-regions-v1", "glm_calls": glm_summary["calls"],
                "glm_cost": glm_summary["cost"], "glm_resolved": glm_summary["resolved"],
                "glm_abstained": glm_summary["abstained"],
            },
        })
        (package / "manifest.json").write_bytes(json_bytes(manifest))
        (package / "catalog-version.txt").write_text(args.catalog_version + "\n", encoding="utf-8")
    if args.biography_package_dir is not None:
        package = args.biography_package_dir
        package.mkdir(parents=True, exist_ok=True)
        base_profiles = json.loads(args.base_biographies.read_text(encoding="utf-8"))
        tr_ids = {str(row["refid"]).casefold() for row in profiles}
        base_profiles = [row for row in base_profiles if str(row["refid"]).casefold() not in tr_ids]
        combined = sorted([*base_profiles, *profiles], key=lambda row: (
            str(row["npc_name"]).casefold(), str(row["refid"]).casefold()))
        duplicate_names = {name for name, count in Counter(
            str(row["npc_name"]).casefold() for row in combined).items() if count > 1}
        combined = [({**row, "npc_name": f'{row["npc_name"]}_{hashlib.sha256(str(row["refid"]).encode()).hexdigest()[:8]}'}
                    if str(row["npc_name"]).casefold() in duplicate_names else row) for row in combined]
        biographies_raw = json_bytes(combined)
        (package / "biographies.json").write_bytes(biographies_raw)
        base_manifest = json.loads(args.base_biography_manifest.read_text(encoding="utf-8"))
        base_items = [row for row in base_manifest["items"]
                      if str(row["record_id"]).casefold() not in tr_ids]
        tr_items = [{
            **result["identity"], "evidence_status": "complete", "evidence_error": None,
            "generation_status": "complete", "generation_error": None,
            "telemetry": result.get("generation", {}).get("telemetry", {}),
        } for result in review_results]
        manifest = {
            "format": "almsivi.morrowind-biography-preflight.v1",
            "catalog_version": args.biography_catalog_version,
            "selected_count": len(combined), "completed_count": len(combined), "failed_count": 0,
            "official_content_sha256": {
                **base_manifest["official_content_sha256"],
                "TR_Mainland.esm": hashlib.sha256(args.esm.read_bytes()).hexdigest(),
            },
            "builder_sha256": script_sha, "model": "z-ai/glm-5.1 plus deterministic regional classifier",
            "items": [*base_items, *tr_items],
            "biographies_sha256": hashlib.sha256(biographies_raw).hexdigest(),
            "regional_classification": {"ruleset": "tr-mainland-esm-regions-v1"},
        }
        (package / "manifest.json").write_bytes(json_bytes(manifest))
        (package / "catalog-version.txt").write_text(args.biography_catalog_version + "\n", encoding="utf-8")


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--esm", type=Path, required=True)
    parser.add_argument("--catalog", type=Path, required=True)
    parser.add_argument("--ontology", type=Path, default=Path(__file__).resolve().parents[1] /
                        "resources" / "oghma" / "morrowind-official" / "ontology.json")
    parser.add_argument("--npc-review", type=Path, required=True)
    parser.add_argument("--npc-profiles", type=Path, required=True)
    parser.add_argument("--output-dir", type=Path, required=True)
    parser.add_argument("--write-classified", action="store_true")
    parser.add_argument("--resolve-with-glm", action="store_true")
    parser.add_argument("--api-key-env", default="OPENROUTER_API_KEY")
    parser.add_argument("--model", default="z-ai/glm-5.1")
    parser.add_argument("--max-glm-cost", type=float, default=1.0)
    parser.add_argument("--catalog-package-dir", type=Path)
    parser.add_argument("--catalog-version", default="morrowind-official-3e427-v5.20")
    parser.add_argument("--base-catalog-manifest", type=Path, default=Path(__file__).resolve().parents[1] /
                        "resources" / "oghma" / "morrowind-official" / "catalogs" /
                        "morrowind-official-3e427-v5.17" / "manifest.json")
    parser.add_argument("--base-topic-seeds", type=Path, default=Path(__file__).resolve().parents[1] /
                        "resources" / "oghma" / "morrowind-official" / "catalogs" /
                        "morrowind-official-3e427-v5.17" / "tamriel-rebuilt-topic-seeds.json")
    parser.add_argument("--editorial-decisions", type=Path, default=Path(__file__).resolve().parents[1] /
                        "resources" / "oghma" / "morrowind-official" / "editorial-decisions.json")
    parser.add_argument("--biography-package-dir", type=Path)
    parser.add_argument("--biography-catalog-version", default="morrowind-official-2026-08-biographies-v5")
    parser.add_argument("--base-biographies", type=Path, default=Path(__file__).resolve().parents[1] /
                        "resources" / "biographies" / "morrowind-official" / "biographies.json")
    parser.add_argument("--base-biography-manifest", type=Path, default=Path(__file__).resolve().parents[1] /
                        "resources" / "biographies" / "morrowind-official" / "manifest.json")
    args = parser.parse_args()

    review = json.loads(args.npc_review.read_text(encoding="utf-8"))
    results = review["results"]
    profiles = json.loads(args.npc_profiles.read_text(encoding="utf-8"))
    ontology = json.loads(args.ontology.read_text(encoding="utf-8"))
    profile_by_refid = {str(row["refid"]).casefold(): row for row in profiles}
    npc_ids = {str(row["identity"]["record_id"]).casefold() for row in results}
    cells, placements = extract_world(args.esm, npc_ids)
    named_regions = infer_named_cell_regions(cells)
    region_names: dict[str, str] = {}
    for cell in cells:
        if not cell["region_name"]:
            continue
        region_names[key(cell["region_name"])] = cell["region_class"]
        region_names[key(re.sub(r"\bregion\b", "", cell["region_name"], flags=re.IGNORECASE))] = cell["region_class"]
    region_classes = sorted(set(region_names.values()))

    catalog = apply_tr_editorial_cleanup(json.loads(args.catalog.read_text(encoding="utf-8")))
    resolution_path = args.output_dir / "glm-resolutions.json"
    stored = json.loads(resolution_path.read_text(encoding="utf-8")) if resolution_path.is_file() else {}
    stored_resolutions = {topic: LEGACY_REGION_CLASSES.get(
                              str(value.get("selected_region", "")), str(value.get("selected_region", "")))
                          for topic, value in stored.get("decisions", {}).items()
                          if value.get("selected_region") != "abstain"}
    classified_catalog, article_audit, place_regions = classify_articles(
        catalog, region_names, named_regions, stored_resolutions)
    glm_summary = {"calls": len(stored.get("usage", [])), "cost": sum(
        float(row.get("cost") or 0.0) for row in stored.get("usage", [])),
        "resolved": len(stored_resolutions), "abstained": sum(
            value.get("selected_region") == "abstain" for value in stored.get("decisions", {}).values())}
    if args.resolve_with_glm:
        api_key = str(__import__("os").environ.get(args.api_key_env, "")).strip()
        if not api_key:
            raise RuntimeError(f"{args.api_key_env} is required for --resolve-with-glm")
        new_resolutions, glm_summary = resolve_articles_with_glm(
            catalog, article_audit, place_regions, resolution_path, api_key, args.model, args.max_glm_cost)
        classified_catalog, article_audit, place_regions = classify_articles(
            catalog, region_names, named_regions, new_resolutions)
    named_regions.update(place_regions)

    classified_profiles: list[dict[str, Any]] = []
    npc_audit: list[dict[str, Any]] = []
    for result in results:
        identity = result["identity"]
        record_id = str(identity["record_id"])
        row = apply_profile_text_cleanup(profile_by_refid[record_id.casefold()])
        npc_placements = placements.get(record_id.casefold(), [])
        placement_regions = unique(p["region"] for p in npc_placements if p["region"])
        if not placement_regions:
            inferred = unique(infer_interior_region(p["cell"], named_regions) for p in npc_placements)
            placement_regions = [value for value in inferred if value]
        regional = placement_regions if len(placement_regions) == 1 else []
        race = RACE_CLASSES.get(str(identity.get("race_id", "")).casefold(), "")
        roles = ROLE_CLASSES.get(str(identity.get("class_id", "")).casefold(), [])
        faction = FACTION_CLASSES.get(str(identity.get("faction_id", "")).casefold(), "")
        classes = [value for value in unique([race, *roles, faction, *regional]) if value not in FORBIDDEN_NPC_CLASSES]
        row["oghma_knowledge_tags"] = ", ".join(classes)
        classified_profiles.append(row)
        npc_audit.append({"record_id": record_id, "classes": classes, "placements": npc_placements,
                          "regional_classes": regional, "status": "classified" if regional else (
                              "ambiguous" if len(placement_regions) > 1 else "no_region")})

    args.output_dir.mkdir(parents=True, exist_ok=True)
    payloads = {
        "regions.json": {"source_sha256": hashlib.sha256(args.esm.read_bytes()).hexdigest(),
                         "region_classes": region_classes,
                         "cell_counts": dict(sorted(Counter(c["region_class"] for c in cells if c["region_class"]).items()))},
        "npc-classification-audit.json": npc_audit,
        "article-classification-audit.json": article_audit,
        "summary.json": {
            "region_count": len(region_classes), "cell_count": len(cells),
            "npc_count": len(npc_audit), "npc_with_region": sum(bool(r["regional_classes"]) for r in npc_audit),
            "npc_ambiguous": sum(r["status"] == "ambiguous" for r in npc_audit),
            "articles_considered": len(article_audit),
            "articles_classified": sum(r["status"] == "classified" for r in article_audit),
            "glm": glm_summary,
        },
    }
    if args.write_classified:
        payloads["biographies.classified.json"] = classified_profiles
        payloads["articles.classified.json"] = classified_catalog
    validation_errors = validate_outputs(profiles, classified_profiles, classified_catalog, article_audit, ontology)
    payloads["validation.json"] = {"valid": validation_errors == [], "errors": validation_errors}
    for name, payload in payloads.items():
        (args.output_dir / name).write_text(json.dumps(payload, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    print(json.dumps(payloads["summary.json"], indent=2))
    if validation_errors:
        raise RuntimeError(f"classification validation failed with {len(validation_errors)} errors")
    write_packages(args, classified_catalog, classified_profiles, results, glm_summary)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
