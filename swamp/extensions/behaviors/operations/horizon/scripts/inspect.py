import hashlib
import json
import os
import subprocess

unit = os.environ["UNIT"]
path = "/etc/systemd/system/" + unit
present, matches = False, False
try:
    fd = os.open(path, os.O_RDONLY)
except FileNotFoundError:
    fd = None
if fd is not None:
    with os.fdopen(fd, "rb") as source:
        content = source.read(65537)
    if (
        "X-Orbit-Process-ID=" + os.environ["PROCESS_ID"]
    ).encode() not in content.splitlines():
        raise SystemExit("process.horizon_foreign_unit")
    present = True
    matches = hashlib.sha256(content).hexdigest() == os.environ["EXPECTED_HASH"]
state = subprocess.run(
    ["/usr/bin/systemctl", "is-active", unit],
    capture_output=True,
    text=True,
    check=False,
).stdout.strip()
print(json.dumps({"present": present, "matches": matches, "active": state == "active"}))
