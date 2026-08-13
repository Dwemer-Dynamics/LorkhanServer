#!/usr/bin/env python3
"""Build the reviewed book-and-lore topic inventory for Morrowind Oghma v4."""

from __future__ import annotations

import argparse
from collections import Counter
import hashlib
import importlib.util
import json
from pathlib import Path
import re
from typing import Any


ROOT = Path(__file__).resolve().parents[1]
DEFAULT_BASE = ROOT / "resources" / "oghma" / "morrowind-official" / "catalogs" / "morrowind-official-3e427-v3" / "topic-seeds.json"
DEFAULT_CATALOG = ROOT / "resources" / "oghma" / "morrowind-official" / "catalogs" / "morrowind-official-3e427-v3"
DEFAULT_OUTPUT = ROOT / "build" / "oghma-v4-seeds"


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


def write_json(path: Path, value: Any) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(value, ensure_ascii=False, indent=2) + "\n", encoding="utf-8", newline="\n")


def key(value: str) -> str:
    return re.sub(r"[^a-z0-9]+", "", value.casefold())


def topic(topic: str, title: str, category: str, profile: str, books: list[str],
          aliases: list[str] | None = None, classes: list[str] | None = None,
          record_links: list[dict[str, str]] | None = None) -> dict[str, Any]:
    row: dict[str, Any] = {
        "topic": topic,
        "title": title,
        "category": category,
        "profile": profile,
        "aliases": aliases or [],
        "classes": classes or [],
        "preflight": False,
        "book_sources": books,
    }
    if record_links:
        row["record_links"] = record_links
    return row


def curated_topics(sermon_ids: list[str]) -> list[dict[str, Any]]:
    anuad = ["bk_AnnotatedAnuad"]
    monomyth = ["bk_manyfacesmissinggod"]
    empire = ["bk_BriefHistoryEmpire1", "bk_BriefHistoryEmpire2", "bk_BriefHistoryEmpire3", "bk_BriefHistoryEmpire4"]
    arcturian = ["bk_ArcturianHeresy"]
    wolf_queen = ["BookSkill_Speechcraft1", "bookskill_security2", "bookskill_hand to hand2", "bookskill_illusion1",
                  "bookskill_mercantile2", "bookskill_speechcraft2", "bookskill_sneak1", "bookskill_speechcraft4", "BookSkill_Enchant2"]
    varieties = ["bk_varietiesoffaithintheempire"]
    khajiit = ["bk_wordsclanmother", "bookskill_medium armor1"]
    akavir = ["bk_MysteriousAkavir"]
    snow_prince = ["bk_snowprince"]
    rows = [
        topic("anu", "Anu", "lore", "esoteric", anuad, ["Anu the Everything"]),
        topic("padomay", "Padomay", "lore", "esoteric", anuad, ["Padomay the Nothing"]),
        topic("nir", "Nir", "lore", "esoteric", anuad, ["Nir the world-parent"]),
        topic("aurbis", "Aurbis", "lore", "esoteric", monomyth, ["the Aurbis"]),
        topic("mundus", "Mundus", "lore", "esoteric", monomyth, ["the mortal plane", "the Mundus"]),
        topic("aetherius", "Aetherius", "lore", "esoteric", monomyth, ["the immortal plane"]),
        topic("ehlnofey", "Ehlnofey", "lore", "esoteric", anuad, ["Old Ehlnofey", "Wandering Ehlnofey"]),
        topic("hist", "the Hist", "lore", "esoteric", anuad, ["Hist trees"]),
        topic("convention", "Convention", "history", "esoteric", monomyth, ["the Convention"]),
        topic("earthbones", "the Earthbones", "lore", "esoteric", monomyth, ["Earth Bones"]),
        topic("lunar_lorkhan", "the Lunar Lorkhan", "lore", "esoteric", ["BookSkill_Alteration5"], ["Lorkhan's moons"]),
        topic("dragon_break", "Dragon Break", "history", "esoteric", ["BookSkill_Alteration2", "bk_wherewereyoudragonbroke"], ["the Dragon Break"]),
        topic("aedra", "Aedra", "religion", "religious", ["bk_AedraAndDaedra"], ["the Aedra"]),
        topic("oblivion_realms", "realms of Oblivion", "lore", "esoteric", ["bk_onoblivion", "bk_WatersOfOblivion"], ["Oblivion realms", "planes of Oblivion"]),
        topic("sithis", "Sithis", "religion", "esoteric", ["BookSkill_Alteration3"], ["the Void"]),
        topic("shezarr", "Shezarr", "religion", "esoteric", monomyth, ["the Missing Sibling"]),

        topic("velothi_exodus", "the Velothi exodus", "history", "scholarly", ["bk_ChangedOnes"], ["Veloth's exodus"]),
        topic("dunmer_funerary_rites", "Dunmer funerary rites", "cultures", "religious", ["bk_AncestorsAndTheDunmer", "bk_corpsepreperation1_c"], ["Dunmer burial customs"]),
        topic("temple_canon", "Tribunal Temple canon", "religion", "religious", ["bk_fellowshiptemple", "bk_progressoftruth"], ["Temple doctrine"]),
        topic("dwemer_law", "Dwemer law", "cultures", "scholarly", ["bk_AntecedantsDwemerLaw"], ["Dwarven law"]),
        topic("dwemer_freeholds", "Dwemer freeholds", "history", "scholarly", ["bk_ChroniclesNchuleft"], ["Dwarven freeholds"]),
        topic("tonal_architecture", "Tonal Architecture", "magic", "esoteric", ["bk_kagrenac'stools"], ["Dwemer tonal architecture"]),
        topic("red_mountain_accounts", "conflicting accounts of Red Mountain", "history", "esoteric", ["bk_vivec_murders", "bk_vivec_no_murder"], ["Red Mountain accounts", "the Red Moment"]),

        topic("alessian_empire", "Alessian Empire", "history", "scholarly", empire, ["First Empire of Cyrodiil"]),
        topic("alessian_order", "Alessian Order", "religion", "scholarly", empire + monomyth, ["Alessian doctrines"]),
        topic("marukh", "Marukh", "figures", "scholarly", empire + monomyth, ["the Prophet Marukh"]),
        topic("reman_empire", "Reman Empire", "history", "scholarly", empire, ["Second Empire of Cyrodiil"]),
        topic("akaviri_potentate", "Akaviri Potentate", "history", "scholarly", empire, ["the Potentate"]),
        topic("septim_dynasty", "Septim Dynasty", "history", "scholarly", empire, ["the Septim line"]),
        topic("wars_of_tiber_septim", "wars of Tiber Septim", "history", "scholarly", empire + arcturian, ["Tiber Wars"]),
        topic("imperial_simulacrum", "Imperial Simulacrum", "history", "scholarly", empire, ["Jagar Tharn's usurpation"]),
        topic("war_of_the_red_diamond", "War of the Red Diamond", "history", "scholarly", empire + wolf_queen, ["Red Diamond War"]),
        topic("potema", "Potema", "figures", "scholarly", wolf_queen, ["the Wolf Queen", "Potema Septim"]),
        topic("cephorus_septim", "Cephorus Septim", "figures", "scholarly", wolf_queen + empire),
        topic("pelagius_iii", "Pelagius III", "figures", "scholarly", ["bk_madnessofpelagius"] + empire, ["Pelagius the Mad"]),
        topic("symmachus", "Symmachus", "figures", "scholarly", ["bk_BiographyBarenziah1", "bk_BiographyBarenziah2", "bk_BiographyBarenziah3"], ["General Symmachus"]),
        topic("zurin_arctus", "Zurin Arctus", "figures", "esoteric", arcturian, ["the Imperial Battlemage"]),
        topic("wulfharth", "Wulfharth", "figures", "esoteric", arcturian + ["bk_fivesongsofkingwulfharth"], ["Ysmir", "the Ash-King"]),
        topic("underking", "the Underking", "figures", "esoteric", arcturian, ["Underking"]),
        topic("mantella", "the Mantella", "artifacts", "esoteric", arcturian, ["Mantella"]),
        topic("warp_in_the_west", "Warp in the West", "history", "esoteric", arcturian + ["bk_wherewereyoudragonbroke"], ["the Miracle of Peace"]),
        topic("arcturian_heresy", "the Arcturian Heresy", "lore", "esoteric", arcturian, ["Arcturian account"]),
        topic("mages_guild_founding", "founding of the Mages Guild", "history", "scholarly", ["bk_OriginOfTheMagesGuild"]),
        topic("galerion", "Vanus Galerion", "figures", "scholarly", ["bk_OriginOfTheMagesGuild", "bk_galerionthemystic"], ["Galerion the Mystic"]),
        topic("psijic_order", "Psijic Order", "factions", "esoteric", ["bk_OriginOfTheMagesGuild", "bk_oldways"], ["the Psijics"]),
        topic("artaeum", "Artaeum", "regions", "esoteric", ["bk_OriginOfTheMagesGuild", "bk_fragmentonartaeum"], ["the island of Artaeum"]),
        topic("old_ways", "the Old Ways", "magic", "esoteric", ["bk_oldways"], ["Psijic Old Ways"]),

        topic("green_pact", "Green Pact", "cultures", "scholarly", varieties + ["bookskill_medium armor1"], ["the Green Pact"]),
        topic("wild_hunt", "Wild Hunt", "cultures", "esoteric", varieties + ["bookskill_medium armor1"], ["the Wild Hunt"]),
        topic("orsinium", "Orsinium", "settlements", "scholarly", ["bookskill_heavy armor4", "bk_truenatureoforcs"], ["Nova Orsinium"]),
        topic("snow_elves", "Snow Elves", "races", "scholarly", snow_prince, ["Falmer"]),
        topic("battle_of_moesring", "Battle of Moesring", "history", "scholarly", snow_prince, ["Battle of the Moesring"]),
        topic("snow_prince", "the Snow Prince", "figures", "scholarly", snow_prince, ["Snow Prince"]),
        topic("atmora", "Atmora", "regions", "scholarly", ["bk_ChildrenOfTheSky"], ["the Elder Wood"]),
        topic("mane", "the Mane", "figures", "scholarly", khajiit, ["Mane of the Khajiit"]),
        topic("lunar_lattice", "Lunar Lattice", "religion", "esoteric", khajiit + varieties, ["ja-Kha'jay", "the ja-Kha'jay"]),
        topic("lorkhaj", "Lorkhaj", "religion", "esoteric", khajiit + varieties, ["Moon Beast"]),
        topic("azurah", "Azurah", "religion", "religious", khajiit + varieties, ["Khajiiti Azura"]),
        topic("khajiit_furstocks", "Khajiit furstocks", "races", "scholarly", khajiit + ["bookskill_restoration2"], ["Khajiit forms"]),
        topic("akavir", "Akavir", "regions", "scholarly", akavir, ["the Dragon Land"]),
        topic("tsaesci", "Tsaesci", "races", "scholarly", akavir, ["vampiric serpent-folk"]),
        topic("kamal", "Kamal", "races", "scholarly", akavir, ["Snow Demons of Kamal"]),
        topic("tang_mo", "Tang Mo", "races", "scholarly", akavir, ["Thousand Monkey Isles"]),
        topic("ka_po_tun", "Ka Po' Tun", "races", "scholarly", akavir, ["Po Tun", "Tiger-Dragon Empire"]),
        topic("ayleids", "Ayleids", "races", "scholarly", ["bk_wildelves"], ["Wild Elves", "Heartland High Elves"]),
        topic("direnni", "Direnni", "cultures", "scholarly", ["bookskill_restoration2"], ["Direnni clan"]),
        topic("nedes", "Nedes", "races", "scholarly", ["bookskill_restoration2", "bk_AnnotatedAnuad"], ["Nedic peoples"]),
        topic("skaal_standing_stones", "Skaal standing stones", "religion", "religious", ["bk_BM_Aevar"], ["All-Maker Stones", "Standing Stones of the Skaal"]),
        topic("aevar_stone_singer", "Aevar Stone-Singer", "figures", "religious", ["bk_BM_Aevar"], ["Aevar"]),
        topic("maormer", "Maormer", "races", "scholarly", ["bk_provinces_of_tamriel"], ["Sea Elves"]),
        topic("sload", "Sload", "races", "scholarly", ["bk_provinces_of_tamriel"], ["slug-folk of Thras"]),
        topic("aldmeri_pantheon", "Aldmeri pantheon", "religion", "religious", varieties, ["Altmeri pantheon"]),
        topic("khajiiti_pantheon", "Khajiiti pantheon", "religion", "religious", varieties + khajiit),
        topic("redguard_pantheon", "Redguard pantheon", "religion", "religious", varieties, ["Yokudan pantheon"]),
        topic("nordic_pantheon", "Nordic pantheon", "religion", "religious", varieties, ["Nord pantheon"]),

        topic("the_monomyth", "The Monomyth", "artifacts", "esoteric", monomyth,
              ["Monomyth"], record_links=[{"record_type": "BOOK", "record_id": "bk_manyfacesmissinggod"}]),
        topic("the_annotated_anuad", "The Annotated Anuad", "artifacts", "scholarly", anuad,
              ["Annotated Anuad"], record_links=[{"record_type": "BOOK", "record_id": "bk_AnnotatedAnuad"}]),
        topic("mysterious_akavir", "Mysterious Akavir", "artifacts", "scholarly", akavir,
              record_links=[{"record_type": "BOOK", "record_id": "bk_MysteriousAkavir"}]),
        topic("varieties_of_faith", "Varieties of Faith in the Empire", "artifacts", "scholarly", varieties,
              ["Varieties of Faith"], record_links=[{"record_type": "BOOK", "record_id": "bk_varietiesoffaithintheempire"}]),
        topic("thirty_six_lessons_of_vivec", "The 36 Lessons of Vivec", "artifacts", "esoteric", sermon_ids,
              ["36 Lessons", "Sermons of Vivec"], record_links=[{"record_type": "BOOK", "record_id": value} for value in sermon_ids]),
        topic("nerevar_at_red_mountain", "Nerevar at Red Mountain", "artifacts", "esoteric", ["bk_vivec_murders"],
              record_links=[{"record_type": "BOOK", "record_id": "bk_vivec_murders"}]),
        topic("five_songs_of_king_wulfharth", "Five Songs of King Wulfharth", "artifacts", "scholarly", ["bk_fivesongsofkingwulfharth"],
              ["Five Songs of Wulfharth"], record_links=[{"record_type": "BOOK", "record_id": "bk_fivesongsofkingwulfharth"}]),
        topic("children_of_the_sky", "Children of the Sky", "artifacts", "scholarly", ["bk_ChildrenOfTheSky"],
              record_links=[{"record_type": "BOOK", "record_id": "bk_ChildrenOfTheSky"}]),
        topic("fall_of_the_snow_prince", "Fall of the Snow Prince", "artifacts", "scholarly", snow_prince,
              record_links=[{"record_type": "BOOK", "record_id": "bk_snowprince"}]),
    ]
    return rows


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--baseline-seeds", type=Path, default=DEFAULT_BASE)
    parser.add_argument("--baseline-catalog", type=Path, default=DEFAULT_CATALOG)
    parser.add_argument("--data-dir", type=Path)
    parser.add_argument("--ontology", type=Path, default=ROOT / "resources" / "oghma" / "morrowind-official" / "ontology.json")
    parser.add_argument("--output-dir", type=Path, default=DEFAULT_OUTPUT)
    return parser.parse_args()


def main() -> int:
    args = parse_args()
    generator = load_generator()
    records, hashes = generator.extract_records(args.data_dir or generator.DEFAULT_DATA_DIR)
    sermons_by_number: dict[int, str] = {}
    for record in records.values():
        match = re.fullmatch(r"36 Lessons of Vivec, Sermon (\d+)", str(record["display_name"]), re.IGNORECASE)
        if record["record_type"] != "BOOK" or match is None:
            continue
        number = int(match.group(1))
        record_id = str(record["record_id"])
        if number not in sermons_by_number or sermons_by_number[number].casefold().endswith("_open"):
            sermons_by_number[number] = record_id
    sermon_ids = [sermons_by_number[number] for number in sorted(sermons_by_number)]
    if len(sermon_ids) != 36:
        raise ValueError(f"Expected 36 winning Vivec sermon records, found {len(sermon_ids)}")
    baseline = read_json(args.baseline_seeds)
    baseline_articles = read_json(args.baseline_catalog / "articles.json")
    additions = curated_topics(sermon_ids)
    combined = {"format": baseline.get("format"), "topics": [*baseline["topics"], *additions]}
    ontology = read_json(args.ontology)
    generator.validate_seed_document(combined, ontology, records)

    baseline_keys = {key(value): row["topic"] for row in baseline["topics"]
                     for value in [row["topic"], row["title"], *row.get("aliases", [])]}
    collisions = [{"topic": row["topic"], "value": value, "owner": baseline_keys[key(value)]}
                  for row in additions for value in [row["topic"], row["title"], *row.get("aliases", [])]
                  if key(value) in baseline_keys]
    if collisions:
        raise ValueError(f"V4 additions collide with the v3 baseline: {collisions}")

    args.output_dir.mkdir(parents=True, exist_ok=True)
    additions_document = {"format": "almsivi.morrowind-oghma-v4-selection.v1", "topics": additions}
    write_json(args.output_dir / "additions.json", additions_document)
    write_json(args.output_dir / "topic-seeds.json", combined)
    additions_sha = hashlib.sha256((args.output_dir / "additions.json").read_bytes()).hexdigest()
    combined_sha = hashlib.sha256((args.output_dir / "topic-seeds.json").read_bytes()).hexdigest()
    manifest = {
        "format": "almsivi.morrowind-oghma-v4-selection-manifest.v1",
        "baseline_catalog": "morrowind-official-3e427-v3",
        "baseline_count": len(baseline_articles),
        "baseline_seed_count": len(baseline["topics"]),
        "selected_new_count": len(additions),
        "proposed_catalog_count": len(baseline_articles) + len(additions),
        "category_counts": dict(sorted(Counter(row["category"] for row in additions).items())),
        "official_book_source_links": sum(len(row["book_sources"]) for row in additions),
        "exact_record_linked_additions": sum(bool(row.get("record_links")) for row in additions),
        "official_content_sha256": hashes,
        "additions_sha256": additions_sha,
        "combined_seeds_sha256": combined_sha,
    }
    write_json(args.output_dir / "selection-manifest.json", manifest)
    lines = [
        "# Morrowind Oghma v4 book-and-lore selection", "",
        f"- V3 baseline: **{manifest['baseline_count']}**", f"- Selected additions: **{manifest['selected_new_count']}**",
        f"- Proposed v4 total: **{manifest['proposed_catalog_count']}**",
        f"- Official book-source links: **{manifest['official_book_source_links']}**",
        f"- Exact record-linked additions: **{manifest['exact_record_linked_additions']}**", "",
        "| Topic | Category | Official book sources |", "|---|---|---|",
    ]
    lines.extend(f"| {row['title']} | {row['category']} | {', '.join(f'`{value}`' for value in row['book_sources'])} |" for row in additions)
    (args.output_dir / "selection-review.md").write_text("\n".join(lines) + "\n", encoding="utf-8", newline="\n")
    print(json.dumps(manifest, indent=2))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
