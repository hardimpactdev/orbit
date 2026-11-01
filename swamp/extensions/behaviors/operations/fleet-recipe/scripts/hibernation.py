import os
import stat
from pathlib import Path

PROTECTED = {
    "/dev",
    "/dev/shm",
    "/etc",
    "/proc",
    "/run",
    "/sys",
    "/tmp",
    "/var",
    "/var/tmp",
}


def main(data):
    try:
        paths = [data["marker_directory"], data["log_directory"]]
        for path in paths:
            if (
                not os.path.isabs(path)
                or path == "/"
                or path in PROTECTED
                or any(part in (".", "..") for part in path.split("/"))
            ):
                raise ValueError()
            for current in [Path(path), *Path(path).parents]:
                if os.path.lexists(current) and not stat.S_ISDIR(
                    os.lstat(current).st_mode
                ):
                    raise ValueError()
        if os.path.commonpath(paths) in paths:
            raise ValueError()
        owner = data["owner"]
        for directory, mode in zip(paths, [0o755, 0o2775]):
            ancestors = []
            for parent in Path(directory).parents:
                if str(parent) == "/" or str(parent) in PROTECTED:
                    break
                ancestors.append(str(parent))
            for path in reversed(ancestors):
                if not os.path.isdir(path):
                    os.mkdir(path, 0o755)
                os.chmod(path, 0o755)
            if not os.path.isdir(directory):
                os.mkdir(directory, 0o755)
            os.chown(directory, *owner)
            os.chmod(directory, mode)
        return {}
    except (KeyError, ValueError, OSError):
        return {"error_code": "hibernation.node_failed"}
