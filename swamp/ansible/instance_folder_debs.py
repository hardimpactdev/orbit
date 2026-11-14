"""Gather the libraries and fonts of a shared folder's Debian package parts into the folder's `lib` and `fonts`.

docs/reference/development-containers.md#browser-libraries-and-fonts. Each entry is a relative link into its part, so it stays
valid when the staging folder is renamed to its version. Two parts that give the same name fail: one would hide the other.

A part with a `trust_module` gives the folder only that one module, linked at its own path outside `lib`, and nothing in `lib` or
`fonts`: the dynamic linker never sees its name (docs/reference/development-containers.md#trust). Beside it, `requires` names the
library package and the exact version the module needs, as `<package> <version>`, so an agent in a container can compare it with
its own.
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
    modules = {}
    for part in parts:
        name = part["name"]
        if "trust_module" in part:
            module = part["trust_module"]
            if not (stage / name / module["source"]).is_file():
                print(json.dumps({"error_code": "instance.host_unavailable", "missing": f"{name}/{module['source']}"}))
                return 1
            target = Path(module["path"])
            modules[target] = (Path(*[".."] * (len(target.parts) - 1), name, module["source"]), f"{module['library']} {part['version']}\n")
            continue
        libraries = stage / name / LIBRARIES
        if libraries.is_dir():
            for entry in sorted(libraries.iterdir()):
                links["lib"].setdefault(entry.name, []).append(Path("..", name, LIBRARIES, entry.name))
        fonts = stage / name / FONTS
        if fonts.is_dir():
            for root, _, files in os.walk(fonts):
                for file in sorted(files):
                    if Path(file).suffix.lower() in FONT_SUFFIXES:
                        relative = (Path(root) / file).relative_to(stage)
                        links["fonts"].setdefault(file, []).append(Path("..", relative))
    clashes = sorted(f"{kind}/{name}" for kind, names in links.items() for name, sources in names.items() if len(sources) > 1)
    if clashes:
        print(json.dumps({"error_code": "instance.host_unavailable", "clashes": clashes}))
        return 1
    for kind, names in links.items():
        (stage / kind).mkdir(exist_ok=True)
        for name, (source,) in names.items():
            (stage / kind / name).symlink_to(source)
    for target, (source, requires) in modules.items():
        (stage / target).parent.mkdir(parents=True, exist_ok=True)
        (stage / target).symlink_to(source)
        (stage / target).with_name("requires").write_text(requires)
    print(json.dumps({"lib": len(links["lib"]), "fonts": len(links["fonts"]), "modules": sorted(str(target) for target in modules)}))
    return 0


if __name__ == "__main__":
    sys.exit(gather(sys.argv[1], json.loads(sys.argv[2])))
