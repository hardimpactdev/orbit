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
SOURCE = Path("/var/lib/orbit-release-git")
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
    # Reachability through a merge's second parent is insufficient.
    distance = subprocess.run([*git, "rev-list", "--first-parent", "--count", sha + ".." + remote],
                              check=False, stdout=subprocess.PIPE, text=True, **options)
    if distance.returncode or not distance.stdout.strip().isdecimal():
        raise CommitOutsideBranch()
    selected = subprocess.run([*git, "rev-parse", "--verify", "--quiet", remote + "~" + distance.stdout.strip() + "^{commit}"],
                              check=False, stdout=subprocess.PIPE, text=True, **options)
    if selected.returncode or selected.stdout.strip() != sha:
        raise CommitOutsideBranch()


def git_environment():
    # Git needs the Node's egress proxy. No earlier application Git environment participates.
    env = {key: value for key, value in os.environ.items()
           if key.lower() in ("http_proxy", "https_proxy", "no_proxy", "all_proxy")}
    return {**env, "PATH": "/usr/bin:/bin", "GIT_CONFIG_NOSYSTEM": "1", "GIT_CONFIG_GLOBAL": "/dev/null",
            "GIT_TERMINAL_PROMPT": "0", "GIT_ALLOW_PROTOCOL": "https", "GIT_NO_REPLACE_OBJECTS": "1"}


def fetch(plan):
    sha = plan["sha"]
    if not SHA.fullmatch(sha):
        raise ValueError("invalid admitted commit")
    url = repository(plan["repository"])
    cache = SOURCE / "repository"
    owner = SOURCE / "origin"
    identity = json.dumps([url, plan["branch"]])
    pending = SOURCE / "fetching"
    # Only the dedicated fetch account can change this directory. Drop the entire object
    # store on a repository change so another project's history never enters a clone.
    if pending.exists() or not owner.is_file() or owner.read_text() != identity:
        remove(cache)
        file_bytes(owner, identity)
    pending.touch()
    env = git_environment()
    # The build lock serializes fetches. Clean up this UID's interrupted files; tmpfs
    # keeps them outside every container snapshot even if the previous fetch was killed.
    for stale in Path("/dev/shm").glob("orbit-release-credential-*"):
        info = stale.lstat()
        if info.st_uid == os.geteuid() and stat.S_ISDIR(info.st_mode):
            remove(stale)
    with tempfile.TemporaryDirectory(dir="/dev/shm", prefix="orbit-release-credential-") as private:
        credential = Path(private) / "store"
        file_bytes(credential, plan.get("credential", ""))
        git = ["git", "-c", "core.hooksPath=/dev/null", "-c", "credential.helper=",
               "-c", "credential.helper=store --file=" + str(credential), "-c", "http.followRedirects=false",
               "--git-dir", str(cache)]
        subprocess.run([*git, "init", "--bare", "-q", "--template="], env=env, check=True, timeout=60)
        previous = subprocess.run([*git, "rev-parse", "--verify", "--quiet", "refs/orbit/admitted"],
                                  env=env, check=False, stdout=subprocess.PIPE, text=True).stdout.strip()
        select_commit(git, url, plan["branch"], sha, env)
        if previous and subprocess.run([*git, "merge-base", "--is-ancestor", previous, "refs/orbit/admitted"],
                                       env=env, check=False).returncode:
            # A force-push must not leave the previous branch's unreachable objects in the sealed filesystem.
            remove(cache)
            subprocess.run([*git, "init", "--bare", "-q", "--template="], env=env, check=True, timeout=60)
            select_commit(git, url, plan["branch"], sha, env)

    pending.unlink()


DEPENDENCIES = {
    "composer.lock": "vendor",
    "package-lock.json": "node_modules",
    "npm-shrinkwrap.json": "node_modules",
    "pnpm-lock.yaml": "node_modules",
    "yarn.lock": "node_modules",
    "bun.lock": "node_modules",
    "bun.lockb": "node_modules",
}
PACKAGE_INPUTS = set(DEPENDENCIES) | {"composer.json", "package.json", "pnpm-workspace.yaml", ".npmrc",
                                      ".yarnrc", ".yarnrc.yml", ".pnpmfile.cjs", "bunfig.toml", ".node-version",
                                      ".nvmrc", ".gitattributes"}


def dependency_trees(git, sha, env):
    entries = subprocess.check_output([*git, "ls-tree", "-rz", sha], env=env).split(b"\0")
    inputs = []
    package_dirs = set()
    locked = []
    indirect = False
    for entry in filter(None, entries):
        descriptor, raw_path = entry.split(b"\t", 1)
        path = Path(os.fsdecode(raw_path))
        if path.name in PACKAGE_INPUTS or any(part in ("patches", ".yarn") for part in path.parts):
            inputs.append(entry)
            indirect |= descriptor.startswith(b"120000 ")
        if descriptor.split()[0] not in (b"100644", b"100755"):
            continue
        if path.name == "package.json":
            package_dirs.add(path.parent)
        if path.name in DEPENDENCIES:
            locked.append((path.parent, DEPENDENCIES[path.name]))
    trees = set()
    for root, name in locked:
        roots = {root}
        if name == "node_modules":
            roots.update(directory for directory in package_dirs if root in directory.parents)
        for directory in roots:
            # These paths become Git clean exclusion patterns. Only literal, known paths qualify.
            try:
                trees.add(relative(str(directory / name)).as_posix())
            except ValueError:
                continue
    return hashlib.sha256(b"\0".join(inputs)).hexdigest(), set() if indirect else trees


def checkout(plan):
    sha = plan["sha"]
    if not SHA.fullmatch(sha):
        raise ValueError("invalid admitted commit")
    current = WORK / "current"
    if current.is_symlink():
        raise ValueError("preparation checkout is a symlink")
    current.mkdir(mode=0o755, exist_ok=True)
    remove(WORK / ".building")  # discarded by the older checkout implementation
    previous = plan.get("prepared") or {}
    reuse = previous.get("scope") == plan["scope"] and previous.get("artifact") == content_digest()
    # Drop every application-controlled Git setting, index, hook and ref. Borrow objects
    # read-only from the dedicated fetch account, with fresh metadata in this same worktree.
    remove(current / ".git")
    git = ["git", "-c", "core.hooksPath=/dev/null", "-C", str(current)]
    env = git_environment()
    subprocess.run([*git, "init", "-q", "--template="], env=env, check=True, timeout=60)
    (current / ".git/objects/info/alternates").write_text(str(SOURCE / "repository/objects") + "\n")
    fingerprint, dependencies = dependency_trees(git, sha, env)
    keep = []
    if reuse and previous.get("inputs") == fingerprint:
        shared = [relative(value) for value in plan["shared_paths"]]
        for value in sorted(dependencies):
            path = Path(value)
            if any(path == item or path in item.parents or item in path.parents for item in shared):
                continue
            if not any((current / parent).is_symlink() for parent in (path, *path.parents)) and (current / path).is_dir():
                keep.extend(["-e", "/" + value + "/"])
    # The new commit's index identifies every removed file as untracked. Refresh stats
    # before reset so unchanged tracked files keep their inode as well as their contents.
    subprocess.run([*git, "read-tree", sha], env=env, check=True)
    subprocess.run([*git, "update-index", "--refresh"], env=env, check=False, stdout=subprocess.DEVNULL)
    subprocess.run([*git, "clean", "-qffdx", *keep], env=env, check=True)
    subprocess.run([*git, "update-ref", "--no-deref", "HEAD", sha], env=env, check=True)
    subprocess.run([*git, "reset", "--quiet", "--hard", sha], env=env, check=True)
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
    created = link_public_storage(plan)
    environment(plan)
    return {"inputs": fingerprint, "storage_links": created}


def link_public_storage(plan):
    """The host layout's `storage:link`: a Laravel release serves its public uploads from the shared volume.

    The link is made only where `public/storage` is absent. A real directory or a link the checkout has is kept.
    Returns the application directories that got the link, which the candidate's readiness then requires."""
    created = []
    for application in public_storage(plan):
        link = application / "public/storage"
        if not link.exists() and not link.is_symlink():
            (application / "storage/app/public").mkdir(parents=True, exist_ok=True)
            link.symlink_to("../storage/app/public", target_is_directory=True)
            created.append(str(application.relative_to(WORK / "current")))
    return created


def applications(plan):
    root = WORK / "current"
    directories = {relative(plan["application_suffix"].removeprefix("/"), empty=True)}
    for route in plan["routes"]:
        web = relative(route["web_root"], empty=True)
        if web.name == "public":
            directories.add(web.parent)
    return [beneath(root, directory) for directory in sorted(directories)]


def public_storage(plan):
    """Laravel application directories whose `storage` is a shared path, which serve `public/storage` from it."""
    return [application for application in applications(plan)
            if (application / "artisan").is_file() and (application / "public").is_dir()
            and (application / "storage").is_symlink() and (application / "storage").resolve().is_relative_to(SHARED)]


def links(plan):
    """A candidate never serves a `public/storage` link that leads nowhere, such as a host path the container lacks.

    Where preparation made the link, the candidate still has it after the build steps, leading to the shared uploads."""
    for application in public_storage(plan):
        link = application / "public/storage"
        if link.is_symlink() and not link.exists():
            raise ValueError("the release has a public storage link that does not resolve")
    root = WORK / "current"
    for directory in plan["storage_links"]:
        application = beneath(root, relative(directory, empty=True))
        link = application / "public/storage"
        if not link.exists() or link.resolve() != (application / "storage/app/public").resolve():
            raise ValueError("a build step removed or replaced the public storage link that preparation made")


def environment(plan):
    for application in applications(plan):
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
    if action == "fetch":
        fetch(plan)
    elif action == "checkout":
        print(json.dumps(checkout(plan)))
    elif action == "environment":
        environment(plan)
    elif action == "links":
        links(plan)
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
