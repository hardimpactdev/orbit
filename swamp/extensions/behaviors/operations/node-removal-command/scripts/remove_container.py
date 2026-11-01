"""Remove one admitted role container while retaining its named volumes."""

import json
import re
import subprocess

REMOVAL_INPUT = None


def remove(data):
    name = data["name"]
    if not re.fullmatch(r"orbit-(?:metrics-(?:prometheus|grafana)|process-[1-9][0-9]*-plausible)", name):
        raise ValueError("node.role_runtime_invalid")
    present = subprocess.run(["/usr/bin/docker", "container", "ls", "--all", "--filter", "name=^/" + name + "$", "--format", "{{.Names}}"], capture_output=True, text=True, check=True)
    if name not in present.stdout.splitlines():
        return {"removed": True}
    action = data["action"]
    if action not in ("stop", "rm"):
        raise ValueError("node.role_runtime_invalid")
    # --volumes removes only anonymous volumes; the named role volumes stay.
    argv = ["/usr/bin/docker", "container", action, *(["--volumes"] if action == "rm" else []), "--", name]
    subprocess.run(argv, check=True, stdout=subprocess.DEVNULL)
    return {"removed": action == "rm", "stopped": True}


if __name__ == "__main__":
    print(json.dumps(remove(REMOVAL_INPUT)))
