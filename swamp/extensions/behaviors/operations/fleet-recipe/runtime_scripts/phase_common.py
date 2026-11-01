"""Shared primitives for the canonical Reverb phases; Swamp owns their order."""

import json
import os
import subprocess
from pathlib import Path

import role_file
import websocket_cleanup
import websocket_link
import websocket_policy

UNIT = Path("/etc/systemd/system/orbit-websocket.service")


def context():
    data = json.loads(os.environ["ORBIT_ROLE_INPUT"])
    account = json.loads(os.environ.get("ORBIT_ROLE_ACCOUNT", "{}"))
    return data, account


def run(argv, check=True):
    return subprocess.run(
        argv,
        stdin=subprocess.DEVNULL,
        stdout=subprocess.DEVNULL,
        stderr=subprocess.DEVNULL,
        check=check,
    )


def control(verb, check=True):
    return run(["/usr/bin/systemctl", verb, "orbit-websocket"], check)


def managed(account, argv):
    return run(
        [
            "/usr/bin/sudo",
            "-u",
            account["user"],
            "-H",
            "/usr/bin/env",
            "HOME=" + account["home"],
            *map(str, argv),
        ]
    )


def unit_content(data, account):
    return (
        data["unit_template"]
        .replace("__ROLE_USER__", account["user"])
        .replace("__ROLE_GROUP__", account["group"])
    )


def publish(path, content, owner, mode, marker=None):
    return role_file.apply(
        {
            "path": str(path),
            "content": content,
            "owner": owner,
            "mode": mode,
            "marker": marker,
        }
    )


def ready():
    print(json.dumps({"ready": True}))


def cleanup(data, account, retain):
    websocket_cleanup.own_paths = websocket_policy.own_paths
    return websocket_cleanup.cleanup(
        {
            "install_path": data["runtime"]["install_path"],
            "release": account["release"],
            "previous": account["previous"],
            "retain_candidate": retain,
        }
    )


def link(data, account, restore=False):
    return websocket_link.publish(
        {
            "install_path": data["runtime"]["install_path"],
            "release": account["release"],
            "previous": account["previous"],
            "restore": restore,
        }
    )
