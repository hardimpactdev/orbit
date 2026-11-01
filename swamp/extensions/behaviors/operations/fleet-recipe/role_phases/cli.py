"""Use the existing CLI install policy in one bounded native phase."""

import json
import subprocess
import sys

from phase_common import DIRECTORY, inputs

if __name__ == "__main__":
    cli = inputs()["intent"]["cli"]
    subprocess.run(
        [sys.executable, "-B", str(DIRECTORY / "canonical_cli.py")],
        input=json.dumps({key: cli[key] for key in ["user", "release", "profile"]}),
        text=True,
        check=True,
    )
