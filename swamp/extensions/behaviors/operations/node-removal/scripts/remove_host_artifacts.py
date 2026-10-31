import pathlib
import shutil
import stat

for value in [
    "/etc/systemd/system/orbit-agent.service",
    "/usr/local/bin/orbit-agent",
    "/etc/systemd/system/orbit-node-worker.service",
    "/usr/local/libexec/orbit-node-worker-check",
]:
    p = pathlib.Path(value)
    try:
        s = p.lstat()
    except FileNotFoundError:
        continue
    if (
        stat.S_ISLNK(s.st_mode)
        or s.st_uid != 0
        or not stat.S_ISREG(s.st_mode)
        or s.st_mode & 0o022
    ):
        raise RuntimeError("unsafe artifact")
for value in ["/etc/orbit/agent", "/etc/orbit/swamp-worker"]:
    p = pathlib.Path(value)
    if p.exists() or p.is_symlink():
        s = p.lstat()
        if (
            stat.S_ISLNK(s.st_mode)
            or s.st_uid != 0
            or not stat.S_ISDIR(s.st_mode)
            or s.st_mode & 0o022
        ):
            raise RuntimeError("unsafe directory")
for value in [
    "/etc/systemd/system/orbit-agent.service",
    "/usr/local/bin/orbit-agent",
    "/etc/systemd/system/orbit-node-worker.service",
    "/usr/local/libexec/orbit-node-worker-check",
]:
    pathlib.Path(value).unlink(missing_ok=True)
for value in [
    "/etc/orbit/agent",
    "/etc/orbit/swamp-worker",
    "/var/lib/orbit-node-worker",
]:
    p = pathlib.Path(value)
    if p.exists():
        shutil.rmtree(p)
