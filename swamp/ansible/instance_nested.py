"""Nested policy, appended to the shared NIC functions only for an admitted allowlist."""

import json
import sys


def nested_unsafe_device(container, allowed):
    config = container["expanded_config"]
    if any(key.startswith("raw.") for key in config):
        return "raw config"
    if config.get("security.privileged", "false") != "false":
        return "privileged container"
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
    device = nested_unsafe_device(container, json.loads(sys.argv[3])) or globals()["unsafe_device"](container, *sys.argv[1:3])
    if device:
        print(device)
        sys.exit(1)
