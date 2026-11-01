"""Remove one role pair after its replacement native Caddy publication has completed."""

import json
import shutil
import subprocess
from pathlib import Path

from role_paths import role_directory

REMOVAL_INPUT = None


def remove(data):
    current = role_directory(data["scope"])
    if not current.exists() and not current.is_symlink():
        return {"removed": True}
    config = subprocess.run(["/usr/bin/caddy", "adapt", "--config", "/etc/caddy/Caddyfile", "--adapter", "caddyfile"],
                            capture_output=True, check=True).stdout
    tls = json.loads(config).get("apps", {}).get("tls", {}).get("certificates", {})
    for pair in tls.get("load_files", []):
        if any(str(current) + "/" in str(pair.get(name, "")) for name in ("certificate", "key")):
            raise ValueError("certificate.in_use")
    for folder in tls.get("load_folders", []):
        if current.is_relative_to(Path(folder)):
            raise ValueError("certificate.in_use")
    if current.is_symlink():
        current.unlink()
    else:
        shutil.rmtree(current)
    return {"removed": True}


if __name__ == "__main__":
    print(json.dumps(remove(REMOVAL_INPUT)))
