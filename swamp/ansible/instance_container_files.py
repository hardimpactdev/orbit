"""Bring every existing Instance container to the image's shared files and the Node's Orbit root CA.

docs/reference/development-containers.md#existing-containers and #trust. A new container gets these files from its image. A container
made from an older image gets them here, written from the Node through the Incus file API, whether it is running, stopped or frozen.
Nothing restarts: every later process reads the files by itself.

- The dynamic linker reads its cache. A running container whose cache lacks a library of the runtime folder's `lib` runs
  `ldconfig -X` (no links: the folder is read-only). A stopped container loses its cache instead, and the stock `ldconfig.service`
  builds it again at its next start, because it runs when `/etc/ld.so.cache` is missing. A frozen container keeps its cache: it
  thaws without a boot, so nothing would rebuild it; its wake runs the check.
- The root CA goes in as the Node holds it and under its hashed name in the CA directory, where OpenSSL, curl and PHP look it up.
  A container that trusted an older root loses that root's hashed name. A running container whose CA bundle lacks the root runs
  `update-ca-certificates`; a stopped or frozen one gets the bundle at its wake.
- Chromium reads local roots only from the user's NSS store. `agent`'s store, `~/.pki/nssdb`, gets the runtime folder's trust
  module in `pkcs11.txt`: a missing store is made with only that entry, and a store without the entry gets it appended; nothing
  else in the file changes. `agent` owns these paths, so root never writes through them: only a running container gets the store,
  written by `agent` itself. A stopped or frozen container (which can wake at any moment) gets it at the next converge that finds it
  running. A container without `/home/agent`, with a path of another type, or whose library of the module (read from its dpkg
  status) is not the exact version the module needs gets no store; in the last case `agent` removes an Orbit entry it already has.
  Each is listed in `browser_trust` with its reason, and the run still passes: one container's own state never fails the converge.
- `incus exec` refuses a frozen container, so a frozen one gets only file writes and deletes.

The Node keeps a digest of the library names it last applied to every container, so a changed folder reaches stopped containers
too. Prints a JSON summary; a failed Incus call fails the run after every other container had its turn.
"""

import hashlib
import json
import os
import re
import subprocess
import sys
from pathlib import Path

# The names the dynamic linker lists: a library's soname, with at most one version number after `.so`.
SONAME = re.compile(r"^lib.+\.so(\.[0-9]+)?$")
LINKER_CONFIGURATION = "/etc/ld.so.conf.d/zz-orbit-browser.conf"
CA_BUNDLE = "/etc/ssl/certs/ca-certificates.crt"
# A Debian version as dpkg writes it; anything else in a container's status file is not repeated.
VERSION = re.compile(r"^[0-9A-Za-z.+:~-]{1,64}$")
class IncusFailure(Exception):
    pass


def incus(*args, stdin=None, ok=(0,), timeout=None):
    # A container's own bytes, such as its dpkg status or what its user prints, need not be UTF-8: they never fail a decode.
    result = subprocess.run(["incus", *args], input=stdin, capture_output=True, text=True, errors="surrogateescape", check=False,
                            timeout=timeout)
    if result.returncode not in ok:
        raise IncusFailure(f"incus {args[0]} {args[1] if len(args) > 1 else ''}: {result.stderr.strip()[:200]}")
    return result


def subject_hash(pem):
    """OpenSSL's hashed name of a certificate, or None for text that is not one."""
    result = subprocess.run(["openssl", "x509", "-noout", "-subject_hash"], input=pem, capture_output=True, text=True, check=False)
    value = result.stdout.strip()
    return value if result.returncode == 0 and re.fullmatch(r"[0-9a-f]{8}", value) else None


def library_names(libraries):
    return sorted(name for name in os.listdir(libraries) if SONAME.match(name)) if os.path.isdir(libraries) else []


def pull(project, name, path):
    """A file's content in the container, or None when it is missing."""
    result = incus("file", "pull", "--project", project, name + path, "-", ok=(0, 1))
    return result.stdout if result.returncode == 0 else None


def installed(status, package):
    """The version of an installed amd64 package in a dpkg status file, or None."""
    for stanza in (status or "").split("\n\n"):
        fields = dict(line.split(": ", 1) for line in stanza.splitlines() if ": " in line and not line.startswith(" "))
        if fields.get("Package") == package and fields.get("Architecture") == "amd64" and fields.get("Status") == "install ok installed":
            return fields.get("Version")
    return None


def as_agent(project, name, trust, mode):
    """The store script (files/instance-browser-trust.sh) as `agent`, bounded inside the container and on the Node."""
    lines = trust["entry"].splitlines()
    return incus("exec", "--project", project, name, "--user", str(trust["uid"]), "--group", str(trust["gid"]), "--cwd", "/",
                 "--env", f"HOME={trust['home']}", "--", "timeout", "30", "sh", "-c", trust["script"], "instance-browser-trust", mode, trust["home"], trust["path"],
                 lines[0], lines[1], trust["entry"], *trust["directories"], ok=range(256), timeout=60)


def store(project, name, status, label, trust, summary):
    """`agent`'s NSS store names the trust module (docs/reference/development-containers.md#trust); the reason it does not, or None.
    Only `agent` writes it, and only in a running container: a stopped or frozen one can wake while the step runs."""
    if status != "Running":
        return "not running"
    held = installed(pull(project, name, "/var/lib/dpkg/status"), trust["library"])
    mismatch = None
    if held != trust["version"]:
        shown = "not installed" if held is None else held if VERSION.match(held) else "unreadable"
        mismatch = f"version mismatch: {shown} != {trust['version']}"
    result = as_agent(project, name, trust, "remove" if mismatch else "write")
    words = result.stdout.split()
    if result.returncode == 3:
        return "no agent home"
    if result.returncode == 4 and len(words) == 2 and words[0] in [*trust["directories"], trust["path"]]:
        return f"path refused: {words[0]} is {words[1]}"
    if result.returncode == 124:
        return "store failed: timeout"
    if result.returncode != 0 or words not in (["created"], ["appended"], ["removed"], ["unchanged"]):
        return f"store failed: exit {result.returncode}"
    if words != ["unchanged"]:
        summary["written"].append(label + trust["path"])
    return mismatch


def browser_trust(project, name, status, label, trust, summary):
    """The store, or its reason in `browser_trust`. Nothing here fails the container or the run."""
    try:
        reason = store(project, name, status, label, trust, summary)
    except subprocess.TimeoutExpired:
        reason = "store failed: timeout"
    except Exception as failure:  # noqa: BLE001 - one container's store never stops its other work or the next container
        reason = f"store failed: {type(failure).__name__}"
    if reason is not None:
        summary["browser_trust"].append({"container": label, "reason": reason})


def link_target(project, name, path):
    """Where a symlink in the container points, or None when the path is missing or is no link. The files API answers a link
    with its target as an absolute path."""
    result = incus("query", f"/1.0/instances/{name}/files?project={project}&path={path}", ok=(0, 1))
    value = result.stdout.strip()
    return value if result.returncode == 0 and value.startswith("/") and "\n" not in value else None


def delete(project, name, path):
    """Removes a file; only a missing file is no failure, anything else leaves it trusted."""
    result = incus("file", "delete", "--project", project, name + path, ok=(0, 1))
    if result.returncode and "does not exist" not in result.stderr:
        raise IncusFailure(f"incus file delete {path}: {result.stderr.strip()[:200]}")


def container(project, row, files, trust, browser, libraries, names, libraries_changed, summary):
    name, status = row["name"], row["status"]
    label = f"{project}/{name}"
    if status not in ("Running", "Stopped", "Frozen"):
        summary["skipped"].append(f"{label}: {status}")
        return
    try:
        written = set()
        # The root the container trusts now: its hashed name goes when the Node's root differs.
        held = pull(project, name, trust["path"])
        if held is not None and held != trust["content"]:
            old = subject_hash(held)
            if old is not None and old != trust["hash"]:
                delete(project, name, f"{trust['directory']}/{old}.0")
                summary["removed"].append(f"{label}{trust['directory']}/{old}.0")
        for file in files + trust["files"]:
            if pull(project, name, file["path"]) == file["content"]:
                continue
            incus("file", "push", "--project", project, "--create-dirs", "--uid", "0", "--gid", "0", "--mode", file["mode"], "-",
                  name + file["path"], stdin=file["content"])
            summary["written"].append(label + file["path"])
            written.add(file["path"])
        # The root's links, as `update-ca-certificates` makes them, so its rehash keeps them as they are.
        for path, target in trust["links"]:
            if link_target(project, name, path) == target:
                continue
            incus("file", "create", "--project", project, "--type=symlink", "--force", name + path, target)
            summary["written"].append(label + path)
        if status == "Running":
            if names or LINKER_CONFIGURATION in written:
                cache = incus("exec", "--project", project, name, "--", "ldconfig", "-p").stdout
                known = {line.split()[0] for line in cache.splitlines() if f"=> {libraries}/" in line}
                if LINKER_CONFIGURATION in written or set(names) - known:
                    incus("exec", "--project", project, name, "--", "ldconfig", "-X")
                    summary["ldconfig"].append(label)
            bundle = pull(project, name, CA_BUNDLE) or ""
            if trust["body"] not in bundle.replace("\r", ""):
                incus("exec", "--project", project, name, "--", "update-ca-certificates")
                summary["ca_bundle"].append(label)
        elif status == "Stopped" and (LINKER_CONFIGURATION in written or libraries_changed):
            # A missing cache is no failure: the stock unit builds it at the next start either way.
            incus("file", "delete", "--project", project, f"{name}/etc/ld.so.cache", ok=(0, 1))
            summary["cache_dropped"].append(label)
        elif status == "Frozen":
            summary["frozen"].append(label)
    except IncusFailure as failure:
        summary["failed"].append(f"{label}: {failure}")
    browser_trust(project, name, status, label, browser, summary)


def converge(projects, files, trust_spec, browser, libraries, state):
    names = library_names(libraries)
    digest = hashlib.sha256("\n".join(names).encode()).hexdigest()
    state_file = Path(state)
    libraries_changed = (state_file.read_text().strip() if state_file.is_file() else "") != digest
    summary = {"written": [], "removed": [], "ldconfig": [], "ca_bundle": [], "cache_dropped": [], "frozen": [], "skipped": [],
               "browser_trust": [], "failed": []}
    try:
        pem = Path(trust_spec["source"]).read_text()
    except OSError:
        pem = ""
    digest_name = subject_hash(pem)
    if digest_name is None:
        print(json.dumps({"error_code": "instance.host_unavailable",
                          "message": f"The Node holds no readable Orbit root CA at {trust_spec['source']}: its base converge writes it.", **summary}))
        return 1
    directory = trust_spec["directory"]
    linked = f"{directory}/{Path(trust_spec['path']).stem}.pem"
    trust = {"path": trust_spec["path"], "content": pem, "hash": digest_name, "directory": directory,
             "body": "".join(line for line in pem.replace("\r", "").splitlines(keepends=True) if not line.startswith("-----")),
             "files": [{"path": trust_spec["path"], "content": pem, "mode": "0644"}],
             # The files API reports a link's target as an absolute path, so each target is written as the API reports it.
             "links": [(linked, trust_spec["path"]), (f"{directory}/{digest_name}.0", linked)]}
    # One list of every project, as the folder's restart reads it: a project that does not exist yet has no containers.
    rows = json.loads(incus("query", "/1.0/instances?all-projects=true&recursion=1").stdout)
    for row in sorted((row for row in rows if row.get("project") in projects), key=lambda row: (row["project"], row["name"])):
        container(row["project"], row, files, trust, browser, libraries, names, libraries_changed, summary)
    if summary["failed"]:
        print(json.dumps({"error_code": "instance.host_unavailable", **summary}))
        return 1
    # The digest serves stopped containers. A skipped one has not seen these libraries, so the next converge drops a stopped cache
    # again; a frozen one keeps its cache and gets the check at its wake. A container listed in `browser_trust` has seen them.
    if not summary["skipped"]:
        state_file.parent.mkdir(parents=True, exist_ok=True)
        state_file.write_text(digest + "\n")
    print(json.dumps(summary))
    return 0


if __name__ == "__main__":
    spec = json.loads(sys.argv[1])
    sys.exit(converge(spec["projects"], spec["files"], spec["trust"], spec["browser_trust"], spec["libraries"], spec["state"]))
