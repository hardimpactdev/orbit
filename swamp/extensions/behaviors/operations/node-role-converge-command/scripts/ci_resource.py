"""Converge one reserved Incus resource. Other resources are separate native steps."""

import json
import subprocess
import sys

ROLE_INPUT = None
PROJECT = "orbit-ci"
NETWORK = "orbitci0"
ACL = "orbit-ci-egress"
PROJECT_CONFIG = {
    "features.images": "true",
    "features.profiles": "true",
    "features.storage.volumes": "true",
    "features.storage.buckets": "true",
    "features.networks": "false",
    "restricted": "true",
    "restricted.networks.access": NETWORK,
    "restricted.containers.nesting": "block",
    "restricted.containers.privilege": "unprivileged",
    "restricted.virtual-machines.lowlevel": "block",
    "restricted.containers.lowlevel": "allow",
    "limits.virtual-machines": "0",
}
NETWORK_CONFIG = {
    "ipv4.nat": "true",
    "ipv6.address": "none",
    "security.acls": ACL,
    "security.acls.default.egress.action": "reject",
    "security.acls.default.ingress.action": "reject",
}
EGRESS = [
    {
        "action": "reject",
        "destination": "0.0.0.0/8,10.0.0.0/8,100.64.0.0/10,127.0.0.0/8,169.254.0.0/16,172.16.0.0/12,192.0.0.0/24,192.168.0.0/16,198.18.0.0/15,224.0.0.0/3",
        "state": "enabled",
    },
    {"action": "reject", "destination": "::/0", "state": "enabled"},
    {"action": "allow", "destination": "0.0.0.0/0", "state": "enabled"},
]
PROFILE = {
    "description": "Orbit CI shard runner",
    "config": {
        "boot.autostart": "true",
        "limits.cpu": "6",
        "limits.memory": "12GiB",
        "limits.processes": "8192",
        "security.nesting": "false",
        "security.privileged": "false",
    },
    "devices": {
        "eth0": {"name": "eth0", "network": NETWORK, "type": "nic"},
        "root": {"path": "/", "pool": "default", "type": "disk"},
    },
}
RESOURCES = {
    "pool": ("storage-pools", "default"),
    "project": ("projects", PROJECT),
    "acl": ("network-acls", ACL),
    "network": ("networks", NETWORK),
    "profile": ("profiles", "default"),
}


def command(args, document=None):
    output = subprocess.run(
        ["/usr/bin/incus", *args],
        input=None if document is None else json.dumps(document),
        text=True, capture_output=True, check=True,
    )
    return json.loads(output.stdout) if output.stdout.strip() else None


def path(collection, name=None):
    base = "/1.0/" + collection + ("/" + name if name else "")
    project = PROJECT if collection == "profiles" else "default"
    return base + "?project=" + project


def includes(actual, expected):
    return all(actual.get(key) == value for key, value in expected.items())


def rules(actual):
    return [{key: value for key, value in rule.items() if value != ""} for rule in actual]


def matches(resource, current):
    if resource == "pool":
        return True
    if resource == "project":
        return includes(current["config"], PROJECT_CONFIG)
    if resource == "acl":
        return rules(current["egress"]) == EGRESS and current["ingress"] == []
    if resource == "network":
        address = current["config"].get("ipv4.address")
        return (current["type"] == "bridge" and current["managed"] is True
                and isinstance(address, str) and address not in ("", "none", "auto")
                and includes(current["config"], NETWORK_CONFIG))
    return current["config"] == PROFILE["config"] and current["devices"] == PROFILE["devices"]


def apply(data, invoke=command):
    resource = data["resource"]
    collection, name = RESOURCES[resource]
    endpoint = path(collection, name)
    listing = invoke(["query", path(collection)])
    if not isinstance(listing, list) or any(not isinstance(item, str) for item in listing):
        raise ValueError("Invalid Incus listing")
    exists = any(item.split("?")[0] == endpoint.split("?")[0] for item in listing)
    current = invoke(["query", endpoint]) if exists else None
    if exists and matches(resource, current):
        return {"ready": True, "changed": False}
    if resource == "pool":
        desired = {"name": name, "driver": "dir"}
    elif resource == "project":
        if exists and current["config"].get("features.networks") != "false":
            raise ValueError("The CI project has foreign network ownership")
        desired = {"name": name, "config": {**(current or {}).get("config", {}), **PROJECT_CONFIG}}
    elif resource == "acl":
        desired = {"name": name, "description": "Orbit CI runner boundary", "egress": EGRESS, "ingress": [], "config": {}}
    elif resource == "network":
        if exists and (current["type"] != "bridge" or current["managed"] is not True):
            raise ValueError("The CI network is not a managed bridge")
        desired = {"name": name, "type": "bridge", "config": {
            **({"ipv4.address": "auto"} if current is None else current["config"]), **NETWORK_CONFIG,
        }}
    else:
        if not exists:
            raise ValueError("The CI project has no default profile")
        desired = PROFILE
    invoke(["query", endpoint if exists else path(collection), "--request", "PUT" if exists else "POST", "--data", "-", "--wait"], desired)
    verified = invoke(["query", endpoint])
    if not isinstance(verified, dict) or not matches(resource, verified):
        raise ValueError("The CI resource did not converge")
    return {"ready": True, "changed": True}


if __name__ == "__main__":
    try:
        print(json.dumps(apply(ROLE_INPUT)))
    except (ValueError, KeyError, TypeError, OSError, subprocess.SubprocessError):
        print(json.dumps({"error_code": "ci_runner.host_failed"}))
        sys.exit(1)
