import ipaddress
import json
import os
import re
import subprocess

v = json.loads(os.environ["ORBIT_EXPORTER_INTENT"])
r = subprocess.run(
    ["/usr/sbin/ufw", "status", "numbered"], capture_output=True, text=True, check=True
)
if not any(line.strip().lower() == "status: active" for line in r.stdout.splitlines()):
    raise SystemExit("metrics.exporter_firewall_inactive")
source = None
for line in r.stdout.splitlines():
    text, separator, comment = line.rpartition("#")
    if not separator or comment.strip() != v["comment"]:
        continue
    match = re.fullmatch(r"\s*\[\s*[1-9][0-9]*\]\s+(.*)", text)
    if not match or source is not None:
        raise SystemExit("metrics.exporter_firewall_ownership_drift")
    words = match.group(1).split()
    if len(words) == 7 and words[2:4] == ["on", "orbit"]:
        words = words[:2] + words[4:] + words[2:4]
    if (
        len(words) != 7
        or words[0].removesuffix("/32") != v["address"]
        or words[1] != v["port"] + "/tcp"
        or words[2:4] != ["ALLOW", "IN"]
        or words[5:] != ["on", "orbit"]
    ):
        raise SystemExit("metrics.exporter_firewall_ownership_drift")
    source = str(ipaddress.IPv4Address(words[4].removesuffix("/32")))
print(json.dumps({"source": source}))
