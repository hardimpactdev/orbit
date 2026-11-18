#!/usr/bin/env python3
"""Publish a release upstream for the Instance's existing Caddy sites and migrate its shared files."""
import fcntl
import grp
import hashlib
import json
import os
from pathlib import Path, PurePosixPath
import re
import subprocess
import sys
import tempfile

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


def pipe(source, sink):
    # Read as the legacy app account. File names, file contents and symlinks from that home are never evaluated on the host.
    with subprocess.Popen(source, stdout=subprocess.PIPE) as src:
        try:
            result = subprocess.run(sink, stdin=src.stdout, timeout=900)
            src.stdout.close()
            if result.returncode or src.wait(timeout=10):
                raise ValueError("Could not copy the legacy shared data")
        finally:
            if src.poll() is None:
                src.kill()


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
    for path in local_paths(plan["shared_paths"]):
        source = home / path
        if not source.exists():
            continue
        if source.resolve(strict=True) != source or not source.is_dir():
            raise ValueError("Legacy shared path is not a real directory")
        pipe([*reader, "tar", "--one-file-system", "-C", str(home), "-cf", "-", "--", str(path)],
             [*guest, "tar", "--keep-newer-files", "--no-same-owner", "--no-same-permissions", "-C", "/srv/shared", "-xf", "-"])
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
                subprocess.run([*guest, "sh", "-ec",
                                'umask 077; t=$(mktemp /srv/shared/.sqlite.XXXXXXXX); trap \'rm -f -- "$t"\' EXIT; cat > "$t"; test -s "$t"; mv -fT "$t" /srv/shared/database.sqlite'],
                               stdin=snapshot, check=True, timeout=900)
                marker.write_text(digest)
            elif not marker.is_file() or marker.read_text() != digest:
                raise ValueError("Legacy SQLite received writes after preparation: retry the migration while that application is quiescent")
    elif (BASE / str(instance) / "private" / f"sqlite-source-{operation}").exists():
        raise ValueError("Legacy SQLite source disappeared during preparation")


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
        with Path("/etc/caddy/.orbit-publish.lock").open("a") as lock:
            fcntl.flock(lock, fcntl.LOCK_EX)
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
        (root / "private" / f"sqlite-source-{operation}").unlink(missing_ok=True)
    else:
        raise ValueError("Unknown route operation")


if __name__ == "__main__":
    if len(sys.argv) != 4 or not all(re.fullmatch(r"[1-9][0-9]*", v) for v in sys.argv[2:]):
        sys.exit(64)
    execute(sys.argv[1], int(sys.argv[2]), int(sys.argv[3]), json.load(sys.stdin))
