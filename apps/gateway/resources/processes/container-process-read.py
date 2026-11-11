"""Fixed container Process journal/state reads; no caller-supplied program or Incus target."""
import json
import re
import subprocess
import sys
import time

LIMIT = 4 * 1024 * 1024
ENTRY = 256 * 1024


def pair(container, unit):
    if not re.fullmatch(r"instance-[1-9][0-9]*", container) or not re.fullmatch(
        r"orbit-process-[1-9][0-9]*-[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.service", unit
    ):
        raise ValueError("invalid Process target")
    return ["incus", "exec", "--project", "orbit-instances", container, "--", "/usr/bin/timeout", "2", "/usr/bin/env", "-i"]


def states(arguments):
    deadline = time.monotonic() + 2.5
    result = []
    for container, unit in zip(arguments[::2], arguments[1::2], strict=True):
        command = pair(container, unit) + ["/usr/bin/systemctl", "show", "--property=LoadState", "--property=ActiveState", "--", unit]
        state = "unknown"
        remaining = deadline - time.monotonic()
        if remaining > 0:
            try:
                read = subprocess.run(command, stdin=subprocess.DEVNULL, stdout=subprocess.PIPE,
                                      stderr=subprocess.DEVNULL, timeout=remaining, check=False)
                # systemctl show for exactly two properties has a small, fixed output.
                if read.returncode == 0 and len(read.stdout) <= 256:
                    properties = dict(line.split("=", 1) for line in read.stdout.decode().splitlines())
                    state = "inactive" if properties.get("LoadState") == "not-found" else properties.get("ActiveState", "unknown")
            except (OSError, ValueError, subprocess.TimeoutExpired):
                pass
        result.append(state)
    print(json.dumps(result))


def journal(arguments):
    lines, container, unit = arguments
    if not lines.isdecimal() or not 1 <= int(lines) <= 1000:
        raise ValueError("invalid line limit")
    command = pair(container, unit) + ["/usr/bin/journalctl", "--no-pager", "--quiet", "--unit=" + unit,
                                      "--reverse", "--lines=" + lines, "--output=short-iso", "--utc"]
    child = subprocess.Popen(command, stdin=subprocess.DEVNULL, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL)
    used = indent = total = 0
    cut = False
    try:
        while True:
            # Bound memory even for one huge printed line; count and drain its remainder.
            kept = child.stdout.readline(2 * ENTRY)
            if not kept:
                break
            length = len(kept.rstrip(b"\n"))
            if not kept.endswith(b"\n"):
                while True:
                    rest = child.stdout.readline(65536)
                    length += len(rest.rstrip(b"\n"))
                    if not rest or rest.endswith(b"\n"):
                        break
            kept = kept.rstrip(b"\n")
            output = []
            if kept.startswith(b" "):
                if cut:
                    continue
                if used + length > ENTRY:
                    cut = True
                else:
                    used += length
                    output.append(kept)
            else:
                start = kept.find(b": ")
                start = start + 2 if start >= 0 else 0
                indent = len(kept[:start].decode("utf-8", "replace"))
                used, cut = length, False
                if start and length - start > ENTRY:
                    end = start + ENTRY
                    while end < len(kept) and kept[end] & 0xC0 == 0x80:
                        end -= 1
                    output.append(kept[:end])
                    cut = True
                else:
                    output.append(kept)
            if cut:
                output.append(b" " * indent + b"[orbit] message cut at 256 KiB")
            for line in output:
                data = (line + b"\n")[:LIMIT - total]
                sys.stdout.buffer.write(data)
                total += len(data)
                if total >= LIMIT:
                    return
        if child.wait() != 0:
            raise RuntimeError("journal unavailable")
    finally:
        if child.poll() is None:
            child.kill()
        child.wait()


if sys.argv[1] == "states":
    states(sys.argv[2:])
elif sys.argv[1] == "logs":
    journal(sys.argv[2:])
else:
    raise ValueError("unsupported Process read")
