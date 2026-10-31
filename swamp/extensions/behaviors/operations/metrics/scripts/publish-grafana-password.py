import os
import pathlib
import stat
import tempfile

password = os.environ.pop("ORBIT_GRAFANA_PASSWORD")
if not password or len(password.encode()) > 65536 or "\x00" in password:
    raise SystemExit("metrics.credentials_missing")
root = pathlib.Path("/")
p = root / "etc/orbit/metrics/grafana/admin-password"
marker = root / "etc/orbit/metrics/.orbit-owner"
for parent in [*reversed([q for q in p.parents if q == root or root in q.parents]), p]:
    st = parent.lstat() if parent.exists() or parent.is_symlink() else None
    if st is None:
        continue
    if (
        stat.S_ISLNK(st.st_mode)
        or st.st_mode & 0o022
        or (parent != p and (not stat.S_ISDIR(st.st_mode) or st.st_uid != 0))
        or (parent == p and (not stat.S_ISREG(st.st_mode) or st.st_uid not in (0, 472)))
    ):
        raise SystemExit("metrics.configuration_ownership_drift")
if not marker.is_file() or marker.is_symlink() or marker.read_bytes() != b"metrics\n":
    raise SystemExit("metrics.configuration_ownership_drift")
fd, temporary = tempfile.mkstemp(prefix=".orbit-password-", dir=p.parent)
try:
    with os.fdopen(fd, "w") as stream:
        stream.write(password)
        os.fchown(stream.fileno(), 472, 472)
        os.fchmod(stream.fileno(), 0o400)
        stream.flush()
        os.fsync(stream.fileno())
    os.replace(temporary, p)
finally:
    pathlib.Path(temporary).unlink(missing_ok=True)
print('{"published":true}')
