"""F13 parity through the topology's real CLI and managed Reverb listener."""

import json
import os
import shlex
import subprocess
import sys
import uuid
from pathlib import Path


def guest(node, argv):
    completed = subprocess.run(
        [
            *shlex.split(os.environ["PROOF_EXEC"]),
            node,
            "--argv=" + json.dumps(argv),
            "--timeout=15",
            "--json",
        ],
        capture_output=True,
        text=True,
        timeout=20,
        check=False,
    )
    if completed.returncode != 0:
        raise AssertionError("Topology guest observation failed")
    result = json.loads(completed.stdout)
    assert result["state"] == "executed" and result["exit_code"] == 0
    return result["stdout"]


def cli(*arguments):
    return json.loads(guest("operator", ["orbit", *arguments, "--json"]))


def main():
    (
        kind,
        subject,
        unrelated,
        unrelated_key,
        before,
        operation_id,
        node_id,
        address,
        tag,
    ) = sys.argv[1:]
    # These are public app ids/keys. App and registry secrets stay on their normal path.
    other = cli("websocket:app:show", unrelated)
    assert other["key"] == unrelated_key
    apps = cli("websocket:app:list")
    rows = apps["apps"]
    if kind == "websocket.app_destroy":
        assert not any(app["name"] == subject for app in rows)
        subject_key, present = before, False
    else:
        selected = cli("websocket:app:show", subject)
        subject_key, present = selected["key"], True
        if kind == "websocket.app_rotate":
            assert subject_key == before
    operation = cli("operation:show", operation_id)
    assert operation["kind"] == kind and operation["status"] == "succeeded"
    assert operation["result"] == {
        "outcome": "succeeded",
        "data": {"backend": "applied"},
    }
    admitted = operation["input"]
    registry = admitted["native_registry"]
    assert (
        registry["start_tag"]
        == registry["registry_operation_id"]
        == admitted["registry_operation_id"]
    )
    uuid.UUID(registry["start_tag"])
    assert registry["epoch"] == admitted["epoch"]
    assert registry["revision"] == admitted["revision"]
    assert registry["missing_node_ids"] == []
    assert len(registry["runtimes"]) == 1
    runtime = registry["runtimes"][0]
    uuid.UUID(runtime["process_id"])
    assert runtime["node_id"] == int(node_id) and runtime["port"] == 8790
    ssh = runtime["ssh"]
    assert ssh["control_node_id"] == 209
    assert ssh["target"]["host"] == address
    assert ssh["target"]["host_keys"] and ssh["identity_file"]
    field = "runtime_" + runtime["process_id"].replace("-", "_")
    assert runtime["credential_field"] == field
    assert admitted["execution_secrets"] == {
        field: {
            "vault": "orbit-control-inputs",
            "key": "websocket-registry-" + runtime["process_id"],
        }
    }
    if present:
        assert registry["app_id"] == (
            None if kind == "websocket.sync" else selected["app_id"]
        )
    # Node-side output is only two booleans. No application or registry credential is read.
    probe = r"""
import json
import socket
import sys
request = json.loads(sys.argv[1])
def handshake(key):
    with socket.create_connection(('127.0.0.1', 8790), timeout=3) as connection:
        connection.sendall(('GET /app/' + key + '?protocol=7&client=js&version=8.4.0 HTTP/1.1\r\nHost: localhost\r\nConnection: Upgrade\r\nUpgrade: websocket\r\nSec-WebSocket-Version: 13\r\nSec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==\r\n\r\n').encode())
        return b'101 Switching Protocols' in connection.recv(4096)
assert handshake(request['unrelated_key'])
assert handshake(request['subject_key']) == request['present']
print('managed-runtime-handshake: PASS')
"""
    public = json.dumps(
        {"subject_key": subject_key, "unrelated_key": unrelated_key, "present": present}
    )
    guest("app-prod", ["python3", "-c", probe, public])
    if kind == "websocket.app_rotate":
        subprocess.run(
            [
                "python3",
                "-I",
                str(
                    Path(__file__).parents[2]
                    / "websocket.app_rotate/tests/socket_observer.py"
                ),
                "check",
                tag,
            ],
            check=True,
            timeout=20,
        )
    print("F13 public app state and managed-runtime parity: PASS")


if __name__ == "__main__":
    main()
