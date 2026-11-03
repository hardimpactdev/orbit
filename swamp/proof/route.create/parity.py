"""route.create on the target: the Caddy site, a leaf that the Orbit root verifies, the account copy, no private key
in native history, and a TLS handshake for the domain."""

import json
import os
import re
import subprocess
import sys
from pathlib import Path

domain = "r-" + os.environ["PROOF_RUN"] + ".proof.test"
outputs = Path(os.environ["PROOF_OUTPUTS"]).read_text()
ROOT = "/usr/local/share/ca-certificates/orbit-managed-root-ca.crt"
operation = json.loads(Path(os.environ["PROOF_OPERATION_FILE"]).read_text())
assert operation["kind"] == "route.create" and operation["status"] == "succeeded", operation["status"]
assert operation["result"] == {"ready": True, "route_id": operation["target_id"]}, operation["result"]
assert operation["input"]["route_create"]["probe_ssh"]["control_node_id"] == 209


def target(*argv, stdin=""):
    return subprocess.run(
        ["incus", "exec", os.environ["PROOF_TARGET"], "--", *argv],
        input=stdin,
        capture_output=True,
        text=True,
        check=False,
        timeout=30,
    )


def check(condition, message):
    if not condition:
        sys.exit("parity: " + message)


check("PRIVATE KEY-----" not in outputs, "a private key reached native history")
caddyfile = target("cat", "/etc/caddy/Caddyfile").stdout
check(
    f"https://{domain} {{" in caddyfile,
    "the Caddy site serves the domain",
)
roots = re.findall(r"(?m)^\s*root \* (.+)/public\s*$", caddyfile)
check(bool(roots) and "php_fastcgi" in caddyfile, "the Route serves a PHP application")
check(
    any("laravel/framework" in json.loads(target("cat", root + "/composer.json").stdout).get("require", {}) for root in roots),
    "the Route's checkout is a Laravel application",
)
leaves = target(
    "sh", "-c", "ls -d /etc/caddy/orbit-certificates/*/current/cert.pem"
).stdout.split()
check(
    any(
        target(
            "openssl", "verify", "-CAfile", ROOT, "-verify_hostname", domain, leaf
        ).returncode
        == 0
        for leaf in leaves
    ),
    "a Caddy leaf verifies against the Orbit root for the domain",
)
copies = target(
    "sh", "-c", "ls -d /home/*/.orbit/certificates/*/current/cert.pem"
).stdout.split()
check(
    any(
        target(
            "openssl", "verify", "-CAfile", ROOT, "-verify_hostname", domain, copy
        ).returncode
        == 0
        for copy in copies
    ),
    "the account copy holds the same leaf",
)
# Caddy binds the Node's private address, not loopback.
listen = target("ss", "-ltnH", "sport = :443").stdout.split()[3]
handshake = target(
    "openssl",
    "s_client",
    "-connect",
    listen,
    "-servername",
    domain,
    "-verify_hostname",
    domain,
    "-verify_return_error",
    "-CAfile",
    ROOT,
)
check(
    handshake.returncode == 0,
    "the site answers TLS for the domain with a trusted chain",
)
print("parity: site, leaf, account copy and TLS handshake for", domain)
