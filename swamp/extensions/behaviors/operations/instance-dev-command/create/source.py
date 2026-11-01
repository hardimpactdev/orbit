"""One legacy source phase: initialize/worktree, fetch, select, checkout and inspect."""
import base64
import json
import os
import re
import subprocess
import sys
from pathlib import Path


def source(value, material):
    target = Path(value["source"]["checkout"])
    repository = value["source"]["repository"]
    settings = ["core.hooksPath=/dev/null", "core.fsmonitor=false", "credential.helper=", "protocol.allow=never",
                "protocol.ext.allow=never", "protocol.https.allow=always", "core.sshCommand=false",
                "core.alternateRefsCommand=", "http.followRedirects=false"]
    environment = {key: item for key, item in os.environ.items() if not key.startswith("GIT_")}
    environment.update(GIT_TERMINAL_PROMPT="0", GIT_CONFIG_NOSYSTEM="1", GIT_CONFIG_GLOBAL="/dev/null", GIT_ALLOW_PROTOCOL="https")
    token = material.get("github_token")
    if token is not None:
        if not isinstance(token, str) or not re.fullmatch(r"[A-Za-z0-9_.-]{1,2048}", token):
            raise ValueError("instance.git_mint_invalid")
        entries = [("http.https://github.com/.extraheader", "Authorization: Basic " + base64.b64encode(("x-access-token:" + token).encode()).decode()),
                   ("url.https://github.com/.insteadOf", "git@github.com:"), ("url.https://github.com/.insteadOf", "ssh://git@github.com/")]
        environment["GIT_CONFIG_COUNT"] = str(len(entries))
        for index, (key, item) in enumerate(entries):
            environment["GIT_CONFIG_KEY_" + str(index)] = key
            environment["GIT_CONFIG_VALUE_" + str(index)] = item

    def git(*args, directory=target, allow_empty=False, raw=False):
        result = subprocess.run(["/usr/bin/git", *[part for setting in settings for part in ("-c", setting)],
                                 "-C", str(directory), *args], env=environment, capture_output=True, text=True, check=False)
        if result.returncode and not (allow_empty and result.returncode == 1):
            if "is already used by worktree" in result.stderr:
                raise ValueError("instance.branch_in_use")
            raise ValueError("instance.source_git_failed")
        return result.stdout if raw else result.stdout.strip()

    seed, commit = value["source"].get("seed_repository"), value["resolve"].get("seed_commit")
    branch = value["resolve"].get("branch_override") or (
        value["resolve"]["default_branch"] if value["instance_name"] == "default" else value["instance_name"])
    if seed and not target.exists():
        if Path(seed).is_symlink() or git("config", "--get", "remote.origin.url", directory=seed) != repository:
            raise ValueError("instance.source_identity_invalid")
        if not isinstance(commit, str) or not re.fullmatch(r"[a-f0-9]{40}(?:[a-f0-9]{24})?", commit):
            raise ValueError("instance.source_identity_invalid")
        git("worktree", "add", "--no-checkout", "--detach", "--", str(target), commit, directory=seed)
    elif not (target / ".git" / "HEAD").exists() and not (target / ".git").is_file():
        git("init", "--quiet")
    origin = git("config", "--get", "remote.origin.url", allow_empty=True)
    if origin and origin != repository:
        raise ValueError("instance.source_identity_invalid")
    if not origin:
        git("remote", "add", "origin", repository)
    gitdir = Path(git("rev-parse", "--absolute-git-dir"))
    info = target.stat()
    marker = gitdir / "orbit-source-prepare"
    expected = f"{value['source']['prepare_id']}:{info.st_dev}:{info.st_ino}\n"
    if marker.is_symlink() or marker.exists() and marker.read_text() != expected:
        raise ValueError("instance.source_identity_changed")
    marker.write_text(expected)
    marker.chmod(0o600)
    retained = value.get("retained_source")
    def public_template():
        application = Path(value['application'])
        if not application.is_relative_to(target) or any(path.is_symlink() for path in (application, *application.parents) if path == target or target in path.parents):
            raise ValueError('env.import_invalid')
        path = (application.relative_to(target) / '.env.example').as_posix()
        tracked = git('ls-tree', 'HEAD', '--', path)
        if not tracked:
            return ''
        if not tracked.startswith(('100644 ', '100755 ')):
            raise ValueError('env.import_invalid')
        template = git('show', 'HEAD:' + path, raw=True)
        if len(template.encode()) > 131072:
            raise ValueError('env.import_invalid')
        return template
    if retained:
        if git("rev-parse", "--abbrev-ref", "HEAD") != retained["branch"] or git("rev-parse", "HEAD") != retained["commit"]:
            raise ValueError("instance.source_identity_changed")
        return {**retained, "ready": True, 'template': public_template()}
    if value["resolve"].get("network", True):
        git("fetch", "--quiet", "--no-tags", "--prune", "--", repository, "+refs/heads/*:refs/remotes/origin/*")
    refs = git("for-each-ref", "--format=%(refname)", "refs/heads", "refs/remotes/origin").splitlines()
    remote, local = "refs/remotes/origin/" + branch, "refs/heads/" + branch
    if value["resolve"].get("require_branch") and remote not in refs and local not in refs:
        raise ValueError("instance.branch_not_found")
    reference = commit or (remote if remote in refs else local if local in refs else "refs/remotes/origin/" + value["resolve"]["default_branch"])
    git("checkout", "--quiet", "--no-track", "-B", branch, reference)
    if commit:
        git('update-ref', 'refs/worktree/orbit/ci-baseline', commit)
    actual = git("rev-parse", "--verify", "HEAD^{commit}")
    profile = {"kind": "none"}
    if not value.get("skip_profile") and value["project_type"] != "monorepo":
        application = Path(value["application"])
        composer, entry = application / "composer.json", application / (value.get("entry_point") or "artisan")
        if composer.is_symlink() or composer.exists() and (not composer.is_file() or composer.stat().st_size > 1048576):
            raise ValueError("app-dev.php_version_unsupported")
        if composer.is_file():
            raw = json.loads(composer.read_text())
            public = {section: {key: raw.get(section, {})[key] for key in ("php", "laravel/framework", "symfony/framework-bundle") if key in raw.get(section, {})}
                      for section in ("require", "require-dev")}
            profile = {"kind": "composer", "composer": base64.b64encode(json.dumps(public).encode()).decode(),
                       "entry_point": "unsafe" if entry.is_symlink() else "regular" if entry.is_file() else "absent"}
        elif entry.exists():
            profile = {"kind": "partial"}
    return {"ready": True, "branch": branch, "commit": actual, "profile": profile, 'template': public_template()}


if __name__ == "__main__":
    try:
        print(json.dumps(source(json.loads(sys.argv[1]), json.load(sys.stdin))))
    except ValueError as error:
        code = str(error)
        if not re.fullmatch(r"(?:instance|env|app-dev)\.[a-z_]+", code):
            code = "instance.create_source_failed"
        print(json.dumps({"ready": False, "error_code": code}))
        sys.exit(1)
