"""The effective-NIC gate shared by native clones and Ansible starts and host audits.

Exit 1: an unguarded NIC (no ACL, no default egress reject, the bridge union ACL, or an unexpected NIC). Exit 2: a NIC with its own
address that lacks the address filters (`instance.nic_filters_missing`).
"""

import json
import sys

# Each Instance NIC accepts only its own IPv4 and MAC address (docs/reference/development-containers.md#network). The Python holder
# of the keys: the native clone reads them from this source. Ansible reads instance-nic-filters.json, the Gateway
# InstanceHostIntent::NicFilters; instance_clone_test.py keeps the three equal.
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


def unfiltered_device(container, network):
    """The first bridge NIC with its own `ipv4.address` that lacks a filter key, or None.

    Every Instance NIC holds its own address: create, clone and migrate write it with the filters. A NIC without one takes a DHCP
    lease from the bridge: the project profile's `eth0`, which the host converge's clone probe uses. Incus can pin no address there,
    so it carries no filter and this rule does not apply to it; its ACL rules above still do.
    """
    for name, device in container["expanded_devices"].items():
        if (
            device.get("type") == "nic"
            and network in (device.get("network"), device.get("parent"))
            and device.get("ipv4.address")
            and any(device.get(key) != value for key, value in FILTERS.items())
        ):
            return name
    return None


def refuse(container, network, device):
    """Exit 1 for an unguarded NIC, 2 for a NIC without the address filters, and return when every NIC passes."""
    if device:
        print(container["name"] + "/" + device + " needs an effective ACL with default egress reject and no bridge union ACL")
        raise SystemExit(1)
    unfiltered = unfiltered_device(container, network)
    if unfiltered is not None:
        print(container["name"] + "/" + unfiltered + " lacks " + " and ".join(FILTERS))
        raise SystemExit(2)


if __name__ == "__main__":
    container = json.load(sys.stdin)
    refuse(container, sys.argv[1], unsafe_device(container, *sys.argv[1:]))
