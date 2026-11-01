"""Observe the exact admitted release, unit and listener once."""

import socket
from pathlib import Path

from phase_common import UNIT, context, control, ready, unit_content

data, account = context()
control("is-active")
install = Path(data["runtime"]["install_path"])
if not install.is_symlink() or install.resolve() != Path(account["release"]):
    raise ValueError("websocket.runtime_release_not_ready")
if UNIT.read_text() != unit_content(data, account):
    raise ValueError("websocket.runtime_unit_not_ready")
with socket.create_connection(("127.0.0.1", data["runtime"]["port"]), timeout=3):
    ready()
