"""Observe one owned checkout profile and its initial analysis base; no network."""

import base64
import json
import os
import stat
import subprocess
import sys
from pathlib import Path

from repository_common import checkout, git


def observe(value):
    root = Path(checkout(value))
    profile = {"kind": "none", "composer": None, "entry_point": None}
    if not value["skip_profile"]:
        composer = root / "composer.json"
        entry = root / value["entry_point"]
        profile = {"kind": "none", "composer": None, "entry_point": None}
        if composer.exists() or composer.is_symlink():
            details = composer.lstat()
            if (
                not stat.S_ISREG(details.st_mode)
                or details.st_uid != os.getuid()
                or details.st_size > 1048576
            ):
                profile["kind"] = "unsafe"
            else:
                entry_kind = "absent"
                if entry.exists() or entry.is_symlink():
                    entry_kind = (
                        "regular"
                        if entry.is_file()
                        and not entry.is_symlink()
                        and entry.parent.resolve() == entry.parent
                        else "unsafe"
                    )
                metadata = json.loads(composer.read_bytes())
                if isinstance(metadata, dict):
                    selected = {}
                    for section, keys in (
                        (
                            "require",
                            ("php", "laravel/framework", "symfony/framework-bundle"),
                        ),
                        ("require-dev", ("laravel/framework",)),
                    ):
                        packages = metadata.get(section)
                        if isinstance(packages, dict):
                            selected[section] = {
                                key: packages[key] for key in keys if key in packages
                            }
                    metadata = selected
                profile = {
                    "kind": "composer",
                    "composer": base64.b64encode(
                        json.dumps(metadata).encode()
                    ).decode(),
                    "entry_point": entry_kind,
                }
        elif entry.exists() or entry.is_symlink():
            profile["kind"] = "partial"
    commit = git(root, "rev-parse", "HEAD")
    try:
        base = git(root, "merge-base", f"refs/remotes/origin/{value['default_branch']}", commit)
    except subprocess.CalledProcessError:
        base = None
    return {
        "branch": git(root, "symbolic-ref", "--short", "HEAD"),
        "commit": commit,
        "analysis_base": base,
        "profile": profile,
    }


if __name__ == "__main__":
    print(json.dumps(observe(json.load(sys.stdin))), flush=True)
