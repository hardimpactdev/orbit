"""Reload the service manager after the preceding native file-removal steps."""

import subprocess

REMOVAL_INPUT = None

if __name__ == "__main__":
    subprocess.run(["/usr/bin/systemctl", "daemon-reload"], check=True)
