"""Read the normal apt removal plan without changing package state."""
import json
import re
import subprocess

REMOVE_INPUT = None
result = subprocess.run(["/usr/bin/apt-get", "--simulate", "remove", "--", REMOVE_INPUT["package"]],
                        check=True, stdin=subprocess.DEVNULL, capture_output=True, text=True, env={"PATH":"/usr/bin:/bin","LC_ALL":"C"})
packages = []
for line in result.stdout.splitlines():
    if not re.match(r"\s*Remv(?:\s|$)", line):
        continue
    match = re.match(r"Remv\s+([a-z0-9][a-z0-9+.-]*(?::[a-z0-9][a-z0-9-]*)?)(?:\s|$)", line)
    if not match:
        raise ValueError("Invalid apt removal plan")
    package = match[1].split(":")[0]
    if package not in packages:
        packages.append(package)
print(json.dumps({"packages": packages}))
