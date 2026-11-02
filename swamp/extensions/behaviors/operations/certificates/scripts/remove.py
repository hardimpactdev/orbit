"""Remove the exact admitted scope after its users have been withdrawn."""

import json
import shutil
import subprocess
from pathlib import Path

from local import account, emit


def remove(scope):
    if scope.get("in_use") is not False:
        raise ValueError("certificate.in_use")
    _, root, caddy = account(scope)
    # The parent reloads its replacement Caddy configuration before this step.
    # Read that disk configuration now, rather than an admission-time scope list.
    result = subprocess.run(
        [
            "caddy",
            "adapt",
            "--config",
            "/etc/caddy/Caddyfile",
            "--adapter",
            "caddyfile",
        ],
        check=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.DEVNULL,
    )
    certificates = (
        json.loads(result.stdout).get("apps", {}).get("tls", {}).get("certificates", {})
    )
    for pair in certificates.get("load_files", []):
        for filename in (pair.get("certificate"), pair.get("key")):
            if filename and str(caddy) + "/" in filename:
                raise ValueError("certificate.in_use")
    for folder in certificates.get("load_folders", []):
        folder = Path(folder)
        if caddy.is_relative_to(folder) or folder.is_relative_to(caddy):
            raise ValueError("certificate.in_use")
    for path in (caddy, root):
        if path.exists():
            shutil.rmtree(path)
    return {"applied": True}


if __name__ == "__main__":
    emit(remove)
