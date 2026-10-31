"""Canonical fixed role certificate policy; native control flow stays in the workflow."""

# BEGIN HOST
import base64
import ctypes
import grp
import hashlib
import json
import os
import re
import secrets
import shutil
import stat
import subprocess
import sys
from pathlib import Path

ROOT = Path("/etc/caddy")


class Refusal(Exception):
    def __init__(self, stage):
        self.stage = stage


def command(program, *args):
    try:
        return subprocess.run(
            [program, *map(str, args)],
            stdin=subprocess.DEVNULL,
            stdout=subprocess.PIPE,
            stderr=subprocess.DEVNULL,
            timeout=35,
            check=False,
        )
    except (OSError, subprocess.TimeoutExpired):
        raise Refusal("command")


def service(*args):
    return command("/usr/bin/systemctl", *args, "caddy").returncode == 0


def exists(path):
    return path.exists() or path.is_symlink()


def remove(path):
    if path.is_symlink() or path.is_file():
        path.unlink()
    elif path.is_dir():
        shutil.rmtree(path)
    elif exists(path):
        raise Refusal("filesystem")


# END HOST
# BEGIN PAIR

OWNER_UID = 0


class CertificateRefusal(Exception):
    pass


def certificate_scope(raw):
    if not re.fullmatch(
        r"/etc/caddy/orbit-(?:[a-z][a-z0-9-]{0,63}-)?cert-current", raw
    ):
        raise CertificateRefusal("caddy.certificate_input_invalid")
    return ROOT / Path(raw).name


def certificate_names(cert, key):
    return (
        re.fullmatch(r"[a-z][a-z0-9_-]{0,63}\.pem", cert) is not None
        and key == cert[:-4] + ".key"
    )


def certificate_parents():
    for path in [ROOT.parent, ROOT]:
        if not path.exists():
            return False
        meta = path.lstat()
        if (
            not stat.S_ISDIR(meta.st_mode)
            or meta.st_uid != OWNER_UID
            or meta.st_mode & 0o022
        ):
            raise CertificateRefusal("caddy.certificate_unsafe")
    return True


def previous_pair(path, gid):
    if not exists(path):
        return None
    meta = path.lstat()
    if stat.S_ISLNK(meta.st_mode):
        if meta.st_uid != OWNER_UID:
            raise CertificateRefusal("caddy.certificate_unsafe")
        versions = path.name.removesuffix("-current") + "-versions"
        target = os.readlink(path)
        match = re.fullmatch(
            "/etc/caddy/" + re.escape(versions) + r"/([a-f0-9]{1,64})", target
        )
        if match is None:
            raise CertificateRefusal("caddy.certificate_unsafe")
        parent = ROOT / versions
        meta = parent.lstat()
        if (
            not stat.S_ISDIR(meta.st_mode)
            or meta.st_uid != OWNER_UID
            or meta.st_gid != gid
            or stat.S_IMODE(meta.st_mode) != 0o750
        ):
            raise CertificateRefusal("caddy.certificate_unsafe")
        path = parent / match[1]
        meta = path.lstat()
    if (
        not stat.S_ISDIR(meta.st_mode)
        or meta.st_uid != OWNER_UID
        or meta.st_gid != gid
        or stat.S_IMODE(meta.st_mode) != 0o750
    ):
        raise CertificateRefusal("caddy.certificate_unsafe")
    names = sorted(p.name for p in path.iterdir())
    pair = [name for name in names if name != "root-ca.pem"]
    if (
        len(pair) != 2
        or len(names) not in (2, 3)
        or not certificate_names(pair[1], pair[0])
    ):
        raise CertificateRefusal("caddy.certificate_unsafe")
    result = {}
    for name in names:
        meta = (path / name).lstat()
        if (
            not stat.S_ISREG(meta.st_mode)
            or meta.st_uid != OWNER_UID
            or meta.st_gid != gid
            or stat.S_IMODE(meta.st_mode) != 0o640
            or meta.st_size > 65536
        ):
            raise CertificateRefusal("caddy.certificate_unsafe")
        fd = os.open(path / name, os.O_RDONLY | os.O_NOFOLLOW)
        with os.fdopen(fd, "rb") as file:
            result[name] = file.read(65537)
        if len(result[name]) > 65536:
            raise CertificateRefusal("caddy.certificate_unsafe")
    return result


def certificate_write(path, value, gid):
    fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o640)
    with os.fdopen(fd, "w") as file:
        os.fchown(file.fileno(), OWNER_UID, gid)
        os.fchmod(file.fileno(), 0o640)
        file.write(value)


def exchange_pair(a, b):
    libc = ctypes.CDLL(None, use_errno=True)
    if libc.renameat2(-100, os.fsencode(a), -100, os.fsencode(b), 2) != 0:
        raise OSError(ctypes.get_errno(), "certificate exchange failed")


def certificate_active():
    code = command("/usr/bin/systemctl", "is-active", "--quiet", "caddy").returncode
    if code not in (0, 3, 4):
        raise CertificateRefusal("caddy.certificate_failed")
    return code == 0


def certificate_reload():
    return service("reload-or-restart")


def certificate_valid(cert, key):
    public = command("/usr/bin/openssl", "x509", "-in", cert, "-noout", "-pubkey")
    private = command("/usr/bin/openssl", "pkey", "-in", key, "-pubout")
    return (
        public.returncode == private.returncode == 0 and public.stdout == private.stdout
    )


def certificate_values(payload):
    if (
        payload["operation"] != "install"
        or payload["service"] != "caddy"
        or not certificate_names(payload["certificate_file"], payload["key_file"])
    ):
        raise CertificateRefusal("caddy.certificate_input_invalid")
    values = {
        payload["certificate_file"]: payload["certificate"],
        payload["key_file"]: payload["key"],
    }
    if "root_certificate" in payload:
        values["root-ca.pem"] = payload["root_certificate"]
    if any(
        not isinstance(v, str) or not v or len(v) > 65536 or "\x00" in v
        for v in values.values()
    ):
        raise CertificateRefusal("caddy.certificate_input_invalid")
    return values


def certificate_candidate(current, payload, gid):
    values = certificate_values(payload)
    candidate = current.with_name(current.name + ".candidate." + secrets.token_hex(8))
    candidate.mkdir(mode=0o750)
    os.chown(candidate, OWNER_UID, gid)
    candidate.chmod(0o750)
    try:
        for name, value in values.items():
            certificate_write(candidate / name, value, gid)
        if not certificate_valid(
            candidate / payload["certificate_file"], candidate / payload["key_file"]
        ):
            raise CertificateRefusal("caddy.certificate_invalid")
        return candidate
    except BaseException:
        remove(candidate)
        raise


# END PAIR
# BEGIN ACTIONS
"""Fixed role CSR and public certificate publication; private keys stay on this Node."""

KEY_ROOT = Path("/var/lib/orbit/role-certificates")


def role_paths(data):
    role = data["scope"].removeprefix("role-")
    if role not in ("gateway", "metrics", "websocket", "analytics"):
        raise CertificateRefusal("certificate.role_input_invalid")
    if not re.fullmatch("[a-f0-9]{16}", data["version"]) or not re.fullmatch(
        "[a-f0-9]{64}", data["root_hash"]
    ):
        raise CertificateRefusal("certificate.role_input_invalid")
    if len(data["domain"]) > 253 or not re.fullmatch(
        r"(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]*[a-z0-9])?",
        data["domain"],
    ):
        raise CertificateRefusal("certificate.role_input_invalid")
    directory = (
        "/etc/caddy/orbit-" + ("" if role == "gateway" else role + "-") + "cert-current"
    )
    return role, certificate_scope(directory), directory


def role_checked(*args):
    result = command("/usr/bin/openssl", *args)
    if result.returncode:
        raise CertificateRefusal("certificate.role_failed")
    return result.stdout.decode()


def role_valid(current, data):
    try:
        cert = current / (data["scope"].removeprefix("role-") + ".pem")
        key = cert.with_suffix(".key")
        root = current / "root-ca.pem"
        if hashlib.sha256(root.read_bytes()).hexdigest() != data["root_hash"]:
            return False
        role_checked("verify", "-CAfile", root, cert)
        role_checked("x509", "-in", cert, "-noout", "-checkend", "2592000")
        if "does NOT match" in role_checked(
            "x509", "-in", cert, "-noout", "-checkhost", data["domain"]
        ):
            return False
        return certificate_valid(cert, key)
    except (CertificateRefusal, OSError):
        return False


def role_workspace(data):
    paths = [
        KEY_ROOT.parent.parent,
        KEY_ROOT.parent,
        KEY_ROOT,
        KEY_ROOT / data["scope"],
        KEY_ROOT / data["scope"] / data["version"],
    ]
    for path in paths:
        if not exists(path):
            path.mkdir(mode=0o700)
        meta = path.lstat()
        if (
            not stat.S_ISDIR(meta.st_mode)
            or meta.st_uid != OWNER_UID
            or meta.st_mode & 0o022
        ):
            raise CertificateRefusal("certificate.role_unsafe")
        if path == KEY_ROOT or path.is_relative_to(KEY_ROOT):
            if stat.S_IMODE(meta.st_mode) != 0o700:
                raise CertificateRefusal("certificate.role_unsafe")
    return paths[-1]


def role_key(path):
    meta = path.lstat()
    if (
        not stat.S_ISREG(meta.st_mode)
        or meta.st_uid != OWNER_UID
        or meta.st_nlink != 1
        or stat.S_IMODE(meta.st_mode) != 0o600
        or meta.st_size > 8192
    ):
        raise CertificateRefusal("certificate.role_unsafe")
    if "2048 bit" not in role_checked("pkey", "-in", path, "-text_pub", "-noout"):
        raise CertificateRefusal("certificate.role_unsafe")


def role_request(data, allow_current=True):
    role, current, _ = role_paths(data)
    if not certificate_parents():
        raise CertificateRefusal("caddy.certificate_unsafe")
    gid = grp.getgrnam("caddy").gr_gid
    previous = previous_pair(current, gid)
    if allow_current and previous is not None and role_valid(current, data):
        return {"state": "current"}
    workspace = role_workspace(data)
    key = workspace / "key.pem"
    if not exists(key):
        fd = os.open(key, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
        os.close(fd)
        role_checked("genrsa", "-out", key, "2048")
    role_key(key)
    request = role_checked("req", "-new", "-key", key, "-subj", "/CN=" + data["domain"])
    return {
        "state": "request",
        "request_base64": base64.b64encode(request.encode()).decode(),
    }


def role_publish(data, finalize=True):
    role, current, directory = role_paths(data)
    if not certificate_parents():
        raise CertificateRefusal("caddy.certificate_unsafe")
    previous = previous_pair(current, grp.getgrnam("caddy").gr_gid)
    if data["request"]["state"] == "current":
        if previous is None or not role_valid(current, data):
            raise CertificateRefusal("certificate.role_not_current")
        return {"applied": True}
    certificate = base64.b64decode(
        data["signed"]["certificate_base64"], validate=True
    ).decode()
    root = base64.b64decode(
        data["signed"]["root_certificate_base64"], validate=True
    ).decode()
    if hashlib.sha256(root.encode()).hexdigest() != data["root_hash"]:
        raise CertificateRefusal("certificate.role_root_mismatch")
    if (
        previous is not None
        and previous.get(role + ".pem") == certificate.encode()
        and previous.get("root-ca.pem") == root.encode()
        and role_valid(current, data)
    ):
        return {"applied": True}
    workspace = role_workspace(data)
    key = workspace / "key.pem"
    role_key(key)
    for name, value in [("certificate.pem", certificate), ("root.pem", root)]:
        path = workspace / name
        if exists(path):
            meta = path.lstat()
            if (
                not stat.S_ISREG(meta.st_mode)
                or meta.st_uid != OWNER_UID
                or meta.st_nlink != 1
            ):
                raise CertificateRefusal("certificate.role_unsafe")
            path.unlink()
        certificate_write(path, value, os.getgid())
        path.chmod(0o600)
    cert = workspace / "certificate.pem"
    ca = workspace / "root.pem"
    role_checked("verify", "-CAfile", ca, cert)
    role_checked("x509", "-in", cert, "-noout", "-checkend", "2592000")
    if "does NOT match" in role_checked(
        "x509", "-in", cert, "-noout", "-checkhost", data["domain"]
    ) or not certificate_valid(cert, key):
        raise CertificateRefusal("certificate.role_signature_mismatch")
    # The key is read only inside this fixed Node-local transaction.
    role_certificate(
        dict(
            operation="install",
            directory=directory,
            certificate_file=role + ".pem",
            key_file=role + ".key",
            certificate=certificate,
            key=key.read_text(),
            root_certificate=root,
            service="caddy",
        )
    )
    if finalize:
        shutil.rmtree(workspace)
    return {"applied": True}


# END ACTIONS
ROLE_ACTION_INPUT = None


def role_previous(data):
    _, current, _ = role_paths(data)
    return current.with_name(current.name + ".previous")


def role_certificate(payload):
    current = certificate_scope(payload["directory"])
    gid = grp.getgrnam("caddy").gr_gid
    previous = previous_pair(current, gid)
    values = certificate_values(payload)
    if previous is not None and set(previous) - {"root-ca.pem"} != set(values) - {
        "root-ca.pem"
    }:
        raise CertificateRefusal("caddy.certificate_unsafe")
    saved = role_previous(ROLE_ACTION_INPUT)
    pending = exists(saved)
    candidate = certificate_candidate(current, payload, gid)
    try:
        if pending:
            if exists(current):
                exchange_pair(candidate, current)
            else:
                candidate.rename(current)
        elif previous is None:
            # An empty directory captures prior absence, without publishing private material.
            saved.mkdir(mode=0o700)
            candidate.rename(current)
        else:
            exchange_pair(candidate, current)
            try:
                candidate.rename(saved)
            except OSError:
                exchange_pair(candidate, current)
                raise
    finally:
        if exists(candidate):
            remove(candidate)
    return {"changed": True}


def role_restore(data):
    _, current, _ = role_paths(data)
    saved = role_previous(data)
    if exists(saved):
        if any(saved.iterdir()):
            exchange_pair(saved, current)
            remove(saved)
        else:
            remove(current)
            saved.rmdir()
    return {"restored": True}


def role_finish(data):
    _, current, _ = role_paths(data)
    if not role_valid(current, data):
        raise CertificateRefusal("certificate.role_not_current")
    saved = role_previous(data)
    if exists(saved):
        remove(saved)
    workspace = KEY_ROOT / data["scope"] / data["version"]
    if exists(workspace):
        shutil.rmtree(workspace)
    return {"applied": True}


def native_main():
    try:
        data = ROLE_ACTION_INPUT
        action = data["operation"]
        if action == "request":
            result = role_request(data, allow_current=not exists(role_previous(data)))
        elif action == "publish":
            result = role_publish(data, finalize=False)
        elif action == "restore":
            result = role_restore(data)
        elif action == "finish":
            result = role_finish(data)
        else:
            raise CertificateRefusal("certificate.role_input_invalid")
    except CertificateRefusal as error:
        result = {"error_code": str(error)}
    except Refusal:
        result = {"error_code": "certificate.role_failed"}
    except Exception:
        result = {"error_code": "certificate.role_failed"}
    print(json.dumps(result))
    if "error_code" in result:
        sys.exit(1)


if __name__ == "__main__":
    native_main()
