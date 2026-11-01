"""Prepare the admitted managed account's Composer scope without installing global Tools."""

import json
import os
import pathlib
import pwd
import subprocess
import sys


def prepare(value):
    account = pwd.getpwnam(value["user"])
    version = subprocess.check_output(
        ["dpkg-query", "-W", "-f=${Version}", "composer"], text=True
    )
    if version != value["version"]:
        raise ValueError("node.tool_version_mismatch")
    root = pathlib.Path("/opt/orbit/composer")
    if root.parent.exists() and (root.parent.is_symlink() or not root.parent.is_dir() or root.parent.stat().st_uid != 0):
        raise ValueError("tool.scope_conflict")
    root.parent.mkdir(parents=True, exist_ok=True)
    for path in (root, root / "vendor", root / "vendor/bin"):
        if path.exists() and (path.is_symlink() or not path.is_dir() or path.stat().st_uid != account.pw_uid):
            raise ValueError("tool.scope_conflict")
        path.mkdir(exist_ok=True)
        os.chown(path, account.pw_uid, account.pw_gid)
        path.chmod(0o755)
    manifest = root / "composer.json"
    if manifest.is_symlink() or manifest.exists() and (not manifest.is_file() or manifest.stat().st_uid != account.pw_uid):
        raise ValueError("tool.scope_conflict")
    if not manifest.exists():
        manifest.write_text('{"require":{}}\n')
        os.chown(manifest, account.pw_uid, account.pw_gid)
        manifest.chmod(0o644)
    return {"composer_ready": True}


if __name__ == "__main__":
    try:
        print(json.dumps(prepare(json.load(sys.stdin))))
    except (ValueError, OSError, KeyError, subprocess.SubprocessError):
        print(json.dumps({"error_code": "node.tool_composer_failed"}))
        sys.exit(1)
