"""Nested policy, appended to the shared NIC functions only for an admitted allowlist.

A nested-fleet container starts only when its effective config and devices are exactly what Orbit writes for it. The allowed
config is the intent's own `container.config`, the map that create and clone write with `--config`, so the two cannot drift.
Incus writes `volatile.*` and `image.*` itself and Orbit writes none, so the guard does not judge them; `environment.*` keys only set the environment of processes in the container, and
come from both the intent and the project's profile. Every other key must be in the allowed config with the same value, and
every allowed key must be set: a missing `security.idmap.isolated` would fall back to the shared map.
"""

import json
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


def nested_unsafe_device(container, allowed, allowed_config):
    unsafe = nested_unsafe_config(container["expanded_config"], allowed_config)
    if unsafe:
        return unsafe
    devices = container["expanded_devices"]
    for name, device in devices.items():
        if name not in allowed or device != allowed[name]:
            return name
    for name in allowed:
        if name not in devices:
            return name
    return ""


if __name__ == "__main__":
    container = json.load(sys.stdin)
    device = nested_unsafe_device(container, json.loads(sys.argv[3]), json.loads(sys.argv[4])) \
        or globals()["unsafe_device"](container, *sys.argv[1:3])
    if device:
        print(device)
        sys.exit(1)
