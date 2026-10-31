"""Read public intent installed by the official cfgmgmt file action."""

import json
import os
from pathlib import Path

ROOT = Path("/etc/caddy")
ROOTS = {
    "caddy": ROOT,
    "php": Path("/etc/php"),
    "dns": Path("/var/lib/orbit/private-dns"),
}


def metadata():
    return json.loads(os.environ["ORBIT_ROUTE_PUBLICATION_META"])


def directory(path):
    if not path.is_dir():
        raise ValueError("route.publication_directory_missing")


def stage_for(value):
    return ROOTS[value["family"]] / ".orbit-deltas" / value["run_id"]


def payload(stage, _value):
    return json.loads((stage / "input.json").read_text())
