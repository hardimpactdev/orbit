"""Capture a root-private rollback snapshot before publishing Schedule artifacts."""

import json
import os
import shutil
import stat

from schedule_common import atomic, intent, paths, report, staging


def backup():
    value = intent()
    root = staging(value)
    root.mkdir(mode=0o700, parents=True, exist_ok=True)
    if (root / "snapshot.json").exists():
        return
    saved = {"timer": json.loads(os.environ["ORBIT_SCHEDULE_OBSERVATION"]), "files": {}}
    for name, path in paths(value).items():
        if path.exists():
            info = path.stat()
            shutil.copy2(path, root / ("previous-" + name))
            saved["files"][name] = {
                "uid": info.st_uid,
                "gid": info.st_gid,
                "mode": stat.S_IMODE(info.st_mode),
            }
        else:
            saved["files"][name] = None
    atomic(root / "snapshot.json", json.dumps(saved), mode=0o600)


report(backup)
