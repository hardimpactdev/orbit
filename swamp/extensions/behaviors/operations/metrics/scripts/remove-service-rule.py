import json
import os
import subprocess

rule = json.loads(os.environ["ORBIT_SERVICE_RULE"])
action = os.environ["ORBIT_RULE_ACTION"]
if action == "delete":
    result = subprocess.run(
        ["/usr/sbin/ufw", "--force", "delete", *rule],
        capture_output=True,
        text=True,
        check=False,
    )
    if result.returncode:
        actual = subprocess.run(
            ["/usr/sbin/ufw", "show", "added"],
            capture_output=True,
            text=True,
            check=True,
        )
        if rule[-1] in actual.stdout:
            raise SystemExit("metrics.service_firewall_remove_failed")
else:
    subprocess.run(["/usr/sbin/ufw", "insert", "1", *rule], check=True)
print('{"changed":true}')
