"""F9 development source phase: fetch, pin checks, cleanup and checkout in place."""

import base64
import json
import os
import pwd
import re
import shutil
import ssl
import subprocess
import sys
import urllib.error
import urllib.request
from pathlib import Path


class Refused(Exception):
    pass


def require(value, code="deployment.source_failed"):
    if not value:
        raise Refused(code)


def git(root, *arguments, env=None):
    return subprocess.run(["/usr/bin/git", "-C", str(root), *arguments], env=env,
                          stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, check=False)


def text(root, *arguments):
    result = git(root, *arguments)
    require(result.returncode == 0)
    return result.stdout.decode().rstrip("\n")


def github_environment(request):
    intent = request["intent"]
    env = {**os.environ, "GIT_TERMINAL_PROMPT": "0"}
    if not intent["github_mint"]:
        return env
    repository = intent["github_repository"]
    try:
        query = urllib.request.Request("https://api.github.com/repos/" + repository,
                                       headers={"Accept": "application/vnd.github+json"})
        with urllib.request.urlopen(query, timeout=30) as response:
            public = json.load(response)
        require(public.get("private") is False and public.get("full_name", "").lower() == repository.lower())
        return env
    except urllib.error.HTTPError as error:
        require(error.code == 404)
        error.close()
    query = urllib.request.Request(
        "https://gateway.orbit/api/v1/direct-operations/" + str(request["operation_id"]) + "/github-token",
        data=json.dumps({"run_id": request["run_id"]}).encode(), method="POST",
        headers={"Content-Type": "application/json", "Accept": "application/json"})
    context = ssl.create_default_context(cafile="/usr/local/share/ca-certificates/orbit-root.crt")
    with urllib.request.urlopen(query, timeout=30, context=context) as response:
        grant = json.load(response)["data"]["github"]
    require(grant["repository"] == repository and grant["permissions"] == {"contents": "read"},
            "operation.github_token_unavailable")
    token = grant["token"]
    require(isinstance(token, str) and re.fullmatch(r"[A-Za-z0-9_.-]{1,4096}", token),
            "operation.github_token_unavailable")
    # Git supports an execution-only configuration in its environment; the token never enters argv.
    header = "Authorization: Basic " + base64.b64encode(("x-access-token:" + token).encode()).decode()
    env.update(GIT_CONFIG_COUNT="1", GIT_CONFIG_KEY_0="http.https://github.com/.extraheader",
               GIT_CONFIG_VALUE_0=header)
    for index, origin in enumerate(("git@github.com:", "ssh://git@github.com/"), start=1):
        env["GIT_CONFIG_KEY_" + str(index)] = "url.https://github.com/.insteadOf"
        env["GIT_CONFIG_VALUE_" + str(index)] = origin
    env["GIT_CONFIG_COUNT"] = "3"
    return env


def remove_obsolete(root, commit):
    for entry in text(root, "ls-files", "-z", "--others", "--directory").split("\0"):
        entry = entry.rstrip("/")
        if "/" not in entry:
            continue
        require(not entry.startswith("/") and ".." not in entry.split("/"))
        parent = entry.rsplit("/", 1)[0]
        if text(root, "ls-tree", "-d", commit, "--", parent):
            continue
        path = root / entry
        if path.is_dir() and not path.is_symlink():
            for directory, _, _ in os.walk(path):
                os.chmod(directory, os.stat(directory).st_mode | 0o200)
            shutil.rmtree(path)
        elif path.exists() or path.is_symlink():
            path.unlink()


def prepare_source(request):
    intent = request["intent"]
    deployment = intent["deployment"]
    root = Path(deployment["home"])
    account = pwd.getpwnam(deployment["node_user"])
    require(deployment["variant"] == "development" and account.pw_uid == os.getuid() and account.pw_uid > 0,
            "deployment.identity_changed")
    require(root.is_dir() and str(root.resolve()) == str(root) and not root.is_symlink()
            and (root / ".git").is_dir() and not (root / ".git").is_symlink(), "deployment.identity_changed")
    require(root.stat().st_uid == account.pw_uid and (root / ".git").stat().st_uid == account.pw_uid,
            "deployment.identity_changed")
    require(text(root, "config", "--get", "remote.origin.url") == deployment["repository"],
            "deployment.repository_mismatch")
    require(text(root, "rev-parse", "--git-common-dir") == ".git", "deployment.identity_changed")
    branch = deployment["branch"]
    require(not branch.startswith("-") and git(root, "check-ref-format", "--branch", branch).returncode == 0,
            "deployment.branch_invalid")
    tracking = "refs/remotes/origin/" + branch
    result = git(root, "fetch", "--quiet", "--no-tags", "--", deployment["repository"],
                 "+refs/heads/" + branch + ":" + tracking, env=github_environment(request))
    require(result.returncode == 0)
    commit = intent["commit"] or text(root, "rev-parse", "--verify", tracking + "^{commit}")
    require(isinstance(commit, str) and re.fullmatch(r"[a-f0-9]{40}|[a-f0-9]{64}", commit),
            "deployment.commit_not_on_branch")
    try:
        return checkout(root, branch, tracking, commit)
    except (Refused, ValueError, OSError) as error:
        code = str(error) if isinstance(error, Refused) else "deployment.source_failed"
        return {"ready": False, "error_code": code, "commit": commit}


def checkout(root, branch, tracking, commit):
    require(git(root, "merge-base", "--is-ancestor", commit, tracking).returncode == 0,
            "deployment.commit_not_on_branch")
    require(text(root, "status", "--porcelain", "--untracked-files=no") == "", "deployment.checkout_dirty")
    local = "refs/heads/" + branch
    if git(root, "rev-parse", "--verify", "--quiet", local + "^{commit}").returncode == 0:
        require(git(root, "merge-base", "--is-ancestor", local, commit).returncode == 0,
                "deployment.branch_diverged")
    remove_obsolete(root, commit)
    require(git(root, "checkout", "--quiet", "--no-track", "-B", branch, commit).returncode == 0)
    require(text(root, "symbolic-ref", "--short", "HEAD") == branch
            and text(root, "rev-parse", "--verify", "HEAD") == commit)
    return {"ready": True, "commit": commit}


if __name__ == "__main__":
    request = None
    try:
        request = json.loads(sys.argv[1])
        result = prepare_source(request)
    except (Refused, ValueError, OSError, KeyError, TypeError, IndexError) as error:
        code = str(error) if isinstance(error, Refused) else "deployment.source_failed"
        commit = request.get("intent", {}).get("commit") if isinstance(request, dict) else None
        commit = commit if isinstance(commit, str) and re.fullmatch(r"[a-f0-9]{40}|[a-f0-9]{64}", commit) else None
        result = {"ready": False, "error_code": code, "commit": commit}
    print(json.dumps(result, separators=(",", ":")), flush=True)
    if not result["ready"]:
        print(result["error_code"], file=sys.stderr)
        sys.exit(1)
