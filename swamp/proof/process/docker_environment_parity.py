"""Actual Docker argv/env-file semantics and privacy on the topology target."""

import json
import os
import subprocess
from pathlib import Path

operation = json.loads(os.environ["PROOF_OPERATION"])
node = os.environ["PROOF_TARGET"]
name = operation["input"]["name"]
reply = subprocess.run(
    ["incus", "exec", node, "--", "docker", "container", "inspect", name],
    capture_output=True,
    text=True,
    check=True,
)
container = json.loads(reply.stdout)[0]
environment = dict(item.split("=", 1) for item in container["Config"]["Env"])
token = environment["TOKEN"]
assert len(token) == 64 and all(c in "0123456789abcdef" for c in token)
assert environment["PUNCT"] == ' literal $HOME "quoted" = : #'
assert container["Config"]["Cmd"] == ["sleep", "infinity"]
assert container["Config"]["WorkingDir"] == "/tmp"
assert container["Config"]["Labels"]["orbit.process.id"] == str(operation["target_id"])
assert token not in Path(os.environ["PROOF_OUTPUTS"]).read_text()
assert token not in os.environ["PROOF_OPERATION"]
files = subprocess.run(
    [
        "incus",
        "exec",
        node,
        "--",
        "find",
        f"/etc/orbit/processes/{operation['target_id']}",
        "-name",
        "*.env",
    ],
    capture_output=True,
    text=True,
    check=True,
)
assert files.stdout == "", "Docker env file was not cleaned up"
print(
    "PASS: exact Docker definition, private env file removed, native output contains no token"
)
