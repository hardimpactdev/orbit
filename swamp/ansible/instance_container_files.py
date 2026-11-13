"""Bring every existing Instance container to the image's shared files (docs/reference/development-containers.md#existing-containers).

A new container gets these files from its image. A container made from an older image gets them here, written from the Node,
running or stopped. Nothing restarts: every later process reads them by itself. The dynamic linker reads its cache, so a running
container whose cache lacks a library of the runtime folder's `lib` runs `ldconfig -X` (no links: the folder is read-only). A
stopped container loses its cache instead, and the stock `ldconfig.service` builds it again at its next start, because it runs when
`/etc/ld.so.cache` is missing. The Node keeps a digest of the library names it last applied to every container, so a changed folder
reaches stopped containers too. Prints a JSON summary; a failed Incus call fails the run after every other container had its turn.
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


class IncusFailure(Exception):
    pass


def incus(*args, stdin=None, ok=(0,)):
    result = subprocess.run(["incus", *args], input=stdin, capture_output=True, text=True, check=False)
    if result.returncode not in ok:
        raise IncusFailure(f"incus {args[0]} {args[1] if len(args) > 1 else ''}: {result.stderr.strip()[:200]}")
    return result


def library_names(libraries):
    return sorted(name for name in os.listdir(libraries) if SONAME.match(name)) if os.path.isdir(libraries) else []


def container(project, row, files, libraries, names, libraries_changed, summary):
    name, status = row["name"], row["status"]
    label = f"{project}/{name}"
    if status not in ("Running", "Stopped"):
        summary["skipped"].append(f"{label}: {status}")
        return
    try:
        linker_written = False
        for file in files:
            target = f"{name}{file['path']}"
            current = incus("file", "pull", "--project", project, target, "-", ok=(0, 1))
            if current.returncode == 0 and current.stdout == file["content"]:
                continue
            incus("file", "push", "--project", project, "--create-dirs", "--uid", "0", "--gid", "0", "--mode", file["mode"], "-",
                  target, stdin=file["content"])
            summary["written"].append(f"{project}/{target}")
            linker_written = linker_written or file["path"] == LINKER_CONFIGURATION
        if status == "Running" and (names or linker_written):
            cache = incus("exec", "--project", project, name, "--", "ldconfig", "-p").stdout
            known = {line.split()[0] for line in cache.splitlines() if f"=> {libraries}/" in line}
            if linker_written or set(names) - known:
                incus("exec", "--project", project, name, "--", "ldconfig", "-X")
                summary["ldconfig"].append(label)
        elif status == "Stopped" and (linker_written or libraries_changed):
            # A missing cache is no failure: the stock unit builds it at the next start either way.
            incus("file", "delete", "--project", project, f"{name}/etc/ld.so.cache", ok=(0, 1))
            summary["cache_dropped"].append(label)
    except IncusFailure as failure:
        summary["failed"].append(f"{label}: {failure}")


def converge(projects, files, libraries, state):
    names = library_names(libraries)
    digest = hashlib.sha256("\n".join(names).encode()).hexdigest()
    state_file = Path(state)
    libraries_changed = (state_file.read_text().strip() if state_file.is_file() else "") != digest
    summary = {"written": [], "ldconfig": [], "cache_dropped": [], "skipped": [], "failed": []}
    # One list of every project, as the folder's restart reads it: a project that does not exist yet has no containers.
    rows = json.loads(incus("query", "/1.0/instances?all-projects=true&recursion=1").stdout)
    for row in sorted((row for row in rows if row.get("project") in projects), key=lambda row: (row["project"], row["name"])):
        container(row["project"], row, files, libraries, names, libraries_changed, summary)
    if summary["failed"]:
        print(json.dumps({"error_code": "instance.host_unavailable", **summary}))
        return 1
    # A skipped container, such as a frozen one, has not seen these libraries: the next converge drops a stopped cache again.
    if not summary["skipped"]:
        state_file.parent.mkdir(parents=True, exist_ok=True)
        state_file.write_text(digest + "\n")
    print(json.dumps(summary))
    return 0


if __name__ == "__main__":
    spec = json.loads(sys.argv[1])
    sys.exit(converge(spec["projects"], spec["files"], spec["libraries"], spec["state"]))
