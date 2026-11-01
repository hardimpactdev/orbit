"""One source action per native SSH step."""
import base64
import json
import os
import re
import shutil
import ssl
import urllib.error
import urllib.request
from pathlib import Path

from common import (
    ACCOUNT,
    DEPLOY,
    ENV,
    INPUT,
    INTENT,
    MODE,
    ROOT,
    Refused,
    emit,
    git,
    need,
    text,
)


def identity():
    need(DEPLOY["variant"] == "development" and ACCOUNT.pw_uid > 0 and os.geteuid() == ACCOUNT.pw_uid)
    need(os.path.realpath(ROOT) == ROOT and Path(ROOT).is_dir() and not Path(ROOT).is_symlink())
    need(Path(ROOT).stat().st_uid == ACCOUNT.pw_uid and (Path(ROOT) / ".git").is_dir()
         and not (Path(ROOT) / ".git").is_symlink() and (Path(ROOT) / ".git").stat().st_uid == ACCOUNT.pw_uid)
    need(text("config", "--get", "remote.origin.url") == DEPLOY["repository"])
    need(text("rev-parse", "--git-common-dir") == ".git")
    need(not DEPLOY["branch"].startswith("-") and git("check-ref-format", "--branch", DEPLOY["branch"]).returncode == 0,
         "deployment.branch_invalid")


def selected():
    value = INPUT.get("selected") or INTENT["commit"] or text("rev-parse", "--verify", "refs/remotes/origin/" + DEPLOY["branch"] + "^{commit}")
    need(isinstance(value, str) and re.fullmatch(r"[a-f0-9]{40}|[a-f0-9]{64}", value))
    return value


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        raise Refused("operation.github_token_unavailable")


def fetch():
    env = dict(ENV)
    private_repository = False
    if INTENT["github_mint"]:
        # Decide public versus private source explicitly; an unavailable mint never falls back.
        request = urllib.request.Request("https://api.github.com/repos/" + INTENT["github_repository"],
                                         headers={"Accept": "application/vnd.github+json"})
        try:
            with urllib.request.build_opener(NoRedirect()).open(request, timeout=60) as response:
                metadata = json.loads(response.read(65537))
            need(metadata.get("private") is False and metadata.get("full_name", "").lower() == INTENT["github_repository"].lower(), "deployment.source_failed")
        except urllib.error.HTTPError as error:
            code = error.code
            error.close()
            need(code == 404, "deployment.source_failed")
            private_repository = True
    if private_repository:
        operation_id = str(INPUT["operation_id"])
        if not re.fullmatch("[1-9][0-9]*", operation_id):
            raise Refused("operation.github_token_unavailable")
        run_id = INPUT["run_id"]
        context = ssl.create_default_context(cafile="/usr/local/share/ca-certificates/orbit-root.crt")
        request = urllib.request.Request("https://gateway.orbit/api/v1/direct-operations/" + operation_id + "/github-token",
                                         data=json.dumps({"run_id": run_id}).encode(), method="POST",
                                         headers={"Content-Type": "application/json", "Accept": "application/json"})
        with urllib.request.build_opener(NoRedirect(), urllib.request.HTTPSHandler(context=context)).open(request, timeout=60) as response:
            raw = response.read(65537)
        need(len(raw) <= 65536, "operation.github_token_unavailable")
        credential = json.loads(raw)["data"]["github"]
        need(credential["repository"] == INTENT["github_repository"] and credential["permissions"] == {"contents": "read"})
        token = credential["token"]
        need(isinstance(token, str) and re.fullmatch(r"[A-Za-z0-9_.-]{1,4096}", token))
        entries = [("http.https://github.com/.extraheader", "Authorization: Basic " + base64.b64encode(("x-access-token:" + token).encode()).decode()),
                   ("url.https://github.com/.insteadOf", "git@github.com:"),
                   ("url.https://github.com/.insteadOf", "ssh://git@github.com/")]
        env["GIT_CONFIG_COUNT"] = str(len(entries))
        for index, (key, value) in enumerate(entries):
            env["GIT_CONFIG_KEY_" + str(index)] = key
            env["GIT_CONFIG_VALUE_" + str(index)] = value
    config = ["-c", "protocol.allow=never", "-c", "http.followRedirects=false"]
    for protocol in DEPLOY["protocols"]:
        need(protocol in ("https", "ssh"))
        config += ["-c", "protocol." + protocol + ".allow=always"]
    result = git(*config, "fetch", "--quiet", "--no-tags", "--", DEPLOY["repository"],
                 "+refs/heads/" + DEPLOY["branch"] + ":refs/remotes/origin/" + DEPLOY["branch"], env=env)
    need(result.returncode == 0, "deployment.source_failed")
    return {}


def checked():
    target = selected()
    need(text("status", "--porcelain", "--untracked-files=no") == "", "deployment.checkout_dirty")
    tracking = "refs/remotes/origin/" + DEPLOY["branch"]
    distance = text("rev-list", "--first-parent", "--count", target + ".." + tracking)
    need(distance.isdigit() and text("rev-parse", "--verify", tracking + "~" + distance) == target, "deployment.commit_not_on_branch")
    local = "refs/heads/" + DEPLOY["branch"]
    if git("rev-parse", "--verify", "--quiet", local + "^{commit}").returncode == 0:
        need(git("merge-base", "--is-ancestor", local, target).returncode == 0, "deployment.branch_diverged")
    return target


def action():
    identity()
    if MODE == "fetch":
        return fetch()
    if MODE == "check":
        return {"commit": checked()}
    if MODE in ("clean", "checkout"):
        target = checked()
        if MODE == "clean":
            for entry in text("ls-files", "-z", "--others", "--directory").split("\0"):
                entry = entry.rstrip("/")
                if not entry or "/" not in entry:
                    continue
                need(not entry.startswith("/") and ".." not in entry.split("/"))
                parent = entry.rsplit("/", 1)[0]
                if text("ls-tree", "-d", target, "--", parent):
                    continue
                path = Path(ROOT) / entry
                if path.is_dir() and not path.is_symlink():
                    for directory, _, _ in os.walk(path):
                        os.chmod(directory, os.stat(directory).st_mode | 0o200)
                    shutil.rmtree(path)
                elif path.exists() or path.is_symlink():
                    path.unlink()
        else:
            need(git("checkout", "--quiet", "--no-track", "-B", DEPLOY["branch"], target).returncode == 0, "deployment.source_failed")
        return {"commit": target}
    need(MODE == "inspect")
    branch = git("symbolic-ref", "--quiet", "--short", "HEAD")
    need(branch.returncode == 0 and branch.stdout.decode().strip() == DEPLOY["branch"], "deployment.branch_changed")
    commit = text("rev-parse", "--verify", "HEAD")
    need(commit == selected(), "deployment.identity_changed")
    return {"commit": commit}


emit(action)
