import grp
import hashlib
import json
import os
import pathlib
import pwd
import re
import stat
import subprocess
import sys
import tempfile

P = pathlib.Path
SETTING = P("/etc/sysctl.d/60-orbit-caddy.conf")
LEGACY = [
    P("/usr/share/keyrings/orbit-caddy.gpg"),
    P("/etc/apt/sources.list.d/orbit-caddy.sources"),
]
OS_RELEASE = P("/etc/os-release")
DOCKER = P("/usr/bin/docker")
COMPOSER = P("/opt/orbit/composer")
ROOT_DIRS = [
    P(p)
    for p in [
        "/etc",
        "/etc/sysctl.d",
        "/etc/apt",
        "/etc/apt/sources.list.d",
        "/usr",
        "/usr/share",
        "/usr/share/keyrings",
    ]
]


class Refusal(Exception):
    pass


def run(*args, check=True, env=None):
    result = subprocess.run(
        [str(a) for a in args],
        stdin=subprocess.DEVNULL,
        stdout=subprocess.PIPE,
        stderr=subprocess.DEVNULL,
        timeout=110,
        check=False,
        env={**os.environ, "LC_ALL": "C", **(env or {})},
    )
    if check and result.returncode:
        raise Refusal("cluster.router_preparation_failed")
    return result


def preflight(path, directory, uid, gid=None, mode=None):
    try:
        meta = path.lstat()
    except FileNotFoundError:
        return
    if (
        not (stat.S_ISDIR(meta.st_mode) if directory else stat.S_ISREG(meta.st_mode))
        or meta.st_uid != uid
        or (gid is not None and meta.st_gid != gid)
        or (mode is not None and stat.S_IMODE(meta.st_mode) != mode)
    ):
        raise Refusal("role.prerequisite_conflict")


def directory(path, uid, gid):
    path.mkdir(exist_ok=True)
    os.chown(path, uid, gid)
    os.chmod(path, 0o755)


def version():
    try:
        result = run("caddy", "version", check=False)
    except FileNotFoundError:
        return None
    if result.returncode == 127:
        return None
    if result.returncode:
        raise Refusal("caddy.package_failed")
    value = result.stdout.decode().split()[0].removeprefix("v")
    if not re.fullmatch(r"[0-9]+\.[0-9]+\.[0-9]+", value):
        raise Refusal("caddy.package_failed")
    return value


def minimum(current, floor):
    result = run("dpkg", "--compare-versions", current, "ge", floor, check=False)
    if result.returncode not in (0, 1):
        raise Refusal("caddy.package_failed")
    return result.returncode == 0


def caddy(data):
    if not all(
        re.fullmatch(r"[0-9]+\.[0-9]+\.[0-9]+", data[k])
        for k in ["release", "minimum_version"]
    ) or not re.fullmatch("[a-f0-9]{128}", data["amd64_sha512"]):
        raise Refusal("caddy.package_invalid")
    for path in ROOT_DIRS:
        if not path.exists():
            raise Refusal("caddy.package_unsafe")
        preflight(path, True, 0)
    preflight(SETTING, False, 0, 0, 0o644)
    for path in LEGACY:
        preflight(path, False, 0)
    current = version()
    changed = False
    with tempfile.TemporaryDirectory(prefix="orbit-caddy-") as work:
        package = None
        if current is None or not minimum(current, data["minimum_version"]):
            if run("dpkg", "--print-architecture").stdout.decode().strip() != "amd64":
                raise Refusal("caddy.package_failed")
            filename = "caddy_" + data["release"] + "_linux_amd64.deb"
            package = P(work) / filename
            url = (
                "https://github.com/caddyserver/caddy/releases/download/v"
                + data["release"]
                + "/"
                + filename
            )
            run(
                "curl",
                "--fail",
                "--silent",
                "--show-error",
                "--location",
                "--proto",
                "=https",
                "--proto-redir",
                "=https",
                "--tlsv1.2",
                "--max-filesize",
                "536870912",
                "--output",
                package,
                url,
            )
            if hashlib.sha512(package.read_bytes()).hexdigest() != data["amd64_sha512"]:
                raise Refusal("caddy.package_failed")
        body = "net.ipv4.tcp_migrate_req = 1\n"
        staged = P(work) / "sysctl.conf"
        staged.write_text(body)
        run("sysctl", "--quiet", "--load=" + str(staged))
        if not SETTING.exists() or SETTING.read_text() != body:
            staged = SETTING.with_name(".orbit-caddy-" + str(os.getpid()))
            staged.write_text(body)
            os.chown(staged, 0, 0)
            os.chmod(staged, 0o644)
            os.replace(staged, SETTING)
            changed = True
        for path in LEGACY:
            if path.exists():
                path.unlink()
                changed = True
        if package is not None:
            os.chmod(work, 0o755)
            os.chmod(package, 0o644)
            run(
                "apt-get",
                "-o",
                "DPkg::Lock::Timeout=300",
                "-o",
                "Dpkg::Options::=--force-confold",
                "install",
                "--yes",
                "--no-install-recommends",
                "--no-remove",
                "--",
                package,
                env={"DEBIAN_FRONTEND": "noninteractive"},
            )
            current = version()
            changed = True
    if current is None or not minimum(current, data["minimum_version"]):
        raise Refusal("caddy.package_failed")
    return {"changed": changed, "version": current}


def prerequisites(data):
    release = {}
    for line in OS_RELEASE.read_text().splitlines():
        if "=" in line:
            k, v = line.split("=", 1)
            release[k] = v.strip("\"'")
    if (
        release.get("ID") != "ubuntu"
        or release.get("VERSION_CODENAME") not in data["releases"]
    ):
        raise Refusal("role.os_unsupported")
    if not all(
        re.fullmatch("[a-zA-Z0-9][a-zA-Z0-9.+:_-]{0,254}", p) for p in data["packages"]
    ):
        raise Refusal("role.prerequisite_failed")
    account = pwd.getpwnam(data["user"])
    uid = account.pw_uid
    gid = grp.getgrnam(data["group"]).gr_gid
    home = P(data["home"])
    if not home.is_absolute() or ".." in home.parts:
        raise Refusal("role.prerequisite_conflict")
    if data["apps_directory"]:
        preflight(home / "apps", True, uid, gid)
    if data["composer_home"]:
        preflight(COMPOSER.parent, True, 0, 0)
        preflight(COMPOSER, True, uid, gid)
        for path, folder in [
            (COMPOSER / "composer.json", False),
            (COMPOSER / "vendor", True),
            (COMPOSER / "vendor/bin", True),
        ]:
            preflight(path, folder, uid, gid)
    docker = (
        DOCKER.is_file()
        and os.access(DOCKER, os.X_OK)
        and all(
            run("dpkg-query", "-W", "-f=$" + "{Status}", "--", p, check=False)
            .stdout.decode()
            .strip()
            == "install ok installed"
            for p in ["docker-ce", "docker-ce-cli", "containerd.io"]
        )
        and run("systemctl", "is-active", "--quiet", "docker", check=False).returncode
        == 0
    )
    packages = [p for p in data["packages"] if not (docker and p == "docker.io")]
    if type(data.get("install_packages", True)) is not bool:
        raise Refusal("role.prerequisite_failed")
    if data.get("install_packages", True):
        run(
            "apt-get",
            "-o",
            "DPkg::Lock::Timeout=300",
            "update",
            env={"DEBIAN_FRONTEND": "noninteractive"},
        )
        if packages:
            run(
                "apt-get",
                "-o",
                "DPkg::Lock::Timeout=300",
                "install",
                "--yes",
                "--no-install-recommends",
                "--no-remove",
                "--",
                *packages,
                env={"DEBIAN_FRONTEND": "noninteractive"},
            )
    if data["apps_directory"]:
        directory(home / "apps", uid, gid)
    if data["composer_home"]:
        directory(COMPOSER.parent, 0, 0)
        directory(COMPOSER, uid, gid)
        manifest = COMPOSER / "composer.json"
        if not manifest.exists():
            with manifest.open("x") as file:
                file.write('{"require":{}}\n')
            os.chown(manifest, uid, gid)
            os.chmod(manifest, 0o644)
        directory(COMPOSER / "vendor", uid, gid)
        directory(COMPOSER / "vendor/bin", uid, gid)
        run(
            "sudo",
            "-u",
            data["user"],
            "-H",
            "env",
            "COMPOSER_HOME=" + str(COMPOSER),
            "/usr/bin/composer",
            "--version",
            "--no-ansi",
        )
    return {}


def execute(data):
    if data["name"] == "caddy.install-package":
        return caddy(data["input"])
    if data["name"] == "role.install-prerequisites":
        return prerequisites(data["input"])
    raise Refusal("cluster.router_preparation_failed")


if __name__ == "__main__":
    try:
        result = execute(json.load(sys.stdin))
    except Refusal as error:
        result = {"error_code": str(error)}
    except Exception:  # noqa: BLE001 - preserve the existing public refusal envelope
        result = {"error_code": "cluster.router_preparation_failed"}
    print(json.dumps(result))
