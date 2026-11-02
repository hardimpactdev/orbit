"""Public admission/projection parity and actual unit state for both Horizon branches."""

import json
import os
import subprocess
import sys

operation = json.load(open(os.environ["PROOF_OPERATION_FILE"]))
result = operation["result"]
expected = sys.argv[1]
assert result["ready"] is True and result["outcome"] == expected, result
assert result["state"] == "active", result
assert operation["kind"] == "process.horizon_handoff"
unit = operation["input"]["unit"]
subprocess.run(
    ["incus", "exec", "target", "--", "systemctl", "is-active", "--quiet", unit],
    check=True,
)
subprocess.run(
    ["incus", "exec", "target", "--", "systemctl", "is-enabled", "--quiet", unit],
    check=True,
)
marker = subprocess.run(
    ["incus", "exec", "target", "--", "test", "-f", "/opt/proof-horizon/terminated"],
    check=False,
)
assert (marker.returncode == 0) == (expected == "terminated")
print("PASS: native systemd state and Horizon public outcome agree")
