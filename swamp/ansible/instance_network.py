"""The effective-NIC gate shared by native clones and Ansible starts and host audits."""

import json
import sys

# Each Instance NIC accepts only its own IPv4 and MAC address (docs/reference/development-containers.md#network). The Python holder
# of the keys: the native clone reads them from this source. Ansible reads instance-nic-filters.json, the Gateway
# InstanceHostIntent::NicFilters; instance_clone_test.py keeps the three equal. A NIC made before them is reported, not refused, until
# every existing NIC has them.
FILTERS = {"security.ipv4_filtering": "true", "security.mac_filtering": "true"}


def unsafe_device(container, network, expected_device=""):
    devices = container["expanded_devices"]
    if expected_device and (
        devices.get(expected_device, {}).get("network") != network
        or devices.get(expected_device, {}).get("type") != "nic"
    ):
        return expected_device
    for name, device in devices.items():
        if device.get("type") != "nic" or network not in (
            device.get("network"),
            device.get("parent"),
        ):
            continue
        acls = [
            acl.strip()
            for acl in device.get("security.acls", "").split(",")
            if acl.strip()
        ]
        if (
            not acls
            or device.get("security.acls.default.egress.action") != "reject"
            or network + "-egress" in acls
            or (expected_device and name != expected_device)
        ):
            return name
    return None


def unfiltered_report(container, network):
    """One line per bridge NIC that lacks a filter key; empty when every NIC has them."""
    return "".join(
        container["name"] + "/" + name + " lacks " + " and ".join(FILTERS) + "\n"
        for name, device in container["expanded_devices"].items()
        if device.get("type") == "nic"
        and network in (device.get("network"), device.get("parent"))
        and any(device.get(key) != value for key, value in FILTERS.items())
    )


if __name__ == "__main__":
    container = json.load(sys.stdin)
    device = unsafe_device(container, *sys.argv[1:])
    if device is not None:
        print(
            container["name"]
            + "/"
            + device
            + " needs an effective ACL with default egress reject and no bridge union ACL"
        )
        raise SystemExit(1)
    print(unfiltered_report(container, sys.argv[1]), end="")
