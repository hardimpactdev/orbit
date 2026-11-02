"""Check, write or verify one Instance's .env and .env.testing as the Instance account.

argv: mode (check, write or verify) and the public plan as JSON. The write mode reads the
rendered files from stdin as JSON; no value is ever printed. Prints one public JSON object and
exits 1 with an error_code on a refusal.
"""

import hashlib
import json
import os
import pwd
import re
import secrets
import stat
import subprocess
import sys

KEY = re.compile(rb"[ \t]*(?:export[ \t]+)?([A-Za-z_][A-Za-z0-9_.]*)[ \t]*=")


class Refused(Exception):
    def __init__(self, code, **public):
        super().__init__(code)
        self.public = {"error_code": code, **public}


def check(ok, code):
    if not ok:
        raise Refused(code)


def identity(info):
    return (info.st_dev, info.st_ino)


def open_base(base):
    check(base.startswith("/") and base != "/" and all(p not in ("", ".", "..") for p in base[1:].split("/")), "env.sync_preflight_failed")
    fd = os.open("/", os.O_RDONLY | os.O_DIRECTORY | os.O_CLOEXEC)
    try:
        for segment in base[1:].split("/"):
            following = os.open(segment, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW | os.O_CLOEXEC, dir_fd=fd)
            os.close(fd)
            fd = following
        return fd
    except OSError:
        os.close(fd)
        raise Refused("env.sync_preflight_failed") from None


def keys(content):
    return [match[1].decode("ascii") for match in (KEY.match(line) for line in content.splitlines()) if match]


def managed_lines(content, managed):
    lines = [line if line.endswith(b"\n") else line + b"\n" for line in content.splitlines(keepends=True)]
    return b"".join(sorted(line for line in lines if (m := KEY.match(line)) and m[1].decode("ascii") in managed))


class Directory:
    """The admitted directory, opened without following links; every file is read and replaced inside it."""

    def __init__(self, plan):
        self.plan, self.maximum = plan, plan["maximum"]
        check(re.fullmatch("[a-z_][a-z0-9_-]{0,31}", plan["user"]) is not None and plan["user"] != "root", "env.sync_preflight_failed")
        try:
            self.account = pwd.getpwnam(plan["user"])
        except KeyError:
            raise Refused("env.sync_preflight_failed") from None
        check(self.account.pw_uid != 0 and os.geteuid() == self.account.pw_uid, "env.sync_preflight_failed")
        check(not plan["require_home"] or self.account.pw_dir == plan["base"], "env.sync_preflight_failed")
        self.fd = open_base(plan["base"])
        self.info = os.fstat(self.fd)

    def close(self):
        os.close(self.fd)

    def writable(self):
        check(self.info.st_uid == self.account.pw_uid and self.info.st_mode & 0o300 == 0o300, "env.sync_preflight_failed")
        disk = os.fstatvfs(self.fd)
        check(not disk.f_flag & os.ST_RDONLY and disk.f_bavail * disk.f_frsize >= self.plan["required_capacity"], "env.sync_preflight_failed")

    def stat(self, name):
        try:
            info = os.stat(name, dir_fd=self.fd, follow_symlinks=False)
        except FileNotFoundError:
            return None
        check(stat.S_ISREG(info.st_mode) and info.st_uid == self.account.pw_uid and info.st_size <= self.maximum, "env.import_preflight_failed")
        return info

    def read(self, name):
        info = self.stat(name)
        if info is None:
            return None, None
        fd = os.open(name, os.O_RDONLY | os.O_NONBLOCK | os.O_NOFOLLOW | os.O_CLOEXEC, dir_fd=self.fd)
        try:
            check(identity(os.fstat(fd)) == identity(info), "env.import_preflight_failed")
            with os.fdopen(fd, "rb", closefd=False) as file:
                value = file.read(self.maximum + 1)
            check(len(value) <= self.maximum, "env.import_preflight_failed")
            return info, value
        finally:
            os.close(fd)

    def tracked(self, name):
        def git(*args):
            return subprocess.run(
                ["/usr/bin/git", "-c", "core.hooksPath=/dev/null", "-c", "core.fsmonitor=false", "-C", self.plan["base"], *args],
                stdin=subprocess.DEVNULL, capture_output=True, timeout=30, check=False,
                env={"PATH": "/usr/bin:/bin", "HOME": self.account.pw_dir, "LC_ALL": "C", "GIT_OPTIONAL_LOCKS": "0"},
            )

        try:
            inside = git("rev-parse", "--is-inside-work-tree")
            if inside.returncode == 128 and inside.stderr.split(b"\n")[0].lower().startswith(b"fatal: not a git repository (or any "):
                return False
            check(inside.returncode == 0 and inside.stdout == b"true\n", "env.testing_tracking_unknown")
            listed = git("ls-files", "--error-unmatch", "--", name)
            check(listed.returncode in (0, 1), "env.testing_tracking_unknown")
            return listed.returncode == 0
        except (OSError, subprocess.SubprocessError):
            raise Refused("env.testing_tracking_unknown") from None

    def publish(self, name, payload):
        """Replace the file through a private temporary file in the same directory; a matching file stays."""
        check(len(payload) <= self.maximum, "env.configuration_invalid")
        previous, existing = self.read(name)
        if previous is not None and previous.st_mode & 0o7777 == 0o600 and existing == payload:
            return False
        temporary = ".env.orbit-" + secrets.token_hex(16)
        fd = os.open(temporary, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW | os.O_CLOEXEC, 0o600, dir_fd=self.fd)
        try:
            with os.fdopen(fd, "wb", closefd=False) as file:
                file.write(payload)
                file.flush()
                os.fsync(fd)
            current = self.stat(name)
            check((identity(current) if current else None) == (identity(previous) if previous else None), "env.sync_unconfirmed")
            os.rename(temporary, name, src_dir_fd=self.fd, dst_dir_fd=self.fd)
            os.fsync(self.fd)
            return True
        finally:
            os.close(fd)
            try:
                os.unlink(temporary, dir_fd=self.fd)
            except FileNotFoundError:
                pass


def merged(existing, supplied, managed):
    """Replace the managed keys of .env.testing and keep every other line as it is."""
    updates = {}
    for line in supplied.splitlines(keepends=True):
        match = KEY.match(line)
        if match and match[1].decode("ascii") in managed:
            updates.setdefault(match[1].decode("ascii"), line if line.endswith(b"\n") else line + b"\n")
    check(updates, "env.configuration_invalid")
    if existing is None:
        return supplied
    lines, written = [], set()
    for line in existing.splitlines(keepends=True):
        match = KEY.match(line)
        key = match[1].decode("ascii") if match else None
        if key in managed:
            if key in updates and key not in written:
                lines.append(updates[key])
                written.add(key)
        else:
            lines.append(line)
    missing = [line for key, line in updates.items() if key not in written]
    if missing and lines and not lines[-1].endswith(b"\n"):
        lines[-1] += b"\n"
    return b"".join(lines + missing)


def check_mode(directory, plan, _stdin):
    directory.writable()
    _, existing = directory.read(".env")
    if existing is not None and plan["preserve_workload_keys"]:
        known = set(plan["keys"]) | set(plan["owned_keys"])
        foreign = sorted({key for key in keys(existing) if key not in known})
        if foreign:
            raise Refused("env.sync_would_drop_keys", foreign_keys=foreign)
    return {"ready": True}


def write_mode(directory, plan, stdin):
    try:
        material = json.loads(stdin)
        content = material["content"].encode("utf-8")
        testing = material["testing"]
    except (ValueError, KeyError, TypeError, AttributeError):
        raise Refused("env.configuration_invalid") from None
    check((testing is None) == (plan["testing"] is None), "env.configuration_invalid")
    directory.writable()
    environment_changed = directory.publish(".env", content)
    status = None
    if testing is not None:
        if directory.tracked(".env.testing"):
            status = "skipped_tracked"
        else:
            _, existing = directory.read(".env.testing")
            supplied = testing["content"].encode("utf-8")
            status = "written" if directory.publish(".env.testing", merged(existing, supplied, set(plan["testing"]["managed_keys"]))) else "unchanged"
    return {"environment_changed": environment_changed, "testing_status": status}


def verify_mode(directory, plan, _stdin):
    _, environment = directory.read(".env")
    check(environment is not None and hashlib.sha256(environment).hexdigest() == plan["sha256"], "env.sync_unconfirmed")
    if plan["testing"] is not None and not directory.tracked(".env.testing"):
        _, testing = directory.read(".env.testing")
        lines = managed_lines(testing or b"", set(plan["testing"]["managed_keys"]))
        check(testing is not None and hashlib.sha256(lines).hexdigest() == plan["testing"]["sha256"], "env.sync_unconfirmed")
    return {"ready": True}


MODES = {"check": check_mode, "write": write_mode, "verify": verify_mode}


def run(argv, stdin):
    directory = None
    try:
        mode, plan = argv[0], json.loads(argv[1])
        check(mode in MODES, "env.configuration_invalid")
        directory = Directory(plan)
        return MODES[mode](directory, plan, stdin), 0
    except Refused as refusal:
        return refusal.public, 1
    except (OSError, ValueError, KeyError, TypeError, IndexError):
        return {"error_code": "env.sync_unconfirmed"}, 1
    finally:
        if directory is not None:
            directory.close()


if __name__ == "__main__":
    result, code = run(sys.argv[1:], sys.stdin.buffer.read() if len(sys.argv) > 1 and sys.argv[1] == "write" else b"")
    print(json.dumps(result, separators=(",", ":")))
    sys.exit(code)
