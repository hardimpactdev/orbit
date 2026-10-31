import json
import os
import pathlib
import stat
import tempfile

v = json.loads(os.environ["ORBIT_SERVICE_FILE"])
root = pathlib.Path("/")
p = root / v["path"].lstrip("/")
base = root / "etc/orbit/service-metrics"
marker = base / ".orbit-owner"
for target in [p, marker]:
    for parent in [
        *reversed([q for q in target.parents if q == root or root in q.parents]),
        target,
    ]:
        try:
            st = parent.lstat()
        except FileNotFoundError:
            continue
        if (
            stat.S_ISLNK(st.st_mode)
            or st.st_uid != 0
            or st.st_mode & 0o022
            or (parent != target and not stat.S_ISDIR(st.st_mode))
        ):
            raise SystemExit("metrics.service_ownership")
if base.exists() and (
    base.stat().st_mode & 0o077
    or not marker.is_file()
    or marker.read_text() != "# Managed by Orbit: service-metrics\n"
):
    raise SystemExit("metrics.service_ownership")
if p.exists() and (
    not p.is_file()
    or p.stat().st_size > 65536
    or not p.read_text().startswith("# Managed by Orbit: service-metrics\n")
):
    raise SystemExit("metrics.service_ownership")
if v["content"] is None:
    p.unlink(missing_ok=True)
else:
    if not v["content"].startswith("# Managed by Orbit: service-metrics\n"):
        raise SystemExit("metrics.service_invalid")
    base.mkdir(parents=True, mode=0o700, exist_ok=True)
    if not marker.exists():
        fd = os.open(
            marker, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600
        )
        with os.fdopen(fd, "w") as out:
            out.write("# Managed by Orbit: service-metrics\n")
    fd, temporary = tempfile.mkstemp(prefix=".orbit-fpm-", dir=p.parent)
    try:
        with os.fdopen(fd, "w") as out:
            out.write(v["content"])
            os.fchmod(out.fileno(), v["mode"])
            out.flush()
            os.fsync(out.fileno())
        os.replace(temporary, p)
    finally:
        pathlib.Path(temporary).unlink(missing_ok=True)
print('{"published":true}')
