"""Read the isolated target after the expected native account refusal."""

import json
import os
import shlex
import subprocess

TARGET = r"""
import json, os, pathlib, pwd
user = pwd.getpwnam(os.environ["SUDO_USER"])
assert user.pw_uid != 0
assert user.pw_dir.endswith("/.")
home = pathlib.Path(user.pw_dir)
for scope in ("route-791327", "route-791328"):
    for root in (home / ".orbit/certificates", pathlib.Path("/etc/caddy/orbit-certificates")):
        assert (root / scope / "proof-marker").read_text() == "public-proof-marker\n"
print(json.dumps({"noncanonical-nss-home-present": True, "certificate-scope-preserved": True, "unrelated-scope-preserved": True}))
"""

prefix = shlex.split(os.environ["PROOF_TARGET_SSH"])
result = subprocess.run(
    [*prefix, shlex.join(["sudo", "-n", "--", "python3", "-I", "-c", TARGET])],
    check=True,
    capture_output=True,
    text=True,
    timeout=30,
)
checks = json.loads(result.stdout)
if checks != {
    "noncanonical-nss-home-present": True,
    "certificate-scope-preserved": True,
    "unrelated-scope-preserved": True,
}:
    raise SystemExit("Certificate removal parity failed")
print(json.dumps(checks))
