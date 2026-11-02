"""analytics.disable on the workload Node: the tracking host's site and certificate are gone, the app site stays."""

import json
import os
import subprocess
import sys
from pathlib import Path

run = os.environ["PROOF_RUN"]
tracking = "t-" + run + ".proof.test"
app = "a-" + run + ".proof.test"


def target(*argv):
    return subprocess.run(
        ["incus", "exec", os.environ["PROOF_TARGET"], "--", *argv],
        capture_output=True,
        text=True,
        check=False,
    )


def check(condition, message):
    if not condition:
        sys.exit("parity: " + message)


operation = json.loads(Path(os.environ["PROOF_OPERATION_FILE"]).read_text())
check(
    operation["status"] == "succeeded",
    f"the Operation is {operation['status']} ({operation.get('error_code')})",
)
caddyfile = target("cat", "/etc/caddy/Caddyfile").stdout
check(f"https://{tracking} {{" not in caddyfile, "the tracking host has no Caddy site")
check(f"https://{app} {{" in caddyfile, "the app site still serves")
check(
    tracking not in target("ls", "-R", "/etc/caddy/orbit-certificates").stdout,
    "no certificate folder names the tracking host",
)
check(
    target("caddy", "validate", "--config", "/etc/caddy/Caddyfile").returncode == 0,
    "the Caddyfile still validates",
)
print("parity: the tracking host", tracking, "is withdrawn; the app site serves")
