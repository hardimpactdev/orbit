#!/usr/bin/python3
"""Unprivileged build actions inside a single production Instance's release container."""
import hashlib
import json
import os
import re
import shutil
import stat
import subprocess
import sys
import tempfile
from pathlib import Path

WORK = Path("/srv/workspace")
SHARED = Path("/srv/shared")
SHA = re.compile(r"[0-9a-f]{40}")


def relative(value, *, empty=False):
    if value in ("", ".") and empty:
        return Path(".")
    if (not isinstance(value, str) or len(value) > 255
            or any(not re.fullmatch(r"[A-Za-z0-9_.-]+", part) or part in (".", "..", ".git") for part in value.split("/"))):
        raise ValueError("unsafe relative application path")
    return Path(value)


def beneath(root, path, *, parents_only=False):
    here = root
    for part in (path.parent if parents_only else path).parts:
        here /= part
        if here.is_symlink():
            raise ValueError("unexpected symlink in a production application path")
    return root / path


def remove(path):
    if path.is_symlink() or path.is_file():
        path.unlink()
    elif path.is_dir():
        shutil.rmtree(path)


def file_bytes(path, data):
    if not isinstance(data, str) or "\0" in data:
        raise ValueError("invalid environment file")
    fd, name = tempfile.mkstemp(dir=path.parent)
    try:
        with os.fdopen(fd, "w") as handle:
            handle.write(data)
        os.replace(name, path)
    finally:
        Path(name).unlink(missing_ok=True)


def repository(url):
    match = re.fullmatch(r"(?:https://github[.]com/|git@github[.]com:|ssh://git@github[.]com/)([\w.-]+)/([\w.-]+)", url)
    if not match or any(piece in (".", "..") for piece in match.groups()):
        raise ValueError("invalid admitted repository")
    return "https://github.com/" + "/".join(match.groups())


class CommitOutsideBranch(ValueError):
    pass


def select_commit(git, url, branch, sha, env):
    remote = "refs/orbit/admitted"
    branch_ref = "refs/heads/" + branch
    options = {"env": env, "timeout": 900, "stderr": subprocess.DEVNULL}
    subprocess.run([*git, "check-ref-format", branch_ref], check=True, stdout=subprocess.DEVNULL, **options)
    subprocess.run([*git, "fetch", "--no-tags", "--no-recurse-submodules", "--quiet", "--", url,
                    "+" + branch_ref + ":" + remote], check=True, stdout=subprocess.DEVNULL, **options)
    # Count first-parent commits newer than the requested commit, then name that exact
    # ancestor. A commit reachable only through a merge's other parent fails this check.
    distance = subprocess.run([*git, "rev-list", "--first-parent", "--count", sha + ".." + remote],
                              check=False, capture_output=False, stdout=subprocess.PIPE, text=True, **options)
    if distance.returncode or not distance.stdout.strip().isdecimal():
        raise CommitOutsideBranch()
    selected = subprocess.run([*git, "rev-parse", "--verify", "--quiet", remote + "~" + distance.stdout.strip() + "^{commit}"],
                              check=False, stdout=subprocess.PIPE, text=True, **options)
    if selected.returncode or selected.stdout.strip() != sha:
        raise CommitOutsideBranch()
    subprocess.run([*git, "-c", "advice.detachedHead=false", "checkout", "--quiet", "--detach", sha],
                   check=True, stdout=subprocess.DEVNULL, **options)


def checkout(plan):
    sha = plan["sha"]
    if not SHA.fullmatch(sha):
        raise ValueError("invalid admitted commit")
    url = repository(plan["repository"])
    # The filesystem is private to preparation. Never execute hooks, credential helpers or
    # filters supplied by an earlier checkout, or carry its .git/config into a release.
    staging = WORK / ".building"
    remove(staging)
    staging.mkdir(mode=0o755)
    clean_env = {**os.environ, "GIT_CONFIG_NOSYSTEM": "1", "GIT_CONFIG_GLOBAL": "/dev/null",
                 "GIT_TERMINAL_PROMPT": "0", "GIT_ALLOW_PROTOCOL": "https", "GIT_NO_REPLACE_OBJECTS": "1"}
    with tempfile.TemporaryDirectory(dir="/tmp", prefix="orbit-release-credential-") as private:
        credential = Path(private) / "store"
        file_bytes(credential, plan.get("credential", ""))
        git = ["git", "-c", "core.hooksPath=/dev/null", "-c", "credential.helper=",
               "-c", "credential.helper=store --file=" + str(credential), "-c", "http.followRedirects=false", "-C", staging]
        subprocess.run([*git, "init", "-q", "--template="], env=clean_env, check=True, timeout=60,
                       stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        select_commit(git, url, plan["branch"], sha, clean_env)
        head = subprocess.check_output([*git, "rev-parse", "HEAD"], text=True, env=clean_env).strip()
        if head != sha:
            raise ValueError("checkout returned a different commit")
    # Only preparation is off traffic. Its previous tree stays out of the sealed clone.
    current = WORK / "current"
    remove(current)
    os.replace(staging, current)
    for value in plan["shared_paths"]:
        relative_path = relative(value)
        if relative_path.parts[0].startswith(".") or relative_path.parts[0] in ("current", "releases", "env", "database.sqlite"):
            raise ValueError("reserved shared path")
        local = beneath(current, relative_path)
        persistent = beneath(SHARED, relative_path)
        persistent.mkdir(parents=True, exist_ok=True)
        if local.is_dir():
            # Seed missing files only. Keep simultaneous writes by the serving release.
            subprocess.run(["rsync", "-r", "--ignore-existing", "--safe-links", "--", str(local) + "/", str(persistent) + "/"], check=True)
        remove(local)
        local.parent.mkdir(parents=True, exist_ok=True)
        local.symlink_to(persistent, target_is_directory=True)
    environment(plan)


def environment(plan):
    root = WORK / "current"
    suffix = relative(plan["application_suffix"].removeprefix("/"), empty=True)
    directories = {suffix}
    for route in plan["routes"]:
        web = relative(route["web_root"], empty=True)
        if web.name == "public":
            directories.add(web.parent)
    for directory in directories:
        application = beneath(root, directory)
        if not application.is_dir():
            raise ValueError("selected application directory is missing")
        file_bytes(application / ".env", plan["environment"])
        if plan.get("testing_environment") is not None:
            tracked = subprocess.run(["git", "ls-files", "--error-unmatch", "--", ".env.testing"], cwd=application,
                                     stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, check=False)
            if tracked.returncode == 1:
                file_bytes(application / ".env.testing", plan["testing_environment"])
            elif tracked.returncode:
                raise ValueError("cannot establish whether the testing environment is tracked")


def content_digest():
    # Include names, type, executable mode and content. Symlink targets are hashed as links:
    # the shared volume and its current uploads do not belong to a release artifact.
    tree = WORK / "current"
    digest = hashlib.sha256()
    for parent, dirs, files in os.walk(tree, followlinks=False):
        if Path(parent) == tree and ".git" in dirs:
            dirs.remove(".git")
        dirs.sort()
        for name in sorted(dirs + files):
            path = Path(parent, name)
            mode = path.lstat().st_mode
            row = [str(path.relative_to(tree)), mode]
            if stat.S_ISLNK(mode):
                row.append(os.readlink(path))
            elif stat.S_ISREG(mode):
                with path.open("rb") as stream:
                    row.append(hashlib.file_digest(stream, "sha256").hexdigest())
            elif not stat.S_ISDIR(mode):
                raise ValueError("unexpected special file in the build")
            digest.update(json.dumps(row, separators=(",", ":")).encode() + b"\n")
    return "sha256:" + digest.hexdigest()


def main(action):
    if action == "digest":
        print(content_digest())
        return
    plan = json.load(sys.stdin)
    if action == "checkout":
        checkout(plan)
    elif action == "environment":
        environment(plan)
    else:
        raise ValueError("unknown build action")


if __name__ == "__main__":
    try:
        main(sys.argv[1])
    except CommitOutsideBranch:
        sys.exit(78)
    except (ValueError, KeyError, OSError, subprocess.SubprocessError) as error:
        # A credential or private build command must never reach Operation history.
        sys.exit(f"deployment.step_failed: guest {sys.argv[1]} failed ({type(error).__name__})")
