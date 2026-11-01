"""Stop one admitted Orbit service through its installed stop policy."""

import json
import re
import subprocess

REMOVAL_INPUT = None


def stop(data):
    unit = data["unit"]
    if not re.fullmatch(r"(?:orbit-(?:node-worker|swamp-worker|agent|websocket|cadvisor|fpm-exporter)|prometheus-node-exporter)\.service", unit):
        raise ValueError("node.removal_unit_invalid")
    found = subprocess.run(["/usr/bin/systemctl", "show", "--property=LoadState", "--value", unit], capture_output=True, text=True, check=False)
    if found.stdout.strip() == "not-found":
        return {"stopped": True}
    found.check_returncode()
    subprocess.run(["/usr/bin/systemctl", "disable", "--now", unit], check=True, stdout=subprocess.DEVNULL)
    stopped = subprocess.run(["/usr/bin/systemctl", "is-active", "--quiet", unit], check=False)
    if stopped.returncode not in (3, 4):
        raise RuntimeError("node.removal_service_active")
    return {"stopped": True}


if __name__ == "__main__":
    print(json.dumps(stop(REMOVAL_INPUT)))
