"""Verify native result and normal Gateway settlement of the prepared group."""

import json
import os
import shlex
import subprocess
from pathlib import Path


def orbit(*args):
    reply = subprocess.run(
        [
            *shlex.split(os.environ["PROOF_EXEC"]),
            "operator",
            "--argv=" + json.dumps(["orbit", *args, "--json"]),
            "--json",
        ],
        check=True,
        capture_output=True,
        text=True,
        timeout=30,
    )
    payload = json.loads(reply.stdout)
    assert payload["exit_code"] == 0
    return json.loads(payload["stdout"])


outputs = json.loads(Path(os.environ["PROOF_OUTPUTS"]).read_text())
result = json.loads(outputs["stdout"])
operation = orbit("operation:show", os.environ["PROOF_OPERATION_ID"])
assert operation["kind"] == "tasks.cancel"
assert operation["target_type"] == "task_group"
assert operation["status"] == "succeeded"
assert result == {
    "group_id": operation["target_id"],
    "instance_id": None,
    "cleanup_complete": True,
    "vm": {},
}
assert operation["result"] == result
group = orbit("tasks:show", str(operation["target_id"]))
assert group["status"] == "cancelled"
assert group["taskable_id"] is None
print(
    json.dumps(
        {
            "empty-workspace-native-assert": True,
            "normal-gateway-cancellation-projection": True,
        }
    )
)
