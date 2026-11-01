"""Workload-only Docker observations; never emit environment values or ACL hashes."""

import argparse
import ipaddress
import json
import re
import subprocess
from pathlib import Path


def require(value, message):
    if not value:
        raise AssertionError(message)


def docker(*argv):
    reply = subprocess.run(
        ["docker", "--host", "unix:///var/run/docker.sock", *argv],
        capture_output=True, text=True, timeout=30, check=False,
    )
    require(reply.returncode == 0, "Docker observation failed")
    return reply.stdout


def private(address):
    networks = ["10.0.0.0/8", "172.16.0.0/12", "192.168.0.0/16", "fc00::/7"]
    return any(ipaddress.ip_address(address) in ipaddress.ip_network(net) for net in networks)


def summary(raw, expected):
    config, host = raw["Config"], raw["HostConfig"]
    labels = config.get("Labels") or {}
    bindings = host.get("PortBindings") or {}
    ports = [item for values in bindings.values() for item in (values or [])]
    data = {"mysql": "/var/lib/mysql", "pgsql": "/var/lib/postgresql", "redis": "/data"}
    container_port = {"mysql": 3306, "pgsql": 5432, "redis": 6379}[expected["driver"]]
    mount = [item for item in raw["Mounts"] if item.get("Destination") == data[expected["driver"]]]
    return {
        "owned": labels.get("orbit.managed") == "true"
        and labels.get("orbit.container.kind") == "process"
        and labels.get("orbit.process.id") == str(expected["process_id"]),
        "running": raw["State"]["Status"] == "running",
        "image": config["Image"] == expected["image"],
        "environment_clean": all(
            item.split("=", 1)[0] not in {"MYSQL_ROOT_PASSWORD", "POSTGRES_PASSWORD"}
            for item in (config.get("Env") or [])
        ),
        "private_bindings": bool(ports)
        and set(bindings) == {str(container_port) + "/tcp"}
        and all(private(item["HostIp"]) and item["HostPort"] == str(expected["port"]) for item in ports)
        and any(item["HostIp"] == expected["ip"] for item in ports),
        "data_mount": len(mount) == 1 and mount[0].get("Type") == "volume"
        and mount[0].get("Name") == expected["volume"] and mount[0].get("RW") is True,
    }


def acl_summary(text, username):
    matches = [line.split() for line in text.splitlines() if line.startswith("user " + username + " ")]
    tokens = matches[0][2:] if len(matches) == 1 else []
    commands = [token for token in tokens if token.startswith(("+", "-"))]
    # GET has no admin/dangerous overlap. ACL SAVE may omit those redundant denials.
    allowed = {"-@all", "+get", "-@admin", "-@dangerous", "-function", "-script"}
    hashes = [token for token in tokens if token.startswith("#")]
    patterns = [token for token in tokens if token.startswith(("~", "%"))]
    known = {"on", "sanitize-payload", "resetchannels", *allowed, *hashes, *patterns}
    return {
        "user": len(matches) == 1,
        "enabled": "on" in tokens and "off" not in tokens,
        "hashed": len(hashes) == 1 and bool(re.fullmatch(r"#[0-9a-fA-F]{64}", hashes[0]))
        and not any(token == "nopass" or token.startswith(">") for token in tokens),
        "restricted": "-@all" in commands and "+get" in commands and set(tokens) <= known
        and set(commands) <= allowed and commands.index("-@all") < commands.index("+get"),
        "pattern": patterns == ["~proof:*"],
        "channels": not any(token.startswith("&") or token == "allchannels" for token in tokens),
    }


def observe(mode, slug, process_id, driver, port, ip, marker, username=None):
    require(re.fullmatch(r"proof-[a-z0-9-]{1,56}", slug), "Not an owned proof server")
    require(type(process_id) is int and process_id > 0, "Invalid proof Process")
    require(re.fullmatch(r"[a-zA-Z0-9_-]{1,80}", marker), "Invalid proof marker")
    name = f"orbit-process-{process_id}-{slug}"
    volume = "orbit-" + slug + "-data"
    names = set(docker("ps", "--all", "--filter", "label=orbit.process.id=" + str(process_id),
                       "--format", "{{.Names}}").splitlines())
    names.update(docker("ps", "--all", "--filter", "name=^/orbit-process-" + str(process_id) + "-",
                        "--format", "{{.Names}}").splitlines())
    volumes = json.loads(docker("volume", "inspect", volume))
    require(len(volumes) == 1 and volumes[0]["Name"] == volume, "Owned data volume missing")
    root = Path(volumes[0]["Mountpoint"])
    marker_path = root / (".orbit-proof-" + marker)
    if mode == "absent":
        return {"containers_absent": not names, "volume_retained": True,
                "marker_retained": marker_path.is_file() and marker_path.read_text() == marker}
    require(names == {name}, "Unexpected Process container set")
    raw = json.loads(docker("inspect", name))[0]
    tag = {"mysql": "mysql:8.4", "pgsql": "postgres:18", "redis": "valkey/valkey:8.1"}[driver]
    state = summary(raw, {"driver": driver, "process_id": process_id, "image": tag,
                          "port": port, "ip": ip, "volume": volume})
    require(all(state.values()), "Server container failed independent checks")
    if mode == "mark":
        # This writes workload state only after normal prepare has proved ownership.
        with marker_path.open("x") as output:
            marker_path.chmod(0o600)
            output.write(marker)
        return {"marked": True}
    if driver == "redis":
        text = (root / "users.acl").read_text()
        admin = acl_summary(text, "default")
        state["admin_hashed"] = admin["user"] and admin["enabled"] and admin["hashed"]
        if username:
            state["client_acl"] = all(acl_summary(text, username).values())
    return state


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("mode", choices=["active", "mark", "absent"])
    parser.add_argument("slug")
    parser.add_argument("process_id", type=int)
    parser.add_argument("driver", choices=["mysql", "pgsql", "redis"])
    parser.add_argument("port", type=int)
    parser.add_argument("ip")
    parser.add_argument("marker")
    parser.add_argument("--username")
    args = parser.parse_args()
    try:
        value = observe(**vars(args))
    except (AssertionError, KeyError, OSError, ValueError, TypeError, subprocess.TimeoutExpired):
        # Docker/ACL exceptions may carry private data. Never include their text.
        print(json.dumps({"error": "Workload observation failed"}))
        return 1
    print(json.dumps(value))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
