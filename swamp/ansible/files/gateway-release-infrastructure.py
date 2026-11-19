#!/usr/bin/python3
"""Create only owned, release-specific resources on a preflighted Incus host."""
import json
import subprocess
import sys

PROJECT = "orbit-releases"
NETWORK = "orbitgw0"
OWNER = {"user.orbit.purpose": "gateway-releases"}
LOCAL = ["incus", "--force-local"]
SCOPED = [*LOCAL, "--project", PROJECT]
PREP = "prepare-gateway"


def output(*args):
    return subprocess.check_output(args, text=True, stdin=subprocess.DEVNULL).strip()


def require(condition, reason):
    if not condition:
        raise ValueError(reason)


def existing(resource, name, project=None):
    endpoint = "/1.0/" + resource + "?recursion=1"
    if project:
        endpoint += "&project=" + project
    entries = json.loads(output(*LOCAL, "query", endpoint))
    return next((item for item in entries if item.get("name", item.get("fingerprint")) == name), None)


def require_settings(actual, expected, subject):
    for key, value in expected.items():
        require(actual.get(key) == value, f"{subject} has an unexpected {key}")


def create(resource, name, command, settings):
    item = existing(resource, name)
    if item is None:
        args = list(command)
        for key, value in settings.items():
            if resource == "projects":
                args.append("--config")
            args.append(f"{key}={value}")
        output(*LOCAL, *args)
        item = existing(resource, name)
    require_settings(item["config"], settings, name)
    return item


def create_preparer(digest, identity):
    args = [*SCOPED, "init", digest, PREP, "--no-profiles", "-s", PROJECT, "-n", NETWORK]
    for key, value in identity.items():
        args.extend(["-c", f"{key}={value}"])
    output(*args)


def main(plan):
    dataset = plan["dataset"]
    datasets = output("zfs", "list", "-Hp", "-o", "name").splitlines()
    if dataset not in datasets:
        output("zfs", "create", "-o", "mountpoint=none", "-o", "org.orbit:purpose=gateway-releases", dataset)
    require(output("zfs", "get", "-H", "-o", "value,source", "org.orbit:purpose", dataset).split()
            == ["gateway-releases", "local"], "the dedicated ZFS dataset is not owned by Gateway releases")
    pool = create("storage-pools", PROJECT, ["storage", "create", PROJECT, "zfs"], {"source": dataset, **OWNER})
    require(pool["driver"] == "zfs", "unexpected release pool driver")
    create("projects", PROJECT, ["project", "create", PROJECT],
           {**OWNER, "features.images": "true", "features.profiles": "true", "features.networks": "false"})
    network = create("networks", NETWORK, ["network", "create", NETWORK],
                     {**OWNER, "ipv4.address": plan["bridge"], "ipv4.dhcp": "true",
                      "ipv4.nat": "true", "ipv6.address": "none"})
    require(network["type"] == "bridge" and network["managed"], "unexpected release network")
    require(all("project=" + PROJECT in ref for ref in network["used_by"]), "release bridge has an unrelated consumer")

    digest = plan["stock_sha256"]
    image = existing("images", digest, PROJECT)
    if image is None:
        output(*SCOPED, "image", "import", plan["stock_image"])
        image = existing("images", digest, PROJECT)
    require(image is not None and image["type"] == "container", "imported image is not the selected container fingerprint")
    require_settings(image["properties"], {"os": "ubuntu", "release": "resolute", "architecture": "amd64",
                     "user.orbit.stock-build": "ubuntu-26.04-instance-stock"}, "stock image")
    identity = {"user.orbit.release.target": "gateway", "user.orbit.release.role": "prepare",
                "boot.autostart": "false", "security.privileged": "false", "security.nesting": "false"}
    prep = existing("instances", PREP, PROJECT)
    if prep is None:
        # A release promotes the preparer to its candidate. Its retire recreates preparation; a converge never
        # adds a fourth Gateway container beside a candidate, live and old. bin/gateway-container-prepare already
        # holds the release lock around this playbook, so no release phase runs during the check.
        names = json.loads(output(*SCOPED, "list", "--format", "json"))
        owned = [item for item in names if item["name"].startswith("gateway-") or item["config"].get("user.orbit.release.target") == "gateway"]
        require(len(owned) < 3, "a Gateway release holds the preparation slot; finish it before converging")
        create_preparer(digest, identity)
        prep = existing("instances", PREP, PROJECT)
    require(not prep["profiles"] and prep["type"] == "container", "preparer must have an isolated device set")
    require_settings(prep["expanded_config"], {**identity, "volatile.base_image": digest}, PREP)
    require(not any(key.startswith("raw.") for key in prep["expanded_config"]), "preparer has a raw host override")
    devices = prep["expanded_devices"]
    require_settings(devices["root"], {"type": "disk", "path": "/", "pool": PROJECT}, "preparer root")
    require_settings(devices["eth0"], {"type": "nic", "network": NETWORK}, "preparer interface")
    allow = {"root", "eth0", "env", "state", "storage", "runtime"}
    require(set(devices) <= allow, "preparer has an unexpected device")
    if set(devices) - {"root", "eth0"}:
        require(prep["config"].get("user.orbit.release.account") == "ready", "unexpected mounts before account provisioning")


if __name__ == "__main__":
    try:
        main(json.loads(sys.argv[1]))
    except (ValueError, KeyError, subprocess.CalledProcessError) as error:
        sys.exit(str(error))
