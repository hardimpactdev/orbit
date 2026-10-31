"""Read-only identity checks shared by the placed Instance removal actions."""

import base64
import grp
import hashlib
import json
import os
import pwd
import re
import stat
import subprocess
from pathlib import Path
from urllib.parse import urlsplit

HOME_ROOT = "/home"
STATE_ROOT = "/var/lib/orbit/app-instance-sources"


class Refusal(Exception):
    def __init__(self, code="instance.removal_conflict"):
        super().__init__(code)
        self.code = code


def require(value, code="instance.removal_conflict"):
    if not value:
        raise Refusal(code)


def info(path):
    try:
        return os.lstat(path)
    except FileNotFoundError:
        return None


def identity(path):
    value = info(path)
    return "absent" if value is None else f"{value.st_dev}:{value.st_ino}"


def canonical(path):
    require(isinstance(path, str) and path.startswith("/"))
    require(not any(ord(char) < 32 or ord(char) == 127 for char in path))
    require(all(part and part not in (".", "..") for part in path[1:].split("/")))
    current = ""
    for part in path[1:].split("/"):
        current += "/" + part
        value = info(current)
        if value is not None:
            require(stat.S_ISDIR(value.st_mode) and not stat.S_ISLNK(value.st_mode))
    return path


def directory(path, uid, gid):
    canonical(path)
    value = info(path)
    require(value is not None and stat.S_ISDIR(value.st_mode))
    require(value.st_uid == uid and value.st_gid == gid)
    return value


def read_regular(path, maximum=1048576):
    descriptor = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    try:
        value = os.fstat(descriptor)
        require(stat.S_ISREG(value.st_mode) and value.st_nlink == 1)
        require(value.st_size <= maximum)
        with os.fdopen(descriptor, "rb", closefd=False) as stream:
            contents = stream.read(maximum + 1)
        require(len(contents) <= maximum)
        return contents
    finally:
        os.close(descriptor)


def repository_identity(value):
    require(isinstance(value, str) and len(value) <= 4096)
    require(not any(ord(char) < 32 or ord(char) == 127 for char in value))
    if value.startswith("git@"):
        match = re.fullmatch(r"git@([^:/\s]+):([^\s]+)", value)
        require(match is not None, "instance.source_origin_mismatch")
        host, path = match.groups()
    else:
        parsed = urlsplit(value)
        require(parsed.scheme in ("https", "ssh") and parsed.hostname, "instance.source_origin_mismatch")
        require(not parsed.password and not parsed.query and not parsed.fragment)
        require(parsed.username in (None, "git"))
        host, path = parsed.hostname, parsed.path
    path = path.strip("/").removesuffix(".git").rstrip("/")
    require(bool(path))
    return host.lower() + "/" + path


def environment_file(path, owner, required):
    canonical(os.path.dirname(path))
    value = info(path)
    if value is None:
        require(not required, "instance.step_layout_invalid")
        return {}
    require(value.st_uid == owner.pw_uid and value.st_gid == owner.pw_gid)
    require(not value.st_mode & 0o022)
    result = {}
    text = read_regular(path).decode()
    escapes = {"n": "\n", "r": "\r", "\\": "\\", '"': '"', "$": "$"}
    for line in text.splitlines():
        if not line.strip() or line.lstrip().startswith("#"):
            continue
        match = re.fullmatch(r"([A-Za-z_][A-Za-z0-9_]{0,254})=(.*)", line)
        require(match is not None)
        key, content = match.groups()
        require(not key.startswith(("LD_", "BASH_")) and "\0" not in content)
        if content.startswith('"'):
            require(len(content) >= 2 and content.endswith('"'))
            content = content[1:-1]
            require(re.search(r'\\(?![nr\\"$])', content) is None)
            content = re.sub(r'\\([nr\\"$])', lambda item: escapes[item.group(1)], content)
        else:
            require('"' not in content and "'" not in content)
        require(key not in result and len(content.encode()) <= 65536 and len(result) < 1024)
        result[key] = content
    return result


def account(member, production):
    user = member["user"]
    require(re.fullmatch(r"[a-z_][a-z0-9_-]{0,31}", user) and user != "root")
    try:
        value = pwd.getpwnam(user)
    except KeyError:
        require(production)
        return None
    require(value.pw_uid != 0)
    if production:
        require(user == f"orbit-app-{member['project_id']}")
        require(value.pw_dir == member["production_home"])
        require(value.pw_uid < 1000 and value.pw_shell == "/usr/sbin/nologin")
        require(grp.getgrgid(value.pw_gid).gr_name == user)
    else:
        require(value.pw_uid == os.geteuid(), "instance.worker_account_mismatch")
    return value


def git(path, args, owner):
    settings = [
        "core.hooksPath=/dev/null", "core.fsmonitor=false",
        "core.alternateRefsCommand=true", "core.sshCommand=ssh", "core.askPass=",
        "core.gitProxy=", "credential.helper=", "uploadpack.packObjectsHook=",
        "protocol.ext.allow=never",
    ]
    command = ["/usr/bin/git"]
    for setting in settings:
        command += ["-c", setting]
    command += ["-C", path, *args]
    if owner.pw_uid != os.geteuid():
        command = ["/usr/sbin/runuser", "-u", owner.pw_name, "--", *command]
    result = subprocess.run(command, stdin=subprocess.DEVNULL, stdout=subprocess.PIPE,
                            stderr=subprocess.DEVNULL, check=False,
                            env={"PATH": "/usr/bin:/bin", "HOME": owner.pw_dir,
                                 "LANG": "C.UTF-8", "GIT_CONFIG_NOSYSTEM": "1",
                                 "GIT_CONFIG_GLOBAL": "/dev/null", "GIT_OPTIONAL_LOCKS": "0",
                                 "GIT_TERMINAL_PROMPT": "0"})
    require(result.returncode == 0, "instance.source_identity_invalid")
    require(len(result.stdout) <= 524288)
    return result.stdout


def production_marker(member, repository, state_root=None):
    state_root = state_root or STATE_ROOT
    path = f"{state_root}/{member['instance_id']}"
    if info(path) is None:
        return {"path": path, "identity": "absent", "present": False}
    for root in [state_root, path]:
        value = directory(root, 0, 0)
        require(stat.S_IMODE(value.st_mode) == 0o700)
    marker = path + "/release-layout"
    value = info(marker)
    require(value is not None and value.st_uid == 0 and value.st_gid == 0)
    require(stat.S_IMODE(value.st_mode) == 0o600)
    expected = (repository + "\0" + member["user"] + "\0" + member["production_home"] + "\0initial\0").encode()
    require(read_regular(marker) == expected)
    return {"path": path, "identity": identity(path), "present": True}


def capture_member(member, intent):
    production = member["environment"] == "production"
    owner = account(member, production)
    source = member["production_home"] if production else member["checkout_path"]
    canonical(source)
    present = info(source) is not None
    require(owner is not None or not present)
    if present:
        directory(source, owner.pw_uid, owner.pw_gid)
    marker = None
    release = source if present else None
    if production:
        require(source == HOME_ROOT + "/" + member["user"])
        marker = production_marker(member, intent["repository"])
        if present and not marker["present"]:
            require(not os.listdir(source))
        current = source + "/current"
        release = None
        if info(current) is not None:
            link = os.lstat(current)
            require(stat.S_ISLNK(link.st_mode) and link.st_uid == owner.pw_uid and link.st_gid == owner.pw_gid)
            require(re.fullmatch(r"releases/[A-Za-z0-9][A-Za-z0-9._-]*", os.readlink(current)))
            release = os.path.realpath(current)
            require(release == member["checkout_path"] and os.path.dirname(release) == source + "/releases")
            directory(release, owner.pw_uid, owner.pw_gid)
    else:
        root = member["source_root"]
        directory(root, owner.pw_uid, owner.pw_gid)
        require(os.path.dirname(os.path.dirname(source)) == root)
        require(owner.pw_dir != root and not owner.pw_dir.startswith(root + "/"))
        for protected in intent["protected_source_roots"]:
            require(root != protected and not root.startswith(protected + "/") and not protected.startswith(root + "/"))
        require(not root.startswith(owner.pw_dir + "/."))
        if not present:
            require(intent["force"] or member["failed_creation"])
    commit, origin, inventory, common = "", intent["repository"], b"", source
    admin = None
    if release:
        top = git(release, ["rev-parse", "--show-toplevel"], owner).decode().rstrip("\n")
        require(top == release)
        common = git(release, ["rev-parse", "--path-format=absolute", "--git-common-dir"], owner).decode().rstrip("\n")
        admin = git(release, ["rev-parse", "--absolute-git-dir"], owner).decode().rstrip("\n")
        canonical(common)
        directory(common, owner.pw_uid, owner.pw_gid)
        if production or member["source_layout"] == "checkout":
            require(common == release + "/.git")
        else:
            require(common != release + "/.git")
            require(os.path.dirname(admin) == common + "/worktrees")
            directory(admin, owner.pw_uid, owner.pw_gid)
            require(read_regular(release + "/.git") == ("gitdir: " + admin + "\n").encode())
        prepare_id = member.get("source_prepare_id")
        if prepare_id and not production:
            marker = (prepare_id + ":" + identity(source) + "\n").encode()
            require(read_regular(admin + "/orbit-source-prepare") == marker)
            marker = None
        if not production and member.get("stored_status") == "reserved":
            require(prepare_id and member.get("registration_request_id") is None, "instance.remove_refused")
        origin = git(release, ["config", "--get", "remote.origin.url"], owner).decode().rstrip("\n")
        require(repository_identity(origin) == member["repository_identity"], "instance.source_origin_mismatch")
        try:
            commit = git(release, ["rev-parse", "--verify", "HEAD^{commit}"], owner).decode().rstrip("\n")
        except Refusal:
            require(member["failed_creation"] and member["starting_commit"] is None)
        require(commit == "" or re.fullmatch(r"[a-f0-9]{40}(?:[a-f0-9]{24})?", commit))
        inventory = git(release, ["worktree", "list", "--porcelain", "-z"], owner)
        if not production:
            branch = git(release, ["symbolic-ref", "--quiet", "--short", "HEAD"], owner).decode().rstrip("\n")
            require(branch == (member["branch"] or "") or member["failed_creation"], "instance.source_branch_mismatch")
            if not intent["force"] and not member["failed_creation"]:
                require(not git(release, ["status", "--porcelain=v1", "--untracked-files=all"], owner), "instance.unsafe_content")
        if not production and member["source_layout"] == "checkout":
            admitted = {row["checkout_path"] for row in intent["members"]}
            paths = [field[9:].decode() for field in inventory.split(b"\0") if field.startswith(b"worktree ")]
            require(all(path in admitted for path in paths), "instance.remove_refused")
    value = {"instance_id": member["instance_id"], "environment": member["environment"],
             "user": member["user"], "group": grp.getgrgid(owner.pw_gid).gr_name if owner else member["user"],
             "uid": owner.pw_uid if owner else None, "gid": owner.pw_gid if owner else None,
             "source": source, "source_identity": identity(source), "release": release,
             "release_identity": identity(release) if release else "absent", "marker": marker,
             "source_commit": commit, "origin": origin, "common_repository_path": str(Path(common).parent),
             "git_admin": admin, "git_admin_identity": identity(admin) if admin else "absent",
             "git_common": common, "git_common_identity": identity(common),
             "source_root": HOME_ROOT if production else member["source_root"],
             "worktree_inventory": base64.b64encode(inventory).decode(),
             "linked_worktree_paths": [field[9:].decode() for field in inventory.split(b"\0") if field.startswith(b"worktree ")]}
    value["source_digest"] = hashlib.sha256(json.dumps(value, sort_keys=True, separators=(",", ":")).encode()).hexdigest()
    return value


def quarantine_path(member, capture, removal_id):
    require(re.fullmatch(r"[a-f0-9-]{36}", removal_id))
    root = HOME_ROOT if capture["environment"] == "production" else member["source_root"]
    return f"{root}/.orbit-removal-{removal_id}-{member['instance_id']}"


def verify_source(member, capture, intent, allow_quarantine=False):
    production = capture["environment"] == "production"
    owner = account(member, production)
    if owner:
        require(owner.pw_uid == capture["uid"] and owner.pw_gid == capture["gid"])
    else:
        require(capture["uid"] is None or allow_quarantine)
    source = capture["source"]
    quarantine = quarantine_path(member, capture, intent["removal_id"])
    require(not (info(source) is not None and info(quarantine) is not None))
    actual = source if info(source) is not None else quarantine
    if info(actual) is None:
        require(capture["source_identity"] == "absent" or allow_quarantine)
        return {"source_present": False}
    require(actual == source or allow_quarantine)
    require(identity(actual) == capture["source_identity"])
    directory(actual, capture["uid"], capture["gid"])
    if actual == source:
        current = capture_member(member, intent)
        for field in ["uid", "gid", "source_identity", "release", "release_identity", "source_commit", "origin", "worktree_inventory", "marker"]:
            require(current[field] == capture[field])
    return {"source_present": capture["release"] is not None}
