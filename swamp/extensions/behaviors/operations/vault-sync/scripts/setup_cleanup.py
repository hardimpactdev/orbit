"""Native completed dependency: remove the probe, logout, and erase private cache."""

import os
import shutil
import subprocess
from pathlib import Path

from bw import cli

directory = Path(os.environ["ORBIT_VAULT_DIRECTORY"])
try:
    probe = directory / "probe"
    if probe.exists():
        cli("delete", "item", probe.read_text(), "--permanent")
    if directory.exists():
        # Logout is best effort; erasing the local session is unconditional.
        try:
            cli("logout")
        except subprocess.CalledProcessError:
            pass
finally:
    if directory.exists():
        shutil.rmtree(directory)
