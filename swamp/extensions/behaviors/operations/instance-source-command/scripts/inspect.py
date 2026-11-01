"""Read filesystem metadata without source execution or returning private bytes."""
import base64
import hashlib
import json
import os
import stat
from pathlib import Path

SOURCE_INPUT = None


def inspect(value):
    root = Path(value["checkout"])
    if not root.is_dir() or root.is_symlink() or root.resolve() != root:
        raise ValueError("instance.source_invalid")
    info = root.stat()
    if info.st_uid != os.getuid() or info.st_mode & 0o7002:
        raise ValueError("instance.source_owner_invalid")
    dotgit = root / ".git"
    if not dotgit.is_dir() or dotgit.is_symlink():
        raise ValueError("instance.registration_conflict")
    for lock in ("index.lock", "HEAD.lock", "config.lock", "shallow.lock"):
        if (dotgit / lock).exists():
            raise ValueError("instance.source_busy")
    result = {"digest": None, "profile": {"kind": "none"}}
    if value["kind"] == "instance.register":
        digest = hashlib.sha256()
        for directory, dirs, files in os.walk(root, followlinks=False):
            if Path(directory) == root:
                dirs.remove(".git")
            dirs.sort()
            for name in sorted(dirs + files):
                path = Path(directory) / name
                mode = path.lstat().st_mode
                digest.update(str(path.relative_to(root)).encode() + b"\0")
                digest.update(str(stat.S_IMODE(mode)).encode() + b"\0")
                if path.is_symlink():
                    digest.update(b"L" + os.readlink(path).encode())
                elif path.is_file():
                    with path.open("rb") as stream:
                        for chunk in iter(lambda: stream.read(1048576), b""):
                            digest.update(chunk)
                elif not path.is_dir():
                    raise ValueError("instance.source_invalid")
        result["digest"] = digest.hexdigest()
        if value["project_type"] != "monorepo":
            app = Path(value["application"])
            if app != root and root not in app.parents:
                raise ValueError("instance.source_invalid")
            composer = app / "composer.json"
            if composer.is_symlink():
                raise ValueError("app-dev.source_metadata_unsafe")
            if composer.exists():
                if not composer.is_file() or composer.stat().st_size > 1048576:
                    raise ValueError("app-dev.source_metadata_unsafe")
                source = json.loads(composer.read_text())
                entry = app / "artisan"
                public = {}
                for section in ("require", "require-dev"):
                    row = source.get(section, {})
                    if not isinstance(row, dict):
                        raise TypeError("app-dev.php_version_unsupported")
                    public[section] = {key: row[key] for key in ("php", "laravel/framework") if key in row}
                result["profile"] = {"kind": "composer",
                                     "composer": base64.b64encode(json.dumps(public).encode()).decode(),
                                     "entry_point": "unsafe" if entry.is_symlink() else "regular" if entry.is_file() else "absent"}
    return result


if __name__ == "__main__":
    print(json.dumps(inspect(SOURCE_INPUT)))
