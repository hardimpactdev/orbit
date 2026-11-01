"""Read one Git origin without persisting an embedded credential in native history."""
import json  # noqa: F401 - the asset method injects json.loads for SOURCE_INPUT
import re
import subprocess
import sys
from urllib.parse import urlsplit

SOURCE_INPUT = None


def origin(value):
    path = value.get("checkout") or value["source"]["checkout"]
    result = subprocess.run(["/usr/bin/git", "-c", "core.hooksPath=/dev/null", "-c", "core.fsmonitor=false", "-C", path,
                             "config", "--get", "remote.origin.url"], check=False, capture_output=True, text=True)
    if result.returncode == 1 and value.get("allow_empty", False):
        return ""
    if result.returncode != 0:
        raise ValueError("instance.source_identity_invalid")
    address = result.stdout.strip()
    if address.startswith("git@"):
        valid = re.fullmatch(r"git@[^:/\s]+:[^\s]+", address) is not None
    else:
        parsed = urlsplit(address)
        valid = parsed.scheme in ("https", "ssh") and parsed.hostname and parsed.path and not parsed.password and not parsed.query and not parsed.fragment and parsed.username in (None, "git")
    if not valid:
        raise ValueError("instance.source_identity_invalid")
    return address


if __name__ == "__main__":
    sys.stdout.write(origin(SOURCE_INPUT))
