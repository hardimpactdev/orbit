"""Public inputs and the existing canonical file and loader suppliers."""

import importlib.util
import json
import os
import pwd
import stat
import subprocess
import sys
from pathlib import Path


def plan():
    return json.loads((Path(sys.argv[1]) / "plan.json").read_text())


def run(argv, check=True):
    return subprocess.run(argv, check=check, text=True, stdout=subprocess.PIPE)


def control(*args, check=True):
    return run(["/usr/bin/systemctl", *args], check)


def managed(argv):
    return run(
        [
            "/usr/sbin/runuser",
            "-u",
            "orbit",
            "--",
            "/usr/bin/env",
            "HOME=/home/orbit",
            *argv,
        ]
    )


def source_sha(data):
    return managed(
        ["/usr/bin/git", "-C", data["checkout"], "rev-parse", "HEAD"]
    ).stdout.strip()


def module(path):
    spec = importlib.util.spec_from_file_location(path.stem, path)
    value = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(value)
    return value


def publish(data, file):
    return module(
        Path(data["checkout"])
        / "swamp/extensions/behaviors/operations/fleet-recipe/scripts/file.py"
    ).apply(file)


def directory(path, uid, gid, mode=0o700):
    path = Path(path)
    if path.is_symlink() or (path.exists() and not path.is_dir()):
        raise ValueError("swamp.role_directory_invalid")
    path.mkdir(parents=True, exist_ok=True)
    os.chown(path, uid, gid)
    path.chmod(mode)


def mount_units(data):
    return [
        (
            run(
                ["/usr/bin/systemd-escape", "--path", "--suffix=mount", row["where"]]
            ).stdout.strip(),
            row,
        )
        for row in data["mounts"]
    ]


def private_file(path, owner):
    metadata = Path(path).lstat()
    account = pwd.getpwnam(owner)
    if (
        not stat.S_ISREG(metadata.st_mode)
        or stat.S_IMODE(metadata.st_mode) != 0o600
        or metadata.st_uid != account.pw_uid
        or metadata.st_gid != account.pw_gid
    ):
        raise ValueError("swamp.role_private_file_custody")


def tls_custody(data):
    for name in ("cert.pem", "key.pem"):
        private_file(data["config"] + "/serve-tls/" + name, "orbit")


def ready(**facts):
    print(json.dumps({"ready": True, **facts}))
