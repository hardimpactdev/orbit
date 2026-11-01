"""Check admitted role identity and real Node state in a disposable F13 topology."""

import json
import os
import shlex
import subprocess
import sys
from pathlib import Path

kind, role, target_name, *source_names = sys.argv[1:]
prefix = shlex.split(os.environ["PROOF_EXEC"])


def execute(node, argv):
    return subprocess.run(
        [*prefix, node, "--argv=" + json.dumps(argv)],
        check=True,
        capture_output=True,
        text=True,
        timeout=45,
    ).stdout


def operator(argv):
    return json.loads(execute("operator", ["orbit", *argv, "--json"]))


def node_python(node, script, *args):
    return execute(node, ["sudo", "/usr/bin/python3", "-I", "-c", script, *args])


result = json.loads(Path(os.environ["PROOF_OUTPUTS"]).read_text())
assert result["ready"] is True, result
assert result["target_completed"] is True, result
receipt = result["target"]
assert receipt["role"] == role, receipt
assert receipt["created"] is (kind == "node.role_add"), receipt
for field in [
    "role_proven",
    "runtime_proven",
    "certificates_proven",
    "publication_proven",
]:
    assert receipt[field] is True, (field, receipt)
assert receipt["footprint"]["footprint_proven"] is True, receipt
assert not receipt.get("follow_up"), receipt

operation = operator(["operation:show", os.environ["PROOF_OPERATION_ID"]])
assert operation["kind"] == kind, operation
assert operation["status"] == "succeeded", operation
intent = operation["input"]["intent"]
target = intent["target"]
assert (receipt["node_id"], receipt["assignment_id"], receipt["role"]) == (
    target["node_id"],
    target["assignment_id"],
    target["role"],
)
assignments = operator(["node:role:list", str(receipt["node_id"])])["assignments"]
assignment = next(
    item for item in assignments if item["id"] == receipt["assignment_id"]
)
assert assignment["role"] == role and assignment["status"] == "active", assignment
execute(target_name, ["sudo", "/usr/bin/systemctl", "is-active", "orbit-agent.service"])

if role == "database":
    execute(target_name, ["sudo", "/usr/bin/docker", "--version"])
elif role in ["ingress", "app-prod"]:
    execute(target_name, ["sudo", "/usr/bin/systemctl", "is-active", "caddy.service"])
    node_python(
        target_name,
        "from pathlib import Path; p=Path('/etc/caddy/Caddyfile'); "
        "assert p.is_file() and '# Managed by Orbit' in p.read_text()",
    )
    if role == "app-prod":
        execute(target_name, ["php", "--version"])
elif role == "websocket":
    execute(
        target_name,
        ["sudo", "/usr/bin/systemctl", "is-active", "orbit-websocket.service"],
    )
    runtime = target["runtime"]
    node_python(
        target_name,
        "import socket,sys; from pathlib import Path; p=Path(sys.argv[1]); "
        "assert p.is_symlink() and (p/'artisan').is_file(); "
        "s=socket.create_connection(('127.0.0.1',int(sys.argv[2])),timeout=3); s.close()",
        runtime["install_path"],
        str(runtime["port"]),
    )
else:
    raise AssertionError("No parity checks for role " + role)

if kind == "node.role_relocate":
    assert len(source_names) == 1
    assert result["source_completed"] is True, result
    source = intent["source"]
    assert source["assignment_id"] == receipt["assignment_id"], source
    assert source["node_id"] != receipt["node_id"], source
    assignments = operator(["node:role:list", str(source["node_id"])])["assignments"]
    assert all(item["role"] != role for item in assignments), assignments
    node_python(
        source_names[0],
        "import subprocess; from pathlib import Path; "
        "assert not Path('/etc/systemd/system/orbit-websocket.service').exists(); "
        "p=subprocess.run(['/usr/bin/systemctl','is-active','orbit-websocket.service'],"
        "capture_output=True,text=True); assert p.returncode!=0 and p.stdout.strip() in "
        "['inactive','failed','unknown']",
    )
print(json.dumps({"parity": "pass", "kind": kind, "role": role, "target": target_name}))
