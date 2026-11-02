"""Actual Docker argv/env-file semantics and privacy on the topology target."""

import json
import os
import subprocess
from pathlib import Path

operation = json.load(open(os.environ["PROOF_OPERATION_FILE"]))
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
assert token not in Path(os.environ["PROOF_OPERATION_FILE"]).read_text()
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
paths = files.stdout.splitlines()
assert len(paths) == 1, "Expected one managed Docker environment file"
metadata = subprocess.run(
    ["incus", "exec", node, "--", "stat", "-c", "%a:%U:%G", paths[0]],
    capture_output=True, text=True, check=True,
)
assert metadata.stdout.strip() == "600:root:root", "Docker environment must remain root-owned 0600"
content = subprocess.run(
    ["incus", "exec", node, "--", "cat", paths[0]], capture_output=True, text=True, check=True,
)
assert "TOKEN=" + token in content.stdout.splitlines(), "Managed environment differs from the container"
print(
    "PASS: exact Docker definition, managed env file is root-owned 0600, native output contains no token"
)
