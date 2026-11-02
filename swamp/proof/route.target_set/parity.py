"""route.target_set, development target, on the target: the published site now serves the new Instance's checkout,
under a leaf the Orbit root verifies, and answers TLS for the domain."""

import os
import subprocess
import sys

run = os.environ["PROOF_RUN"]
domain = "t-" + run + ".proof.test"
ROOT = "/usr/local/share/ca-certificates/orbit-managed-root-ca.crt"


def target(*argv):
    return subprocess.run(["incus", "exec", os.environ["PROOF_TARGET"], "--", *argv], capture_output=True, text=True, check=False)


def check(condition, message):
    if not condition:
        sys.exit("parity: " + message)


caddyfile = target("cat", "/etc/caddy/Caddyfile").stdout
site = caddyfile.split(f"https://{domain} {{", 1)
check(len(site) == 2, "the Caddy site still serves the domain")
block = site[1].split("\n}\n", 1)[0]
check("n-" + run in block, "the site's root is the new Instance's checkout")
check("o-" + run not in block, "the site no longer names the old Instance's checkout")
listen = target("ss", "-ltnH", "sport = :443").stdout.split()[3]
handshake = target("openssl", "s_client", "-connect", listen, "-servername", domain, "-verify_hostname", domain, "-verify_return_error", "-CAfile", ROOT)
check(handshake.returncode == 0, "the site answers TLS for the domain with a trusted chain")
print("parity: the site for", domain, "serves the new checkout over trusted TLS")
