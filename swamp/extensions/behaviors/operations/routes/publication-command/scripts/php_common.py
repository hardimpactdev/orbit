"""Shared FPM pool delta actions. Local configuration bytes never leave the worker."""

import grp
import hashlib
import json
import os
import pwd
import re
import shutil
import stat
import subprocess
import tempfile
from pathlib import Path

from php_sections import merge, split
from publication_files import ROOTS, directory, metadata, payload, stage_for


def digest(path):
    if not path.exists():
        return None
    info = path.lstat()
    if not stat.S_ISREG(info.st_mode) or info.st_uid != 0:
        raise ValueError("php.shared_pool_unsafe")
    return hashlib.sha256(path.read_bytes()).hexdigest()


def same(left, right):
    return (
        left.is_file()
        and right.is_file()
        and os.path.samestat(left.stat(), right.stat())
    )


def atomic_json(path, value):
    fd, temporary = tempfile.mkstemp(prefix=".json-", dir=path.parent)
    try:
        with os.fdopen(fd, "w") as output:
            output.write(json.dumps(value))
            output.flush()
            os.fsync(output.fileno())
        os.replace(temporary, path)
    finally:
        if os.path.lexists(temporary):
            os.unlink(temporary)


def context():
    meta = metadata()
    if meta["family"] != "php":
        raise ValueError("php.input_invalid")
    version = os.environ["ORBIT_ROUTE_PHP_VERSION"]
    if not re.fullmatch(r"[1-9][0-9]*\.[0-9]+", version):
        raise ValueError("php.version_invalid")
    admitted = payload(stage_for(meta), meta)
    before, after = (
        admitted["before"]["configurations"],
        admitted["after"]["configurations"],
    )
    if admitted["direction"] == "restore":
        before, after = after, before
    indices = []
    for configurations in (before, after):
        names = {}
        for selected, text in configurations.items():
            for name in split(text)[1]:
                if name in names:
                    raise ValueError("php.shared_pool_invalid")
                names[name] = selected
        indices.append(names)
    for name in indices[0].keys() & indices[1].keys():
        if indices[0][name] != indices[1][name]:
            raise ValueError("php.version_move_requires_withdrawal")
    root = ROOTS["php"] / version / "fpm"
    directory(root)
    directory(root / "pool.d")
    stage = stage_for(meta) / version
    stage.mkdir(mode=0o700, exist_ok=True)
    directory(stage)
    return (
        meta,
        admitted,
        before.get(version, ""),
        after.get(version, ""),
        version,
        root,
        stage,
    )


def service(action, version):
    reply = subprocess.run(
        ["/usr/bin/systemctl", action, "--quiet", f"php{version}-fpm"],
        check=False,
        stdin=subprocess.DEVNULL,
        stdout=subprocess.DEVNULL,
        stderr=subprocess.DEVNULL,
        timeout=110,
    )
    inactive = {"is-active": 3, "is-enabled": 1}.get(action)
    if reply.returncode != 0 and reply.returncode != inactive:
        raise ValueError("php.service_failed")
    return reply.returncode == 0


def invocation(version):
    value = (
        subprocess.run(
            [
                "/usr/bin/systemctl",
                "show",
                "--property=InvocationID",
                "--value",
                f"php{version}-fpm",
            ],
            check=True,
            capture_output=True,
            timeout=35,
        )
        .stdout.decode()
        .strip()
    )
    if not re.fullmatch(r"[a-f0-9]{32}", value):
        raise ValueError("php.service_identity_invalid")
    return value


def loaded(root, live, version):
    accepted = root / ".orbit-scopes-loaded"
    receipt = root / ".orbit-scopes-loaded.json"
    return (
        ((digest(live) is None and not accepted.exists()) or same(accepted, live))
        and receipt.is_file()
        and json.loads(receipt.read_text())
        == {"sha256": digest(live), "invocation_id": invocation(version)}
    )


def accept(root, live, version, stage):
    link = stage / "accepted"
    link.unlink(missing_ok=True)
    accepted = root / ".orbit-scopes-loaded"
    if live.exists():
        os.link(live, link)
        os.replace(link, accepted)
    else:
        accepted.unlink(missing_ok=True)
    atomic_json(
        root / ".orbit-scopes-loaded.json",
        {"sha256": digest(live), "invocation_id": invocation(version)},
    )


def bound(text, user):
    account = pwd.getpwnam(user)
    group = grp.getgrgid(account.pw_gid)
    home = account.pw_dir
    if (
        account.pw_name != user
        or account.pw_uid < 0
        or account.pw_gid < 0
        or group.gr_gid != account.pw_gid
        or not re.fullmatch(r"[a-z_][a-z0-9_-]{0,31}", group.gr_name)
        or not isinstance(home, str)
        or not home.startswith("/")
        or len(home) > 4096
        or any(ord(c) < 32 or c in ';"' for c in home)
        or any(part in (".", "..") for part in home.split("/"))
    ):
        raise ValueError("php.shared_pool_account_invalid")
    return (
        text.replace("/__ORBIT_NODE_HOME__", account.pw_dir)
        .replace("__ORBIT_NODE_HOME__", account.pw_dir)
        .replace("__ORBIT_NODE_GROUP__", group.gr_name)
    )


def stage_action():
    _, admitted, before, after, version, root, stage = context()
    state = stage / "state.json"
    live = root / "pool.d/orbit-scopes.conf"
    current = live.read_text() if digest(live) is not None else ""
    before, after = bound(before, admitted["user"]), bound(after, admitted["user"])
    desired = merge(before, after, current)
    for text in split(after)[1].values():
        paths = [
            line.split("=", 1)[1].strip()
            for line in text.splitlines()
            if line.split("=", 1)[0].strip() == "chdir" and "=" in line
        ]
        if len(paths) != 1 or not Path(paths[0]).is_dir():
            raise ValueError("php.pool_directory_missing")
    active, enabled = service("is-active", version), service("is-enabled", version)
    changed = (
        desired != current
        or not active
        or not enabled
        or not loaded(root, live, version)
    )
    prior = stage / "prior"
    prior.unlink(missing_ok=True)
    if live.exists():
        os.link(live, prior)
    candidate = stage / "candidate"
    fd, temporary = tempfile.mkstemp(prefix=".candidate-", dir=stage)
    try:
        with os.fdopen(fd, "w") as output:
            output.write(desired)
            output.flush()
            os.fsync(output.fileno())
        os.replace(temporary, candidate)
    finally:
        if os.path.lexists(temporary):
            os.unlink(temporary)
    atomic_json(
        state,
        {
            "changed": changed,
            "active": active,
            "enabled": enabled,
            "prior_digest": digest(live),
            "candidate_digest": digest(candidate) if desired else None,
        },
    )


def read_state(stage):
    return json.loads((stage / "state.json").read_text())


def expected_live(live, pin, sha):
    return (not live.exists() and sha is None) or (
        same(live, pin) and digest(live) == sha
    )


def validate_action():
    _, _, _, _, version, root, stage = context()
    state = read_state(stage)
    if not state["changed"]:
        return
    main = root / "php-fpm.conf"
    digest(main)
    with tempfile.TemporaryDirectory(prefix="validate-", dir=stage) as temporary:
        work = Path(temporary)
        pools = work / "pool.d"
        pools.mkdir()
        for source in (root / "pool.d").iterdir():
            if source.name == "orbit-scopes.conf" or source.suffix != ".conf":
                continue
            digest(source)
            shutil.copyfile(source, pools / source.name)
        shutil.copyfile(stage / "candidate", pools / "orbit-scopes.conf")
        expected = str(root / "pool.d/*.conf")
        count, lines = 0, []
        for line in main.read_text().splitlines(True):
            if list(map(str.strip, line.strip().split("=", 1))) == [
                "include",
                expected,
            ]:
                count += 1
                lines.append(f"include = {pools}/*.conf\n")
            else:
                lines.append(line)
        if count != 1:
            raise ValueError("php.shared_pool_invalid")
        configuration = work / "php-fpm.conf"
        configuration.write_text("".join(lines))
        reply = subprocess.run(
            [
                f"/usr/sbin/php-fpm{version}",
                "--test",
                "--fpm-config",
                str(configuration),
            ],
            check=False,
            stdin=subprocess.DEVNULL,
            stdout=subprocess.DEVNULL,
            stderr=subprocess.DEVNULL,
            timeout=110,
        )
        if reply.returncode:
            raise ValueError("php.validation_failed")


def publish_action():
    _, _, _, _, _, root, stage = context()
    state, live = read_state(stage), root / "pool.d/orbit-scopes.conf"
    if not state["changed"] or expected_live(
        live, stage / "candidate", state["candidate_digest"]
    ):
        return
    if not expected_live(live, stage / "prior", state["prior_digest"]):
        raise ValueError("php.publication_stale")
    (root / ".orbit-scopes-loaded").unlink(missing_ok=True)
    if state["candidate_digest"] is None:
        live.unlink(missing_ok=True)
        return
    candidate = stage / "candidate"
    candidate.chmod(0o644)
    link = stage / "next"
    link.unlink(missing_ok=True)
    os.link(candidate, link)
    os.replace(link, live)


def owned(root, stage):
    state = read_state(stage)
    pin = stage / ("candidate" if state["changed"] else "prior")
    sha = state["candidate_digest"] if state["changed"] else state["prior_digest"]
    if not expected_live(root / "pool.d/orbit-scopes.conf", pin, sha):
        raise ValueError("php.publication_stale")
    return state


def enable_action():
    _, _, _, _, version, root, stage = context()
    state = owned(root, stage)
    if state["changed"] and not service("is-enabled", version):
        service("enable", version)


def reload_action():
    _, _, _, _, version, root, stage = context()
    state = owned(root, stage)
    if state["changed"]:
        service("reload-or-restart", version)
        accept(root, root / "pool.d/orbit-scopes.conf", version, stage)


def confirm_action():
    _, _, _, _, version, root, stage = context()
    owned(root, stage)
    if (
        not service("is-active", version)
        or not service("is-enabled", version)
        or not loaded(root, root / "pool.d/orbit-scopes.conf", version)
    ):
        raise ValueError("php.publication_not_loaded")


def restore_action():
    _, _, _, _, _, root, stage = context()
    if not (stage / "state.json").exists():
        return
    state = read_state(stage)
    if not state["changed"]:
        return
    live = root / "pool.d/orbit-scopes.conf"
    if not expected_live(
        live, stage / "candidate", state["candidate_digest"]
    ) and not expected_live(live, stage / "prior", state["prior_digest"]):
        raise ValueError("php.publication_stale")
    (root / ".orbit-scopes-loaded").unlink(missing_ok=True)
    if state["prior_digest"] is None:
        live.unlink(missing_ok=True)
    else:
        link = stage / "restore"
        link.unlink(missing_ok=True)
        os.link(stage / "prior", link)
        os.replace(link, live)


def restored():
    _, _, _, _, version, root, stage = context()
    if not (stage / "state.json").exists():
        return None
    state = read_state(stage)
    if not state["changed"]:
        return None
    live = root / "pool.d/orbit-scopes.conf"
    if not expected_live(live, stage / "prior", state["prior_digest"]):
        raise ValueError("php.publication_stale")
    return version, root, stage, state


def restore_enable_action():
    value = restored()
    if value:
        version, _, _, state = value
        service("enable" if state["enabled"] else "disable", version)


def restore_runtime_action():
    value = restored()
    if value:
        version, root, stage, state = value
        service("reload-or-restart" if state["active"] else "stop", version)
        if state["active"]:
            accept(root, root / "pool.d/orbit-scopes.conf", version, stage)


def entry(action):
    action()
    print('{"ready":true}')
