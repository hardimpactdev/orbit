import json
import os
import pathlib
import subprocess

v = json.loads(os.environ["ORBIT_SERVICE_INTENT"])
root = pathlib.Path("/")
for path, key in [
    ("etc/orbit/service-metrics/fpm.yml", "config"),
    ("etc/systemd/system/orbit-fpm-exporter.service", "unit"),
]:
    p = root / path
    content = p.read_text() if p.exists() else None
    if content != v[key]:
        raise SystemExit("metrics.service_unverified")
for property_name, key in [("is-active", "active"), ("is-enabled", "enabled")]:
    reply = subprocess.run(
        ["/usr/bin/systemctl", property_name, "orbit-fpm-exporter"],
        capture_output=True,
        text=True,
        check=False,
    )
    if key == "active":
        if not (
            (reply.returncode == 0 and reply.stdout.strip() == "active")
            or (
                reply.returncode != 0
                and reply.stdout.strip() in ("inactive", "failed", "unknown")
            )
        ):
            raise SystemExit("metrics.service_unverified")
    else:
        if not (
            (reply.returncode == 0 and reply.stdout.strip() == "enabled")
            or (
                reply.returncode != 0
                and reply.stdout.strip() in ("disabled", "masked", "not-found")
            )
        ):
            raise SystemExit("metrics.service_unverified")
    if (reply.returncode == 0) != v[key]:
        raise SystemExit("metrics.service_unverified")
print('{"verified":true}')
