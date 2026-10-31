import hashlib
import os
import pathlib
import tempfile

content = os.environ["UNIT_CONTENT"].encode()
if (
    len(content) > 65536
    or hashlib.sha256(content).hexdigest() != os.environ["EXPECTED_HASH"]
):
    raise SystemExit("process.horizon_unit_changed")
path = pathlib.Path(os.environ["CANDIDATE"])
parent = path.parent
parent.mkdir(mode=0o755, parents=True, exist_ok=True)
fd, name = tempfile.mkstemp(prefix=".horizon-", suffix=".service", dir=parent)
try:
    with os.fdopen(fd, "wb") as output:
        output.write(content)
        output.flush()
        os.fchmod(output.fileno(), 0o644)
        os.fsync(output.fileno())
    os.replace(name, path)
finally:
    if os.path.exists(name):
        os.unlink(name)
print("staged")
