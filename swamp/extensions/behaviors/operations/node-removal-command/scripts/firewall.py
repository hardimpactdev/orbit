"""Apply one canonical UFW argument vector, including absent-rule idempotence."""

import json
import subprocess

REMOVAL_INPUT = None


def apply(data):
    argv = data["argv"]
    if not argv or argv[0] != "ufw":
        raise ValueError("firewall.input_invalid")
    result = subprocess.run(["/usr/sbin/ufw", *argv[1:]], capture_output=True, text=True, check=False)
    absent = "delete" in argv and result.returncode == 1 and "Could not delete non-existent rule" in result.stdout + result.stderr
    if result.returncode and not absent:
        raise RuntimeError("firewall.remove_failed")
    return {"applied": True}


if __name__ == "__main__":
    print(json.dumps(apply(REMOVAL_INPUT)))
