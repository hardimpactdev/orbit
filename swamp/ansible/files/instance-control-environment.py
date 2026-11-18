#!/usr/bin/env python3
"""Synchronize the verified live Instance environment under its release lock."""
import json
import os
import pwd
import re
import stat
import subprocess
import sys
import tempfile
import uuid
from pathlib import Path


def atomic(path, content):
    with tempfile.NamedTemporaryFile(dir=path.parent, delete=False) as handle:
        temporary = Path(handle.name)
        try:
            handle.write(content)
            handle.flush()
            os.fsync(handle.fileno())
            os.replace(temporary, path)
        finally:
            temporary.unlink(missing_ok=True)


def current(path):
    if path.is_symlink() or (path.exists() and not path.is_file()):
        raise ValueError("unsafe environment file")
    return path.read_bytes() if path.is_file() else None


def apply(plan, root=Path("/srv/workspace/current")):
    application = Path(plan["base"])
    directories = {application}
    for route in plan["routes"]:
        web = root / route["web_root"]
        if web.name == "public":
            directories.add(web.parent)
    writes = []
    changed = False
    testing = "unchanged" if plan["testing"] is not None else None
    for base in sorted(directories):
        if not base.is_relative_to(root) or base.resolve() != base or not base.is_dir():
            raise ValueError("invalid release application")
        env = base / ".env"
        previous = current(env)
        if previous is None and not plan["force"]:
            raise ValueError("env.keys_not_observed")
        if previous is not None and not plan["force"]:
            keys = re.findall(rb"(?m)^[ \t]*(?:export[ \t]+)?([A-Za-z_][A-Za-z0-9_]*)[ \t]*=", previous)
            if any(key.decode() not in plan["managed_keys"] for key in keys):
                raise ValueError("env.sync_would_drop_keys")
        content = plan["content"].encode()
        if previous != content:
            writes.append((env, previous, content))
            changed = True
        if plan["testing"] is not None:
            tracked = subprocess.run(["git", "ls-files", "--error-unmatch", "--", ".env.testing"], cwd=base, check=False,
                                     stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL).returncode
            if tracked == 0:
                testing = "skipped_tracked"
            elif tracked == 1:
                path = base / ".env.testing"
                old = current(path)
                data = plan["testing"].encode()
                if old != data:
                    writes.append((path, old, data))
                    testing = "written"
            else:
                raise ValueError("could not establish whether the testing environment is tracked")
    applied = []
    try:
        for path, old, content in writes:
            atomic(path, content)
            applied.append((path, old))
        if plan["laravel"]:
            cache = application / "bootstrap/cache/config.php"
            with tempfile.TemporaryDirectory(dir=cache.parent) as scratch:
                staged = Path(scratch) / "config.php"
                subprocess.run([plan["php"], "artisan", "config:cache", "--no-interaction", "--quiet"], cwd=application,
                               env={**os.environ, "APP_CONFIG_CACHE": str(staged)}, timeout=120, check=True,
                               stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
                staged.chmod(0o600)
                os.replace(staged, cache)
    except BaseException:
        for path, old in reversed(applied):
            if old is None:
                path.unlink(missing_ok=True)
            else:
                atomic(path, old)
        raise
    return {"environment_changed": changed, "testing_status": testing}


def read_environment(directory, root=Path("/srv/workspace/current")):
    base = Path(directory)
    if not base.is_relative_to(root) or base.resolve() != base:
        raise ValueError("unsafe import directory")
    with os.fdopen(os.open(base / ".env", os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK), "rb") as source:
        metadata = os.fstat(source.fileno())
        if not stat.S_ISREG(metadata.st_mode) or metadata.st_uid != os.geteuid() or metadata.st_size > 1048576:
            raise ValueError("unsafe import file")
        data = source.read(1048577)
        if len(data) > 1048576:
            raise ValueError("import exceeds size limit")
        return data


def stage(incus, run_id, directory, account):
    if str(uuid.UUID(run_id)) != run_id:
        raise ValueError("invalid run")
    owner = pwd.getpwnam(account)
    dest = Path("/tmp") / ("orbit-env-import-" + run_id + "-node")
    with tempfile.NamedTemporaryFile(dir=dest.parent, delete=False) as handle:
        private = Path(handle.name)
        try:
            result = subprocess.run([*incus, "runuser", "-u", "orbit", "--", "/usr/bin/python3", "-c",
                                     Path(__file__).read_text(), "--read", directory], stdout=handle,
                                    stderr=subprocess.DEVNULL, timeout=30, check=False)
            if result.returncode:
                sys.exit(3)
            handle.flush()
            os.fchown(handle.fileno(), owner.pw_uid, owner.pw_gid)
            os.replace(private, dest)
        finally:
            private.unlink(missing_ok=True)


def main():
    if sys.argv[1:2] == ["--read"]:
        sys.stdout.buffer.write(read_environment(sys.argv[2]))
        return
    if sys.argv[1:] == ["--guest"]:
        print(json.dumps(apply(json.load(sys.stdin))))
        return
    instance, sha, machine, *args = sys.argv[1:]
    if not re.fullmatch("[1-9][0-9]*", instance) or not re.fullmatch("[0-9a-f]{40}", sha) or machine != f"instance-{instance}-{sha[:12]}":
        raise ValueError("invalid release")
    incus = ["incus", "--force-local", "--project", "orbit-releases", "exec", machine, "--"]
    if args:
        if args[0] != "stage" or len(args) != 4:
            raise ValueError("invalid import")
        stage(incus, *args[1:])
        return
    plan = json.load(sys.stdin)
    record = json.loads((Path("/var/lib/orbit/production") / instance / ".release" / (machine + ".json")).read_text())
    if plan["instance_id"] != int(instance) or plan["release"] != sha:
        raise ValueError("env.configuration_changed")
    version = record["php_version"]
    if not re.fullmatch(r"[0-9]+[.][0-9]+", version):
        raise ValueError("invalid release runtime")
    plan.update(routes=record["routes"], php=f"/opt/orbit-runtime/current/php/{version}/bin/php")
    guest = subprocess.run([*incus, "runuser", "-u", "orbit", "--", "/usr/bin/python3", "-c", Path(__file__).read_text(), "--guest"],
                           input=json.dumps(plan), capture_output=True, text=True, timeout=180, check=False)
    if guest.returncode:
        for message in ("env.sync_would_drop_keys", "env.keys_not_observed"):
            if message in guest.stderr:
                raise ValueError(message)
        raise ValueError("env.runtime_refresh_failed")
    receipt = json.loads(guest.stdout)
    if plan["laravel"]:
        subprocess.run([*incus, "systemctl", "restart", "orbit-frankenphp.service"], check=True, timeout=120)
    restarted = []
    for unit in plan["restart_units"]:
        match = re.fullmatch(r"orbit-process-([1-9][0-9]*)-[a-z0-9-]+[.]service", unit)
        if not match:
            raise ValueError("invalid managed Process")
        if subprocess.run([*incus, "systemctl", "is-active", "--quiet", unit], check=False).returncode == 0:
            subprocess.run([*incus, "systemctl", "restart", unit], check=True, timeout=180)
            restarted.append(int(match[1]))
    print(json.dumps({**receipt, "restarted_process_ids": restarted, "instance_id": int(instance), "release": sha}))


if __name__ == "__main__":
    try:
        main()
    except (ValueError, KeyError, OSError, subprocess.SubprocessError) as error:
        # Dotenv content and server-side command diagnostics never enter Operation history.
        code = str(error) if isinstance(error, ValueError) and str(error).startswith("env.") else "env.runtime_refresh_failed"
        sys.exit(code)
