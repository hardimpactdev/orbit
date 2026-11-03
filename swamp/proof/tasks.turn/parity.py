"""Real SSH/Git and Gateway result parity; Pi is a synthetic HTTP fixture."""

import json
import os
import subprocess
from pathlib import Path

operation = json.load(open(os.environ["PROOF_OPERATION_FILE"]))
result = operation["result"]
outputs = Path(os.environ["PROOF_OUTPUTS"]).read_text()
credential = "a1" * 32


def target(*argv):
    return subprocess.check_output(
        ["incus", "exec", os.environ["PROOF_TARGET"], "--", *argv], text=True
    ).strip()


assert operation["status"] == "succeeded", operation["status"]
assert result["state"] == "done", result
assert result["receipt"]["outcome"] == "approved", result
assert credential not in outputs, "private agent text entered native history"
assert "[REDACTED]" in result["receipt"]["summary"]
assert "[REDACTED]" in result["difference"]["diff"]
assert result["difference"]["summary"]["files"] == 2
assert result["after_reset"]["head"] == result["before"]["head"]
assert target("cat", "/home/orbit/turn-proof/file") == "original"
assert target("sh", "-c", "test ! -e /home/orbit/turn-proof/untracked; echo $?") == "0"
calls = json.loads(target("cat", "/home/orbit/turn-proof/.git/orbit/pi-calls.json"))
assert sum(path.endswith("/messages") for path in calls) == 1
run_id = operation["swamp_run_id"]
assert target("sh", "-c", f"test ! -e /tmp/orbit-task-turn-{run_id}/before.index; echo $?") == "0"
assert target("sh", "-c", f"test ! -e /tmp/orbit-task-turn-{run_id}/after.index; echo $?") == "0"
assert not Path(f"/tmp/orbit-task-turn-local-{run_id}").exists(), "private worker artifacts remain"
print(
    "Real SSH Git snapshots include untracked files; reviewer cleanup restores candidate; private text stays out of history; one synthetic Pi send."
)
