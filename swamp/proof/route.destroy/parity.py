"""Require the exact child refusal, then inspect the workload through PROOF_EXEC."""

import json
import os
import shlex
import subprocess
from pathlib import Path

outputs = json.loads(Path(os.environ["PROOF_OUTPUTS"]).read_text())
assert json.loads(outputs["stdout"]) == {"error_code": "certificate.account_invalid"}
argv = [
    "sudo",
    "-n",
    "--",
    "python3",
    "-I",
    "-c",
    Path(__file__).with_name("target.py").read_text(),
    "verify",
]
result = subprocess.run(
    [
        *shlex.split(os.environ["PROOF_EXEC"]),
        "app-dev",
        "--argv=" + json.dumps(argv),
        "--json",
    ],
    check=True,
    capture_output=True,
    text=True,
    timeout=30,
)
payload = json.loads(result.stdout)
assert payload["exit_code"] == 0
checks = json.loads(payload["stdout"])
assert checks == {
    "noncanonical-nss-home-present": True,
    "certificate-scope-preserved": True,
    "unrelated-scope-preserved": True,
}
print(json.dumps(checks))
