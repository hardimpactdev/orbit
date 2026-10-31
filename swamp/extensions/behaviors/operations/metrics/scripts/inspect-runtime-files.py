import pathlib
import stat

root = pathlib.Path("/")
base = root / "etc/orbit/metrics"
marker = base / ".orbit-owner"
paths = [
    base / "prometheus.yml",
    base / "grafana/grafana.ini",
    base / "grafana/provisioning/datasources/prometheus.yml",
    base / "grafana/provisioning/dashboards/provider.yml",
    base / "grafana/dashboards/orbit-node-resources.json",
    base / "grafana/dashboards/orbit-caddy.json",
    base / "grafana/dashboards/orbit-fpm.json",
    marker,
]
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
            or (parent == p and not stat.S_ISREG(st.st_mode))
        ):
            raise SystemExit("metrics.configuration_ownership_drift")
if base.exists() and (not marker.is_file() or marker.read_bytes() != b"metrics\n"):
    raise SystemExit("metrics.configuration_ownership_drift")
p = base / "grafana/admin-password"
if p.exists() or p.is_symlink():
    st = p.lstat()
    if (
        not stat.S_ISREG(st.st_mode)
        or st.st_uid not in (0, 472)
        or st.st_mode & 0o077
        or st.st_mode & 0o022
    ):
        raise SystemExit("metrics.configuration_ownership_drift")
print('{"owned":true}')
