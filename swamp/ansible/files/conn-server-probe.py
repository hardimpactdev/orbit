"""Read a known thread through the canary's installed CLI without logging its contents."""
import json
import subprocess
import sys
from pathlib import Path
from urllib.parse import urlsplit


def probe(binary, port):
    fixture = json.loads((Path.home() / ".config/orbit/conn-canary.json").read_text())
    state = Path(fixture["state_dir"])
    thread = fixture["thread_id"]
    if not state.is_absolute() or not isinstance(thread, str) or not thread or thread.startswith("-"):
        raise ValueError("Invalid canary fixture")
    runtime = json.loads((state / "server-runtime.json").read_text())
    origin = urlsplit(runtime["origin"])
    if origin.scheme != "http" or origin.hostname not in ("127.0.0.1", "::1", "localhost") or origin.port != port:
        raise ValueError("Unexpected server address")
    result = subprocess.run([binary, "thread", "show", thread, "--state-dir", str(state),
                             "--messages", "1", "--json"], capture_output=True, check=True, timeout=30)
    if json.loads(result.stdout)["thread"]["threadId"] != thread:
        raise ValueError("Unexpected thread")


if __name__ == "__main__":
    try:
        probe(sys.argv[1], int(sys.argv[2]))
    except (OSError, ValueError, KeyError, TypeError, subprocess.SubprocessError):
        sys.exit("conn_server.canary_thread_unreadable")
