"""Remove only the fixed retired host artifacts after both services are inactive."""

import json
from pathlib import Path

from ssh_common import execute

execute(
    "sudo -n /usr/bin/python3 -",
    program=Path(__file__).with_name("remove_host_artifacts.py").read_bytes(),
)
print(json.dumps({"ready": True}))
