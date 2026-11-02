"""Inject the account fault through the shared workload-only topology seam."""

import json
import os
import shlex
import subprocess
from pathlib import Path

argv = [
    "sudo",
    "-n",
    "--",
    "python3",
    "-I",
    "-c",
    Path(__file__).with_name("target.py").read_text(),
    "inject",
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
assert json.loads(payload["stdout"]) == {"fault_injected": True}
