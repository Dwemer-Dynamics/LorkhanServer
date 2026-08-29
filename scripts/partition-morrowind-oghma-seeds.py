#!/usr/bin/env python3
"""Split an Oghma seed document into deterministic independent generation partitions."""

from __future__ import annotations

import argparse
import json
from pathlib import Path
from typing import Any


def read_json(path: Path) -> Any:
    raw = path.read_bytes()
    if raw.startswith((b"\xff\xfe", b"\xfe\xff", b"\xff\xfe\x00\x00", b"\x00\x00\xfe\xff")):
        raise ValueError(f"File is not UTF-8: {path}")
    return json.loads(raw.decode("utf-8-sig"))


def write_json(path: Path, value: Any) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    temporary = path.with_name(path.name + ".tmp")
    temporary.write_text(json.dumps(value, indent=2, ensure_ascii=False) + "\n", encoding="utf-8", newline="\n")
    temporary.replace(path)


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--seeds", type=Path, required=True)
    parser.add_argument("--output-dir", type=Path, required=True)
    parser.add_argument("--parts", type=int, default=4)
    return parser.parse_args()


def main() -> int:
    args = parse_args()
    if args.parts < 1:
        raise ValueError("--parts must be positive")
    document = read_json(args.seeds)
    topics = document.get("topics") if isinstance(document, dict) else None
    if not isinstance(topics, list) or not topics:
        raise ValueError("Seed document does not contain topics")
    partitions: list[list[dict[str, Any]]] = [[] for _ in range(args.parts)]
    for index, topic in enumerate(topics):
        partitions[index % args.parts].append(topic)
    manifest = {"format": "lorkhan.morrowind-oghma-partitions.v1", "source": str(args.seeds), "total": len(topics), "parts": []}
    for index, rows in enumerate(partitions, 1):
        path = args.output_dir / f"part-{index}.json"
        write_json(path, {"format": "lorkhan.morrowind-oghma-expansion-part.v2", "topics": rows})
        manifest["parts"].append({"part": index, "path": path.name, "count": len(rows)})
    write_json(args.output_dir / "manifest.json", manifest)
    print(json.dumps(manifest, indent=2))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
