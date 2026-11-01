"""Verify the enrolled identity, publish observer files, then reconcile its unit."""

import json
import os
import sys

from phase_common import account, control, inputs, module


def converge(data, observed, expected, binary_changed):
    module("observer_identity").verify(expected)
    payload = data["intent"]["footprint"]["payloads"]["agent"]
    root = payload["apps_root"] or observed["home"] + "/apps"
    policy = data["observer_policy"]
    access = policy["protection"]
    if root.startswith(tuple(policy["protected_prefixes"])):
        access += "\n" + policy["binding"] + root
    unit = "\n".join(
        access if line == "ProtectHome=yes" else line
        for line in payload["unit"].split("\n")
    )
    changed = binary_changed
    files = module("file")
    for path, content, marker in [
        ("/etc/orbit/agent/config.toml", payload["configuration"], None),
        ("/etc/orbit/agent/ca.pem", payload["certificate"], None),
        ("/etc/systemd/system/orbit-agent.service", unit, "# Managed by Orbit: agent"),
    ]:
        result = files.apply(
            {
                "path": path,
                "content": content,
                "mode": "0644",
                "owner": "root:root",
                "marker": marker,
            }
        )
        changed = result["changed"] or changed
    checkout = module("observer_checkout")
    for path in payload["checkouts"]:
        checkout.apply({"checkout": path})
    control("daemon-reload")
    control("enable", "--now", "orbit-agent.service")
    if changed:
        control("restart", "orbit-agent.service")
    control("is-active", "--quiet", "orbit-agent.service")
    return {"ready": True, "changed": changed}


if __name__ == "__main__":
    changed = json.loads(os.environ["ORBIT_ROLE_DELIVERY"])["changed"]
    print(json.dumps(converge(inputs(), account(), sys.stdin.read(), changed)))
