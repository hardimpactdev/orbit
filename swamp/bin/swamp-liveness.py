#!/usr/bin/env python3
"""Independent Node 209 health, claim progress and inode monitor. No credentials."""
import datetime as dt
import json
import math
import os
from pathlib import Path
import re
import ssl
import subprocess
import tempfile
import time
import urllib.request

UNIT = "orbit-process-392-swamp-serve.service"
STATE = Path("/var/lib/orbit-swamp-liveness/state.json")
CA = "/usr/local/share/ca-certificates/orbit-managed-root-ca.crt"
STALE_SECONDS = 180
COOLDOWN_SECONDS = 600


def read_state(path, now):
    if not path.exists():
        return {}
    try:
        value = json.loads(path.read_text())
        if not isinstance(value, dict) or set(value) - {"last_restart", "unhealthy_since", "unchanged_since", "claimable_heads"}:
            raise ValueError("invalid_state")
        timestamps = [v for k, v in value.items() if k != "claimable_heads"]
        heads = value.get("claimable_heads", {})
        if not isinstance(heads, dict) or not all(isinstance(k, str) for k in heads):
            raise ValueError("invalid_heads")
        timestamps.extend(heads.values())
        if not all(type(v) in (int, float) and math.isfinite(v) and 0 <= v <= now for v in timestamps):
            raise ValueError("invalid_time")
        return value
    except (ValueError, TypeError):
        path.replace(path.with_name(f"state-invalid-{time.time_ns()}.json"))
        print('<3>{"action":"state_invalid","restart_cooldown_seconds":600}', flush=True)
        return {"last_restart": now}


def write_state(path, value):
    candidate = None
    try:
        with tempfile.NamedTemporaryFile(mode="w", dir=path.parent, delete=False) as target:
            candidate = Path(target.name)
            json.dump(value, target)
            target.flush()
            os.fsync(target.fileno())
        os.replace(candidate, path)
        descriptor = os.open(path.parent, os.O_RDONLY | os.O_DIRECTORY)
        try:
            os.fsync(descriptor)
        finally:
            os.close(descriptor)
    finally:
        if candidate is not None:
            candidate.unlink(missing_ok=True)


def read_json(url, **kwargs):
    request = urllib.request.Request(url, headers={"Host": "swamp.orbit"} if url.startswith("http:") else {})
    with urllib.request.urlopen(request, timeout=15, **kwargs) as response:
        return json.loads(response.read(1_048_576))


def health_available(value):
    return isinstance(value, dict) and value.get("status") == "ok"


def claimable_heads(value):
    if not isinstance(value, dict) or not isinstance(value.get("data"), dict) or not isinstance(value["data"].get("kinds"), list):
        raise ValueError("invalid_claimable_envelope")
    heads = {}
    seen = set()
    for entry in value["data"]["kinds"]:
        if not isinstance(entry, dict) or not isinstance(entry.get("kind"), str) or not re.fullmatch(r"[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*", entry["kind"]):
            raise ValueError("invalid_claimable_kind")
        if entry["kind"] in seen:
            raise ValueError("duplicate_claimable_kind")
        seen.add(entry["kind"])
        if "claimable" not in entry:
            raise ValueError("missing_claimable_count")
        if type(entry["claimable"]) is not int or entry["claimable"] < 0:
            raise ValueError("invalid_claimable_count")
        if entry["claimable"]:
            # Refuse malformed dates instead of treating unavailable evidence as a stall.
            if not isinstance(entry.get("oldest_at"), str):
                raise ValueError("invalid_claimable_time")
            timestamp = dt.datetime.fromisoformat(entry["oldest_at"].replace("Z", "+00:00"))
            if timestamp.tzinfo is None:
                raise ValueError("invalid_claimable_time")
            heads[entry["kind"]] = timestamp.timestamp()
    return heads


def evaluate(previous, *, now, healthy, heads, free_inodes, total_inodes, serve_age):
    state = dict(previous)
    free_percent = 100 * free_inodes / total_inodes
    result = {"action": "healthy", "serve_healthy": healthy, "free_inodes": free_inodes, "free_inode_percent": round(free_percent, 2)}
    if free_percent <= 20:
        result["inode_alert"] = "critical" if free_percent < 2 else "warning"
    if healthy:
        state.pop("unhealthy_since", None)
    else:
        state.setdefault("unhealthy_since", now)
    stalled = False
    if heads is not None:
        if heads != previous.get("claimable_heads"):
            state["unchanged_since"] = now
        state["claimable_heads"] = heads
        if heads:
            result["oldest_claimable_age_seconds"] = max(0, round(now - min(heads.values())))
            stalled = now - state.get("unchanged_since", now) >= STALE_SECONDS and result["oldest_claimable_age_seconds"] > STALE_SECONDS
    else:
        # An unavailable Gateway read is not proof of stalled execution.
        state.pop("unchanged_since", None)
        state.pop("claimable_heads", None)
        result["claimable_probe"] = "unavailable"
    unhealthy = not healthy and now - state["unhealthy_since"] >= STALE_SECONDS
    if unhealthy or stalled:
        result["reason"] = "health_unavailable" if unhealthy else "claimable_not_progressing"
        if free_percent < 1:
            result["action"] = "inode_exhaustion"  # Restart cannot create storage capacity.
        elif serve_age < STALE_SECONDS:
            result["action"] = "startup_grace"
        elif now - previous.get("last_restart", 0) < COOLDOWN_SECONDS:
            result["action"] = "restart_cooldown"
        else:
            result["action"] = "restart"
    return result, state


def main():
    now = time.time()
    previous = read_state(STATE, now)
    try:
        healthy = health_available(read_json("http://127.0.0.1:9418/health"))
    except (OSError, ValueError):
        healthy = False
    try:
        heads = claimable_heads(read_json("https://gateway.orbit/api/v1/operation-executor/claimable", context=ssl.create_default_context(cafile=CA)))
    except (OSError, ValueError, KeyError, TypeError):
        heads = None
    inode_paths = ["/", "/run/swamp-datastore-inode-probe"]
    inode_filesystem, filesystem = min(
        ((path, os.statvfs(path)) for path in inode_paths),
        key=lambda item: item[1].f_favail / item[1].f_files,
    )
    started = subprocess.run(["systemctl", "show", UNIT, "-p", "ActiveEnterTimestampMonotonic", "--value"], capture_output=True, text=True, check=True, timeout=10)
    serve_age = time.monotonic() - int(started.stdout.strip()) / 1_000_000
    result, state = evaluate(previous, now=now, healthy=healthy, heads=heads,
                             free_inodes=filesystem.f_favail, total_inodes=filesystem.f_files, serve_age=serve_age)
    result["inode_filesystem"] = inode_filesystem
    if result["action"] == "restart":
        # Persist the cooldown before the effect, including uncertain restart replies.
        state["last_restart"] = now
    priority = 3 if result.get("inode_alert") == "critical" else 4 if result.get("inode_alert") else 6
    print(f"<{priority}>" + json.dumps({"at": dt.datetime.fromtimestamp(now, dt.timezone.utc).isoformat(), **result}), flush=True)
    write_state(STATE, state)
    if result["action"] == "restart":
        subprocess.run(["systemctl", "restart", UNIT], check=True, timeout=90)


if __name__ == "__main__":
    main()
