"""Combine the tested platform artifacts, refusing mixed sources or altered bytes."""

import hashlib
import json
from pathlib import Path
import shutil
import sys

from check_agent_release import PLATFORMS


def combine(destination, sources):
    artifacts = {}
    files = {}
    identity = None
    for directory in sources:
        manifest = json.loads((directory / "manifest.json").read_text())
        current = (manifest["commit"], manifest["version"])
        if identity is not None and current != identity:
            raise ValueError("The platform artifacts name different source commits or versions.")
        identity = current
        for platform, artifact in manifest["artifacts"].items():
            if platform not in PLATFORMS or platform in artifacts:
                raise ValueError("An unexpected or duplicate platform was supplied.")
            name = f"orbit-agent-{platform}"
            if artifact["path"] != name:
                raise ValueError("An artifact must use its fixed platform filename.")
            path = directory / name
            if path.is_symlink() or hashlib.sha256(path.read_bytes()).hexdigest() != artifact["sha256"]:
                raise ValueError("An artifact does not match its tested digest.")
            artifacts[platform] = {"path": name, "sha256": artifact["sha256"]}
            files[platform] = path
    if set(artifacts) != PLATFORMS:
        raise ValueError("The delivery requires every platform.")
    # Validate every input before publishing any part of the combined delivery.
    destination.mkdir(parents=True, exist_ok=False)
    for platform, path in files.items():
        shutil.copy2(path, destination / artifacts[platform]["path"])
    (destination / "SHA256SUMS").write_text("".join(
        f"{artifact['sha256']}  {artifact['path']}\n" for artifact in artifacts.values()
    ))
    (destination / "manifest.json").write_text(json.dumps({
        "commit": identity[0], "version": identity[1], "artifacts": artifacts,
    }, indent=2) + "\n")


if __name__ == "__main__":
    if len(sys.argv) < 4:
        sys.exit("Usage: combine_agent_delivery.py DESTINATION SOURCE SOURCE [...]")
    try:
        combine(Path(sys.argv[1]), [Path(path) for path in sys.argv[2:]])
    except (ValueError, KeyError, TypeError, OSError) as error:
        sys.exit(f"Cannot combine agent delivery: {error}")
