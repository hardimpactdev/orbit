"""route.destroy on the target: the Caddyfile no longer serves the domain and no certificate scope of the Route is
left; the Instance's own checkout stays."""

import os
import subprocess
import sys

domain = "r-" + os.environ["PROOF_RUN"] + ".proof.test"


def target(*argv):
    return subprocess.run(["incus", "exec", os.environ["PROOF_TARGET"], "--", *argv], capture_output=True, text=True, check=False)


def check(condition, message):
    if not condition:
        sys.exit("parity: " + message)


check(f"https://{domain} {{" not in target("cat", "/etc/caddy/Caddyfile").stdout, "the site is withdrawn")
leaves = target("sh", "-c", "grep -l " + domain + " /etc/caddy/orbit-certificates/*/current/cert.pem 2>/dev/null").stdout.split()
check(leaves == [], "no certificate for the domain is left")
check(target("caddy", "validate", "--config", "/etc/caddy/Caddyfile", "--adapter", "caddyfile").returncode == 0, "the Caddyfile is still valid")
print("parity: the site and certificate for", domain, "are gone")
