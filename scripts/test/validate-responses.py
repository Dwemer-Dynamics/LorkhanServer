#!/usr/bin/env python3
"""Validate every captured JSON response against the shared Draft 2020-12 schemas."""
from __future__ import annotations

import json
import sys
from pathlib import Path

from referencing import Registry, Resource
from jsonschema import Draft202012Validator

ROOT = Path(__file__).resolve().parents[2]
SCHEMAS = ROOT / "protocol/schemas/v1"
SCHEMA_FOR = {
    "almsivi.health.v1": "health.schema.json",
    "almsivi.error.v1": "error.schema.json",
    "almsivi.session.accepted.v1": "session-accepted.schema.json",
    "almsivi.turn.accepted.v1": "turn-accepted.schema.json",
    "almsivi.events.v1": "events.schema.json",
    "almsivi.interruption.accepted.v1": "interruption-accepted.schema.json",
    "almsivi.action-result.accepted.v1": "action-result-accepted.schema.json",
    "almsivi.stt.accepted.v1": "stt-accepted.schema.json",
    "almsivi.dialogue-delivery-result.accepted.v1": "dialogue-delivery-result-accepted.schema.json",
    "almsivi.session.ended.v1": "session-ended.schema.json",
}

def main() -> int:
    if len(sys.argv) != 2:
        raise SystemExit("usage: validate-responses.py CAPTURE.jsonl")
    documents = [json.loads(path.read_text(encoding="utf-8")) for path in sorted(SCHEMAS.glob("*.json"))]
    registry = Registry()
    by_name = {}
    for document in documents:
        registry = registry.with_resource(document["$id"], Resource.from_contents(document))
        by_name[Path(document["$id"]).name] = document
    count = 0
    with Path(sys.argv[1]).open(encoding="utf-8") as handle:
        for line_number, line in enumerate(handle, 1):
            instance = json.loads(line)
            schema_name = instance.get("schema")
            if schema_name not in SCHEMA_FOR:
                raise ValueError(f"line {line_number}: unknown response schema {schema_name!r}")
            Draft202012Validator(by_name[SCHEMA_FOR[schema_name]], registry=registry,
                format_checker=Draft202012Validator.FORMAT_CHECKER).validate(instance)
            count += 1
    print(f"validated {count} server responses with Draft 2020-12")
    return 0

if __name__ == "__main__":
    raise SystemExit(main())
