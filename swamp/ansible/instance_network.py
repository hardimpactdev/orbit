"""The effective-NIC gate shared by native clones and Ansible starts and host audits."""

import json
import sys


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
