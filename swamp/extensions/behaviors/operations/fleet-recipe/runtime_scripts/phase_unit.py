"""Render and validate the canonical candidate service before changing the live unit."""

from pathlib import Path

from phase_common import context, publish, ready, run, unit_content

data, account = context()
candidate = Path(account["release"]) / "orbit-websocket.service"
publish(candidate, unit_content(data, account), "root:root", "0600")
run(["/usr/bin/systemd-analyze", "verify", str(candidate)])
ready()
