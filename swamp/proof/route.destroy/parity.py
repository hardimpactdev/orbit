"""route.destroy on the target: the Caddyfile no longer serves the domain and no certificate scope of the Route is
left; the Instance's own checkout stays."""

import json
import os
import subprocess
import sys
from pathlib import Path

operation = json.loads(Path(os.environ["PROOF_OPERATION_FILE"]).read_text())
assert operation["kind"] == "route.destroy" and operation["status"] == "succeeded", operation["status"]

domain = "r-" + os.environ["PROOF_RUN"] + ".proof.test"


def target(*argv):
    return subprocess.run(["incus", "exec", os.environ["PROOF_TARGET"], "--", *argv], capture_output=True, text=True, check=False, timeout=30)


def check(condition, message):
    if not condition:
        sys.exit("parity: " + message)


check(f"https://{domain} {{" not in target("cat", "/etc/caddy/Caddyfile").stdout, "the site is withdrawn")
plan = operation["input"]["route_lifecycle"]["plan"]
for part in plan["cleanup"]:
    scope = part["leaf"]["scope"]
    check(target("test", "!", "-e", "/etc/caddy/orbit-certificates/" + scope).returncode == 0, "the captured Caddy certificate scope is removed")
    account = target("getent", "passwd", part["leaf"]["user"])
    check(account.returncode == 0, "the retained account exists")
    home = account.stdout.split(":")[5]
    check(target("test", "!", "-e", home + "/.orbit/certificates/" + scope).returncode == 0, "the captured account certificate copy is removed")
check(target("caddy", "validate", "--config", "/etc/caddy/Caddyfile", "--adapter", "caddyfile").returncode == 0, "the Caddyfile is still valid")
print("parity: the site and certificate for", domain, "are gone")
