#!/usr/bin/python3
"""Writes the state of this Node's CI runners for the node exporter (docs/reference/node-provisioning.md#runner-state-for-the-runner-view).

It only reads: one `incus list` of the project `orbit-ci`, then one `incus exec` into each running container. Each call has its own
time limit, and the read starts no call after `BUDGET` seconds, inside the service's 10-second limit. The one argument is the
`.prom` file to write. The script writes a file beside it under a name that the exporter skips, then renames it.
"""
import os
import re
import subprocess
import sys
import tempfile
import time

PROJECT = "orbit-ci"
BUDGET = 8.0
NAME = re.compile(r"[A-Za-z0-9][A-Za-z0-9.-]{0,62}")
STATES = {"running", "stopped", "frozen", "error"}
SERVICES = {"active", "activating", "inactive", "failed"}
VERSION = re.compile(r"bin\.([0-9]+(?:\.[0-9]+){1,3})")
# `key=value` lines: the target of the `bin` link, the state of the service that `.service` names, and busy=1 while a job runs.
INSIDE = (
    'cd /home/runner/actions-runner || exit 0; echo "version=$(readlink bin)"; '
    'if [ -s .service ]; then echo "service=$(systemctl is-active -- "$(cat .service)")"; fi; '
    "if pgrep -x Runner.Worker >/dev/null; then echo busy=1; fi"
)


def run(argv, seconds):
    return subprocess.run(argv, stdin=subprocess.DEVNULL, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL,
                          timeout=seconds, check=False, text=True)


def runner(name, state, deadline):
    found = {"runner": name, "state": state, "service": "unknown", "version": "", "busy": 0}
    remaining = min(4.0, deadline - time.monotonic())
    if state != "running" or remaining <= 0:
        return found
    try:
        read = run(["incus", "exec", "--project", PROJECT, "--force-noninteractive", name, "--",
                    "timeout", "3", "sh", "-c", INSIDE], remaining)
    except (OSError, subprocess.TimeoutExpired):
        return found
    values = dict(line.partition("=")[::2] for line in read.stdout.splitlines()) if read.returncode == 0 else {}
    version = VERSION.fullmatch(os.path.basename(values.get("version", "").strip()))
    service = values.get("service", "").strip()
    found["version"] = version.group(1) if version else ""
    found["service"] = service if service in SERVICES else "unknown"
    found["busy"] = 1 if values.get("busy", "").strip() == "1" else 0
    return found


def read():
    """The runners, or None when Incus did not answer."""
    deadline = time.monotonic() + BUDGET
    try:
        listed = run(["incus", "list", "--project", PROJECT, "--format=csv", "--columns=ns"], 5)
    except (OSError, subprocess.TimeoutExpired):
        return None
    if listed.returncode != 0:
        return None
    runners = []
    for line in listed.stdout.splitlines():
        name, _, state = line.strip().partition(",")
        state = state.lower()
        if NAME.fullmatch(name) and state in STATES:
            runners.append(runner(name, state, deadline))
    return sorted(runners, key=lambda found: found["runner"])


def render(runners, observed):
    lines = [
        "# HELP orbit_ci_runner_info A CI runner container in the Incus project orbit-ci.",
        "# TYPE orbit_ci_runner_info gauge",
    ]
    for found in runners or []:
        lines.append('orbit_ci_runner_info{{runner="{runner}",state="{state}",service="{service}",version="{version}"}} 1'.format(**found))
    lines += ["# HELP orbit_ci_runner_busy 1 while a Runner.Worker process runs in the container.", "# TYPE orbit_ci_runner_busy gauge"]
    for found in runners or []:
        lines.append('orbit_ci_runner_busy{{runner="{runner}"}} {busy}'.format(**found))
    lines += [
        "# HELP orbit_ci_runners_observed_timestamp_seconds When the runners were read.",
        "# TYPE orbit_ci_runners_observed_timestamp_seconds gauge",
        "orbit_ci_runners_observed_timestamp_seconds {:.0f}".format(observed),
        "# HELP orbit_ci_runners_read_ok 0 when Incus did not answer.",
        "# TYPE orbit_ci_runners_read_ok gauge",
        "orbit_ci_runners_read_ok {}".format(0 if runners is None else 1),
    ]
    return "\n".join(lines) + "\n"


def write(path, text):
    folder = os.path.dirname(path)
    handle, temporary = tempfile.mkstemp(dir=folder, prefix=".orbit_ci_runners.", suffix=".tmp")
    try:
        with os.fdopen(handle, "w") as file:
            file.write(text)
        os.chmod(temporary, 0o644)
        os.replace(temporary, path)
    except BaseException:
        os.unlink(temporary)
        raise


def main(arguments):
    if len(arguments) != 1 or not arguments[0].endswith(".prom"):
        sys.exit("usage: orbit-ci-runner-state <file.prom>")
    observed = time.time()
    write(arguments[0], render(read(), observed))


if __name__ == "__main__":
    main(sys.argv[1:])
