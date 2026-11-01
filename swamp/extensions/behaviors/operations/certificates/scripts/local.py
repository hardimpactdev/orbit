"""Node-local paths and OpenSSL checks; no transport or signing policy."""

import base64
import hashlib
import json
import os
import pathlib
import pwd
import re
import stat
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
    if not isinstance(raw.get("user"), str) or not re.fullmatch(
        r"[a-z_][a-z0-9_-]{0,31}", raw["user"]
    ):
        raise ValueError("certificate.account_invalid")
    if not re.fullmatch(
        r"(?:app-instance-[1-9][0-9]*(?:-hostname-change)?|route-[1-9][0-9]*(?:-router(?:-hostname-change)?|-ingress)?)",
        raw["scope"],
    ):
        raise ValueError("certificate.scope_invalid")
    try:
        user = pwd.getpwnam(raw["user"])
    except KeyError:
        raise ValueError("certificate.account_invalid") from None
    if (
        type(user.pw_uid) is not int
        or user.pw_uid < 0
        or type(user.pw_gid) is not int
        or user.pw_gid < 0
        or not isinstance(user.pw_dir, str)
    ):
        raise ValueError("certificate.account_invalid")
    if raw.get("home", user.pw_dir) != user.pw_dir:
        raise ValueError("certificate.home_mismatch")
    if (
        not user.pw_dir.startswith("/")
        or any(part in ("", ".", "..") for part in user.pw_dir[1:].split("/"))
        or any(char in user.pw_dir for char in ("\r", "\n"))
    ):
        raise ValueError("certificate.account_invalid")
    home = pathlib.Path(user.pw_dir)
    try:
        info = home.lstat()
    except OSError:
        raise ValueError("certificate.home_unsafe") from None
    if not stat.S_ISDIR(info.st_mode) or info.st_uid != user.pw_uid:
        raise ValueError("certificate.home_unsafe")
    return user, home / ".orbit/certificates" / raw["scope"], CADDY / raw["scope"]


def fingerprint(path):
    return hashlib.sha256(path.read_bytes()).hexdigest()


def valid_pair(cert, key, ca, leaf):
    try:
        if fingerprint(ca) != leaf["root_hash"]:
            return False
        openssl("verify", "-CAfile", ca, "-verify_hostname", leaf["domain"], cert)
        openssl("x509", "-in", cert, "-noout", "-checkend", "2592000")
        return openssl("pkey", "-in", key, "-pubout") == openssl(
            "x509", "-in", cert, "-noout", "-pubkey"
        )
    except (OSError, KeyError, subprocess.CalledProcessError):
        return False


def valid(user_root, caddy_root, leaf):
    try:
        current = user_root / "current"
        cert, key, ca = (current / name for name in ("cert.pem", "key.pem", "root.pem"))
        return (
            valid_pair(cert, key, ca, leaf)
            and openssl("pkey", "-in", key, "-pubout")
            == openssl("pkey", "-in", caddy_root / "current/key.pem", "-pubout")
            and cert.read_bytes() == (caddy_root / "current/cert.pem").read_bytes()
        )
    except (OSError, KeyError, subprocess.CalledProcessError):
        return False


def request_for_key(key, domain):
    if not key.exists():
        openssl("genrsa", "-out", key, "2048")
    key.chmod(0o600)
    csr = openssl("req", "-new", "-key", key, "-subj", "/CN=" + domain)
    return {"state": "request", "request_base64": base64.b64encode(csr).decode()}


def write_signature(root, leaf, signed, check_expiry=False):
    certificate = base64.b64decode(signed["certificate_base64"], validate=True)
    ca = base64.b64decode(signed["root_certificate_base64"], validate=True)
    if hashlib.sha256(ca).hexdigest() != leaf["root_hash"]:
        raise ValueError("certificate.root_mismatch")
    for name, content in (("cert.pem", certificate), ("root.pem", ca)):
        path = root / name
        path.write_bytes(content)
        path.chmod(0o644)
    cert, key, ca = (root / name for name in ("cert.pem", "key.pem", "root.pem"))
    openssl("verify", "-CAfile", ca, "-verify_hostname", leaf["domain"], cert)
    if check_expiry:
        openssl("x509", "-in", cert, "-noout", "-checkend", "2592000")
    if openssl("pkey", "-in", key, "-pubout") != openssl(
        "x509", "-in", cert, "-noout", "-pubkey"
    ):
        raise ValueError("certificate.key_mismatch")


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
    except Exception as error:  # noqa: BLE001 - keep private exception details out of native output.
        code = str(error)
        if not re.fullmatch(r"certificate\.[a-z_]+", code):
            code = "certificate.action_failed"
        print(json.dumps({"error_code": code}))
        sys.exit(1)
