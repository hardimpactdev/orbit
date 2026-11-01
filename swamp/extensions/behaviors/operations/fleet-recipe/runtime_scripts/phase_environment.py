"""Publish the existing private environment and validate Reverb before cutover."""

import json
import sys
from pathlib import Path

from phase_common import context, managed, publish, ready

data, account = context()
material = json.load(sys.stdin)
release = Path(account["release"])
publish(
    release / ".env",
    material["websocket_environment"],
    account["user"] + ":" + account["group"],
    "0600",
)
managed(
    account, ["/usr/bin/php", str(release / "artisan"), "orbit:reverb:start", "--help"]
)
ready()
