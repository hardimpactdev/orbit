"""Stage release-bound clone inputs once; keep large files off the SSH command."""

import json
import os
import runpy
import sys
from pathlib import Path


def main():
    folder = Path(sys.argv[1])
    phase = sys.argv[2]
    try:
        if phase == "stage":
            # This dispatch carries public release assets, never vault values.
            payload = json.load(sys.stdin)
            os.umask(0o077)
            folder.mkdir(mode=0o700)
            (folder / "input.json").write_text(json.dumps(payload))
            (folder / "launch.py").write_text(payload["launcher"])
            (folder / "clone.py").write_text(payload["script"])
            print(json.dumps({"phase": phase, "changed": True}))
            return
        else:
            payload = json.loads((folder / "input.json").read_text())
    except (OSError, ValueError, KeyError):
        print("instance.clone_input_failed", file=sys.stderr)
        raise SystemExit(1) from None
    script = folder / "clone.py"
    # This is in-process argv, never an SSH command or a spawned process argument.
    sys.argv = [str(script), json.dumps(payload["container"]), payload["guest"],
                payload["network"], payload["account"], str(folder),
                json.dumps(payload["bundle"]), phase]
    runpy.run_path(str(script), run_name="__main__")


if __name__ == "__main__":
    main()
