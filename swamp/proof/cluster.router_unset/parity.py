"""cluster.router_unset on the old Router: no ufw rule lets the member's LAN address in, and Caddy still validates."""

import json
import os
import subprocess
import sys
from pathlib import Path

MEMBER_LAN = "10.231.0.12"


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
check(operation.get("status") == "succeeded", "the unset task succeeded")
rules = target("ufw", "status").stdout
check(
    MEMBER_LAN not in rules,
    "no ufw rule on the old Router admits the member's LAN address",
)
check(
    target("caddy", "validate", "--config", "/etc/caddy/Caddyfile").returncode == 0,
    "the old Router's Caddyfile still validates",
)
print("parity: the old Router keeps no Router LAN rule, and Caddy validates")
