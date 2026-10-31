"""Refuse an invalid public target before resolving the private identity."""

import json

from ssh_common import admitted_target

admitted_target()
print(json.dumps({"ready": True}))
