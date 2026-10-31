import json
import os
import pathlib
import stat

v = json.loads(os.environ["ORBIT_EXPORTER_INTENT"])
root = pathlib.Path("/")
p = root / v["path"].lstrip("/")
for parent in [*reversed([q for q in p.parents if q == root or root in q.parents]), p]:
    try:
        st = parent.lstat()
    except FileNotFoundError:
        continue
    if stat.S_ISLNK(st.st_mode) or st.st_uid != 0 or st.st_mode & 0o022:
        raise SystemExit("metrics.exporter_configuration_ownership_drift")
    if parent != p and not stat.S_ISDIR(st.st_mode):
        raise SystemExit("metrics.exporter_configuration_ownership_drift")
content = None
if p.exists():
    if not p.is_file() or p.stat().st_size > 65536:
        raise SystemExit("metrics.exporter_configuration_ownership_drift")
    content = p.read_text()
    if not content.startswith(v["marker"]):
        raise SystemExit("metrics.exporter_configuration_ownership_drift")
print(json.dumps({"configuration": content}))
