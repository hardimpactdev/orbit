import json
import shlex
import subprocess

status = subprocess.run(
    ["/usr/sbin/ufw", "status"], capture_output=True, text=True, check=True
)
if not status.stdout.startswith("Status: active"):
    raise SystemExit("metrics.service_firewall_inactive")
result = subprocess.run(
    ["/usr/sbin/ufw", "show", "added"], capture_output=True, text=True, check=True
)
rules = []
seen = set()
for line in result.stdout.splitlines():
    if "orbit:metrics-service-" not in line:
        continue
    words = shlex.split(line)
    if not words or words.pop(0) != "ufw":
        raise SystemExit("metrics.service_firewall_ownership_drift")
    if words[0] == "insert":
        words = words[:0] + words[2:]
    if (
        len(words) < 4
        or words[0] not in ("allow", "deny")
        or words[1:4] != ["in", "on", "orbit"]
    ):
        raise SystemExit("metrics.service_firewall_ownership_drift")
    fields = dict(zip(words[4::2], words[5::2], strict=True))
    if words[0] == "deny" and "from" not in fields:
        fields["from"] = "any"
    comment = fields.get("comment", "")
    if (
        comment in seen
        or len(fields) != 5
        or set(fields) != {"proto", "from", "to", "port", "comment"}
    ):
        raise SystemExit("metrics.service_firewall_ownership_drift")
    seen.add(comment)
    rules.append(
        [
            words[0],
            "in",
            "on",
            "orbit",
            "proto",
            fields["proto"],
            "from",
            fields["from"],
            "to",
            fields["to"],
            "port",
            fields["port"],
            "comment",
            comment,
        ]
    )
print(json.dumps({"rules": rules}))
