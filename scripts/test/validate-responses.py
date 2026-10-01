#!/usr/bin/env python3
"""Validate shared protocol fixtures or every captured JSON response with Draft 2020-12."""
from __future__ import annotations

import json
import sys
from pathlib import Path

from referencing import Registry, Resource
from jsonschema import Draft202012Validator

ROOT = Path(__file__).resolve().parents[2]
SCHEMAS = ROOT / "protocol/schemas/v1"
FIXTURES = ROOT / "protocol/fixtures/v1"
SCHEMA_FOR = {
    "lorkhan.health.v1": "health.schema.json",
    "lorkhan.error.v1": "error.schema.json",
    "lorkhan.session.accepted.v1": "session-accepted.schema.json",
    "lorkhan.controls.v1": "controls.schema.json",
    "lorkhan.turn.accepted.v1": "turn-accepted.schema.json",
    "lorkhan.gamedata.accepted.v1": "gamedata-accepted.schema.json",
    "lorkhan.events.v1": "events.schema.json",
    "lorkhan.interruption.accepted.v1": "interruption-accepted.schema.json",
    "lorkhan.action-result.accepted.v1": "action-result-accepted.schema.json",
    "lorkhan.stt.accepted.v1": "stt-accepted.schema.json",
    "lorkhan.dialogue-delivery-result.accepted.v1": "dialogue-delivery-result-accepted.schema.json",
    "lorkhan.player-autochat.ready.v1": "player-autochat-ready.schema.json",
    "lorkhan.session.ended.v1": "session-ended.schema.json",
}

def validator(schema, registry):
    return Draft202012Validator(schema, registry=registry, format_checker=Draft202012Validator.FORMAT_CHECKER)

def validate_fixtures(registry, by_id) -> int:
    for document in by_id.values():
        Draft202012Validator.check_schema(document)
    count = 0
    for classification in ("valid", "invalid", "hostile"):
        for path in sorted((FIXTURES / classification).glob("*.json")):
            wrapper = json.loads(path.read_text(encoding="utf-8"))
            if set(wrapper) != {"description", "instance", "schema"}:
                raise ValueError(f"{classification}/{path.name}: fixture wrapper has unexpected fields")
            if wrapper["schema"] not in by_id:
                raise ValueError(f"{classification}/{path.name}: unknown schema URI {wrapper['schema']!r}")
            accepted = validator(by_id[wrapper["schema"]], registry).is_valid(wrapper["instance"])
            if accepted != (classification == "valid"):
                raise ValueError(f"{classification}/{path.name}: fixture was {'accepted' if accepted else 'rejected'}")
            count += 1
    if count == 0:
        raise ValueError(f"no protocol fixtures found under {FIXTURES}")
    print(f"validated {len(by_id)} schemas and {count} fixtures with Draft 2020-12")
    return 0

def main() -> int:
    if len(sys.argv) != 2:
        raise SystemExit("usage: validate-responses.py --fixtures | CAPTURE.jsonl")
    documents = [json.loads(path.read_text(encoding="utf-8")) for path in sorted(SCHEMAS.glob("*.json"))]
    registry = Registry()
    by_name = {}
    by_id = {}
    for document in documents:
        registry = registry.with_resource(document["$id"], Resource.from_contents(document))
        by_name[Path(document["$id"]).name] = document
        by_id[document["$id"]] = document
    if sys.argv[1] == "--fixtures":
        return validate_fixtures(registry, by_id)
    count = 0
    with Path(sys.argv[1]).open(encoding="utf-8") as handle:
        for line_number, line in enumerate(handle, 1):
            instance = json.loads(line)
            schema_name = instance.get("schema")
            if schema_name not in SCHEMA_FOR:
                raise ValueError(f"line {line_number}: unknown response schema {schema_name!r}")
            validator(by_name[SCHEMA_FOR[schema_name]], registry).validate(instance)
            count += 1
    if count == 0:
        raise ValueError(f"no server responses captured in {sys.argv[1]}")
    print(f"validated {count} server responses with Draft 2020-12")
    return 0

if __name__ == "__main__":
    raise SystemExit(main())
