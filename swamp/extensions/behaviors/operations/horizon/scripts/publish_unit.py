import hashlib
import os
import pathlib

candidate = pathlib.Path(os.environ["CANDIDATE"])
target = pathlib.Path("/etc/systemd/system") / os.environ["UNIT"]
try:
    fd = os.open(target, os.O_RDONLY)
except FileNotFoundError:
    fd = None
if fd is not None:
    with os.fdopen(fd, "rb") as current:
        content = current.read(65537)
    if hashlib.sha256(content).hexdigest() == os.environ["EXPECTED_HASH"]:
        print("unchanged")
        raise SystemExit(0)
fd = os.open(candidate, os.O_RDONLY)
with os.fdopen(fd, "rb") as source:
    content = source.read(65537)
if hashlib.sha256(content).hexdigest() != os.environ["EXPECTED_HASH"]:
    raise SystemExit("process.horizon_invalid_candidate")
os.replace(candidate, target)
print("published")
