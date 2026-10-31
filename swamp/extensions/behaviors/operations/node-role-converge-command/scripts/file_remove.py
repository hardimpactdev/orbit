"""Remove one admitted managed role file; service control stays separate."""

import json
import sys
from pathlib import Path

ROLE_INPUT = None


def remove(data):
    path = Path(data["path"])
    if path.is_symlink() or (path.exists() and not path.is_file()):
        raise ValueError("Metrics file is not regular")
    if not path.exists():
        return {"ready": True, "changed": False}
    if data["marker"] and not path.read_text().startswith(data["marker"]):
        raise ValueError("Role file is not managed")
    path.unlink()
    return {"ready": True, "changed": True}


if __name__ == "__main__":
    try:
        print(json.dumps(remove(ROLE_INPUT)))
    except (ValueError, OSError):
        print('{"error_code":"role.file_remove_failed"}')
        sys.exit(1)
