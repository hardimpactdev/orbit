"""Write one policy-selected manifest, refusing changes outside its inspected or desired value."""

import json
import os
import sys
import tempfile
from pathlib import Path

from workspace_files import inspect

if __name__ == "__main__":
    value = json.load(sys.stdin)
    observed = inspect(value)
    patch = json.loads(sys.argv[1])
    filename = "composer.json" if value["manager"] == "composer" else "package.json"
    current = json.loads(observed["files"][filename])
    if current != patch["after"]:
        if current != patch["before"]:
            raise ValueError("Maintenance manifest changed after inspection")
        path = Path(observed["directory"], filename)
        descriptor, name = tempfile.mkstemp(
            prefix=".orbit-maintenance-", dir=path.parent
        )
        try:
            with os.fdopen(descriptor, "w") as stream:
                json.dump(patch["after"], stream, indent=2, ensure_ascii=False)
                stream.write("\n")
            os.replace(name, path)
        finally:
            Path(name).unlink(missing_ok=True)
    print('{"written":true}', flush=True)
