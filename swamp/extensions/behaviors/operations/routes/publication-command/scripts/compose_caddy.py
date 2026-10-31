"""Compose one public Caddy candidate against the current managed generation."""

import hashlib
import json

from caddy_sections import PublicationConflict, merge, split
from publication_files import ROOT, metadata, payload, stage_for

value = metadata()
admitted = payload(stage_for(value), value)
before, after = admitted["before"], admitted["after"]
if admitted["direction"] == "restore":
    before, after = after, before
live = ROOT / "Caddyfile"
if live.is_file():
    target = live.resolve(strict=True)
    observed = target.read_bytes()
    current = observed.decode("utf-8")
    expected = {"sha256": hashlib.sha256(observed).hexdigest(), "target": str(target)}
else:
    if live.exists() or live.is_symlink() or split(before["content"])[1]:
        raise PublicationConflict("caddy.prior_generation_missing")
    current = split(before["content"])[0]
    expected = {"sha256": None, "target": None}
content = merge(before["content"], after["content"], current)
if len(content.encode()) > 4194304:
    raise PublicationConflict("caddy.input_too_large")
print(
    json.dumps(
        {
            "node_id": admitted["node_id"],
            "user": admitted["user"],
            "caddy": {
                "content": content,
                "version": hashlib.sha256(content.encode()).hexdigest()[:32],
                "listen_addresses": sorted(
                    set(before["listen_addresses"] + after["listen_addresses"])
                ),
                "unserved": not bool(split(content)[1]),
            },
            "expected_current": expected,
        }
    )
)
