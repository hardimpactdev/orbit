"""The existing off-worker retirement SSH boundary; one fixed command per caller."""

import base64
import hashlib
import os
import re
import subprocess
import tempfile
from pathlib import Path


def admitted_target():
    host = os.environ["ORBIT_TARGET_HOST"]
    user = os.environ["ORBIT_TARGET_USER"]
    port = int(os.environ["ORBIT_TARGET_PORT"])
    node = int(os.environ["ORBIT_TARGET_NODE"])
    if (
        not re.fullmatch(r"[A-Za-z0-9][A-Za-z0-9.-]{0,253}", host)
        or not re.fullmatch(r"[a-z_][a-z0-9_-]{0,31}", user)
        or not 1 <= port <= 65535
        or node <= 0
    ):
        raise SystemExit("node.removal_target_invalid")
    fields = os.environ["ORBIT_TARGET_KEY"].split()
    if len(fields) != 2 or fields[0] not in {
        "ssh-ed25519",
        "ssh-rsa",
        "ecdsa-sha2-nistp256",
        "ecdsa-sha2-nistp384",
        "ecdsa-sha2-nistp521",
    }:
        raise SystemExit("node.ssh_host_key_mismatch")
    fingerprint = "SHA256:" + base64.b64encode(
        hashlib.sha256(base64.b64decode(fields[1], validate=True)).digest()
    ).decode().rstrip("=")
    if fingerprint != os.environ["ORBIT_TARGET_FINGERPRINT"]:
        raise SystemExit("node.ssh_host_key_mismatch")
    if node == int(os.environ["ORBIT_EXECUTOR_NODE"]):
        raise SystemExit("node.removal_executor_invalid")
    return host, user, port, node, fields


def execute(remote, accepted=(0,), program=None):
    host, user, port, _, fields = admitted_target()
    identity = os.environ.pop("ORBIT_SSH_IDENTITY")
    with tempfile.TemporaryDirectory(prefix="orbit-removal-") as directory:
        key = Path(directory) / "identity"
        known = Path(directory) / "known_hosts"
        for path, value in [
            (key, identity),
            (known, "orbit-removal-target " + " ".join(fields) + "\n"),
        ]:
            with open(
                path, "x", opener=lambda path, flags: os.open(path, flags, 0o600)
            ) as stream:
                stream.write(value)
        command = [
            "/usr/bin/ssh",
            "-F",
            "/dev/null",
            "-o",
            "BatchMode=yes",
            "-o",
            "IdentitiesOnly=yes",
            "-o",
            "StrictHostKeyChecking=yes",
            "-o",
            "UserKnownHostsFile=" + str(known),
            "-o",
            "GlobalKnownHostsFile=/dev/null",
            "-o",
            "HostKeyAlias=orbit-removal-target",
            "-o",
            "ConnectTimeout=10",
            "-o",
            "ConnectionAttempts=1",
            "-o",
            "ControlMaster=no",
            "-o",
            "ForwardAgent=no",
            "-o",
            "ClearAllForwardings=yes",
            "-o",
            "LogLevel=ERROR",
            "-i",
            str(key),
            "-p",
            str(port),
            "--",
            user + "@" + host,
            remote,
        ]
        result = subprocess.run(
            command,
            input=program,
            capture_output=True,
            check=False,
            env=dict(os.environ),
        )
    if result.returncode not in accepted:
        raise SystemExit("node.removal_retirement_uncertain")
    return result.stdout
