"""Remove one stopped Orbit footprint; retain the account and user data."""

import json
import shutil
import subprocess
from pathlib import Path

REMOVAL_INPUT = None
FILES = {
    "worker": ["/etc/systemd/system/orbit-node-worker.service", "/etc/systemd/system/orbit-swamp-worker.service", "/usr/local/libexec/orbit-node-worker-check", "/usr/local/bin/swamp", "/etc/orbit/swamp-worker", "/var/lib/orbit-node-worker"],
    "observer": ["/etc/systemd/system/orbit-agent.service", "/usr/local/bin/orbit-agent", "/etc/orbit/agent"],
    "exporter": ["/etc/systemd/system/prometheus-node-exporter.service.d/orbit.conf", "/etc/systemd/system/orbit-cadvisor.service", "/etc/systemd/system/orbit-fpm-exporter.service", "/etc/orbit/service-metrics", "/usr/local/bin/orbit-cadvisor", "/usr/local/bin/orbit-fpm-exporter"],
}
UNITS = {
    "worker": ["orbit-node-worker.service", "orbit-swamp-worker.service"],
    "observer": ["orbit-agent.service"],
    "exporter": ["prometheus-node-exporter.service", "orbit-cadvisor.service", "orbit-fpm-exporter.service"],
}


def remove(data):
    if data["node_id"] == 209 and data["group"] != "exporter":
        raise ValueError("node.control_removal_forbidden")
    group = data["group"]
    for unit in UNITS[group]:
        result = subprocess.run(["/usr/bin/systemctl", "is-active", "--quiet", unit], check=False)
        if result.returncode not in (3, 4):
            raise RuntimeError("node.removal_service_active")
    for name in FILES[group]:
        path = Path(name)
        if path.is_symlink() or path.is_file():
            path.unlink()
        elif path.is_dir():
            shutil.rmtree(path)
    return {"removed": True}


if __name__ == "__main__":
    print(json.dumps(remove(REMOVAL_INPUT)))
