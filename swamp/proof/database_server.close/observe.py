"""Observe one disposable adopted Valkey server; emit no credentials or ACL hashes."""

import argparse
import hashlib
import ipaddress
import json
import re
import subprocess
from pathlib import Path

PORT = 63983


def require(value, message):
    if not value:
        raise AssertionError(message)


def docker(*argv):
    reply = subprocess.run(["docker", "--host", "unix:///var/run/docker.sock", *argv],
                           capture_output=True, text=True, timeout=30, check=False)
    require(reply.returncode == 0, "Docker observation failed")
    return reply.stdout


def default_policy(text, opened):
    lines = [line.split() for line in text.splitlines() if line.strip()]
    if len(lines) != 1 or lines[0][:2] != ["user", "default"]:
        return False
    tokens = lines[0][2:]
    hashes = [token for token in tokens if token.startswith("#")]
    known = {"on", "sanitize-payload", "skip-sanitize-payload", "nopass", "~*", "allkeys", "&*", "allchannels", "+@all", *hashes}
    return ("on" in tokens and "off" not in tokens and not any(t.startswith(">") for t in tokens)
            and set(tokens) <= known and "+@all" in tokens
            and bool({"~*", "allkeys"} & set(tokens)) and bool({"&*", "allchannels"} & set(tokens))
            and (("nopass" in tokens and not hashes) if opened else
                 ("nopass" not in tokens and len(hashes) == 1
                  and re.fullmatch(r"#[0-9a-fA-F]{64}", hashes[0]) is not None)))


def container_matches(raw, process_id, volume, ip):
    labels = raw["Config"].get("Labels") or {}
    bindings = raw["HostConfig"].get("PortBindings") or {}
    ports = bindings.get("6379/tcp") or []
    private = [ipaddress.ip_network(net) for net in ["10.0.0.0/8", "172.16.0.0/12", "192.168.0.0/16"]]
    mounts = [m for m in raw["Mounts"] if m.get("Destination") == "/data"]
    return {"owned": labels.get("orbit.managed") == "true"
            and labels.get("orbit.container.kind") == "process"
            and labels.get("orbit.process.id") == str(process_id),
            "running": raw["State"]["Status"] == "running" and raw["Config"]["Image"] == "valkey/valkey:8.1",
            "private_binding": set(bindings) == {"6379/tcp"} and bool(ports)
            and any(p["HostIp"] == ip for p in ports)
            and all(p["HostPort"] == str(PORT) and any(ipaddress.ip_address(p["HostIp"]) in n for n in private)
                    for p in ports),
            "data_mount": len(mounts) == 1 and mounts[0].get("Type") == "volume"
            and mounts[0].get("Name") == volume and mounts[0].get("RW") is True}


def observe(action, slug, process_id, ip, run, operation_ids):
    require(re.fullmatch(r"[a-z0-9-]{1,40}", run) and slug == "proof-close-" + run,
            "Not an owned disposable server")
    require(type(process_id) is int and process_id > 0 and
            all(type(op) is int and op > 0 for op in operation_ids), "Invalid proof identity")
    name, volume = f"orbit-process-{process_id}-{slug}", "orbit-" + slug + "-data"
    raw = json.loads(docker("inspect", name))
    require(isinstance(raw, list) and len(raw) == 1, "Owned container missing")
    state = container_matches(raw[0], process_id, volume, ip)
    require(all(state.values()), "Container ownership or placement differs")
    volumes = json.loads(docker("volume", "inspect", volume))
    require(len(volumes) == 1 and volumes[0]["Name"] == volume, "Owned volume missing")
    root = Path(volumes[0]["Mountpoint"])
    acl = (root / "users.acl").read_text()
    opened = action == "before"
    state["acl_matches"] = default_policy(acl, opened)
    ping = docker("exec", name, "valkey-cli", "--raw", "PING").strip()
    state["anonymous_matches"] = ping == ("PONG" if opened else "NOAUTH Authentication required.")
    marker, snapshot = root / (".orbit-proof-close-" + run), root / (".orbit-proof-close-" + run + ".closed")
    require(state["acl_matches"] and state["anonymous_matches"], "Effective or saved access differs")
    if opened:
        with marker.open("x") as output:
            marker.chmod(0o600)
            output.write(run)
    state["marker_retained"] = marker.is_file() and not marker.is_symlink() and marker.read_text() == run
    digest = hashlib.sha256(acl.encode()).hexdigest()
    if action == "closed":
        with snapshot.open("x") as output:
            snapshot.chmod(0o600)
            output.write(digest)
    state["policy_unchanged"] = opened or (snapshot.is_file() and not snapshot.is_symlink()
                                           and snapshot.read_text() == digest)
    state["scratch_absent"] = all(not Path(f"/tmp/orbit-database-close-{op}").exists() for op in operation_ids)
    return state


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("action", choices=["before", "closed", "repeat"])
    parser.add_argument("slug")
    parser.add_argument("process_id", type=int)
    parser.add_argument("ip")
    parser.add_argument("run")
    parser.add_argument("operation_ids", nargs="*", type=int)
    try:
        result = observe(**vars(parser.parse_args()))
        require(all(value is True for value in result.values()), "Observation failed")
    except (AssertionError, KeyError, OSError, ValueError, TypeError, subprocess.TimeoutExpired):
        print('{"error":"Workload observation failed"}')
        return 1
    print(json.dumps(result))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
