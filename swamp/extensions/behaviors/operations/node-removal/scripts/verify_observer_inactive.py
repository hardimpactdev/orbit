"""One fixed retirement service action."""

import json

from ssh_common import execute

execute("sudo -n /usr/bin/systemctl is-active orbit-agent.service", accepted=(3, 4))
print(json.dumps({"ready": True}))
