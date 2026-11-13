#!/usr/bin/python3
"""Incus stand-in: fail closed on any unexpected production argv."""
import os
import pathlib
import sys

args = sys.argv[1:]
assert args[:9] == ["exec", "--project", os.environ["PROCESS_READ_PROJECT"], "instance-1", "--", "/usr/bin/timeout", "2", "/usr/bin/env", "-i"]
case = os.environ["PROCESS_READ_CASE"]
if case in ("stopped", "missing-container"):
    sys.exit(1)
if args[9:] == ["/usr/bin/systemctl", "show", "--property=LoadState", "--property=ActiveState", "--", "orbit-process-2-worker.service"]:
    print("LoadState=not-found" if case == "missing-unit" else "LoadState=loaded")
    print("ActiveState=inactive" if case == "missing-unit" else "ActiveState=active")
else:
    assert args[9:] == ["/usr/bin/journalctl", "--no-pager", "--quiet", "--unit=orbit-process-2-worker.service",
                        "--reverse", "--lines=" + args[14].split("=", 1)[1], "--output=short-iso", "--utc"]
    if case != "missing-unit":
        sys.stdout.buffer.write(pathlib.Path(os.environ["PROCESS_READ_FIXTURE"], "journal").read_bytes())
