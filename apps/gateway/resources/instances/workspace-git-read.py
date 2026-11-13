"""Fixed, read-only Git state of a container workspace (docs/reference/development-containers.md#workspace-work-state).

The Gateway prepends INSTANCE_PROJECTS, BASELINE and BUDGET (seconds) and runs `python3 - <mode> ...` on the Node over SSH. Every Git call
runs inside the container as `agent` with fixed arguments; only validated hex object ids join them. Names from the
workspace leave only as JSON text. No Git write, no config change, no fetch.
"""
import json
import os
import re
import subprocess
import sys
import time

NAMES = 5
NAME_LENGTH = 200
UNTRACKED_BASELINE = 2000
WORKSPACE = "/srv/workspace"
OID = re.compile(r"(?:[0-9a-f]{40}|[0-9a-f]{64})")
# Orbit writes these two files at the workspace root; an untracked copy there is not work. A nested `.env` and a change
# to a tracked one still count.
ORBIT_FILES = {b".env", b".env.testing"}
# The Gateway reads up to 1 MiB of a record; the untracked set gives way first, so heads and stashes keep their baseline.
RECORD_BYTES = 900000
HELD = 5000
GIT = ["/usr/bin/git", "-c", "safe.directory=" + WORKSPACE, "-c", "core.fsmonitor=false", "-c", "core.hooksPath=/dev/null",
       "-c", "core.untrackedCache=false", "--no-optional-locks", "-C", WORKSPACE]
DEADLINE = time.monotonic() + BUDGET


class Unknown(Exception):
    pass


def container(project, name):
    if project not in INSTANCE_PROJECTS or not re.fullmatch(r"instance-[1-9][0-9]*", name):
        raise ValueError("invalid container")
    return project, name


def run(command, timeout=None):
    remaining = DEADLINE - time.monotonic() if timeout is None else timeout
    if remaining <= 0:
        raise Unknown("timeout")
    try:
        return subprocess.run(command, stdin=subprocess.DEVNULL, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL,
                              timeout=remaining, check=False)
    except subprocess.TimeoutExpired:
        raise Unknown("timeout")


def git(target, *arguments):
    project, name = target
    seconds = max(1, int(DEADLINE - time.monotonic() + 0.999))
    result = run(["incus", "exec", "--project", project, "--mode=non-interactive", name, "--", "/usr/bin/timeout",
                  str(seconds), "/usr/sbin/runuser", "-u", "agent", "--", "/usr/bin/env", "-i", "HOME=/home/agent",
                  "GIT_CONFIG_NOSYSTEM=1", "GIT_TERMINAL_PROMPT=0", "GIT_OPTIONAL_LOCKS=0", *GIT, *arguments])
    if result.returncode == 124:
        raise Unknown("timeout")
    return result


def running(target):
    project, name = target
    result = run(["incus", "query", "/1.0/instances/" + name + "/state?project=" + project])
    if result.returncode != 0:
        raise Unknown("unreachable")
    return json.loads(result.stdout).get("status") == "Running"


def text(raw):
    name = raw.decode("utf-8", "replace")
    return name if len(name) <= NAME_LENGTH else name[:NAME_LENGTH - 1] + "…"


def oids(values):
    return [value for value in values if isinstance(value, str) and OID.fullmatch(value)]


def refs(target):
    """Local branch heads (name, oid) and the stash entries (oid, label)."""
    listed = git(target, "for-each-ref", "--format=%(objectname) %(refname)", "refs/heads", "refs/stash")
    if listed.returncode != 0:
        raise Unknown("not_a_repository")
    heads, stash = [], False
    for line in listed.stdout.split(b"\n"):
        oid, _, ref = line.partition(b" ")
        if ref == b"refs/stash":
            stash = True
        elif ref.startswith(b"refs/heads/") and OID.fullmatch(oid.decode("ascii", "replace")):
            heads.append((text(ref[len(b"refs/heads/"):]), oid.decode()))
    stashes = []
    if stash:
        log = git(target, "log", "-g", "-z", "--format=%H %gd: %gs", "refs/stash")
        if log.returncode != 0:
            raise Unknown("not_a_repository")
        for entry in filter(None, log.stdout.split(b"\0")):
            oid, _, label = entry.partition(b" ")
            if OID.fullmatch(oid.decode("ascii", "replace")):
                stashes.append((oid.decode(), text(label)))
    return heads, stashes


def status(target):
    """Tracked changes and untracked files that are not ignored, file by file; NUL-separated."""
    read = git(target, "status", "--porcelain=v1", "-z", "--untracked-files=all", "--no-renames")
    if read.returncode != 0:
        raise Unknown("not_a_repository")
    modified, untracked = [], []
    for entry in filter(None, read.stdout.split(b"\0")):
        if entry[:2] != b"??":
            modified.append(entry[3:])
        elif entry[3:] not in ORBIT_FILES:
            untracked.append(entry[3:])
    return modified, untracked


def head(target):
    read = git(target, "symbolic-ref", "-q", "--short", "HEAD")
    return text(read.stdout.rstrip(b"\n")) if read.returncode == 0 and read.stdout.strip() else None


def unreachable(target, positive, negative):
    """Commits reachable from `positive` and not from any remote ref or `negative`; all are hex object ids."""
    # A baseline head the workspace no longer holds (pruned, or a default's later commit) is skipped, not an error.
    read = git(target, "rev-list", "--ignore-missing", *positive, "--not", "--remotes", *negative)
    if read.returncode != 0:
        raise Unknown("not_a_repository")
    return set(read.stdout.split())


def held(default, commits, heads):
    """The commits the default holds and reaches from its branches now: a default whose branch moved on since the copy
    still owns the commits a clone inherited. Above HELD commits the answer stays plain."""
    if not heads or len(commits) > HELD:
        return set()
    present = git(default, "rev-list", "--no-walk", "--ignore-missing", *commits)
    if present.returncode != 0:
        return set()
    found = sorted(oid.decode() for oid in present.stdout.split() if OID.fullmatch(oid.decode("ascii", "replace")))
    if not found:
        return set()
    beyond = git(default, "rev-list", "--ignore-missing", *found, "--not", *heads)
    if beyond.returncode != 0:
        return set()
    return {oid.encode() for oid in found} - set(beyond.stdout.split())


def record(target):
    if not running(target):
        raise Unknown("stopped")
    heads, stashes = refs(target)
    _, untracked = status(target)
    names = sorted({entry.decode("utf-8", "replace") for entry in untracked})
    answer = {"heads": sorted({oid for _, oid in heads}), "stashes": [oid for oid, _ in stashes],
              "untracked": names if len(names) <= UNTRACKED_BASELINE else None}
    if len(json.dumps(answer)) > RECORD_BYTES:
        answer["untracked"] = None
    print(json.dumps(answer))


def listed(values):
    return {"count": len(values), "names": values[:NAMES]}


def read(target, marker, default):
    if not running(target):
        # The Gateway names the asleep marker; the reader only tests that it exists.
        if not marker.startswith("/") or ".." in marker.split("/"):
            raise ValueError("invalid marker")
        raise Unknown("asleep" if os.path.exists(marker) else "stopped")
    if git(target, "rev-parse", "--is-inside-work-tree").stdout.strip() != b"true":
        raise Unknown("not_a_repository")
    baseline, kind = BASELINE, "recorded" if BASELINE is not None else "none"
    default_heads = []
    if baseline is None and default is not None:
        try:
            if running(default):
                heads, stashes = refs(default)
                default_heads = sorted({oid for _, oid in heads})
                baseline, kind = {"heads": default_heads, "stashes": [oid for oid, _ in stashes], "untracked": None}, "default_now"
        except Unknown as error:
            if str(error) == "timeout":
                raise
    baseline = baseline or {}
    heads, stashes = refs(target)
    modified, untracked = status(target)
    known = baseline.get("untracked")
    if isinstance(known, list):
        known = {name for name in known if isinstance(name, str)}
        untracked = [entry for entry in untracked if entry.decode("utf-8", "replace") not in known]
    seen = set(oids(baseline.get("stashes") or []))
    stashes = [label for oid, label in stashes if oid not in seen]
    negative = sorted(set(oids(baseline.get("heads") or [])))
    tips = sorted({oid for _, oid in heads})
    lost = unreachable(target, tips, negative) if tips else set()
    inherited = held(default, sorted(oid.decode() for oid in lost), default_heads) if kind == "default_now" and lost else set()
    lost -= inherited
    branches = sorted((name, oid) for name, oid in heads if oid.encode() in lost)
    counted = [{"name": name, "count": len(unreachable(target, [oid], negative) - inherited)} for name, oid in branches[:NAMES]]
    modified = [text(entry) for entry in modified]
    untracked = [text(entry) for entry in untracked]
    dirty = modified or untracked or lost or stashes
    print(json.dumps({"state": "dirty" if dirty else "clean", "reason": None, "baseline": kind, "head": head(target),
                      "modified": listed(modified), "untracked": listed(untracked),
                      "commits": {"count": len(lost), "branch_count": len(branches), "branches": counted},
                      "stashes": listed(stashes)}))


try:
    mode, arguments = sys.argv[1], sys.argv[2:]
    if mode == "record" and len(arguments) == 2:
        record(container(*arguments))
    elif mode == "read" and len(arguments) in (3, 5):
        read(container(*arguments[:2]), arguments[2], container(*arguments[3:5]) if len(arguments) == 5 else None)
    else:
        raise ValueError("unsupported workspace read")
except Unknown as error:
    print(json.dumps({"state": "unknown", "reason": str(error)}))
