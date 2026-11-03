"""route.update, domain change, on the target: the Caddyfile serves only the new domain, its leaf verifies against
the Orbit root, the temporary leaf is gone, and the new domain answers TLS."""

import json
import os
import re
import subprocess
import sys
from pathlib import Path

run = os.environ["PROOF_RUN"]
old, new = "r-" + run + ".proof.test", "u-" + run + ".proof.test"
ROOT = "/usr/local/share/ca-certificates/orbit-managed-root-ca.crt"
operation = json.loads(Path(os.environ["PROOF_OPERATION_FILE"]).read_text())
assert operation["kind"] == "route.update" and operation["status"] == "succeeded", operation["status"]
assert operation["result"]["ready"] is True, operation["result"]
assert operation["input"]["route_update"]["plan"]["probe_ssh"]["control_node_id"] == 209


def target(*argv):
    return subprocess.run(["incus", "exec", os.environ["PROOF_TARGET"], "--", *argv], capture_output=True, text=True, check=False, timeout=30)


def check(condition, message):
    if not condition:
        sys.exit("parity: " + message)


caddyfile = target("cat", "/etc/caddy/Caddyfile").stdout
roots = re.findall(r"(?m)^\s*root \* (.+)/public\s*$", caddyfile)
check(bool(roots) and "php_fastcgi" in caddyfile, "the Route serves a PHP application")
check(any("laravel/framework" in json.loads(target("cat", root + "/composer.json").stdout).get("require", {}) for root in roots), "the checkout is a Laravel application")
check(f"https://{new} {{" in caddyfile, "the Caddy site serves the new domain")
check(f"https://{old} {{" not in caddyfile, "the old site is withdrawn")
check(target("sh", "-c", "ls -d /etc/caddy/orbit-certificates/*-hostname-change").returncode != 0, "the temporary leaf is removed")
listen = target("ss", "-ltnH", "sport = :443").stdout.split()[3]
handshake = target("openssl", "s_client", "-connect", listen, "-servername", new, "-verify_hostname", new, "-verify_return_error", "-CAfile", ROOT)
check(handshake.returncode == 0, "the new domain answers TLS with a trusted chain")
print("parity: only", new, "serves, over trusted TLS")
