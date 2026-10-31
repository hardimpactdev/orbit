import hashlib
import os
import pathlib
import stat
import tempfile

root = pathlib.Path("/")
op = os.environ["ORBIT_OPERATION_ID"]
if not op.isdecimal():
    raise SystemExit("metrics.input_invalid")
source = root / ("run/orbit-metrics-cadvisor-" + op)
path = root / "usr/local/bin/orbit-cadvisor"
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
        raise SystemExit("metrics.cadvisor_binary_ownership_drift")
if path.exists():
    if not path.is_file():
        raise SystemExit("metrics.cadvisor_binary_ownership_drift")
    with path.open("rb") as existing:
        if (
            hashlib.file_digest(existing, "sha256").hexdigest()
            != "6f258bed28113218c88729634c47ead8d928b81b1504b75fd911b98d29003a87"
        ):
            raise SystemExit("metrics.cadvisor_binary_ownership_drift")
data = source.read_bytes()
if (
    hashlib.sha256(data).hexdigest()
    != "6f258bed28113218c88729634c47ead8d928b81b1504b75fd911b98d29003a87"
):
    raise SystemExit("metrics.cadvisor_checksum_mismatch")
fd, temporary = tempfile.mkstemp(prefix=".orbit-cadvisor-", dir=path.parent)
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
