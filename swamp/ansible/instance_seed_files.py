"""The files that every clone of a default needs, placed in the default before its seed, and the seed's manifest.

Root runs it in the default container in the seed phase, after the app-user swap and before the snapshot. Stdin is JSON:
`{"items": [...], "ca": "<the Node's Orbit root>", "workspace": "/srv/workspace", "services_digest": "<sha256>"}`. `items` are the clone-identical items of the
executor's `instance.prepare` (file, link, folder, trust, nss), and each is placed as that primitive places it. Then the manifest
`/etc/orbit/seed-manifest.json` records, for each item's paths, what the seed holds: content digests, owners, modes and link
targets, the trust bundle's certificates, the NSS store's lines and the trust module's package version. It also records the
default's Orbit units that no clone keeps, whether the workspace is clean as a task copy's clean reads it, and the digest of the
services part the default holds. A clone's prepare
reads the manifest once and places only what differs (docs/reference/executor.md#instanceprepare).

Prints one JSON line: `{"placed": <count>, "units": <count>}`. Names only. With the argument `clean`, it only records whether the
workspace is clean, right before the snapshot with the Processes stopped, and prints `{"workspace_clean": <bool>}`.
"""

import hashlib
import json
import os
import shutil
import subprocess
import sys

MANIFEST = "/etc/orbit/seed-manifest.json"
# The agent folder's owner: root, so the agent reads it and writes nothing in it.
FOLDER_OWNER = (0, 0)
AGENT_IGNORE = "/home/agent/.config/git/ignore"
# The nested fleet's operator tunnel at each boot (tasks/instance-fleet-start.yml); the executor names it too (fleet.rs `TUNNEL_UNIT`).
FLEET_TUNNEL_UNIT = "orbit-inner-fleet-tunnel.service"
# The folders and rules of instance.prepare's purge, with no unit of the clone's own.
PURGE = [
    # The operator tunnel's boot unit stays in every clone (instance.fleet_ready).
    ("/etc/systemd/system/multi-user.target.wants", lambda n: n.startswith("orbit-") and n.endswith(".service") and n != FLEET_TUNNEL_UNIT),
    ("/etc/systemd/system", lambda n: (n.startswith("orbit-process-") and n.endswith(".service"))
     or (n.startswith("orbit-schedule-") and n.endswith((".service", ".timer"))) or n.startswith("orbit-node-service-")),
    ("/etc/orbit/processes", lambda n: True),
    ("/etc/systemd/system/sockets.target.wants", lambda n: n.startswith("orbit-node-service-")),
    ("/etc/systemd/system/timers.target.wants", lambda n: n.startswith("orbit-schedule-") and n.endswith(".timer")),
    ("/etc/orbit/schedules", lambda n: n.endswith((".sh", ".complete"))),
]


def digest(data):
    return hashlib.sha256(data).hexdigest()


def parents(path, uid, gid):
    """The missing folders above a path, below /, /home and /etc, made for its owner: 0755 for root, 0700 for anyone else."""
    missing = []
    parent = os.path.dirname(path)
    while parent not in ("", "/", "/home", "/etc") and not os.path.isdir(parent):
        missing.append(parent)
        parent = os.path.dirname(parent)
    for folder in reversed(missing):
        os.mkdir(folder, 0o755 if uid == 0 else 0o700)
        os.chown(folder, uid, gid)


def write(path, data, uid, gid, mode):
    with open(path, "wb") as stream:
        stream.write(data)
    os.chown(path, uid, gid)
    os.chmod(path, int(mode, 8))


def link(path, target):
    if os.path.islink(path) or os.path.exists(path):
        os.remove(path)
    os.symlink(target, path)


def subject_hash(pem):
    done = subprocess.run(["openssl", "x509", "-noout", "-subject_hash"], input=pem, capture_output=True, check=False)
    value = done.stdout.decode().strip()
    return value if done.returncode == 0 and len(value) == 8 else None


def certificates(text):
    return sorted({digest("".join(part.split("-----END CERTIFICATE-----")[0].split()).encode())
                   for part in text.split("-----BEGIN CERTIFICATE-----")[1:] if "-----END CERTIFICATE-----" in part})


def installed(package):
    try:
        status = open("/var/lib/dpkg/status").read()
    except OSError:
        return None
    for stanza in status.split("\n\n"):
        fields = dict(line.split(": ", 1) for line in stanza.splitlines() if ": " in line and not line.startswith(" "))
        if fields.get("Package") == package and fields.get("Architecture") == "amd64" and fields.get("Status") == "install ok installed":
            return fields.get("Version")
    return None


def place(item, ca):
    (kind, value), = item.items()
    if kind == "file":
        if value.get("parents"):
            parents(value["path"], value["uid"], value["gid"])
        write(value["path"], value["content"].encode(), value["uid"], value["gid"], value["mode"])
    elif kind == "link":
        owner = value.get("owner")
        if owner:
            parents(value["path"], owner["uid"], owner["gid"])
        link(value["path"], value["target"])
    elif kind == "folder":
        shutil.rmtree(value["path"], ignore_errors=True)
        for entry in value["files"]:
            path = value["path"] + "/" + entry["path"]
            os.makedirs(os.path.dirname(path), mode=0o755, exist_ok=True)
            write(path, entry["content"].encode(), *FOLDER_OWNER, "0644")
        os.makedirs(value["path"] + "/.skills", mode=0o755, exist_ok=True)
        for top, dirs, _ in os.walk(value["path"]):
            os.chmod(top, 0o755)
    elif kind == "trust":
        folder, path = value["hash_directory"], value["path"]
        new = subject_hash(ca.encode())
        if os.path.isfile(path):
            stale = subject_hash(open(path, "rb").read())
            if stale and stale != new and os.path.lexists(f"{folder}/{stale}.0"):
                os.remove(f"{folder}/{stale}.0")
        os.makedirs(os.path.dirname(path), exist_ok=True)
        write(path, ca.encode(), value["uid"], value["gid"], value["mode"])
        named = folder + "/" + os.path.basename(path).removesuffix(".crt") + ".pem"
        link(named, path)
        link(f"{folder}/{new}.0", named)
        bundle = folder + "/ca-certificates.crt"
        text = open(bundle).read()
        if not set(certificates(ca)) <= set(certificates(text)):
            with open(bundle, "a") as stream:
                stream.write(("" if not text or text.endswith("\n") else "\n") + ca + ("" if ca.endswith("\n") else "\n"))
    elif kind == "nss":
        if not os.path.isdir(value["home"]) or installed(value["library"]) != value["version"]:
            return
        for folder in value["directories"]:
            if not os.path.isdir(folder):
                os.mkdir(folder, int(value["directory_mode"], 8))
                os.chown(folder, value["uid"], value["gid"])
        store = open(value["path"]).read() if os.path.isfile(value["path"]) else None
        if store is None or value["entry"].splitlines()[0] not in store.splitlines():
            content = value["entry"] if not store else store + ("" if store.endswith("\n\n") else "\n" if store.endswith("\n") else "\n\n") + value["entry"]
            write(value["path"], content.encode(), value["uid"], value["gid"], value["mode"])


def held_file(path):
    stat = os.lstat(path)
    return {"sha256": digest(open(path, "rb").read()), "uid": stat.st_uid, "gid": stat.st_gid, "mode": format(stat.st_mode & 0o7777, "04o")}


def record(items, ca):
    files, links, folders, bundles, nss = {}, {}, {}, {}, {}
    for item in items:
        (kind, value), = item.items()
        path = value.get("path")
        if kind == "file" and os.path.isfile(path) and not os.path.islink(path):
            files[path] = held_file(path)
        elif kind == "link" and os.path.islink(path):
            links[path] = os.readlink(path)
        elif kind == "folder" and os.path.isdir(path):
            held = {}
            for top, _, names in os.walk(path):
                for name in names:
                    full = os.path.join(top, name)
                    held[os.path.relpath(full, path)] = digest(open(full, "rb").read())
            folders[path] = {"files": held, "skills": os.path.isdir(path + "/.skills")}
        elif kind == "trust":
            folder = value["hash_directory"]
            named = folder + "/" + os.path.basename(path).removesuffix(".crt") + ".pem"
            if os.path.isfile(path):
                files[path] = held_file(path)
            for name in (named, f"{folder}/{subject_hash(ca.encode())}.0"):
                if os.path.islink(name):
                    links[name] = os.readlink(name)
            bundle = folder + "/ca-certificates.crt"
            if os.path.isfile(bundle):
                bundles[bundle] = certificates(open(bundle).read())
        elif kind == "nss" and os.path.isfile(path):
            nss[path] = {"lines": open(path).read().splitlines(), "version": installed(value["library"])}
    return {"files": files, "links": links, "folders": folders, "bundles": bundles, "nss": nss}


def units():
    gone = []
    for folder, goes in PURGE:
        if os.path.isdir(folder):
            gone += [folder + "/" + name for name in sorted(os.listdir(folder)) if goes(name)]
    return gone


def workspace_clean(workspace):
    """Whether a task copy's clean would find nothing: no hidden index flag, and no change or untracked file as its Git reads them."""
    git = ["runuser", "-u", "agent", "--", "git", "-c", "safe.directory=" + workspace, "-c", "core.quotePath=false",
           "-c", "core.excludesFile=" + AGENT_IGNORE, "-c", "core.hooksPath=/dev/null", "-c", "core.fsmonitor=false"]
    env = dict(os.environ, HOME="/home/agent", GIT_CONFIG_GLOBAL="/dev/null", GIT_CONFIG_NOSYSTEM="1", LC_ALL="C")
    flags = subprocess.run(git + ["ls-files", "-v", "-z"], cwd=workspace, env=env, capture_output=True, check=False)
    status = subprocess.run(git + ["status", "--porcelain", "--ignore-submodules=none", "--untracked-files=all"], cwd=workspace, env=env,
                            capture_output=True, check=False)
    hidden = any(entry[:1] in (b"S", b"s") or entry[:1].islower() for entry in flags.stdout.split(b"\0") if len(entry) > 2)
    return flags.returncode == 0 and status.returncode == 0 and not hidden and status.stdout == b""


def main():
    data = json.load(sys.stdin)
    if sys.argv[1:] == ["clean"]:
        # Right before the snapshot, with the Processes stopped: the manifest's word on the tree is what the seed holds.
        with open(MANIFEST) as stream:
            manifest = json.load(stream)
        manifest["workspace_clean"] = workspace_clean(data["workspace"])
        write(MANIFEST, (json.dumps(manifest, sort_keys=True) + "\n").encode(), 0, 0, "0644")
        print(json.dumps({"workspace_clean": manifest["workspace_clean"]}))
        return
    for item in data["items"]:
        place(item, data["ca"])
    # Not clean until the seed phase checks the tree right before the snapshot (`clean`).
    # The digest of the services part the default holds (InstanceContainer::servicesDigest): a clone whose own digest matches, with
    # nothing else to start, skips that part.
    manifest = {"version": 1, **record(data["items"], data["ca"]), "units": units(), "workspace_clean": False,
                "services_digest": data.get("services_digest")}
    os.makedirs(os.path.dirname(MANIFEST), exist_ok=True)
    write(MANIFEST, (json.dumps(manifest, sort_keys=True) + "\n").encode(), 0, 0, "0644")
    print(json.dumps({"placed": len(data["items"]), "units": len(manifest["units"])}))


if __name__ == "__main__":
    main()
