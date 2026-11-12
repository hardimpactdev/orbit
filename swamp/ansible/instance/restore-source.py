# Program: instance.restore-source
# Outcomes: intact refused
# Timeout: 30
# Input: source inventory or one fixed administrator catalog read.
# Result: bounded identity facts only, never file contents, passwords or client output.
import json
import os
import subprocess
import sys
from pathlib import Path


def refuse(reason):
    return {"outcome": "refused", "data": {"reason": reason}}


def git(path, *arguments):
    environment = {key: value for key, value in os.environ.items() if not key.startswith("GIT_")}
    environment.update(GIT_CONFIG_NOSYSTEM="1", GIT_CONFIG_GLOBAL="/dev/null",
                       GIT_TERMINAL_PROMPT="0", GIT_OPTIONAL_LOCKS="0")
    return subprocess.run(
        ["/usr/bin/git", "-c", "core.hooksPath=/dev/null", "-c", "core.fsmonitor=false",
         "-C", str(path), *arguments], env=environment, timeout=3,
        check=True, capture_output=True).stdout.decode().strip()


def identity(origin):
    for prefix in ("https://github.com/", "git@github.com:", "ssh://git@github.com/"):
        if origin.startswith(prefix):
            return "github.com/" + origin[len(prefix):].removesuffix(".git").lower()
    return ""


def source(request):
    members = request["members"]
    if not 0 < len(members) <= 32:
        return refuse("evidence_missing")
    container = request.get("container")
    if container is not None:
        result = subprocess.run(
            ["timeout", "5", "sudo", "-n", "incus", "info", container["name"],
             "--project", container["project"]], capture_output=True, timeout=6, check=False)
        if result.returncode:
            return refuse("container_removed")
    awake = []
    for member in members:
        checkout = Path(member["path"])
        if not checkout.is_dir():
            return refuse("source_removed")
        root = (checkout / member["root"]).resolve()
        if not root.is_dir() or not root.is_relative_to(checkout.resolve()):
            return refuse("source_changed")
        if (git(checkout, "rev-parse", "--show-toplevel") != str(checkout.resolve())
                or git(checkout, "rev-parse", "HEAD") != member["commit"]
                or git(checkout, "symbolic-ref", "--quiet", "--short", "HEAD") != member["branch"]
                or identity(git(checkout, "remote", "get-url", "origin")) != member["repository_identity"]):
            return refuse("source_changed")
        marker = Path(member["awake_path"])
        awake.append({"instance_id": member["instance_id"], "present": marker.exists()})
    return {"outcome": "intact", "data": {"awake_before": awake}}


def catalog(request):
    result = subprocess.run(
        ["timeout", "20", "sudo", "-n", *request["argv"]],
        input=request["password"] + "\n" + request["sql"], text=True,
        capture_output=True, timeout=22, check=False)
    if result.returncode or len(result.stdout) > 131072:
        return refuse("evidence_missing")
    lines = result.stdout.strip().splitlines()
    if len(lines) != 2:
        return refuse("evidence_missing")
    facts = json.loads(lines[0])
    facts["fences"] = json.loads(lines[1])
    return {"outcome": "intact", "data": facts}


def main():
    try:
        request = json.load(sys.stdin)
        result = source(request) if request["action"] == "source" else catalog(request)
    except (OSError, ValueError, KeyError, subprocess.SubprocessError):
        result = refuse("evidence_missing")
    print(json.dumps(result))


if __name__ == "__main__":
    main()
