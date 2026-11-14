"""Nested policy, appended to the shared NIC functions only for an admitted allowlist.

A nested-fleet container starts only when its effective config and devices are exactly what Orbit writes for it. The allowed
config is the intent's own `container.config`, the map that create and clone write with `--config`, so the two cannot drift.
Incus writes `volatile.*` and `image.*` itself and Orbit writes none, so the guard does not judge them; `environment.*` keys only set the environment of processes in the container, and
come from both the intent and the project's profile. Every other key must be in the allowed config with the same value, and
every allowed key must be set: a missing `security.idmap.isolated` would fall back to the shared map. The NIC filter keys
(`FILTERS` of the shared functions) are left out of the device comparison: the shared rule refuses a NIC without them with its own
code, `instance.nic_filters_missing` (exit 2).

Volume keys do not show in the container's config, so the guard reads every volume a disk device names with `incus query`. The
root volume must not be delegated to the container. Any other volume must be one of the allowed volumes, the inner volume of
docs/reference/development-containers.md#the-inner-volume, with exactly its keys; Incus writes `volatile.*` itself, and an empty value is an unset key. The devices are
compared first, so a disk can only name the volume that the intent names.
"""

import json
import subprocess
import sys

OWNED_BY_INCUS = ("volatile.", "image.")
ENVIRONMENT = "environment."


def nested_unsafe_config(config, allowed):
    for key, value in config.items():
        if key.startswith(OWNED_BY_INCUS + (ENVIRONMENT,)):
            continue
        if key not in allowed or allowed[key] != value:
            return "config " + key
    for key, value in allowed.items():
        if not key.startswith(ENVIRONMENT) and config.get(key) != value:
            return "config " + key
    return ""


def without_filters(device):
    return {key: value for key, value in device.items() if key not in globals()["FILTERS"]}


def nested_unsafe_device(container, allowed, allowed_config):
    unsafe = nested_unsafe_config(container["expanded_config"], allowed_config)
    if unsafe:
        return unsafe
    devices = container["expanded_devices"]
    for name, device in devices.items():
        if name not in allowed or without_filters(device) != without_filters(allowed[name]):
            return name
    for name in allowed:
        if name not in devices:
            return name
    return ""


def read_volume(container, device):
    project = container.get("project") or "default"
    kind, name = ("container", container["name"]) if device.get("path") == "/" else ("custom", device.get("source", ""))
    try:
        done = subprocess.run(["incus", "query", "--", f"/1.0/storage-pools/{device['pool']}/volumes/{kind}/{name}?project={project}"],
                              capture_output=True, text=True, stdin=subprocess.DEVNULL, check=False)
        return json.loads(done.stdout).get("config", {}) if done.returncode == 0 else None
    except (OSError, ValueError, AttributeError):
        return None


def nested_unsafe_volume(container, allowed_volumes, read=read_volume):
    for name, device in sorted(container["expanded_devices"].items()):
        if device.get("type") != "disk" or not device.get("pool"):
            continue
        config = read(container, device)
        if config is None:
            return "volume " + name + " unreadable"
        if config.get("zfs.delegate", "false") != "false":
            return "volume " + name + " zfs.delegate"
        if device.get("path") == "/":
            continue
        if name not in allowed_volumes:
            return "volume " + name
        # An empty value is an unset key for Incus, which fills `block.create_options` from the pool that way.
        own = {key: value for key, value in config.items() if not key.startswith("volatile.") and value != ""}
        for key in sorted(set(own) | set(allowed_volumes[name])):
            if own.get(key) != allowed_volumes[name].get(key):
                return "volume " + name + " " + key
    return ""


if __name__ == "__main__":
    container = json.load(sys.stdin)
    device = nested_unsafe_device(container, json.loads(sys.argv[3]), json.loads(sys.argv[4])) \
        or nested_unsafe_volume(container, json.loads(sys.argv[5]) if len(sys.argv) > 5 else {}) \
        or globals()["unsafe_device"](container, *sys.argv[1:3])
    if device:
        print(device)
        sys.exit(1)
    globals()["refuse"](container, sys.argv[1], None)
