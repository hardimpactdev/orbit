import json
import os
import pathlib
import stat
import tempfile

v = json.loads(os.environ["ORBIT_RUNTIME_FILE"])
root = pathlib.Path("/")
p = root / v["path"].lstrip("/")
base = root / "etc/orbit/metrics"
marker = base / ".orbit-owner"
for parent in [
    *reversed([q for q in p.parents if q == root or root in q.parents]),
    p,
    marker,
]:
    try:
        st = parent.lstat()
    except FileNotFoundError:
        continue
    if (
        stat.S_ISLNK(st.st_mode)
        or st.st_uid != 0
        or st.st_mode & 0o022
        or (parent != p and parent != marker and not stat.S_ISDIR(st.st_mode))
    ):
        raise SystemExit("metrics.configuration_ownership_drift")
if base.exists() and (not marker.is_file() or marker.read_bytes() != b"metrics\n"):
    raise SystemExit("metrics.configuration_ownership_drift")
p.parent.mkdir(parents=True, exist_ok=True)
# The marker is part of publishing the owned configuration tree, not a run ledger.
if not marker.exists():
    fd = os.open(marker, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o640)
    with os.fdopen(fd, "wb") as out:
        out.write(b"metrics\n")
fd, temporary = tempfile.mkstemp(prefix=".orbit-metrics-", dir=p.parent)
try:
    with os.fdopen(fd, "w") as out:
        out.write(v["content"])
        os.fchmod(out.fileno(), 0o644)
        out.flush()
        os.fsync(out.fileno())
    os.replace(temporary, p)
finally:
    pathlib.Path(temporary).unlink(missing_ok=True)
print('{"published":true}')
