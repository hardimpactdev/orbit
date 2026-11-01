"""Activate the validated candidate release and canonical service unit."""

from phase_common import UNIT, context, control, link, publish, ready, run, unit_content

data, account = context()
if account["active"]:
    control("stop")
publish(UNIT, unit_content(data, account), "root:root", "0644", "# Managed by Orbit.")
link(data, account)
run(["/usr/bin/systemctl", "daemon-reload"])
control("enable")
control("restart")
ready()
