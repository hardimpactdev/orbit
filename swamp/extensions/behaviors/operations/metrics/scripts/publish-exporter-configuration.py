import json
import os
import pathlib
import stat
import tempfile

v = json.loads(os.environ["ORBIT_EXPORTER_INTENT"])
root = pathlib.Path("/")
p = root / v["path"].lstrip("/")
for parent in [*reversed([q for q in p.parents if q == root or root in q.parents]), p]:
    try:
        st = parent.lstat()
    except FileNotFoundError:
        continue
    if (
        stat.S_ISLNK(st.st_mode)
        or st.st_uid != 0
        or st.st_mode & 0o022
        or (parent != p and not stat.S_ISDIR(st.st_mode))
    ):
        raise SystemExit("metrics.exporter_configuration_ownership_drift")
if p.exists() and (
    not p.is_file()
    or p.stat().st_size > 65536
    or not p.read_text().startswith(v["marker"])
):
    raise SystemExit("metrics.exporter_configuration_ownership_drift")
if v["selected"]:
    p.parent.mkdir(mode=0o755, parents=True, exist_ok=True)
    fd, temporary = tempfile.mkstemp(prefix=".orbit-metrics-", dir=p.parent)
    try:
        with os.fdopen(fd, "w") as stream:
            stream.write(v["configuration"])
            os.fchmod(stream.fileno(), 0o644)
            stream.flush()
            os.fsync(stream.fileno())
        os.replace(temporary, p)
    finally:
        pathlib.Path(temporary).unlink(missing_ok=True)
else:
    p.unlink(missing_ok=True)
print('{"configured":true}')
