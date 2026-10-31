"""One server-create action, with fresh ownership and budget preconditions on resume."""

import json
import os

from upcloud_common import Refused, identifier, intent, inventory, owned, request, run


def action():
    value = intent()
    observed = inventory(value)
    if observed["existing_id"]:
        server = owned(value, observed["existing_id"])
        return {"provider_id": server["uuid"]}
    cloud = value["upcloud"]
    if value["provider_id"] is not None or observed["count"] >= cloud["max_vms"]:
        raise Refused()
    plan = json.loads(os.environ["ORBIT_UPCLOUD_PLAN"])
    if set(plan) != {"tier"}:
        raise Refused()
    pair = json.loads(os.environ.pop("ORBIT_PROVIDER_BOOTSTRAP"))
    if (
        set(pair) != {"private", "public"}
        or not isinstance(pair["private"], str)
        or len(pair["private"]) > 16384
        or not pair["private"].startswith("-----BEGIN OPENSSH PRIVATE KEY-----\n")
        or pair["public"] != value["provider_host_public_key"]
    ):
        raise Refused()
    user_data = (
        "#cloud-config\n"
        + json.dumps(
            {
                "users": [
                    {
                        "name": "orbit",
                        "lock_passwd": True,
                        "shell": "/bin/bash",
                        "sudo": "ALL=(ALL) NOPASSWD:ALL",
                        "ssh_authorized_keys": [cloud["gateway_public_key"]],
                    }
                ],
                "disable_root": True,
                "ssh_deletekeys": True,
                "ssh_genkeytypes": [],
                "ssh_keys": {
                    "ed25519_private": pair["private"],
                    "ed25519_public": pair["public"],
                },
            }
        )
        + "\n"
    )
    disk = {
        "action": "clone",
        "storage": cloud["source_template"],
        "title": value["name"] + "-disk",
        "size": cloud["disk_gb"],
    }
    if plan["tier"] is not None:
        disk["tier"] = plan["tier"]
    try:
        response = request(
            "POST",
            "server",
            {
                "server": {
                    "zone": cloud["zone"],
                    "title": value["name"],
                    "hostname": value["name"],
                    "plan": cloud["plan"],
                    "metadata": "yes",
                    "firewall": "off",
                    "remote_access_enabled": "no",
                    "labels": {
                        "label": [
                            {"key": "orbit-task-vm", "value": str(value["task_vm_id"])}
                        ]
                    },
                    "networking": {
                        "interfaces": {
                            "interface": [
                                {
                                    "type": "public",
                                    "ip_addresses": {
                                        "ip_address": [{"family": "IPv4"}]
                                    },
                                }
                            ]
                        }
                    },
                    "storage_devices": {"storage_device": [disk]},
                    "login_user": {
                        "username": "root",
                        "ssh_keys": {"ssh_key": [cloud["gateway_public_key"]]},
                    },
                    "user_data": user_data,
                }
            },
        )
        return {"provider_id": identifier(response["server"]["uuid"])}
    except (Refused, KeyError, TypeError, ValueError):
        return {"request_uncertain": True}


if __name__ == "__main__":
    run(action)
