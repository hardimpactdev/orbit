"""Guarded helper policy for retained local callers; never shipped to native role actions."""

# BEGIN HOST
import grp
import os
import re
import shutil
import stat
import subprocess
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


def role_remove(payload):
    current = certificate_scope(payload["directory"])
    if not certificate_parents() or not exists(current):
        return {}
    previous_pair(current, grp.getgrnam("caddy").gr_gid)
    remove(current)
    return {}


# END PAIR
