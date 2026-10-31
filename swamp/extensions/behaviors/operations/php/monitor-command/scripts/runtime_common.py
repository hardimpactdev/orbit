"""Canonical dedicated FPM identity, ownership, validation and generation helpers.

These helpers are extracted from the existing PHP runtime program. Entry scripts
invoke one action each; no Gateway client, private input or retry loop is used.
"""

import contextlib
import fcntl
import grp
import hashlib
import os
import pathlib
import pwd
import re
import stat
import subprocess
import tempfile

P = pathlib.Path
PENDING = ".runtime-generation.pending"
APPLIED = ".runtime-generation.applied"
METRICS_PENDING = ".metrics-pool.pending"
METRICS_BACKUP = ".metrics-pool.backup"


class Refused(Exception):
    def __init__(self, check, code="php.runtime_invalid"):
        self.check, self.code = check, code
        super().__init__(code)


def check(value, reason):
    if not value:
        raise Refused(reason)


def exists(p):
    return os.path.lexists(p)


def regular(p):
    return exists(p) and stat.S_ISREG(p.lstat().st_mode)


def directory(p):
    return exists(p) and stat.S_ISDIR(p.lstat().st_mode)


def owned(p, uid, gid, mode):
    try:
        m = p.lstat()
        return m.st_uid == uid and m.st_gid == gid and stat.S_IMODE(m.st_mode) == mode
    except OSError:
        return False


def digest(v):
    return hashlib.sha256(v).hexdigest()


def file_hash(p):
    try:
        return digest(p.read_bytes())
    except OSError:
        return None


def sync(p):
    fd = os.open(p, os.O_RDONLY | os.O_NOFOLLOW)
    try:
        os.fsync(fd)
    finally:
        os.close(fd)


def publish(parent, p, value, mode):
    fd, candidate = tempfile.mkstemp(prefix=".orbit.", suffix=".candidate", dir=parent)
    try:
        with os.fdopen(fd, "wb") as output:
            output.write(value if isinstance(value, bytes) else value.encode())
            output.flush()
            os.fsync(output.fileno())
            os.fchmod(output.fileno(), mode)
        os.replace(candidate, p)
    finally:
        if os.path.lexists(candidate):
            os.unlink(candidate)


def unlink(p):
    if exists(p):
        p.unlink()


def inside(p, base):
    return p != base and p.is_relative_to(base)


class Host:
    def __init__(self):
        self.homes, self.etc, self.units = (
            P("/home"),
            P("/etc/orbit"),
            P("/etc/systemd/system"),
        )
        self.sockets, self.proc, self.sbin = P("/run/php"), P("/proc"), P("/usr/sbin")
        self.php_conf, self.tmp, self.locks = P("/etc/php"), P("/tmp"), P("/run/lock")
        self.systemctl = "/usr/bin/systemctl"
        self.root_uid = self.root_gid = 0
        self.caddy_gid = grp.getgrnam("caddy").gr_gid

    def ctl(self, *args):
        return subprocess.run(
            [self.systemctl, *args],
            stdin=subprocess.DEVNULL,
            stdout=subprocess.PIPE,
            stderr=subprocess.DEVNULL,
            timeout=90,
        )

    def ok(self, *args):
        return self.ctl(*args).returncode == 0

    def active(self, service):
        return self.ok("is-active", "--quiet", service)

    def pid(self, service):
        out = self.ctl("show", "--property", "MainPID", "--value", service)
        value = out.stdout.strip()
        return int(value) if out.returncode == 0 and value.isdigit() else 0

    def fpm(self, version):
        return self.sbin / ("php-fpm" + version)

    def start(self, pid):
        try:
            return int(
                (self.proc / str(pid) / "stat")
                .read_text()
                .rsplit(") ", 1)[1]
                .split()[19]
            )
        except (OSError, ValueError, IndexError):
            return None

    def runs_fpm(self, pid, version):
        try:
            return pid > 1 and (self.proc / str(pid) / "exe").resolve(
                strict=True
            ) == self.fpm(version)
        except OSError:
            return False

    def socket(self, p, uid):
        try:
            return stat.S_ISSOCK(p.lstat().st_mode) and owned(
                p, uid, self.caddy_gid, 0o660
            )
        except OSError:
            return False

    def root(self, p, mode):
        return owned(p, self.root_uid, self.root_gid, mode)

    def account(self, user):
        return pwd.getpwnam(user)


class Runtime:
    def __init__(self, h, data, home=False):
        self.user, self.version = data["user"], data["version"]
        check(re.fullmatch(r"[a-z_][a-z0-9_-]{0,31}", self.user), "valid account")
        check(re.fullmatch(r"[0-9]+\.[0-9]+", self.version), "valid PHP version")
        self.home = h.homes / self.user
        self.service = "orbit-" + self.user + "-php" + self.version + "-fpm.service"
        self.directory = h.etc / "php-fpm" / self.user
        self.generated = self.directory / "generated"
        self.local, self.marker = (
            self.directory / "local.conf",
            self.directory / "orbit.identity",
        )
        self.unit, self.socket = (
            h.units / self.service,
            h.sockets / (self.user + ".sock"),
        )
        self.expected = data["marker"].encode()
        self.uid = 0
        if home:
            a = h.account(self.user)
            check(
                P(data["home"]) == self.home and directory(self.home),
                "canonical account home",
            )
            m = self.home.stat()
            check((m.st_uid, m.st_gid) == (a.pw_uid, a.pw_gid), "account owns home")
            self.uid = a.pw_uid

    def matches(self, h):
        return (
            regular(self.marker)
            and h.root(self.marker, 0o644)
            and self.marker.read_bytes() == self.expected
        )

    def receipt(self, name):
        return self.directory / name


@contextlib.contextmanager
def runtime_lock(h, user):
    check(directory(h.locks), "lock directory exists")
    fd = os.open(
        h.locks / ("orbit-php-" + user + ".lock"),
        os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW,
        0o600,
    )
    try:
        m = os.fstat(fd)
        check(
            stat.S_ISREG(m.st_mode)
            and m.st_uid == h.root_uid
            and m.st_gid == h.root_gid
            and stat.S_IMODE(m.st_mode) == 0o600,
            "trusted runtime lock",
        )
        fcntl.flock(fd, fcntl.LOCK_EX)
        yield
    finally:
        os.close(fd)


def guard(h, p):
    return not exists(p) or (regular(p) and h.root(p, 0o600))


def pool_generation(pool):
    return digest(
        b"".join(
            line + b"\n"
            for line in pool.split(b"\n")
            if line.startswith((b"chdir = ", b"; Orbit application release: "))
        )
    )


def master_token(h, r):
    pid = h.pid(r.service)
    try:
        boot = (h.proc / "sys/kernel/random/boot_id").read_text().rstrip("\n")
    except OSError:
        return None
    started = h.start(pid)
    if (
        pid <= 1
        or not re.fullmatch(r"[a-fA-F0-9-]{36}", boot)
        or started is None
        or h.pid(r.service) != pid
    ):
        return None
    return boot, pid, started


def generation_applied(h, r, pool):
    p = r.receipt(APPLIED)
    if exists(r.receipt(PENDING)) or not guard(h, p) or not regular(p):
        return False
    token = master_token(h, r)
    if token is None:
        return False
    lines = p.read_text().rstrip("\n").split("\n")
    if len(lines) != 4 or lines[0] != pool_generation(pool):
        return False
    boot, pid, started = token
    if lines[1:] == [boot, str(pid), str(started)]:
        return True
    return bool(
        re.fullmatch(r"[a-fA-F0-9-]{36}", lines[1])
        and lines[3].isdigit()
        and (lines[1] != boot or started > int(lines[3]))
    )


def receipt(h, r, name, text):
    p = r.receipt(name)
    check(guard(h, p), "trusted generation receipt")
    publish(r.directory, p, text + "\n", 0o600)
    sync(r.directory)


def confirm(h, r, pool):
    token = master_token(h, r)
    check(token is not None, "master is running")
    receipt(h, r, APPLIED, pool_generation(pool) + "\n" + "\n".join(map(str, token)))
    unlink(r.receipt(PENDING))
    sync(r.directory)
    check(generation_applied(h, r, pool), "generation applied")


def select_pool(data):
    home, app, initial = map(
        P, (data["home"], data["application"], data["initial_application"])
    )
    current, releases = home / "current", home / "releases"
    if app != initial:
        if exists(current):
            check(current.is_symlink(), "current is link")
            selected = current.resolve(strict=True)
            check(
                inside(selected, releases)
                and re.fullmatch(
                    r"[A-Za-z0-9][A-Za-z0-9._-]{0,127}",
                    str(selected.relative_to(releases)),
                ),
                "current names release",
            )
            resolved = app.resolve(strict=True)
            check(
                app.is_relative_to(current)
                and resolved == selected / app.relative_to(current),
                "application in current",
            )
            chosen, pool = app, data["configuration"]["pool"]
        else:
            resolved = initial.resolve(strict=True)
            check(resolved == initial, "initial application is canonical")
            chosen, pool = initial, data["configuration"]["initial_pool"]
    else:
        resolved = app.resolve(strict=True)
        check(resolved == app, "application is canonical")
        chosen, pool = app, data["configuration"]["pool"]
    check(chosen.is_dir(), "application exists")
    return (pool + "\n; Orbit application release: " + str(resolved) + "\n").encode()


def served(pool, key):
    return [
        line[len(key) + 3 :]
        for line in pool.splitlines()
        if line.startswith(key + " = ")
    ][1:]


def tuning_allowed(local, pool_name, monitoring):
    forbidden = {
        "pid",
        "user",
        "group",
        "listen",
        "listen.owner",
        "listen.group",
        "listen.mode",
        "chdir",
        "chroot",
        "env[home]",
        "env[user]",
        "include",
    }
    if monitoring:
        forbidden |= {"pm.status_path", "pm.status_listen"}
    for raw in local.splitlines():
        line = raw.lstrip()
        if not line or line.startswith((";", "#")):
            continue
        if line.startswith("["):
            if raw.strip() != "[" + pool_name + "]":
                return False
            continue
        if re.split(r"\s*=", line.lower(), maxsplit=1)[0] in forbidden:
            return False
    return True


def validate(h, r, work, main):
    lines = []
    for line in main.split("\n"):
        if line.startswith("include = ") and line.endswith("/generated/pool.conf"):
            line = "include = " + str(work / "pool.conf")
        if line.startswith("include = ") and line.endswith("/local.conf"):
            line = "include = " + str(work / "local.conf")
        lines.append(line)
    candidate = work / "php-fpm.validate.conf"
    candidate.write_text("\n".join(lines))
    env = dict(
        os.environ,
        PHP_INI_SCAN_DIR=str(h.php_conf / r.version / "fpm/conf.d") + ":" + str(work),
    )

    def fpm(args):
        return subprocess.run(
            [str(h.fpm(r.version)), *args],
            env=env,
            stdin=subprocess.DEVNULL,
            stdout=subprocess.PIPE,
            stderr=subprocess.DEVNULL,
            timeout=30,
        )

    check(fpm(["-y", str(candidate), "-t"]).returncode == 0, "FPM configuration valid")
    info = fpm(["-i"])
    check(
        info.returncode == 0
        and str(work / "master.ini") in info.stdout.decode(errors="replace"),
        "FPM loads master.ini",
    )
    lines = info.stdout.decode(errors="replace").splitlines()
    for line in (work / "master.ini").read_text().splitlines():
        if not line or line.startswith(";"):
            continue
        key, _, value = line.partition("=")
        key, value = key.rstrip(" "), value.lstrip(" ")
        if key == "opcache.validate_timestamps" and value == "0":
            value = "Off"
        check(
            key + " => " + value + " => " + value in lines,
            "FPM applies master.ini settings",
        )


def reload(h, r):
    check(h.ok("reload", r.service) and h.active(r.service), "FPM reloads")


def inspect(data, h):
    r = Runtime(h, data)

    def read(p):
        for parent in (p, *p.parents):
            m = parent.lstat()
            check(
                not stat.S_ISLNK(m.st_mode)
                and m.st_uid == h.root_uid
                and not m.st_mode & 0o022,
                "runtime path root-owned",
            )
            if parent == h.etc.parent:
                break
        return p.read_text()

    check(read(r.marker).encode() == r.expected, "Instance identity matches")
    main, pool, local = (
        read(r.generated / "php-fpm.conf"),
        read(r.generated / "pool.conf"),
        read(r.local),
    )
    return {"pool": pool, "fingerprint": digest((main + pool + local).encode())}
