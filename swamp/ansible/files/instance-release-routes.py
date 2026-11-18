#!/usr/bin/env python3
"""Publish a release upstream for the Instance's existing Caddy sites and migrate its shared files."""
import grp
import hashlib
import json
import os
import re
import subprocess
import sys
import tempfile
from pathlib import Path, PurePosixPath

from orbit_route_withdraw import caddy_publication_lock

BASE = Path("/var/lib/orbit/production")
CADDY = Path("/etc/caddy/Caddyfile")
DOMAIN = re.compile(r"(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?[.])+[a-z0-9](?:[a-z0-9-]*[a-z0-9])?")
SHA = re.compile(r"[a-f0-9]{40}")
PROJECT = "orbit-releases"


def local_paths(paths):
    for path in paths:
        relative = PurePosixPath(path)
        if (not re.fullmatch(r"[a-zA-Z0-9_-][a-zA-Z0-9_./-]*", path) or str(relative) != path
                or ".." in relative.parts or relative.parts[0] in {"current", "releases", "env", "database.sqlite"}):
            raise ValueError("Unsafe shared path")
        yield relative


def atomic(path, content):
    stat = path.stat()
    with tempfile.NamedTemporaryFile(mode="w", dir=path.parent, delete=False) as out:
        temp = Path(out.name)
        try:
            os.fchown(out.fileno(), stat.st_uid, stat.st_gid)
            os.fchmod(out.fileno(), stat.st_mode & 0o777)
            out.write(content)
            out.flush()
            os.fsync(out.fileno())
        except BaseException:
            temp.unlink(missing_ok=True)
            raise
    os.replace(temp, path)


def with_import(content, instance, routes, legacy_home=None):
    # The managed role/scope and the literal site domain must both belong to this Instance.
    # Keep TLS, address bindings and access matchers outside the ordered application handlers.
    pattern = re.compile(r"^# orbit: (\S+) ([^\n]+)\n", re.M)
    matches = list(pattern.finditer(content))
    pieces = [content[:matches[0].start()] if matches else content]
    domains = {route["domain"] for route in routes}
    by_id = {str(route["id"]):route["domain"] for route in routes}
    if any(not DOMAIN.fullmatch(domain) for domain in domains):
        raise ValueError("Invalid production Route domain")
    seen = set()
    directive = f"import {BASE}/{instance}/selected/*.caddy"
    for i, header in enumerate(matches):
        end = matches[i+1].start() if i+1 < len(matches) else len(content)
        block = content[header.start():end]
        origin = header.group(1) == "app-prod" and header.group(2) == f"app-instance-{instance}"
        public = re.fullmatch(r"route-([1-9][0-9]*)-(?:ingress|router)", header.group(2))
        if origin or (public and public[1] in by_id and (directive in block or
                (legacy_home and "root * " + legacy_home + "/current" in block))):
            site = re.search(r"^(?:https://)?([a-z0-9.-]+) \{$", block, re.M)
            if public and (not site or site[1] != by_id[public[1]]):
                raise ValueError("The public Route no longer belongs to this Instance")
            if not site or site[1] not in domains:
                raise ValueError("The published production Route set changed")
            seen.add(site[1])
            if not any(line.strip() == directive for line in block.splitlines()):
                first = re.search(r"^\s*root \* (/[^\n]+)$", block, re.M)
                last = re.search(r"^file_server\s*\n\}\s*$", block, re.M)
                if not first or not last or first.start() > last.start():
                    raise ValueError("Converge the AppProd Route before switching the release")
                block = block[:first.start()] + "\n    route {\n        " + directive + "\n" + block[first.start():last.start()] + "file_server\n    }\n}\n\n"
        pieces.append(block)
    if seen != domains:
        raise ValueError("The admitted production Routes are not all published")
    return "".join(pieces)


# Consumed inside the off-traffic preparation container, as orbit. Replace each admitted
# directory from a fresh tree so a failed first migration cannot leave deleted uploads.
INSTALL_SHARED = """
import json, os, shutil, sys, tarfile, tempfile
from pathlib import Path
root = Path(sys.argv[1])
with tempfile.TemporaryDirectory(dir=root) as directory:
    tree = Path(directory)
    with tarfile.open(mode='r|', fileobj=sys.stdin.buffer) as archive:
        archive.extractall(tree, filter='data')
    while sys.stdin.buffer.read(1024 * 1024):
        pass
    for value in json.loads(sys.argv[2]):
        source, target = tree / value, root / value
        if target.parent.resolve() != target.parent or target.is_symlink():
            raise ValueError('unsafe shared directory')
        if target.exists():
            shutil.rmtree(target)
        if source.exists():
            target.parent.mkdir(parents=True, exist_ok=True)
            os.replace(source, target)
"""


def shared_snapshot(reader, home, paths, marker, *, guest=None, destination="/srv/shared"):
    normalized = list(local_paths(paths))
    if any(left == right or left in right.parents or right in left.parents
           for i, left in enumerate(normalized) for right in normalized[i+1:]):
        raise ValueError("Overlapping production shared paths")
    existing = []
    for path in normalized:
        source = home / path
        if source.is_symlink() or source.resolve() != source:
            raise ValueError("Legacy shared path contains a symlink")
        if source.exists():
            if not source.is_dir():
                raise ValueError("Legacy shared path is not a directory")
            existing.append(str(path))
    # Deterministic GNU tar headers include the relative name, type, link target and mode.
    # File contents are read even when their size and modification time have not changed.
    tar = [*reader, "tar", "--format=gnu", "--sort=name", "--numeric-owner", "--owner=0", "--group=0",
           "-C", str(home), "-cf", "-"]
    tar.extend(["--", *sorted(existing)] if existing else ["-T", "/dev/null"])
    digest = hashlib.sha256()
    with subprocess.Popen(tar, stdout=subprocess.PIPE) as src:
        sink = None
        try:
            if guest is not None:
                sink = subprocess.Popen([*guest, "python3", "-I", "-c", INSTALL_SHARED, destination,
                                         json.dumps(paths)], stdin=subprocess.PIPE)
            while block := src.stdout.read(1024 * 1024):
                digest.update(block)
                if sink is not None:
                    sink.stdin.write(block)
            if src.wait(timeout=30) != 0:
                raise ValueError("Legacy shared files changed during the snapshot")
            if sink is not None:
                sink.stdin.close()
                if sink.wait(timeout=900) != 0:
                    raise ValueError("Cannot install the initial shared files")
        finally:
            if sink is not None:
                if sink.poll() is None:
                    sink.kill()
                sink.wait()
                sink.stdin.close()
            src.stdout.close()
    if guest is not None:
        marker.write_text(digest.hexdigest())
    elif not marker.is_file() or marker.read_text() != digest.hexdigest():
        raise ValueError("Legacy shared files changed after preparation: retry the migration while the application is quiescent")


# Executed only before the first cutover and before guest workloads start. SQLite's
# WAL, SHM and rollback journal belong to the discarded DB and must never replay.
INSTALL_SQLITE = """
import os, shutil, sys, tempfile
from pathlib import Path
target = Path(sys.argv[1])
with tempfile.NamedTemporaryFile(dir=target.parent, delete=False) as staging:
    incoming = Path(staging.name)
    try:
        shutil.copyfileobj(sys.stdin.buffer, staging)
        staging.flush()
        os.fsync(staging.fileno())
        if staging.tell() == 0:
            raise ValueError('empty SQLite snapshot')
        for suffix in ('-wal', '-shm', '-journal'):
            Path(str(target) + suffix).unlink(missing_ok=True)
        os.replace(incoming, target)
    finally:
        incoming.unlink(missing_ok=True)
"""


def copy_shared(plan, instance, machine, phase, operation):
    legacy = plan.get("legacy")
    if not legacy:
        return
    project = legacy["project_id"]
    if not isinstance(project, int) or project < 1:
        raise ValueError("Invalid legacy Project")
    user = f"orbit-app-{project}"
    home = Path("/home") / user
    if legacy["home"] != str(home) or home.resolve(strict=True) != home:
        raise ValueError("Legacy production home changed")
    inc = ["incus", "--force-local", "--project", PROJECT]
    info = json.loads(subprocess.check_output(["incus", "--force-local", "query", f"/1.0/instances/{machine}?project={PROJECT}"]))
    if info["config"].get("user.orbit.release.target") != f"instance-{instance}":
        raise ValueError("Refusing another Instance's container")
    guest = [*inc, "exec", machine, "--", "runuser", "-u", "orbit", "--"]
    reader = ["runuser", "-u", user, "--"]
    root = BASE / str(instance)
    if plan["previous"] is not None or (phase == "prepare" and
            ((root / "selected/upstream.caddy").is_symlink() or (root / "live").is_symlink())):
        raise ValueError("Cannot import legacy shared files into a serving container release")
    shared_snapshot(reader, home, plan["shared_paths"], root / "private" / f"shared-source-{operation}",
                    guest=guest if phase == "prepare" else None)
    database = home / "database.sqlite"
    if database.exists():
        if not database.is_file() or database.resolve(strict=True) != database:
            raise ValueError("Legacy SQLite database is not a regular file")
        # SQLite's backup API includes committed WAL pages. Write only a complete copy to the guest volume.
        backup = ("import os,sqlite3,sys,tempfile,shutil; "
                  "f=tempfile.NamedTemporaryFile(); src=sqlite3.connect('file:'+sys.argv[1]+'?mode=ro',uri=True); "
                  "dst=sqlite3.connect(f.name); src.backup(dst); dst.close(); src.close(); "
                  "shutil.copyfileobj(f,sys.stdout.buffer)")
        marker = BASE / str(instance) / "private" / f"sqlite-source-{operation}"
        with tempfile.TemporaryFile(dir=marker.parent) as snapshot:
            subprocess.run([*reader, "python3", "-I", "-c", backup, str(database)], stdout=snapshot, check=True, timeout=900)
            snapshot.seek(0)
            digest = hashlib.file_digest(snapshot, "sha256").hexdigest()
            if phase == "prepare":
                snapshot.seek(0)
                subprocess.run([*guest, "python3", "-I", "-c", INSTALL_SQLITE, "/srv/shared/database.sqlite"],
                               stdin=snapshot, check=True, timeout=900)
                marker.write_text(digest)
            elif not marker.is_file() or marker.read_text() != digest:
                raise ValueError("Legacy SQLite received writes after preparation: retry the migration while that application is quiescent")
    elif phase == "prepare":
        # A previous aborted first migration may have seeded SQLite. The host is still authoritative.
        subprocess.run([*guest, "rm", "-f", "--", *("/srv/shared/database.sqlite" + suffix
                        for suffix in ("", "-wal", "-shm", "-journal"))], check=True, timeout=60)
        (root / "private" / f"sqlite-source-{operation}").unlink(missing_ok=True)
    elif (BASE / str(instance) / "private" / f"sqlite-source-{operation}").exists():
        raise ValueError("Legacy SQLite source disappeared during preparation")


def publication_command(arguments, *, pass_fds):
    result = subprocess.run(arguments, pass_fds=pass_fds, text=True, capture_output=True, timeout=65)
    return result.returncode, result.stdout, result.stderr


def execute(phase, instance, operation, plan):
    sha = plan["sha"]
    if not SHA.fullmatch(sha):
        raise ValueError("Invalid release commit")
    root = BASE / str(instance)
    selected = root / "selected"
    selected.mkdir(mode=0o755, exist_ok=True)
    link = selected / "upstream.caddy"
    if link.is_symlink() and os.readlink(link) != "../upstream.caddy":
        raise ValueError("Unexpected production upstream link")
    fence = selected / "00-release-fence.caddy"
    if phase == "prepare":
        copy_shared(plan, instance, f"prepare-instance-{instance}", phase, operation)
    elif phase in ("select", "fence"):
        if phase == "select":
            copy_shared(plan, instance, f"instance-{instance}-{sha[:12]}", phase, operation)
        with caddy_publication_lock(publication_command):
            before = CADDY.read_text()
            after = with_import(before, instance, plan["routes"], (plan.get("legacy") or {}).get("home"))
            if before != after:
                atomic(CADDY, after)
            if phase == "select":
                if not link.is_symlink():
                    link.symlink_to("../upstream.caddy")
            else:
                token, health = plan["verify_token"], plan["health_path"]
                if not re.fullmatch(r"[a-f0-9]{64}", token) or not re.fullmatch(r"/[a-zA-Z0-9._~%/=-]*", health):
                    raise ValueError("Invalid private cutover probe")
                content = f"""@orbit_fenced_{instance} {{
    not {{
        method GET HEAD
        path {health}
        header X-Orbit-Release-Verify {token}
    }}
}}
respond @orbit_fenced_{instance} 503
request_header -X-Orbit-Release-Verify
"""
                # Only the host Caddy account and root may read the transaction's probe secret.
                with tempfile.NamedTemporaryFile(mode="w", dir=selected, delete=False) as out:
                    os.fchown(out.fileno(), 0, grp.getgrnam("caddy").gr_gid)
                    os.fchmod(out.fileno(), 0o640)
                    out.write(content)
                    out.flush()
                    os.fsync(out.fileno())
                os.replace(out.name, fence)
    elif phase == "restore":
        if plan["previous"] is None:
            link.unlink(missing_ok=True)
    elif phase == "unfence":
        fence.unlink(missing_ok=True)
    elif phase == "finish":
        for prefix in ("sqlite-source", "shared-source"):
            (root / "private" / f"{prefix}-{operation}").unlink(missing_ok=True)
    else:
        raise ValueError("Unknown route operation")


if __name__ == "__main__":
    if len(sys.argv) != 4 or not all(re.fullmatch(r"[1-9][0-9]*", v) for v in sys.argv[2:]):
        sys.exit(64)
    execute(sys.argv[1], int(sys.argv[2]), int(sys.argv[3]), json.load(sys.stdin))
