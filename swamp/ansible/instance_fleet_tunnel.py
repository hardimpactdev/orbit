"""The operator tunnel of a nested-fleet Instance container at each boot (docs/reference/development-containers.md#the-operator-tunnel-at-boot).

Root runs it in the Instance container from the fleet start (tasks/instance-fleet-start.yml), after the start made the tunnel. Arguments:
`boot <script> <unit>`. Stdin is JSON `{"script": "<text>", "unit": "<text>"}`. It writes the script (mode 0700) and the unit (0644)
when either differs, then reloads systemd and enables the unit. So the default's seed holds a tunnel that comes up at every boot, and a
clone's create only waits for its fleet (instance.fleet_ready). Prints one JSON line: `{"changed": <bool>}`.
"""

import json
import os
import subprocess
import sys


def place(path, text, mode):
    """Writes the file when its content or mode differs; answers whether it did."""
    try:
        with open(path) as stream:
            if stream.read() == text and os.stat(path).st_mode & 0o7777 == mode:
                return False
    except FileNotFoundError:
        pass
    temporary = path + ".orbit-new"
    with open(temporary, "w") as stream:
        stream.write(text)
    os.chmod(temporary, mode)
    os.replace(temporary, path)
    return True


def main():
    mode, script, unit = sys.argv[1:4]
    if mode != "boot":
        raise SystemExit(f"unknown mode {mode}")
    data = json.load(sys.stdin)
    changed = place(script, data["script"], 0o700)
    changed = place(unit, data["unit"], 0o644) or changed
    name = os.path.basename(unit)
    enabled = subprocess.run(["systemctl", "is-enabled", "--quiet", name], check=False).returncode == 0
    if changed:
        subprocess.run(["systemctl", "daemon-reload"], check=True)
    if changed or not enabled:
        subprocess.run(["systemctl", "enable", "--quiet", name], check=True)
    print(json.dumps({"changed": changed or not enabled}))


if __name__ == "__main__":
    main()
