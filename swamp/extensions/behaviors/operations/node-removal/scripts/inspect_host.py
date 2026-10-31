import json
import pathlib
import pwd
import stat
import sys
import tomllib

node = int(sys.argv[1])


def safe(value, directory=False):
    p = pathlib.Path(value)
    try:
        s = p.lstat()
    except FileNotFoundError:
        return False
    if (
        stat.S_ISLNK(s.st_mode)
        or s.st_uid != 0
        or s.st_mode & 0o022
        or (not stat.S_ISDIR(s.st_mode) if directory else not stat.S_ISREG(s.st_mode))
    ):
        raise RuntimeError("unsafe local state")
    return True


for value in [
    "/etc",
    "/etc/systemd",
    "/etc/systemd/system",
    "/usr",
    "/usr/local",
    "/usr/local/bin",
]:
    if not safe(value, True):
        raise RuntimeError("unsafe parent")
observer = safe("/etc/systemd/system/orbit-agent.service")
worker = safe("/etc/systemd/system/orbit-node-worker.service")
binary = safe("/usr/local/bin/orbit-agent")
directory = safe("/etc/orbit/agent", True)
configuration = None
if directory:
    if not safe("/etc/orbit/agent/config.toml"):
        raise RuntimeError("missing configuration")
    configuration = tomllib.loads(
        pathlib.Path("/etc/orbit/agent/config.toml").read_text()
    ).get("swamp_worker")
    if configuration is not None and (
        not isinstance(configuration, dict) or configuration.get("node_id") != node
    ):
        raise RuntimeError("foreign Node")
elif observer or binary or worker:
    raise RuntimeError("missing owner configuration")
if worker:
    if (
        not isinstance(configuration, dict)
        or not isinstance(configuration.get("user"), str)
        or configuration["user"] == "root"
    ):
        raise RuntimeError("missing worker account")
    account = pwd.getpwnam(configuration["user"])
    cache = pathlib.Path("/var/lib/orbit-node-worker")
    if cache.exists() or cache.is_symlink():
        info = cache.lstat()
        if (
            not stat.S_ISDIR(info.st_mode)
            or info.st_uid != account.pw_uid
            or stat.S_IMODE(info.st_mode) != 0o700
        ):
            raise RuntimeError("unsafe cache")
    for value in ["/etc/orbit", "/usr/local/libexec"]:
        if not safe(value, True):
            raise RuntimeError("unsafe worker parent")
    credentials = pathlib.Path("/etc/orbit/swamp-worker")
    if credentials.exists() or credentials.is_symlink():
        if (
            not safe(str(credentials), True)
            or stat.S_IMODE(credentials.lstat().st_mode) != 0o700
        ):
            raise RuntimeError("unsafe credentials")
        for name in ["enrollment.token", "server.token"]:
            token = credentials / name
            if (token.exists() or token.is_symlink()) and (
                not safe(str(token)) or stat.S_IMODE(token.lstat().st_mode) != 0o600
            ):
                raise RuntimeError("unsafe credential")
    checker = pathlib.Path("/usr/local/libexec/orbit-node-worker-check")
    if checker.exists() or checker.is_symlink():
        safe(str(checker))
print(
    json.dumps(
        {
            "ready": True,
            "worker_present": worker,
            "observer_present": observer,
            "artifacts_present": observer or binary or directory or worker,
        }
    )
)
