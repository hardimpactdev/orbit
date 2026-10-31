"""One fixed retirement service action."""

import json

from ssh_common import execute

execute("sudo -n /usr/bin/systemctl reset-failed orbit-agent.service")
print(json.dumps({"ready": True}))
