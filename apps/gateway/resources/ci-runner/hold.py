#!/usr/bin/python3
"""Holds CI places for proof copies on this Node (docs/reference/node-provisioning.md#proof-places-on-a-ci-node).

`hold <copy> <places>` records the hold of a proof copy, then stops idle runner services in the Incus project `orbit-ci` until the
running runners do not exceed `places` minus the holds on the Node. It records each runner that it stops. `release <copy>` removes the
hold; when no hold is left, it starts each recorded runner and clears the record. Both calls run under one lock per Node, and a rerun
changes nothing. A runner is busy while a `Runner.Worker` process runs in it: the stop reads that in the same command, so a busy runner
is never stopped.
"""
import fcntl
import json
import os
from pathlib import Path
import re
import subprocess
import sys
import tempfile

PROJECT = "orbit-ci"
STATE = Path(os.environ.get("ORBIT_CI_HOLD_STATE", "/var/lib/orbit/ci-hold"))
NAME = re.compile(r"[A-Za-z0-9][A-Za-z0-9.-]{0,62}")
RUNNING = {"active", "activating"}
HOME = "cd /home/runner/actions-runner && [ -s .service ] || exit 3; "
READ = HOME + 'echo "service=$(systemctl is-active -- "$(cat .service)")"; if pgrep -x Runner.Worker >/dev/null; then echo busy=1; fi'
STOP = HOME + 'if pgrep -x Runner.Worker >/dev/null; then echo busy; exit 0; fi; systemctl stop -- "$(cat .service)" && echo stopped'
START = HOME + 'systemctl start -- "$(cat .service)"'


class Refusal(Exception):
    pass


def incus(*argv, seconds=60):
    try:
        return subprocess.run(["incus", *argv], stdin=subprocess.DEVNULL, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL,
                              timeout=seconds, check=False, text=True)
    except subprocess.TimeoutExpired:
        raise Refusal("orbit-ci-runner-hold: incus " + argv[0] + " timed out")


def inside(name, script):
    return incus("exec", "--project", PROJECT, "--force-noninteractive", name, "--", "timeout", "50", "sh", "-c", script)


def containers():
    """The runner containers by name and whether each runs."""
    listed = incus("list", "--project", PROJECT, "--format=csv", "--columns=ns")
    if listed.returncode != 0:
        raise Refusal("orbit-ci-runner-hold: incus list exited " + str(listed.returncode))
    found = {}
    for line in listed.stdout.splitlines():
        name, _, state = line.strip().partition(",")
        if NAME.fullmatch(name):
            found[name] = state.lower() == "running"
    return found


def running_runners():
    """Each running runner service by name, with whether it is busy. A runner whose state no read gave counts as running and busy:
    the hold neither stops it nor counts it free."""
    runners = {}
    for name, running in sorted(containers().items()):
        if not running:
            continue
        read = inside(name, READ)
        if read.returncode == 3:
            continue
        values = dict(line.partition("=")[::2] for line in read.stdout.splitlines()) if read.returncode == 0 else {}
        service = values.get("service", "").strip()
        if read.returncode == 0 and service not in RUNNING:
            continue
        runners[name] = read.returncode != 0 or values.get("busy", "").strip() == "1"
    return runners


def stopped():
    path = STATE / "stopped.json"
    return json.loads(path.read_text()) if path.exists() else []


def record(names):
    handle, temporary = tempfile.mkstemp(dir=STATE, prefix=".stopped.", suffix=".tmp")
    with os.fdopen(handle, "w") as file:
        json.dump(sorted(set(names)), file)
    os.replace(temporary, STATE / "stopped.json")


def hold(copy, places):
    (STATE / "holds" / copy).touch()
    limit = max(places - len(list((STATE / "holds").iterdir())), 0)
    runners = running_runners()
    count = len(runners)
    for name in [name for name, busy in runners.items() if not busy]:
        if count <= limit:
            break
        # Recorded first: a crash after the stop still leaves the runner for the release to start.
        record(stopped() + [name])
        result = inside(name, STOP)
        if result.returncode == 0 and result.stdout.strip() == "stopped":
            count -= 1
            continue
        record([held for held in stopped() if held != name])
        if result.returncode != 0:
            raise Refusal("orbit-ci-runner-hold: the stop of " + name + " exited " + str(result.returncode))


def release(copy):
    (STATE / "holds" / copy).unlink(missing_ok=True)
    if any((STATE / "holds").iterdir()):
        return
    present = containers()
    failed = []
    for name in stopped():
        # A runner that is gone, or whose container no longer runs, has nothing to start.
        if not present.get(name, False):
            continue
        if inside(name, START).returncode not in (0, 3):
            failed.append(name)
    record(failed)
    if failed:
        raise Refusal("orbit-ci-runner-hold: the start of " + ", ".join(failed) + " failed")


def main(arguments):
    usage = "usage: orbit-ci-runner-hold hold <copy> <places> | release <copy>"
    if len(arguments) not in (2, 3) or not NAME.fullmatch(arguments[1]):
        sys.exit(usage)
    action, copy = arguments[0], arguments[1]
    if not ((action == "hold" and len(arguments) == 3 and arguments[2].isdigit()) or (action == "release" and len(arguments) == 2)):
        sys.exit(usage)
    os.umask(0o077)
    (STATE / "holds").mkdir(parents=True, exist_ok=True)
    with open(STATE / "lock", "w") as lock:
        fcntl.flock(lock, fcntl.LOCK_EX)
        try:
            if action == "hold":
                hold(copy, int(arguments[2]))
            else:
                release(copy)
        except Refusal as refusal:
            sys.exit(str(refusal))


if __name__ == "__main__":
    main(sys.argv[1:])
