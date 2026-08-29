#!/usr/bin/env python3
"""Build a revision-pinned Tamriel Rebuilt location inventory from UESP and TR_Mainland.esm."""

from __future__ import annotations

import argparse
from collections import defaultdict
import concurrent.futures
import hashlib
import html
import importlib.util
import json
from pathlib import Path
import re
import time
from typing import Any

import requests


ROOT = Path(__file__).resolve().parents[1]
PREFLIGHT = Path(__file__).with_name("run-morrowind-oghma-preflight.py")
DEFAULT_BASE_CATALOG = ROOT / "resources" / "oghma" / "morrowind-official" / "catalogs" / "morrowind-official-3e427-v5.15" / "articles.json"
UESP_API_URL = "https://en.uesp.net/w/api.php"
ROOT_CATEGORY = "Category:Tamriel Rebuilt-Places"
PUBLIC_INDEX_PAGES = {
    "regions": "Tamriel Rebuilt:Regions",
    "settlements": "Tamriel Rebuilt:Cities & Towns",
    "locations": "Tamriel Rebuilt:Landmarks",
    "waters": "Tamriel Rebuilt:Bodies of Water",
}
SPECIAL_RELEASED_LOCATIONS = {
    "Saros Archipelago": ("locations", "unknown"),
    "Veloth's Path": ("locations", "common"),
}
FORMAT = "lorkhan.tamriel-rebuilt-location-inventory.v1"
USER_AGENT = "LORKHAN-Oghma-Location-Inventory/1.0 (https://dwemerdynamics.com/)"


def read_json(path: Path) -> Any:
    return json.loads(path.read_text(encoding="utf-8-sig"))


def write_json(path: Path, value: Any) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(value, ensure_ascii=False, indent=2) + "\n", encoding="utf-8", newline="\n")


def normalized(value: str) -> str:
    value = value.replace("\u200e", "").replace("\u200f", "")
    return re.sub(r"[^a-z0-9]+", "", value.casefold())


def slug(value: str) -> str:
    return re.sub(r"[^a-z0-9]+", "_", value.casefold()).strip("_")


def clean_title(value: str) -> str:
    value = value.split(":", 1)[-1] if value.startswith("Tamriel Rebuilt:") else value
    value = re.sub(r"\s+", " ", value.replace("\u200e", "").replace("\u200f", "")).strip()
    value = re.sub(r"\s+\(place\)$", "", value, flags=re.IGNORECASE)
    guild = re.fullmatch(r"(Guild of (?:Fighters|Mages)) \(([^)]+)\)", value, flags=re.IGNORECASE)
    return f"{guild.group(2)} {guild.group(1)}" if guild else value


def load_preflight() -> Any:
    spec = importlib.util.spec_from_file_location("lorkhan_oghma_preflight", PREFLIGHT)
    if spec is None or spec.loader is None:
        raise RuntimeError("Unable to load the Oghma preflight parser")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


class UespClient:
    def __init__(self) -> None:
        self.session = requests.Session()
        self.session.headers.update({"User-Agent": USER_AGENT})

    def get(self, params: dict[str, Any]) -> dict[str, Any]:
        last_error: Exception | None = None
        for attempt in range(4):
            try:
                response = self.session.get(UESP_API_URL, params=params, timeout=45)
                response.raise_for_status()
                payload = response.json()
                if "error" in payload:
                    raise RuntimeError(str(payload["error"]))
                return payload
            except Exception as error:
                last_error = error
                if attempt < 3:
                    time.sleep(1.5 * (attempt + 1))
        raise RuntimeError(f"UESP request failed: {last_error}")

    def category_members(self, category: str) -> list[dict[str, Any]]:
        rows: list[dict[str, Any]] = []
        continuation: dict[str, Any] = {}
        while True:
            payload = self.get({
                "action": "query", "list": "categorymembers", "cmtitle": category,
                "cmlimit": "500", "format": "json", "formatversion": 2, **continuation,
            })
            rows.extend(payload.get("query", {}).get("categorymembers", []))
            if "continue" not in payload:
                return rows
            continuation = payload["continue"]


# Traverse every nested place category while retaining each page's classification path.
def collect_category_pages() -> tuple[dict[int, str], dict[int, set[str]], set[str]]:
    pages: dict[int, str] = {}
    memberships: dict[int, set[str]] = defaultdict(set)
    seen: set[str] = set()
    frontier = [ROOT_CATEGORY]
    while frontier:
        batch = [category for category in frontier if category not in seen]
        frontier = []
        if not batch:
            break
        seen.update(batch)
        with concurrent.futures.ThreadPoolExecutor(max_workers=10) as executor:
            results = list(executor.map(lambda category: (category, UespClient().category_members(category)), batch))
        for category, rows in results:
            for row in rows:
                if row.get("ns") == 14 and row["title"] not in seen:
                    frontier.append(str(row["title"]))
                elif row.get("ns") == 170:
                    page_id = int(row["pageid"])
                    pages[page_id] = str(row["title"])
                    memberships[page_id].add(category)
    return pages, memberships, seen


def page_details(client: UespClient, titles: list[str]) -> dict[int, dict[str, Any]]:
    details: dict[int, dict[str, Any]] = {}
    for start in range(0, len(titles), 50):
        payload = client.get({
            "action": "query", "prop": "info|revisions", "titles": "|".join(titles[start:start + 50]),
            "inprop": "url", "rvprop": "ids|timestamp|content", "rvslots": "main",
            "format": "json", "formatversion": 2,
        })
        for page in payload.get("query", {}).get("pages", []):
            if page.get("missing") or "pageid" not in page:
                continue
            revision = (page.get("revisions") or [{}])[0]
            content = str((revision.get("slots") or {}).get("main", {}).get("content", ""))
            details[int(page["pageid"])] = {
                "page_id": int(page["pageid"]), "title": str(page["title"]),
                "url": str(page.get("fullurl", "")), "redirect": "redirect" in page,
                "revision_id": int(revision.get("revid") or 0),
                "revision_timestamp": str(revision.get("timestamp", "")), "wikitext": content,
            }
    return details


def transcluded_place_links(client: UespClient) -> dict[str, set[str]]:
    groups: dict[str, set[str]] = {}
    for group, page in PUBLIC_INDEX_PAGES.items():
        payload = client.get({
            "action": "parse", "page": page, "prop": "wikitext", "format": "json", "formatversion": 2,
        })
        text = str(payload.get("parse", {}).get("wikitext", ""))
        text = re.sub(r"<noinclude>.*?</noinclude>", "", text, flags=re.IGNORECASE | re.DOTALL)
        text = re.sub(r"<includeonly>.*?</includeonly>", "", text, flags=re.IGNORECASE | re.DOTALL)
        groups[group] = {
            clean_title(html.unescape(value.strip()))
            for value in re.findall(r"\{\{\s*Place Link\s*\|\s*([^}|]+)", text, flags=re.IGNORECASE)
        }
    return groups


def field_values(wikitext: str, field: str) -> list[str]:
    values = []
    pattern = rf"^\|[^\S\r\n]*{re.escape(field)}(?:\d+)?[^\S\r\n]*=[^\S\r\n]*([^\r\n]+?)[^\S\r\n]*$"
    for value in re.findall(pattern, wikitext, flags=re.IGNORECASE | re.MULTILINE):
        value = re.sub(r"<!--.*?-->", "", value)
        value = re.sub(r"\[\[(?:[^]|]+\|)?([^]]+)]]", r"\1", value)
        value = re.sub(r"\{\{[^{}]+}}", "", value)
        value = re.sub(r"</?br\s*/?>", ";", value, flags=re.IGNORECASE)
        quoted = re.findall(r'"([^"\r\n]+)"', value)
        fragments = quoted or re.split(r"\s*;\s*", value)
        for fragment in fragments:
            fragment = clean_title(fragment.strip(" \"',"))
            if fragment and len(fragment.encode("utf-8")) <= 256:
                values.append(fragment)
    return values


def page_aliases(title: str, wikitext: str) -> list[str]:
    aliases = field_values(wikitext, "locationcode")
    aliases.extend(re.findall(r"\(or\s+'''([^']+)'''\)", wikitext, flags=re.IGNORECASE))
    result = []
    seen = {normalized(title)}
    for alias in aliases:
        alias = clean_title(alias)
        key = normalized(alias)
        if key and key not in seen:
            seen.add(key)
            result.append(alias)
    return result


def redirect_target(wikitext: str) -> str:
    match = re.search(r"^\s*#redirect\s*\[\[([^]|]+)", wikitext, flags=re.IGNORECASE)
    return clean_title(match.group(1)) if match else ""


def has_location_template(wikitext: str) -> bool:
    return re.search(r"\{\{\s*(?:Place Summary|Morrowind Town Table)\b", wikitext, flags=re.IGNORECASE) is not None


def advanced_classes(title: str, categories: set[str], wikitext: str) -> list[str]:
    introduction = wikitext.split("==", 1)[0][:2200]
    identity = " ".join([title, introduction]).casefold()
    place_type = " ".join([title, *categories]).casefold()
    values = []
    identity_rules = [
        ("house hlaalu", "house_hlaalu"), ("house telvanni", "house_telvanni"),
        ("imperial legion", "imperial_legion"), ("imperial", "imperial"),
        ("tribunal temple", "tribunal_temple"), ("ashlander", "ashlander"),
    ]
    type_rules = [
        ("dwemer", "dwemer"), ("daedric", "daedra"), ("ancestral tomb", "dunmer"),
        ("telvanni tower", "mage"), ("shipwreck", "sailor"), ("ships", "sailor"),
        ("tavern", "traveler"), ("fort", "guard"), ("mine", "merchant"),
        ("cave", "hunter"), ("grotto", "hunter"), ("ruins", "scholar"),
    ]
    for signal, knowledge_class in identity_rules:
        if re.search(rf"(?<![a-z0-9]){re.escape(signal)}(?![a-z0-9])", identity) and knowledge_class not in values:
            values.append(knowledge_class)
    for signal, knowledge_class in type_rules:
        if re.search(rf"(?<![a-z0-9]){re.escape(signal)}(?![a-z0-9])", place_type) and knowledge_class not in values:
            values.append(knowledge_class)
    if "traveler" not in values and not any(value in values for value in ("scholar", "dunmer", "dwemer", "daedra")):
        values.insert(0, "traveler")
    return values[:6] or ["traveler"]


def markdown_summary(summary: dict[str, Any], coverage: list[dict[str, Any]]) -> str:
    lines = [
        "# Tamriel Rebuilt location coverage", "", "## Summary", "",
        f"- Recursive UESP place categories: **{summary['category_count']}**",
        f"- Unique UESP pages: **{summary['wiki_page_count']}**",
        f"- Redirect pages: **{summary['redirect_count']}**",
        f"- Accepted location subjects: **{summary['accepted_count']}**",
        f"- Public common-basic subjects: **{summary['common_basic_count']}**",
        f"- Obscure ignorance-basic subjects: **{summary['unknown_basic_count']}**",
        f"- Excluded or unresolved pages: **{summary['excluded_count']}**",
        f"- ESM-linked subjects: **{summary['record_linked_count']}**", "",
        "## Excluded or unresolved", "", "| Page | Status | Reason |", "|---|---|---|",
    ]
    for row in coverage:
        if row["status"] != "accepted":
            lines.append(f"| {row['title'].replace('|', '\\|')} | {row['status']} | {row['reason'].replace('|', '\\|')} |")
    return "\n".join(lines) + "\n"


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--data-dir", type=Path, required=True)
    parser.add_argument("--output-dir", type=Path, required=True)
    parser.add_argument("--base-catalog", type=Path, default=DEFAULT_BASE_CATALOG)
    return parser.parse_args()


def main() -> int:
    args = parse_args()
    preflight = load_preflight()
    records, hashes = preflight.extract_records(args.data_dir, ("TR_Mainland.esm",))
    cells_by_name: dict[str, list[dict[str, Any]]] = defaultdict(list)
    for record in records.values():
        if record["record_type"] in {"CELL", "REGN"}:
            cells_by_name[normalized(str(record["record_id"]))].append(record)

    client = UespClient()
    public_groups = transcluded_place_links(client)
    pages, memberships, categories = collect_category_pages()
    public_titles = [f"Tamriel Rebuilt:{title}" for titles in public_groups.values() for title in titles]
    public_details = page_details(client, public_titles)
    for page_id, page in public_details.items():
        pages[page_id] = page["title"]
        memberships[page_id].add(ROOT_CATEGORY)
    existing_public_titles = {normalized(clean_title(page["title"])) for page in public_details.values()}
    missing_public_links = sorted({
        clean_title(title) for title in public_titles
        if normalized(clean_title(title)) not in existing_public_titles
    })
    details = page_details(client, list(pages.values()))
    public_category: dict[str, str] = {}
    for group, titles in public_groups.items():
        category = "locations" if group in {"locations", "waters"} else group
        for title in titles:
            public_category[normalized(title)] = category

    base_articles = read_json(args.base_catalog)
    existing_by_key: dict[str, dict[str, Any]] = {}
    reserved_topics = {str(row["topic"]).casefold(): row for row in base_articles}
    reserved_aliases: dict[str, str] = {}
    for row in base_articles:
        for value in [row["topic"], row["title"], *row.get("aliases", [])]:
            key = normalized(str(value))
            if key:
                reserved_aliases.setdefault(key, str(row["topic"]))
        if row.get("mod_source") == "TR_Mainland.esm":
            for value in [row["topic"], row["title"], *row.get("aliases", [])]:
                key = normalized(str(value))
                if key:
                    existing_by_key.setdefault(key, row)

    redirect_aliases: dict[str, list[str]] = defaultdict(list)
    coverage: list[dict[str, Any]] = []
    candidates: list[dict[str, Any]] = []
    redirect_count = 0
    for page_id in sorted(details, key=lambda value: clean_title(details[value]["title"]).casefold()):
        page = details[page_id]
        title = clean_title(page["title"])
        wikitext = page["wikitext"]
        if page["redirect"]:
            redirect_count += 1
            target = redirect_target(wikitext)
            if target and ":" not in title:
                redirect_aliases[normalized(target)].append(title)
            coverage.append({"page_id": page_id, "title": title, "status": "redirect",
                             "reason": f"Redirects to {target or 'an unresolved page'}"})
            continue

        aliases = page_aliases(title, wikitext)
        record_matches: dict[str, dict[str, Any]] = {}
        for value in [title, *aliases]:
            for record in cells_by_name.get(normalized(value), []):
                record_matches[f"{record['record_type']}|{record['record_id']}".casefold()] = record
        public = public_category.get(normalized(title))
        special = SPECIAL_RELEASED_LOCATIONS.get(title)
        location_template = has_location_template(wikitext)
        if not record_matches and public is None and special is None and not location_template:
            coverage.append({"page_id": page_id, "title": title, "status": "excluded",
                             "reason": "Place-category index or service page without a released location identity"})
            continue

        category = public or (special[0] if special is not None else "locations")
        basic_mode = "common" if public is not None else (special[1] if special is not None else "unknown")
        existing = next((existing_by_key[normalized(value)] for value in [title, *aliases]
                         if normalized(value) in existing_by_key), None)
        topic = str(existing["topic"]) if existing is not None else slug(title)
        owner = reserved_topics.get(topic.casefold())
        if owner is not None and owner.get("mod_source") != "TR_Mainland.esm":
            topic = "tr_" + topic
        if topic.casefold() in reserved_topics and reserved_topics[topic.casefold()].get("mod_source") != "TR_Mainland.esm":
            topic = f"{topic}_{page_id}"
        candidates.append({
            "page": page, "title": title, "topic": topic, "category": category,
            "basic_mode": basic_mode, "aliases": aliases, "records": list(record_matches.values()),
            "memberships": memberships.get(page_id, set()),
        })
        coverage.append({
            "page_id": page_id, "title": title, "status": "accepted", "reason": "public_index" if public else (
                "released_location_review" if special is not None else (
                    "esm_record_match" if record_matches else "released_place_template")),
            "topic": topic, "category": category, "basic_mode": basic_mode,
            "revision_id": page["revision_id"], "record_link_count": len(record_matches),
        })
    for title in missing_public_links:
        coverage.append({"page_id": None, "title": title, "status": "missing",
                         "reason": "Linked by the public place index but no UESP article exists"})

    topic_owners: dict[str, str] = {}
    for candidate in candidates:
        topic_key = candidate["topic"].casefold()
        if topic_key in topic_owners and topic_owners[topic_key] != candidate["title"]:
            candidate["topic"] = f"{candidate['topic']}_{candidate['page']['page_id']}"
        topic_owners[candidate["topic"].casefold()] = candidate["title"]
    alias_owners = dict(reserved_aliases)
    for candidate in candidates:
        alias_owners[normalized(candidate["topic"])] = candidate["topic"]
    seeds = []
    for candidate in candidates:
        aliases = [*candidate["aliases"], *redirect_aliases.get(normalized(candidate["title"]), [])]
        kept_aliases = []
        for alias in aliases:
            key = normalized(alias)
            owner = alias_owners.get(key)
            if not key or key == normalized(candidate["title"]) or (owner is not None and owner != candidate["topic"]):
                continue
            alias_owners[key] = candidate["topic"]
            if alias not in kept_aliases:
                kept_aliases.append(alias)
        links = [{"record_type": record["record_type"], "record_id": record["record_id"]}
                 for record in sorted(candidate["records"], key=lambda row: (row["record_type"], row["record_id"].casefold()))]
        seeds.append({
            "topic": candidate["topic"], "title": candidate["title"], "category": candidate["category"],
            "profile": "specialist", "aliases": kept_aliases,
            "classes": advanced_classes(candidate["title"], candidate["memberships"], candidate["page"]["wikitext"]),
            "basic_mode": candidate["basic_mode"], "preflight": True,
            "record_links": links, "include_dialogue_evidence": False,
            "uesp_titles": [candidate["page"]["title"]],
            "uesp_revision_id": candidate["page"]["revision_id"],
            "domain_instructions": "Describe the durable place itself. Exclude quests, temporary occupants, coordinates, record identifiers, walkthrough directions, and player-dependent events.",
            "mod_source": "TR_Mainland.esm",
        })

    seeds.sort(key=lambda row: row["topic"])
    accepted_coverage = [row for row in coverage if row["status"] == "accepted"]
    summary = {
        "format": FORMAT, "generated_at_utc": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
        "root_category": ROOT_CATEGORY, "category_count": len(categories), "wiki_page_count": len(pages),
        "public_index_link_count": len({normalized(title) for title in public_titles}),
        "missing_public_page_count": len(missing_public_links),
        "redirect_count": redirect_count, "accepted_count": len(seeds),
        "common_basic_count": sum(row["basic_mode"] == "common" for row in seeds),
        "unknown_basic_count": sum(row["basic_mode"] == "unknown" for row in seeds),
        "excluded_count": len(coverage) - len(accepted_coverage),
        "record_linked_count": sum(bool(row["record_links"]) for row in seeds),
        "official_content_sha256": hashes,
        "base_catalog_sha256": hashlib.sha256(args.base_catalog.read_bytes()).hexdigest(),
    }
    args.output_dir.mkdir(parents=True, exist_ok=True)
    write_json(args.output_dir / "location-seeds.json", {"format": FORMAT, "topics": seeds})
    write_json(args.output_dir / "coverage.json", {"summary": summary, "pages": coverage})
    (args.output_dir / "coverage.md").write_text(markdown_summary(summary, coverage), encoding="utf-8", newline="\n")
    print(json.dumps(summary, ensure_ascii=False, indent=2))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
