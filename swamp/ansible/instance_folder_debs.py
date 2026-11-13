"""Gather the libraries and fonts of a shared folder's Debian package parts into the folder's `lib` and `fonts`.

docs/reference/development-containers.md#browser-libraries-and-fonts. Each entry is a relative link into its part, so it stays
valid when the staging folder is renamed to its version. Two parts that give the same name fail: one would hide the other.
"""

import json
import os
import sys
from pathlib import Path

LIBRARIES = Path("usr/lib/x86_64-linux-gnu")
FONTS = Path("usr/share/fonts")
FONT_SUFFIXES = {".ttf", ".otf", ".ttc"}


def gather(stage, parts):
    stage = Path(stage)
    links = {"lib": {}, "fonts": {}}
    for part in parts:
        libraries = stage / part / LIBRARIES
        if libraries.is_dir():
            for entry in sorted(libraries.iterdir()):
                links["lib"].setdefault(entry.name, []).append(Path("..", part, LIBRARIES, entry.name))
        fonts = stage / part / FONTS
        if fonts.is_dir():
            for root, _, files in os.walk(fonts):
                for name in sorted(files):
                    if Path(name).suffix.lower() in FONT_SUFFIXES:
                        relative = (Path(root) / name).relative_to(stage)
                        links["fonts"].setdefault(name, []).append(Path("..", relative))
    clashes = sorted(f"{kind}/{name}" for kind, names in links.items() for name, sources in names.items() if len(sources) > 1)
    if clashes:
        print(json.dumps({"error_code": "instance.host_unavailable", "clashes": clashes}))
        return 1
    for kind, names in links.items():
        (stage / kind).mkdir(exist_ok=True)
        for name, (source,) in names.items():
            (stage / kind / name).symlink_to(source)
    print(json.dumps({"lib": len(links["lib"]), "fonts": len(links["fonts"])}))
    return 0


if __name__ == "__main__":
    sys.exit(gather(sys.argv[1], sys.argv[2:]))
