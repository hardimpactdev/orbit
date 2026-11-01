"""Read only the public PHP constraint and framework declarations."""
import base64
import json
from pathlib import Path

SOURCE_INPUT = None


def profile(value):
    if value.get("skip_profile") or value["project_type"] == "monorepo":
        return {"kind": "none"}
    app = Path(value["application"])
    composer = app / "composer.json"
    entry = app / (value.get("entry_point") or "artisan")
    if not composer.exists() and not composer.is_symlink():
        return {"kind": "partial" if entry.exists() else "none"}
    if composer.is_symlink() or not composer.is_file() or composer.stat().st_size > 1048576:
        return {"kind": "unsafe"}
    source = json.loads(composer.read_text())
    public = {}
    for section in ("require", "require-dev"):
        row = source.get(section, {})
        if not isinstance(row, dict):
            raise TypeError("app-dev.php_version_unsupported")
        public[section] = {key: row[key] for key in ("php", "laravel/framework", "symfony/framework-bundle") if key in row}
    return {"kind": "composer", "composer": base64.b64encode(json.dumps(public).encode()).decode(),
            "entry_point": "unsafe" if entry.is_symlink() else "regular" if entry.is_file() else "absent"}


if __name__ == "__main__":
    print(json.dumps(profile(SOURCE_INPUT)))
