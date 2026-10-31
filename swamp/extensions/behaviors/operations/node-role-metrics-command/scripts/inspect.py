"""Observe one canonical Metrics container or volume without returning private engine data."""

import json
import subprocess
import sys

METRICS_INPUT = None
DOCKER = ["/usr/bin/docker", "--host", "unix:///var/run/docker.sock"]


def inspect(data, run=subprocess.run):
    kind = data["kind"]
    name = data["name"]
    listing = run([*DOCKER, kind, "ls", *(["--all"] if kind == "container" else []),
                   "--filter", "name=^" + ("/" if kind == "container" else "") + name + "$",
                   "--format={{." + ("Names" if kind == "container" else "Name") + "}}"],
                  capture_output=True, text=True, check=False)
    if listing.returncode:
        raise ValueError("metrics.inspection_failed")
    if not listing.stdout.strip():
        return {"present": False, "state": "absent", "health": None, "hash": None, "current": False}
    if listing.stdout.strip() != name:
        raise ValueError("metrics.ownership_drift")
    template = '{{json .Config.Labels}}\n{{json .State}}' if kind == "container" else '{{json .Labels}}'
    observed = run([*DOCKER, kind, "inspect", "--format=" + template, "--", name],
                   capture_output=True, text=True, check=False)
    if observed.returncode:
        raise ValueError("metrics.inspection_failed")
    rows = observed.stdout.splitlines()
    labels = json.loads(rows[0]) or {}
    if any(labels.get(key) != value for key, value in data["labels"].items()):
        raise ValueError("metrics.ownership_drift")
    state = json.loads(rows[1]) if kind == "container" else {}
    health = state.get("Health", {}).get("Status")
    digest = labels.get("com.orbit.metrics.spec-hash")
    return {"present": True, "state": state.get("Status", "present"), "health": health,
            "hash": digest, "current": kind == "container" and digest == data["hash"]
            and state.get("Status") == "running" and health == "healthy"}


if __name__ == "__main__":
    try:
        print(json.dumps(inspect(METRICS_INPUT)))
    except (ValueError, KeyError, IndexError, TypeError, OSError):
        print('{"error_code":"metrics.inspection_failed"}')
        sys.exit(1)
