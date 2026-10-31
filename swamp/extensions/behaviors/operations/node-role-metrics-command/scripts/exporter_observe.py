"""Read one exporter configuration and actual service state without changing it."""

import json
import subprocess
import sys
from pathlib import Path

METRICS_INPUT = None


def observe(data, run=subprocess.run):
    path = Path(data["path"])
    try:
        content = path.read_text()
        if not content.startswith(data["marker"]):
            raise ValueError("metrics.exporter_configuration_ownership_drift")
    except FileNotFoundError:
        content = None
    result = run(["/usr/bin/systemctl", "is-active", data["service"]], capture_output=True, text=True, check=False)
    state = result.stdout.strip()
    if not (result.returncode == 0 and state == "active") and not (result.returncode in (1, 3, 4) and state in ("inactive", "failed", "unknown")):
        raise ValueError("metrics.exporter_service_inspection_failed")
    return {"configuration": content, "service_active": state == "active"}


if __name__ == "__main__":
    try:
        print(json.dumps(observe(METRICS_INPUT)))
    except (ValueError, OSError):
        print('{"error_code":"metrics.exporter_inspection_failed"}')
        sys.exit(1)
