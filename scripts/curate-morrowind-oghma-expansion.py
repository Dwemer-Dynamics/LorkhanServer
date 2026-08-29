#!/usr/bin/env python3
"""Curate an expanded Morrowind Oghma seed inventory with checkpointed GLM review."""

from __future__ import annotations

import argparse
from collections import Counter
import hashlib
import json
import os
from pathlib import Path
import re
import time
from typing import Any

import requests


DEFAULT_MODEL = "z-ai/glm-5.1"
OPENROUTER_URL = "https://openrouter.ai/api/v1/chat/completions"
SCRIPT_DIR = Path(__file__).resolve().parent
RESOURCE_DIR = SCRIPT_DIR.parent / "resources" / "oghma" / "morrowind-official"
DEFAULT_AUDIT = SCRIPT_DIR.parent / "build" / "oghma-expansion-audit" / "candidates.json"
DEFAULT_SEEDS = RESOURCE_DIR / "topic-seeds.json"
DEFAULT_ONTOLOGY = RESOURCE_DIR / "ontology.json"
LINKED_RECORD_TYPES = {"NPC_", "CREA", "WEAP", "ARMO", "CLOT", "MISC", "BOOK"}
PROFILE_BY_CATEGORY = {
    "lore": "scholarly", "history": "scholarly", "religion": "religious", "factions": "faction",
    "races": "common", "cultures": "scholarly", "figures": "scholarly", "regions": "regional",
    "settlements": "regional", "locations": "regional", "creatures": "specialist", "diseases": "specialist",
    "magic": "scholarly", "alchemy": "specialist", "artifacts": "esoteric",
    "books": "scholarly", "equipment": "specialist", "ingredients": "specialist",
}
REASON_CODES = {
    "major", "stable_specialist", "minor", "ordinary_actor", "generic_object", "routine_spell",
    "quest_transient", "conversation_noise", "mechanic", "uncertain",
}
# Stable score-3 subjects manually reviewed after the strict pass. This is deliberately bounded to the
# minimum catalog target and excludes generic ingredients, ordinary actors, routine spells, and quest chatter.
PROMOTED_BORDERLINE_TOPICS = {
    "antecedents_of_dwemer_law", "king_llethan_s_death", "ordinators", "mythopoeic_enchantments",
    "shrines", "dissident_priests", "imperial_law", "ald_daedroth", "vampires", "ebony_trade",
    "discontent_in_the_temple", "enchanting_items", "fort_frostmoth", "bal_molagmer", "buoyant_armigers",
    "false_incarnate", "maar_gan", "zainab_camp", "soul_sickness", "ald_velothi", "erabenimsun_camp",
    "ald_sotha", "pelagiad", "urshilaku_camp", "godsreach", "high_ordinators", "slave_rebellion",
    "ahemmusa_camp", "ice_blade_of_the_monarch", "alchemical_formulas", "imperial_cult_doctrine",
    "mages_guild_monopoly", "pilgrimages", "trebonius", "writ", "plaza_brindisi_dorom", "seven_graces",
    "abolitionists", "fields_of_kummu", "dwemer_language", "lake_fjalding", "dreams_and_visions",
    "ash_vampire", "atronachs",
    "adamantium_ore", "divine_metaphysics", "smuggling", "conspiracy_against_the_emperor",
    "morrowind_s_economy", "peace_in_black_marsh", "recall_the_legions", "other_cults", "saints",
    "false_gods", "oneness", "apographa", "ashlander_worship", "seven_curses", "study_for_the_priesthood",
    "suppress_the_apographa", "redoran_councilors", "necromancers", "serving_great_houses",
    "caldera_mining_company", "slavehunters", "almoners", "argonian_mission", "ashlander_gifts",
    "ashlander_courtesy", "egg_mining", "foreigners", "ashlander_nomadic_camp_style",
    "ashlanders_and_foreigners", "ashlanders_in_war", "household_slaves", "literacy", "imperial_provinces",
    "skyrim", "rotheran", "arena", "stronghold", "odirniran", "lost_kogoruhn", "mount_kand", "bal_fell",
    "shishi", "nchuleft", "nchurdamz", "redas_tomb", "holamayan", "halls_of_the_dead",
    "ancestral_tomb", "daedric_sites", "eggmines", "telvanni_tower_urban_style", "ancestral_burial_tombs",
    "sewers_and_ruins", "goblins", "wolves", "snow_bears", "snow_wolves", "bears", "white_guar",
    "sleepers", "dwarven_ghost", "emperor_crab", "undead_creatures", "ash_ghoul", "the_udyrfrykte",
    "kagouti", "grahl", "alit", "ancestor_ghost", "ascended_sleeper", "ash_slave", "ash_zombie",
    "bonelord", "clannfear", "daedroth", "flame_atronach", "frost_atronach", "ogrim", "shalk",
    "winged_twilight", "blight_diseases", "corprus_weepings", "ash_chancre", "swamp_fever", "rockjoint",
    "enchantments", "daedric", "alteration", "becoming_a_lich", "restoration", "stalhrim_weapons",
    "founder_s_helm", "crosier_of_st_llothis", "bloodworm_helm", "bow_of_shadows", "dragonbone_cuirass",
    "eleidon_s_ward", "fang_of_haynekhtnamet", "helm_of_oreyn_bearclaw", "ring_of_phynaster",
    "robe_of_the_lich", "mantle_of_woe", "fork_of_horripilation", "mistress_therana", "dram_bero",
    "archmagister_gothren", "master_barelo", "mehra_milo", "plitinius_mero",
}

EXCLUDED_REVIEWED_TOPICS = {
    "abebaal_egg_mine", "alchemical_formulas", "alms_for_the_poor", "amulet_of_ashamanu", "anareren_s_devil_tanto", "argonian",
    "areth_an_mandas", "areth_an_mandas", "areth_mandas", "arethan_mandas", "ash_vampire",
    "ashlander_culture", "aurane_frernis", "balmora_fighters_guild", "black_jinx", "choosing_a_great_house",
    "bitter_coast_region", "corkbulb", "corprus_disease", "counsel_of_sul_matuul", "daedra_s_heart", "dagoth_ur_s_plans",
    "darts_of_judgement", "death_of_the_horkers", "disappearance_of_the_dwarves", "discerning_eye",
    "drake_s_pride", "dratha", "dwemer_artifact", "dwemer_battle_shield", "dwemer_puzzle_box", "dwemer_sites",
    "dwarven", "empire", "enchant", "erabenimsun", "favel_tomb", "fedris_tharen", "fire_salts", "guilds_and_factions",
    "hasphat_antabolis", "her_hands", "hlervu_locket", "ilunibi_shrine", "imperial_blades",
    "imperial", "imperial_guilds", "inside_the_ghostfence", "itermerel_s_notes", "j_saddha", "lesson_in_power",
    "llevule_andrano", "missing_hand", "morrowind_cultures", "morrowind_lore", "morvayn_manor",
    "nalcarya_of_white_haven", "neloth", "nerevar", "nord", "processus_ring", "pyroil_tar", "queen_mother",
    "reclaim_your_station", "repeal_of_the_war_tax", "salyn_sarethi", "shishi_report", "shulk_egg_mine",
    "seven_curses", "serving_great_houses", "sixth_house_cult", "the_moon_and_star", "therana", "threat_to_our_monarchy", "troubles_for_house_hlaalu",
    "troubles_for_house_redoran", "unique_dwemer_artifact", "urshilaku", "vampires", "vampires_of_vvardenfell",
    "visiting_an_ashlander_camp", "wild_nix_hounds",
}

CATEGORY_OVERRIDES = {
    "balmora_mages_guild": "locations",
    "balmora_temple": "locations",
    "dren_plantation": "locations",
    "four_corners": "religion",
    "gulakhan": "cultures",
    "gnisis_temple": "locations",
    "hlaalu_hortator": "lore",
    "hortator": "lore",
    "hortator_and_nerevarine": "lore",
    "quarra": "factions",
    "redoran_hortator": "lore",
    "sovngarde_a_reexamination": "lore",
    "telvanni_hortator": "lore",
    "telvanni_tower_urban_style": "cultures",
    "tel_fyr": "locations",
    "the_common_tongue": "cultures",
}

SYSTEM_PROMPT = """You are curating a static Morrowind encyclopedia for the CHIM Oghma Infinium system.
Classify every supplied official dialogue subject independently. Include only a stable, useful 3E 427 encyclopedia
subject: history, religion, culture, faction, race, region, settlement, significant location, creature species,
disease, magical tradition, major public or historical figure, or important artifact. Exclude ordinary NPCs,
generic equipment, routine spells, consumables, quest instructions, service chatter, transient incidents, errands,
rumors about player-caused outcomes, and subjects whose only value is numerical coverage. A named actor qualifies
only when the person is a major ruler, faction leader, central historical/religious figure, or otherwise broadly
important beyond one task. An object qualifies only when it is a recognized artifact or culturally important text.

Score 5 for foundational or major subjects, 4 for strong specialist/regional subjects, 3 for defensible but minor
subjects, and 1-2 for unsuitable noise. Use the exact candidate topic key, choose exactly one supplied category,
and choose one supplied reason code. Keep the output compact. Return one decision for every candidate and no extras.
"""

DECISION_SCHEMA = {
    "type": "object",
    "additionalProperties": False,
    "properties": {
        "decisions": {
            "type": "array",
            "items": {
                "type": "object",
                "additionalProperties": False,
                "properties": {
                    "topic": {"type": "string"},
                    "quality_score": {"type": "integer", "minimum": 1, "maximum": 5},
                    "category": {"type": "string"},
                    "reason_code": {"type": "string", "enum": sorted(REASON_CODES)},
                },
                "required": ["topic", "quality_score", "category", "reason_code"],
            },
        },
    },
    "required": ["decisions"],
}


def read_json(path: Path) -> Any:
    raw = path.read_bytes()
    if raw.startswith((b"\xff\xfe", b"\xfe\xff", b"\xff\xfe\x00\x00", b"\x00\x00\xfe\xff")):
        raise ValueError(f"File is not UTF-8: {path}")
    return json.loads(raw.decode("utf-8-sig"))


def atomic_json(path: Path, payload: Any) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    temporary = path.with_name(path.name + ".tmp")
    temporary.write_text(json.dumps(payload, indent=2, ensure_ascii=False) + "\n", encoding="utf-8", newline="\n")
    os.replace(temporary, path)


def normalized(value: str) -> str:
    return re.sub(r"[^a-z0-9]+", "", value.casefold())


def unique_strings(values: Any, *, reject: str = "") -> list[str]:
    result: list[str] = []
    seen = {normalized(reject)} if reject else set()
    for value in values if isinstance(values, list) else []:
        text = re.sub(r"\s+", " ", str(value)).strip().strip(",")
        key = normalized(text)
        if text and key and key not in seen:
            seen.add(key)
            result.append(text)
    return result


def current_cost(run_dir: Path, prior_cost: float = 0.0) -> float:
    total = prior_cost
    for path in sorted((run_dir / "batches").glob("attempt-*.json")):
        row = read_json(path)
        total += float((row.get("usage") or {}).get("cost") or 0.0)
    return total


def call_provider(
    session: requests.Session,
    api_key: str,
    model: str,
    candidates: list[dict[str, Any]],
    ontology: dict[str, Any],
    timeout: float,
) -> tuple[dict[str, Any], dict[str, Any]]:
    compact_candidates = [{
        "topic": row["topic"],
        "title": row["title"],
        "band": row["band"],
        "dialogue_response_count": row["response_count"],
        "official_sources": row["sources"],
        "record_matches": row.get("record_matches", []),
    } for row in candidates]
    user = json.dumps({
        "allowed_categories": ontology["categories"],
        "allowed_reason_codes": sorted(REASON_CODES),
        "candidates": compact_candidates,
    }, ensure_ascii=False)
    response_schema = json.loads(json.dumps(DECISION_SCHEMA))
    response_schema["properties"]["decisions"]["items"]["properties"]["category"]["enum"] = ontology["categories"]
    response = session.post(
        OPENROUTER_URL,
        headers={"Authorization": f"Bearer {api_key}", "Content-Type": "application/json"},
        json={
            "model": model,
            "messages": [{"role": "system", "content": SYSTEM_PROMPT}, {"role": "user", "content": user}],
            "temperature": 0.1,
            "max_tokens": 4000,
            "reasoning": {"effort": "none"},
            "response_format": {"type": "json_schema", "json_schema": {"name": "oghma_curation", "strict": True, "schema": response_schema}},
        },
        timeout=timeout,
    )
    response.raise_for_status()
    payload = response.json()
    choices = payload.get("choices") or []
    if not choices:
        raise ValueError("OpenRouter returned no curation choices")
    content = choices[0].get("message", {}).get("content")
    if isinstance(content, list):
        content = "".join(str(part.get("text", "")) for part in content if isinstance(part, dict))
    result = json.loads(str(content))
    usage = payload.get("usage") or {}
    return result, {
        "cost": usage.get("cost"),
        "prompt_tokens": usage.get("prompt_tokens"),
        "completion_tokens": usage.get("completion_tokens"),
        "provider": payload.get("provider"),
        "model": payload.get("model") or model,
    }


def validate_decisions(
    result: Any,
    candidates: list[dict[str, Any]],
    ontology: dict[str, Any],
    *,
    require_all: bool = True,
) -> list[dict[str, Any]]:
    rows = result.get("decisions") if isinstance(result, dict) else None
    if not isinstance(rows, list):
        raise ValueError("Curation result does not contain a decisions array")
    expected = {str(row["topic"]): row for row in candidates}
    expected_names: dict[str, str] = {}
    for canonical, candidate in expected.items():
        for value in [canonical, str(candidate["title"])]:
            key = normalized(value)
            if key and key not in expected_names:
                expected_names[key] = canonical
    if require_all and len(rows) != len(expected):
        raise ValueError(f"Curation returned {len(rows)} decisions for {len(expected)} candidates")
    if not rows or len(rows) > len(expected):
        raise ValueError(f"Curation returned an invalid partial count: {len(rows)} for {len(expected)} candidates")
    categories = set(ontology["categories"])
    profiles = set(ontology["profiles"])
    classes = set(ontology["knowledge_classes"])
    decisions: list[dict[str, Any]] = []
    seen: set[str] = set()
    for raw in rows:
        returned_topic = str(raw.get("topic", "")) if isinstance(raw, dict) else ""
        topic = returned_topic if returned_topic in expected else expected_names.get(normalized(returned_topic), "")
        if topic not in expected or topic in seen:
            raise ValueError(f"Curation returned an unknown or duplicate topic: {topic}")
        seen.add(topic)
        score = int(raw.get("quality_score", 0))
        category = str(raw.get("category", ""))
        profile = str(raw.get("profile", PROFILE_BY_CATEGORY.get(category, "")))
        selected_classes = unique_strings(raw.get("classes"))
        reason_code = str(raw.get("reason_code", ""))
        reason = re.sub(r"\s+", " ", str(raw.get("curation_reason", raw.get("reason", reason_code)))).strip()
        if score < 1 or score > 5:
            raise ValueError(f"Curation returned invalid ontology values for {topic}")
        if category not in categories:
            category = "lore"
            profile = PROFILE_BY_CATEGORY[category]
            score = min(score, 3)
            reason = "uncertain"
        if profile not in profiles:
            profile = PROFILE_BY_CATEGORY[category]
        if any(value not in classes for value in selected_classes):
            raise ValueError(f"Curation returned invalid knowledge classes for {topic}")
        if not reason or ("curation_reason" not in raw and "reason" not in raw and reason_code not in REASON_CODES):
            raise ValueError(f"Curation returned no reason for {topic}")
        source = expected[topic]
        decisions.append({
            **source,
            "include": bool(raw.get("include", True)) and score >= 4,
            "quality_score": score,
            "category": category,
            "profile": profile,
            "classes": selected_classes,
            "aliases": unique_strings(raw.get("aliases"), reject=str(source["title"])),
            "curation_reason": reason,
        })
    return decisions


def seed_from_decision(row: dict[str, Any]) -> dict[str, Any]:
    seed: dict[str, Any] = {
        "topic": row["topic"],
        "title": row["title"],
        "category": row["category"],
        "profile": row["profile"],
        "aliases": row["aliases"],
        "classes": row["classes"],
        "preflight": False,
    }
    links = []
    seen: set[str] = set()
    for record in row.get("record_matches", []):
        record_type = str(record.get("record_type", ""))
        record_id = str(record.get("record_id", ""))
        key = f"{record_type}|{record_id}".casefold()
        if record_type in LINKED_RECORD_TYPES and record_id and key not in seen:
            seen.add(key)
            links.append({"record_id": record_id, "record_type": record_type})
    if links:
        seed["record_links"] = links
    if row.get("mod_source"):
        seed["mod_source"] = str(row["mod_source"])
    return seed


def portable_decision(row: dict[str, Any]) -> dict[str, Any]:
    return {key: row[key] for key in [
        "topic", "include", "quality_score", "category", "profile", "classes", "aliases", "curation_reason"
    ]}


def write_review(run_dir: Path, selected: list[dict[str, Any]], rejected: list[dict[str, Any]], manifest: dict[str, Any]) -> None:
    lines = [
        "# Morrowind Oghma curation review",
        "",
        f"- Existing reviewed baseline topics preserved: **{manifest['existing_count']}**",
        f"- New GLM-curated topics selected: **{manifest['selected_new_count']}**",
        f"- Proposed catalog total: **{manifest['proposed_total']}**",
        f"- Curation cost (including reserved prior attempts): **${manifest['usage']['cost']:.6f}** of the ${manifest['usage']['limit']:.2f} curation ceiling",
        "",
        "## Selected expansion topics",
        "",
        "| Score | Category | Topic | Source band | Reason |",
        "|---:|---|---|---|---|",
    ]
    for row in selected:
        reason = str(row["curation_reason"]).replace("|", "\\|")
        lines.append(f"| {row['quality_score']} | `{row['category']}` | {row['title']} | `{row['band']}` | {reason} |")
    lines.extend(["", "## Highest-ranked rejected topics", "", "| Score | Topic | Source band | Reason |", "|---:|---|---|---|"])
    for row in rejected[:250]:
        reason = str(row["curation_reason"]).replace("|", "\\|")
        lines.append(f"| {row['quality_score']} | {row['title']} | `{row['band']}` | {reason} |")
    (run_dir / "review.md").write_text("\n".join(lines) + "\n", encoding="utf-8", newline="\n")


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--run-dir", type=Path, required=True)
    parser.add_argument("--audit", type=Path, default=DEFAULT_AUDIT)
    parser.add_argument("--seeds", type=Path, default=DEFAULT_SEEDS)
    parser.add_argument("--ontology", type=Path, default=DEFAULT_ONTOLOGY)
    parser.add_argument("--model", default=DEFAULT_MODEL)
    parser.add_argument("--api-key-env", default="OPENROUTER_API_KEY")
    parser.add_argument("--batch-size", type=int, default=80)
    parser.add_argument("--provider-chunk-size", type=int, default=80, help="Maximum undecided topics sent in one provider request")
    parser.add_argument("--target-total", type=int, default=600)
    parser.add_argument("--minimum-total", type=int, default=500)
    parser.add_argument("--maximum-total", type=int, default=700)
    parser.add_argument("--max-cost", type=float, default=3.0)
    parser.add_argument("--prior-cost", type=float, default=0.0, help="Conservative spend reserved for uncheckpointed earlier attempts")
    parser.add_argument("--budget-reserve", type=float, default=0.10)
    parser.add_argument("--request-timeout", type=float, default=180.0)
    parser.add_argument("--resume", action="store_true")
    parser.add_argument(
        "--disable-reviewed-promotions",
        action="store_true",
        help="Do not reuse the original v2 borderline promotion set for an incremental catalog audit.",
    )
    args = parser.parse_args()
    if args.batch_size < 1 or args.provider_chunk_size < 1 or args.minimum_total > args.target_total or args.target_total > args.maximum_total:
        parser.error("Invalid batch size or catalog total bounds")
    if args.prior_cost < 0 or args.max_cost <= args.budget_reserve + args.prior_cost:
        parser.error("--max-cost must exceed --budget-reserve")
    return args


def main() -> int:
    args = parse_args()
    audit = read_json(args.audit)
    existing_document = read_json(args.seeds)
    ontology = read_json(args.ontology)
    candidates = [row for row in audit["candidates"] if row["band"] not in {"conversation_excluded", "mechanic_excluded"}]
    existing = existing_document["topics"]
    api_key = os.environ.get(args.api_key_env, "").strip()
    if not api_key:
        raise ValueError(f"{args.api_key_env} is required for GLM curation")
    args.run_dir.mkdir(parents=True, exist_ok=True)
    input_lock = {
        "format": "almsivi.morrowind-oghma-curation-lock.v1",
        "audit_sha256": hashlib.sha256(args.audit.read_bytes()).hexdigest(),
        "seeds_sha256": hashlib.sha256(args.seeds.read_bytes()).hexdigest(),
        "ontology_sha256": hashlib.sha256(args.ontology.read_bytes()).hexdigest(),
        "model": args.model,
        "candidate_count": len(candidates),
        "batch_size": args.batch_size,
    }
    lock_path = args.run_dir / "input-lock.json"
    if lock_path.is_file():
        if read_json(lock_path) != input_lock:
            raise ValueError("Existing curation run is locked to different inputs")
    else:
        atomic_json(lock_path, input_lock)
    all_decisions: list[dict[str, Any]] = []
    session = requests.Session()
    for offset in range(0, len(candidates), args.batch_size):
        batch = candidates[offset:offset + args.batch_size]
        batch_number = offset // args.batch_size + 1
        batch_path = args.run_dir / "batches" / f"batch-{batch_number:04d}.json"
        partial_path = args.run_dir / "batches" / f"partial-{batch_number:04d}.json"
        if batch_path.is_file() and args.resume:
            saved = read_json(batch_path)
            decisions = validate_decisions({"decisions": saved["decisions"]}, batch, ontology)
            all_decisions.extend(decisions)
            print(f"[resume] batch {batch_number}: {len(decisions)} decisions", flush=True)
            continue
        decisions: list[dict[str, Any]] = []
        if partial_path.is_file() and args.resume:
            saved = read_json(partial_path)
            decisions = validate_decisions({"decisions": saved["decisions"]}, batch, ontology, require_all=False)
            print(f"[resume-partial] batch {batch_number}: {len(decisions)}/{len(batch)} decisions", flush=True)
        elif args.resume:
            prior_attempts = sorted((args.run_dir / "batches").glob(f"attempt-*-batch-{batch_number:04d}.json"))
            if prior_attempts:
                saved_attempt = read_json(prior_attempts[-1])
                decisions = validate_decisions(saved_attempt["raw_result"], batch, ontology, require_all=False)
                atomic_json(partial_path, {
                    "format": "almsivi.morrowind-oghma-curation-partial.v1",
                    "batch": batch_number,
                    "decisions": [portable_decision(row) for row in decisions],
                })
                print(f"[salvaged] batch {batch_number}: {len(decisions)}/{len(batch)} decisions", flush=True)
        decided_topics = {row["topic"] for row in decisions}
        while len(decisions) < len(batch):
            spent = current_cost(args.run_dir, args.prior_cost)
            if spent >= args.max_cost - args.budget_reserve:
                raise RuntimeError(f"Curation budget gate stopped before batch {batch_number}: spent ${spent:.6f}")
            remaining = [row for row in batch if row["topic"] not in decided_topics][:args.provider_chunk_size]
            attempt_number = len(list((args.run_dir / "batches").glob("attempt-*.json"))) + 1
            started = time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime())
            raw, usage = call_provider(session, api_key, args.model, remaining, ontology, args.request_timeout)
            attempt_path = args.run_dir / "batches" / f"attempt-{attempt_number:04d}-batch-{batch_number:04d}.json"
            atomic_json(attempt_path, {
                "format": "almsivi.morrowind-oghma-curation-attempt.v1",
                "batch": batch_number,
                "started_at": started,
                "completed_at": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
                "requested_topics": [row["topic"] for row in remaining],
                "usage": usage,
                "raw_result": raw,
            })
            partial = validate_decisions(raw, remaining, ontology, require_all=False)
            decisions.extend(partial)
            decided_topics.update(row["topic"] for row in partial)
            atomic_json(partial_path, {
                "format": "almsivi.morrowind-oghma-curation-partial.v1",
                "batch": batch_number,
                "decisions": [portable_decision(row) for row in decisions],
            })
            print(f"[partial] batch {batch_number}: {len(decisions)}/{len(batch)} decisions; total cost ${current_cost(args.run_dir, args.prior_cost):.6f}", flush=True)
        atomic_json(batch_path, {
            "format": "almsivi.morrowind-oghma-curation-batch.v1",
            "batch": batch_number,
            "completed_at": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
            "decisions": [portable_decision(row) for row in decisions],
        })
        if partial_path.is_file():
            partial_path.unlink()
        all_decisions.extend(decisions)
        print(f"[complete] batch {batch_number}: {len(decisions)} decisions; total cost ${current_cost(args.run_dir, args.prior_cost):.6f}", flush=True)
    decision_topics = {row["topic"] for row in all_decisions}
    active_promotions = set() if args.disable_reviewed_promotions else PROMOTED_BORDERLINE_TOPICS
    unknown_promotions = active_promotions - decision_topics
    invalid_promotions = [row["topic"] for row in all_decisions if row["topic"] in active_promotions and int(row["quality_score"]) != 3]
    if unknown_promotions or invalid_promotions:
        raise RuntimeError(f"Borderline promotion audit drifted: unknown={sorted(unknown_promotions)} invalid={sorted(invalid_promotions)}")
    for row in all_decisions:
        override = CATEGORY_OVERRIDES.get(row["topic"])
        if override is not None:
            row["category"] = override
            row["profile"] = PROFILE_BY_CATEGORY[override]
    included = [
        row for row in all_decisions
        if (row["include"] or row["topic"] in active_promotions)
        and row["topic"] not in EXCLUDED_REVIEWED_TOPICS
    ]
    included.sort(key=lambda row: (-int(row["quality_score"]), -int(row["score"]), -int(row["response_count"]), str(row["title"]).casefold()))
    minimum_new = max(0, args.minimum_total - len(existing))
    target_new = max(0, args.target_total - len(existing))
    maximum_new = max(0, args.maximum_total - len(existing))
    if len(included) < minimum_new:
        raise RuntimeError(f"Only {len(included)} strong topics survived curation; {minimum_new} are required for the target range")
    selected = included[:min(target_new, maximum_new)]
    selected_keys = {row["topic"] for row in selected}
    rejected = [row for row in all_decisions if row["topic"] not in selected_keys]
    rejected.sort(key=lambda row: (-int(row["quality_score"]), -int(row["score"]), str(row["title"]).casefold()))
    expansion_seeds = [seed_from_decision(row) for row in selected]
    combined_topics = [*existing, *expansion_seeds]
    combined = {"format": "almsivi.morrowind-oghma-topic-seeds.v2", "topics": combined_topics}
    atomic_json(args.run_dir / "expansion-seeds.json", {"format": "almsivi.morrowind-oghma-expansion-seeds.v2", "topics": expansion_seeds})
    atomic_json(args.run_dir / "combined-topic-seeds.json", combined)
    combined_sha = hashlib.sha256((args.run_dir / "combined-topic-seeds.json").read_bytes()).hexdigest()
    manifest = {
        "format": "almsivi.morrowind-oghma-curation-manifest.v2",
        "model": args.model,
        "existing_count": len(existing),
        "candidate_count": len(candidates),
        "strong_candidate_count": len(included),
        "strict_candidate_count": sum(1 for row in all_decisions if row["include"]),
        "reviewed_borderline_promotions": len(active_promotions),
        "reviewed_exclusions": len(EXCLUDED_REVIEWED_TOPICS),
        "category_overrides": len(CATEGORY_OVERRIDES),
        "selected_new_count": len(selected),
        "proposed_total": len(combined_topics),
        "category_counts": dict(sorted(Counter(str(seed["category"]) for seed in combined_topics).items())),
        "record_linked_new_count": sum(1 for seed in expansion_seeds if seed.get("record_links")),
        "combined_seeds_sha256": combined_sha,
        "usage": {"cost": current_cost(args.run_dir, args.prior_cost), "prior_uncheckpointed_reserve": args.prior_cost, "limit": args.max_cost, "reserve": args.budget_reserve},
    }
    atomic_json(args.run_dir / "manifest.json", manifest)
    write_review(args.run_dir, selected, rejected, manifest)
    print(json.dumps(manifest, indent=2), flush=True)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
