"""Resolve the admitted account and capture the prior runtime before effects."""

import grp
import json
import pwd

import file_snapshot
import websocket_observe
import websocket_policy
from phase_common import UNIT, context

data, _ = context()
account = pwd.getpwnam(data["runtime"]["user"])
if account.pw_uid == 0:
    raise ValueError("websocket.runtime_account_missing")
websocket_observe.own_paths = websocket_policy.own_paths
before = websocket_observe.observe(
    {"install_path": data["runtime"]["install_path"], "run_id": data["run_id"]}
)
file_snapshot.snapshot({"path": str(UNIT), "run_id": data["run_id"]})
print(
    json.dumps(
        {
            "user": account.pw_name,
            "group": grp.getgrgid(account.pw_gid).gr_name,
            "home": account.pw_dir,
            **before,
        }
    )
)
