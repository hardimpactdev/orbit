"""Node-local paths and OpenSSL checks; no transport or signing policy."""

import hashlib
import json
import os
import pathlib
import pwd
import re
import subprocess
import sys

CADDY = pathlib.Path("/etc/caddy/orbit-certificates")
ANCHOR = pathlib.Path("/usr/local/share/ca-certificates/orbit-managed-root-ca.crt")


def openssl(*args):
    result = subprocess.run(
        ["openssl", *map(str, args)],
        check=True,
        stdin=subprocess.DEVNULL,
        stdout=subprocess.PIPE,
        stderr=subprocess.DEVNULL,
    )
    return result.stdout


def account(raw):
    if not re.fullmatch(r"[a-z_][a-z0-9_-]{0,31}", raw["user"]):
        raise ValueError("certificate.account_invalid")
    if not re.fullmatch(
        r"(?:app-instance-[1-9][0-9]*(?:-hostname-change)?|route-[1-9][0-9]*(?:-router(?:-hostname-change)?|-ingress)?)",
        raw["scope"],
    ):
        raise ValueError("certificate.scope_invalid")
    user = pwd.getpwnam(raw["user"])
    if raw.get("home", user.pw_dir) != user.pw_dir:
        raise ValueError("certificate.home_mismatch")
    home = pathlib.Path(user.pw_dir)
    if not home.is_absolute():
        raise ValueError("certificate.account_invalid")
    return user, home / ".orbit/certificates" / raw["scope"], CADDY / raw["scope"]


def fingerprint(path):
    return hashlib.sha256(path.read_bytes()).hexdigest()


def valid(user_root, caddy_root, leaf):
    try:
        current = user_root / "current"
        cert, key, ca = (current / name for name in ("cert.pem", "key.pem", "root.pem"))
        if fingerprint(ca) != leaf["root_hash"]:
            return False
        openssl("verify", "-CAfile", ca, "-verify_hostname", leaf["domain"], cert)
        openssl("x509", "-in", cert, "-noout", "-checkend", "2592000")
        return (
            openssl("pkey", "-in", key, "-pubout")
            == openssl("x509", "-in", cert, "-noout", "-pubkey")
            == openssl("pkey", "-in", caddy_root / "current/key.pem", "-pubout")
            and cert.read_bytes() == (caddy_root / "current/cert.pem").read_bytes()
        )
    except (OSError, KeyError, subprocess.CalledProcessError):
        return False


def directory(path, uid, gid, mode):
    path.mkdir(parents=True, exist_ok=True)
    path.chmod(mode)
    os.chown(path, uid, gid)


def current_link(root, version):
    temporary = root / (".current-" + version)
    temporary.unlink(missing_ok=True)
    temporary.symlink_to("versions/" + version)
    temporary.replace(root / "current")


def emit(action):
    try:
        result = action(json.loads(os.environ["ORBIT_CERTIFICATE_INPUT"]))
        print(json.dumps(result))
    except Exception as error:
        code = str(error)
        if not re.fullmatch(r"certificate\.[a-z_]+", code):
            code = "certificate.action_failed"
        print(json.dumps({"error_code": code}))
        sys.exit(1)
