import json
import pathlib
import stat

root = pathlib.Path("/")
base = root / "etc/orbit/service-metrics"
marker = base / ".orbit-owner"
paths = [
    base / "fpm.yml",
    root / "etc/systemd/system/orbit-fpm-exporter.service",
    marker,
]
state = {}
for p in paths:
    for parent in [
        *reversed([q for q in p.parents if q == root or root in q.parents]),
        p,
    ]:
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
            raise SystemExit("metrics.service_ownership")
    if p.exists():
        if (
            not p.is_file()
            or p.stat().st_size > 65536
            or not p.read_text().startswith("# Managed by Orbit: service-metrics\n")
        ):
            raise SystemExit("metrics.service_ownership")
        state[str(p.relative_to(root))] = p.read_text()
    else:
        state[str(p.relative_to(root))] = None
if base.exists() and (base.stat().st_mode & 0o077 or not marker.exists()):
    raise SystemExit("metrics.service_ownership")
print(json.dumps({"files": state}))
