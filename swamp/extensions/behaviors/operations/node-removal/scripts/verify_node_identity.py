"""Read the admitted host's ownership metadata over pinned retirement SSH."""

import json
from pathlib import Path

from ssh_common import admitted_target, execute

_, _, _, node, _ = admitted_target()
program = Path(__file__).with_name("inspect_host.py").read_bytes()
view = json.loads(execute("sudo -n /usr/bin/python3 - " + str(node), program=program))
if (
    set(view) != {"ready", "worker_present", "observer_present", "artifacts_present"}
    or any(type(v) is not bool for v in view.values())
    or not view["ready"]
):
    raise SystemExit("node.removal_metadata_invalid")
print(json.dumps(view))
