"""Restore the captured unit, release and service state after failed cutover."""

import socket

import file_restore
from phase_common import UNIT, context, control, link, ready, run

data, account = context()
control("stop")
link(data, account, restore=True)
file_restore.restore({"path": str(UNIT), "run_id": data["run_id"]})
run(["/usr/bin/systemctl", "daemon-reload"])
if account["active"]:
    control("start")
control("enable" if account["enabled"] else "disable")
if account["active"]:
    control("is-active")
    with socket.create_connection(("127.0.0.1", data["runtime"]["port"]), timeout=3):
        pass
ready()
