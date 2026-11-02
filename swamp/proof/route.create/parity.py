"""route.create on the target: the Caddy site, a leaf that the Orbit root verifies, the account copy, no private key
in native history, and a TLS handshake for the domain."""

import os
import subprocess
import sys
from pathlib import Path

domain = "r-" + os.environ["PROOF_RUN"] + ".proof.test"
outputs = Path(os.environ["PROOF_OUTPUTS"]).read_text()
ROOT = "/usr/local/share/ca-certificates/orbit-managed-root-ca.crt"


def target(*argv, stdin=""):
    return subprocess.run(
        ["incus", "exec", os.environ["PROOF_TARGET"], "--", *argv],
        input=stdin,
        capture_output=True,
        text=True,
        check=False,
    )


def check(condition, message):
    if not condition:
        sys.exit("parity: " + message)


check("PRIVATE KEY-----" not in outputs, "a private key reached native history")
check(
    f"https://{domain} {{" in target("cat", "/etc/caddy/Caddyfile").stdout,
    "the Caddy site serves the domain",
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
