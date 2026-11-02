"""cluster.router_set on the peer: its Caddyfile serves the Cluster Route as the Router and answers TLS for it."""

import os
import subprocess
import sys

domain = "c-" + os.environ["PROOF_RUN"] + ".proof.test"
ROOT = "/usr/local/share/ca-certificates/orbit-managed-root-ca.crt"


def peer(*argv):
    return subprocess.run(["incus", "exec", "peer", "--", *argv], capture_output=True, text=True, check=False)


def check(condition, message):
    if not condition:
        sys.exit("parity: " + message)


check(f"https://{domain} {{" in peer("cat", "/etc/caddy/Caddyfile").stdout, "the new Router's Caddyfile serves the Cluster Route")
listen = peer("ss", "-ltnH", "sport = :443").stdout.split()[3]
handshake = peer("openssl", "s_client", "-connect", listen, "-servername", domain, "-verify_hostname", domain, "-verify_return_error", "-CAfile", ROOT)
check(handshake.returncode == 0, "the new Router answers TLS for the Route with a trusted chain")
print("parity: the peer serves", domain, "as the Router")
