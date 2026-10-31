import hashlib
import os
import pathlib
import stat
import tempfile

root = pathlib.Path("/")
op = os.environ["ORBIT_OPERATION_ID"]
if not op.isdecimal():
    raise SystemExit("metrics.input_invalid")
source = root / ("run/orbit-metrics-fpm-" + op)
path = root / "usr/local/bin/orbit-fpm-exporter"
for p in [*reversed([q for q in path.parents if q == root or root in q.parents]), path]:
    try:
        st = p.lstat()
    except FileNotFoundError:
        continue
    if (
        stat.S_ISLNK(st.st_mode)
        or st.st_uid != 0
        or st.st_mode & 0o022
        or (p != path and not stat.S_ISDIR(st.st_mode))
    ):
        raise SystemExit("metrics.service_binary_ownership_drift")
if path.exists():
    if not path.is_file():
        raise SystemExit("metrics.service_binary_ownership_drift")
    with path.open("rb") as existing:
        if (
            hashlib.file_digest(existing, "sha256").hexdigest()
            != "570d2b3d3284179c47acff8efd8f73ea650e42a2c6bd08c0a8e95cb4b0e3ca98"
        ):
            raise SystemExit("metrics.service_binary_ownership_drift")
data = source.read_bytes()
if (
    hashlib.sha256(data).hexdigest()
    != "570d2b3d3284179c47acff8efd8f73ea650e42a2c6bd08c0a8e95cb4b0e3ca98"
):
    raise SystemExit("metrics.service_checksum_mismatch")
fd, temporary = tempfile.mkstemp(prefix=".orbit-fpm-", dir=path.parent)
try:
    with os.fdopen(fd, "wb") as stream:
        stream.write(data)
        os.fchmod(stream.fileno(), 0o755)
        stream.flush()
        os.fsync(stream.fileno())
    os.replace(temporary, path)
finally:
    pathlib.Path(temporary).unlink(missing_ok=True)
print('{"installed":true}')
