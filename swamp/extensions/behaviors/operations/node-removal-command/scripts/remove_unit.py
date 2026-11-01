"""Forget one stopped role-owned unit. Keep its release data and application account."""

import json
import subprocess
from pathlib import Path

REMOVAL_INPUT = None


def remove(data):
    if data["unit"] != "orbit-websocket.service":
        raise ValueError("node.removal_unit_invalid")
    if subprocess.run(["/usr/bin/systemctl", "is-active", "--quiet", data["unit"]], check=False).returncode not in (3, 4):
        raise ValueError("node.removal_service_active")
    path = Path("/etc/systemd/system/orbit-websocket.service")
    if path.exists():
        if path.is_symlink() or not path.read_text().startswith("# Managed by Orbit."):
            raise ValueError("websocket.runtime_ownership_drift")
        path.unlink()
    return {"removed": True}


if __name__ == "__main__":
    print(json.dumps(remove(REMOVAL_INPUT)))
