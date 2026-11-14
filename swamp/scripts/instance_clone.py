"""One native clone phase on its Node. Public intent is argv; private input is stdin."""

import ast
import datetime
import json
import os
import pwd
import re
import shutil
import subprocess
import sys
import time
import urllib.parse
from pathlib import Path

# The Node files a `boot_files` entry may name as its `source`: the Orbit root CA that each Node's base converge writes for its agent
# (docs/reference/development-containers.md#trust). No intent can make a clone copy any other host file into a container.
BOOT_SOURCES = ("/etc/orbit/agent/ca.pem",)

# The local Incus API, whose files endpoint names a path's type in `X-Incus-Type` without following a link
# (docs/reference/development-containers.md#trust).
INCUS_SOCKET = "/var/lib/incus/unix.socket"

# Mirrored by the guest emitter; the clone tests keep the two sets in sync.
GUEST_COMMANDS = ("curl", "git", "mkdir", "php", "python3", "systemctl", "t3")



def select_branch(container, git, remote_git, run, mirror):
    """The admitted source commit in the mirror. The admission read GitHub, so the clone runs no `ls-remote`. A new branch at the seed's
    commit needs no fetch: the seed's own repository holds it, and `branch_commit` names it (docs/reference/development-containers.md#create)."""
    selection = container["branch_selection"]
    target = "refs/heads/" + selection["target"]
    source = "refs/heads/" + (selection["base"] or selection["target"])
    commit = selection["source_sha"]
    if selection["mode"] == "new" and commit == container["clone"].get("seed_commit"):
        return
    mirror()
    fetched = run(
        remote_git + ["fetch", "--quiet", "--no-tags", "--", container["repository"],
                      "+" + source + ":refs/orbit/clone-source"],
        check=False,
    )
    if fetched.returncode:
        # Git's own words for a ref the origin lacks (LC_ALL=C); the stderr itself never leaves.
        raise RuntimeError("instance.clone_branch_not_found" if "couldn't find remote ref" in fetched.stderr
                           else "instance.clone_source_unavailable")
    # Retain the exact admitted commit even when the source advanced since admission.
    observed = run(git + ["cat-file", "-e", commit + "^{commit}"], check=False)
    if observed.returncode:
        raise RuntimeError("instance.clone_source_unavailable")
    run(git + ["update-ref", target, commit])


def nic_filters(network_check):
    """The address filters of every Instance NIC: `FILTERS` of the shared NIC check, whose source this phase receives."""
    for node in ast.parse(network_check).body:
        if isinstance(node, ast.Assign) and [getattr(target, "id", "") for target in node.targets] == ["FILTERS"]:
            try:
                filters = ast.literal_eval(node.value)
            except ValueError:
                break
            if isinstance(filters, dict) and filters and all(isinstance(item, str) for pair in filters.items() for item in pair):
                return filters
            break
    raise RuntimeError("instance.nic_filters_missing")


def main():
    container = json.loads(sys.argv[1])
    guest = sys.argv[2]
    network_check = sys.argv[3]
    account = sys.argv[4]
    folder = Path(sys.argv[5])
    phase = sys.argv[-1]
    if phase not in {"container", "club-key", "workspace", "checkout", "services", "check", "t3", "sessions", "cleanup"}:
        raise ValueError("instance.clone_phase_invalid")
    name = container["name"]
    project = ["incus", "--project", container["project"]]
    changes = []
    # The Club key script's own word, which the club-key phase prints for the Operation's result.
    club_outcome = None
    workspace_deadline = time.monotonic() + 115 if phase in {"workspace", "checkout", "services"} else None
    secret = sys.stdin.read()
    # Seconds per part of this phase, printed with its result; names only (docs/reference/development-containers.md#create).
    timings = {}

    class timed:
        def __init__(self, part):
            self.part = part

        def __enter__(self):
            self.started = time.monotonic()

        def __exit__(self, *_):
            timings[self.part] = round(timings.get(self.part, 0) + time.monotonic() - self.started, 2)

    def run(argv, content=None, check=True):
        remaining = 100 if workspace_deadline is None else workspace_deadline - time.monotonic()
        if remaining <= 0:
            raise subprocess.TimeoutExpired(argv, 115)
        result = subprocess.run(
            argv,
            check=False,
            input=content,
            text=True,
            # A copy's own bytes, such as its dpkg status, need not be UTF-8: they never fail a decode.
            errors="surrogateescape",
            capture_output=True,
            timeout=remaining,
            env=dict(os.environ, LC_ALL="C", GIT_TERMINAL_PROMPT="0"),
        )
        if check and result.returncode:
            # Tools may echo private input in stderr. Report the phase, never that output.
            raise RuntimeError(
                "instance.container_"
                + phase
                + "_failed: command "
                + Path(argv[0]).name
                + " exited "
                + str(result.returncode)
            )
        return result

    def branch_commit():
        """The clone's start commit and whether it comes from the seed: the mirror's branch when a fetch put it there, else the seed's
        commit, which the seed's own repository holds. Every phase reads it the same way, so no phase passes it on. Only a branch the
        mirror lacks (`rev-parse` exit 1) starts at the seed; any other Git failure fails the phase."""
        mirror = Path(container["mirror"])
        if (mirror / "HEAD").exists():
            held = run(["git", "-c", "core.hooksPath=/dev/null", "-c", "core.fsmonitor=false", "-C", str(mirror), "rev-parse", "--verify",
                        "--quiet", "refs/heads/" + container["branch"] + "^{commit}"], check=False)
            if held.returncode == 0:
                return held.stdout.strip(), False
            if held.returncode != 1:
                raise RuntimeError("instance.container_" + phase + "_failed: command git exited " + str(held.returncode))
        seed = container.get("clone", {}).get("seed_commit")
        if not seed:
            raise RuntimeError("instance.clone_source_unavailable")
        return seed, True

    def change(argv, content=None):
        result = run(argv, content)
        changes.append(argv[0])
        return result

    def private_folder():
        folder.mkdir(mode=0o700, parents=True, exist_ok=True)
        owner = pwd.getpwnam(account)
        os.chown(folder, owner.pw_uid, owner.pw_gid)
        os.chmod(folder, 0o700)
        return owner

    def inside(member, private=""):
        if member in {"workspace", "services"}:
            # Install the shipped helper outside the app-owned store. Its only store
            # entry runs as orbit inside the existing workspace launch below.
            change(project + ["file", "push", "--create-dirs", "--uid", "0", "--gid", "0",
                              "--mode", "0755", "-", name + "/usr/local/libexec/orbit-instance-node-runtime.py"],
                   json.loads(sys.argv[6])["node"])
        payload = ["python3", "-c", guest, member, json.dumps(container)]
        result = run(project + ["exec", name, "--"] + payload, private, check=False)
        if result.returncode:
            if member == 'check':
                try:
                    evidence = json.loads(result.stdout)
                except (ValueError, TypeError):
                    evidence = {}
                if (isinstance(evidence, dict) and evidence.get('error_code') == 'instance.container_process_inactive'
                        and isinstance(evidence.get('process_failure'), dict)):
                    # Only the guest's structured public receipt crosses, as the check's own output; never its stderr.
                    public = {key: evidence[key] for key in ('error_code', 'process_failure') if key in evidence}
                    print(json.dumps(public))
                    raise RuntimeError(public['error_code'])
            # Only the guest's complete, output-free diagnostic may cross this boundary.
            diagnostic = result.stderr.strip()
            if re.fullmatch(
                r"instance\.container_"
                + member
                + r"_failed: command (?:"
                + "|".join(GUEST_COMMANDS)
                + r") exited -?[0-9]+",
                diagnostic,
            ):
                raise RuntimeError(diagnostic)
            raise RuntimeError(
                "instance.container_"
                + member
                + "_failed: command incus exited "
                + str(result.returncode)
            )
        return json.loads(result.stdout)

    if phase == "cleanup":
        shutil.rmtree(folder, ignore_errors=False) if folder.exists() else None
        print(json.dumps({"phase": phase, "changed": False}))
        return

    def boot_files():
        """The copy's files before its first start. A `source` entry is read on the Node now, before any change, and also gets its
        hashed name in `hash_directory`, where OpenSSL looks a CA up: a missing or unreadable source fails the create by name."""
        files = []
        for item in container["clone"].get("boot_files", []):
            if "entry" in item:
                files.append({key: item[key] for key in ("home", "directories", "path", "entry", "uid", "gid", "mode", "directory_mode",
                                                         "library", "version")})
                continue
            if "source" not in item:
                files.append({key: item[key] for key in ("path", "content", "uid", "gid", "mode")})
                continue
            if item["source"] not in BOOT_SOURCES:
                raise RuntimeError("instance.clone_boot_source_refused")
            try:
                content = Path(item["source"]).read_text()
            except OSError:
                raise RuntimeError("instance.clone_trust_unavailable") from None
            subject = run(["openssl", "x509", "-noout", "-subject_hash"], content, check=False)
            if subject.returncode or not re.fullmatch(r"[0-9a-f]{8}", subject.stdout.strip()):
                raise RuntimeError("instance.clone_trust_unavailable")
            entry = {key: item[key] for key in ("path", "uid", "gid", "mode")}
            files.append({**entry, "content": content})
            if item.get("hash_directory"):
                # The links that `update-ca-certificates` makes, so its rehash keeps them: `<stem>.pem` to the file, the hashed
                # name to `<stem>.pem`. `replaces` names the file whose older root's hashed name goes.
                linked = item["hash_directory"] + "/" + Path(item["path"]).stem + ".pem"
                files.append({"link": linked, "target": item["path"]})
                files.append({"link": item["hash_directory"] + "/" + subject.stdout.strip() + ".0", "target": linked,
                              "replaces": item["path"]})
                # The bundle that `update-ca-certificates` writes in the same folder, the one file p11-kit's trust module reads.
                files.append({"bundle": item["hash_directory"] + "/ca-certificates.crt", "content": content})
        return files

    def files_url(path):
        return (f"http://incus/1.0/instances/{urllib.parse.quote(name)}/files?project={urllib.parse.quote(container['project'])}"
                f"&path={urllib.parse.quote(path)}")

    def file_type(path):
        """What a path is in the copy, from the files API's `X-Incus-Type` header, or None when it is missing."""
        result = run(["curl", "--silent", "--output", "/dev/null", "--write-out", "%{http_code} %header{x-incus-type}", "--unix-socket",
                      INCUS_SOCKET, files_url(path)], check=False)
        code, _, kind = result.stdout.strip().partition(" ")
        if code == "404":
            return None
        if result.returncode or code != "200":
            raise RuntimeError("instance.clone_browser_trust_refused")
        return kind or "unknown"

    def file_read(path, refused):
        """A regular file of the copy with its owner and mode, from the files API, or None when it is missing. The headers go to stderr,
        the bytes to stdout. A link is never followed: it, any other type and a failed read refuse with `refused`."""
        result = run(["curl", "--silent", "--output", "-", "--write-out",
                      "%{stderr}%{http_code} %header{x-incus-type} %header{x-incus-uid} %header{x-incus-gid} %header{x-incus-mode}",
                      "--unix-socket", INCUS_SOCKET, files_url(path)], check=False)
        fields = result.stderr.split()
        if fields[:1] == ["404"]:
            return None
        if (result.returncode or len(fields) != 5 or fields[:2] != ["200", "file"] or not fields[2].isdigit() or not fields[3].isdigit()
                or not re.fullmatch(r"0?[0-7]{3,4}", fields[4])):
            raise RuntimeError(refused)
        return {"content": result.stdout, "uid": fields[2], "gid": fields[3], "mode": fields[4].zfill(4)[-4:]}

    def certificates(text):
        return {re.sub(r"\s", "", body) for body in re.findall(r"-----BEGIN CERTIFICATE-----(.*?)-----END CERTIFICATE-----", text, re.DOTALL)}

    def trust_bundle(item):
        """The copy's CA bundle holds the Orbit root once (docs/reference/development-containers.md#trust). Chromium's trust module reads
        only this file, and a seed taken before the root reached it lacks the root: the root goes at its end, with the bundle's own
        owner and mode. The copy has not started, so nothing changes the file between the read and the push. A bundle that already
        holds the root stays as it is. A copy without a regular bundle refuses the create: a bundle of the root alone would drop every
        public root."""
        held = file_read(item["bundle"], "instance.clone_trust_bundle_refused")
        if held is None:
            raise RuntimeError("instance.clone_trust_bundle_refused")
        if certificates(item["content"]) <= certificates(held["content"]):
            return
        bundle = held["content"]
        content = bundle + ("\n" if bundle and not bundle.endswith("\n") else "") + item["content"] + ("" if item["content"].endswith("\n") else "\n")
        change(project + ["file", "push", "--uid", held["uid"], "--gid", held["gid"], "--mode", held["mode"], "-", name + item["bundle"]], content)

    def renderer(command):
        """The one renderer of hosts text and proxy drop-ins: the bundle's instance_node_services.py, run on the Node."""
        return ["python3", "-c", json.loads(sys.argv[6])["node_services"], command]

    def proxy_drop_ins():
        """The composed proxy bypass in the copy's two drop-ins, before the first start, so its first boot reads them. One renderer
        gives the same bytes `write_proxy_environment` writes: instance_node_services.py `proxy-files`. Only `clone.proxy_environment`
        names drop-ins; without it the seed's stay."""
        proxy = container["clone"].get("proxy_environment")
        if proxy is None:
            return
        files = json.loads(run(renderer("proxy-files"), json.dumps({"proxy_environment": proxy})).stdout)["files"]
        for path, content in files.items():
            change(project + ["file", "push", "--create-dirs", "--uid", "0", "--gid", "0", "--mode", "0644", "-", name + "/" + path], content)

    def private_names_hosts():
        """The access plan's private names in the running copy's /etc/hosts, as env:sync writes them (tasks/instance-private-names.yml):
        read with `cat`, rendered by instance_node_services.py `hosts`, pushed as root with 0644 only when its lines change. It runs
        after the start, because the copy's own Incus template (`/etc/hosts: when: [create, copy]`, copied from its default) rewrites
        the file at the first start. A seed's block goes when the plan has no name. Any failure fails the create by its own name
        (docs/reference/development-containers.md#what-a-clone-has-at-its-first-start)."""
        try:
            read = run(project + ["exec", name, "--", "cat", "--", "/etc/hosts"], check=False)
            if read.returncode and "No such file or directory" not in read.stderr:
                raise RuntimeError("instance.clone_private_names_failed")
            hosts = read.stdout if read.returncode == 0 else ""
            composed = json.loads(run(renderer("hosts"), json.dumps({"hosts": hosts, "private_names": container.get("private_names", [])})).stdout)
            if composed["content"].splitlines() != hosts.splitlines():
                change(project + ["file", "push", "--uid", "0", "--gid", "0", "--mode", "0644", "-", name + "/etc/hosts"], composed["content"])
        except (RuntimeError, OSError, ValueError, KeyError, subprocess.SubprocessError):
            raise RuntimeError("instance.clone_private_names_failed") from None

    def installed(status, package):
        for stanza in status.split("\n\n"):
            fields = dict(line.split(": ", 1) for line in stanza.splitlines() if ": " in line and not line.startswith(" "))
            if fields.get("Package") == package and fields.get("Architecture") == "amd64" and fields.get("Status") == "install ok installed":
                return fields.get("Version")
        return None

    def nss_store(item):
        """`agent`'s NSS store names the runtime folder's trust module (docs/reference/development-containers.md#trust). The copy has
        not started, so no path changes between the reads and the writes. A copy without the home gets nothing. A path of another type
        than expected, or a symlink, refuses the create, as does a library of the module at another version than it needs. A missing
        store gets only the entry; a store without it gets it appended after a blank line, and its own text stays as it is."""
        if file_type(item["home"]) is None:
            return
        kinds = {path: file_type(path) for path in [item["home"], *item["directories"], item["path"]]}
        for path, kind in kinds.items():
            allowed = ("directory",) if path == item["home"] else (None, "file") if path == item["path"] else (None, "directory")
            if kind not in allowed:
                raise RuntimeError("instance.clone_browser_trust_refused")
        status = run(project + ["file", "pull", name + "/var/lib/dpkg/status", "-"], check=False)
        if installed(status.stdout if status.returncode == 0 else "", item["library"]) != item["version"]:
            raise RuntimeError("instance.clone_browser_trust_mismatch")
        owner = ["--uid", str(item["uid"]), "--gid", str(item["gid"])]
        for directory in item["directories"]:
            if kinds[directory] is None:
                change(project + ["file", "create", "--type=directory"] + owner + ["--mode", item["directory_mode"], name + directory])
        store = run(project + ["file", "pull", name + item["path"], "-"]).stdout if kinds[item["path"]] == "file" else None
        if store is not None and item["entry"].splitlines()[0] in store.splitlines():
            return
        content = item["entry"] if not store else store + ("" if store.endswith("\n\n") else "\n" if store.endswith("\n") else "\n\n") + item["entry"]
        change(project + ["file", "push"] + owner + ["--mode", item["mode"], "-", name + item["path"]], content)

    if phase == "container":
        boot = boot_files()
        owner = private_folder()
        credential = folder / "git-credential"
        credential.write_text(secret)
        credential.chmod(0o600)
        try:
            current = run(
                [
                    "incus",
                    "query",
                    f"/1.0/instances/{name}?project={container['project']}",
                ],
                check=False,
            )
            read = json.loads(current.stdout) if current.returncode == 0 else None
            # The copy's own ACL by its name; the list of every ACL on the Node took about a second.
            acl_timer = timed("acl")
            acl_timer.__enter__()
            held = run(["incus", "query", f"/1.0/network-acls/{urllib.parse.quote(container['acl'])}?project=default"], check=False)
            # Incus answers a missing ACL with 404 "Network ACL not found"; anything else refuses before the copy.
            if held.returncode and "network acl not found" not in held.stderr.lower():
                raise RuntimeError("instance.container_" + phase + "_failed: command incus exited " + str(held.returncode))
            found = json.loads(held.stdout) if held.returncode == 0 else None
            if found is None:
                change(
                    [
                        "incus",
                        "network",
                        "acl",
                        "create",
                        container["acl"],
                        "--project",
                        "default",
                    ]
                )
            if (
                found is None
                or {key: found.get(key) for key in ["description", "egress", "ingress"]}
                != container["acl_rules"]
            ):
                change(
                    [
                        "incus",
                        "network",
                        "acl",
                        "edit",
                        container["acl"],
                        "--project",
                        "default",
                    ],
                    json.dumps(container["acl_rules"]),
                )
            acl_timer.__exit__()
            copy_timer = timed("copy")
            copy_timer.__enter__()
            if read is None:
                clone = container["clone"]
                snapshots = json.loads(
                    run(
                        [
                            "incus",
                            "query",
                            f"/1.0/instances/{clone['source']}/snapshots?project={container['project']}&recursion=1",
                        ]
                    ).stdout
                )
                names = [row["name"] for row in snapshots]
                # A default with no seed at all refuses by its own code; a replaced seed means a deployment moved it, and a `next-`
                # snapshot is a retake between its delete and its rename.
                if not any(name.startswith(("seed-", "next-")) for name in names):
                    raise RuntimeError("instance.default_seed_missing")
                if clone["snapshot"] not in names:
                    raise RuntimeError("instance.default_seed_moved")
                # A copy of a default with an inner volume copies the volume's snapshot of the same seed name first, then the container
                # with its disk on the new volume (docs/reference/development-containers.md#the-inner-volume). Every other copy has no
                # `inner_volume` and runs the container copy alone, as before.
                inner = container.get("inner_volume")
                if inner is not None:
                    volumes = f"/1.0/storage-pools/{inner['pool']}/volumes/custom/"
                    seeds = run(["incus", "query", volumes + clone["inner_volume"] + f"/snapshots?project={container['project']}&recursion=1"],
                                check=False)
                    # Incus may name a volume snapshot `<volume>/<snapshot>`; the seed name is the last part. A container seed without its
                    # volume half is a seed taken before the pair existed: the default's next passed deployment takes the pair.
                    if seeds.returncode or clone["snapshot"] not in [row["name"].split("/")[-1] for row in json.loads(seeds.stdout)]:
                        raise RuntimeError("instance.default_seed_incomplete")
                    # No container yet: a volume of this name is left from a failed copy, so it is copied again from this seed.
                    if run(["incus", "query", volumes + inner["name"] + f"?project={container['project']}"], check=False).returncode == 0:
                        change(project + ["storage", "volume", "delete", inner["pool"], inner["name"]])
                    change(project + ["storage", "volume", "copy", inner["pool"] + "/" + clone["inner_volume"] + "/" + clone["snapshot"],
                                      inner["pool"] + "/" + inner["name"]])
                argv = project + [
                    "copy",
                    clone["source"] + "/" + clone["snapshot"],
                    name,
                ]
                for key, value in container["config"].items():
                    argv += ["--config", key + "=" + value]
                for key, value in {
                    "type": "disk",
                    "path": "/",
                    "pool": container["root_disk_pool"],
                    "size": container["root_disk_size"],
                }.items():
                    argv += ["--device", "root," + key + "=" + value]
                argv += [
                    "--device",
                    "eth0,ipv4.address=" + container["address"],
                    "--device",
                    "eth0,security.acls="
                    + container["nic_acl"]
                    + ","
                    + container["acl"],
                    "--device",
                    "eth0,security.acls.default.egress.action=reject",
                    "--device",
                    "eth0,security.acls.default.ingress.action=reject",
                ]
                # The NIC accepts only its own IPv4 and MAC address.
                for key, value in nic_filters(network_check).items():
                    argv += ["--device", "eth0," + key + "=" + value]
                if inner is None:
                    change(argv)
                else:
                    # The seed's disk names the default's volume; the copy's names its own.
                    argv += ["--device", inner["device"] + ",source=" + inner["name"]]
                    try:
                        change(argv)
                    except Exception:
                        # A failed container copy leaves no volume behind: the volume belongs to the copy, which does not exist.
                        run(project + ["storage", "volume", "delete", inner["pool"], inner["name"]], check=False)
                        raise
                read = json.loads(
                    run(
                        [
                            "incus",
                            "query",
                            f"/1.0/instances/{name}?project={container['project']}",
                        ]
                    ).stdout
                )
            copy_timer.__exit__()
            t3 = container["clone"]["t3"]
            if t3["device"] not in read.get("devices", {}):
                change(
                    project
                    + [
                        "config",
                        "device",
                        "add",
                        name,
                        t3["device"],
                        "proxy",
                        "listen=tcp:" + t3["listen"] + ":" + str(t3["port"]),
                        "connect=tcp:"
                        + container["address"]
                        + ":"
                        + str(t3["internal_port"]),
                        "nat=true",
                    ]
                )
            boot_timer = timed("units")
            boot_timer.__enter__()
            if read["status"] != "Running":
                files = (
                    f"/1.0/instances/{name}/files?project={container['project']}&path="
                )
                own_units = {unit["name"] for unit in container.get("processes", [])}
                own_ids = {str(unit["id"]) for unit in container.get("processes", [])}
                links, remove = [], []
                for index, directory in enumerate(
                    [
                        "/etc/systemd/system/multi-user.target.wants",
                        "/etc/systemd/system",
                        "/etc/orbit/processes",
                        "/etc/systemd/system/sockets.target.wants",
                        "/etc/systemd/system/timers.target.wants",
                        "/etc/orbit/schedules",
                    ]
                ):
                    response = run(["incus", "query", files + directory], check=False)
                    if response.returncode:
                        if index in (1, 4, 5) and response.stderr.strip() not in (
                            "Error: file does not exist", "Error: not found", "Error: Not Found", "Error: Path not found"):
                            raise RuntimeError("schedule.container_clone_cleanup_failed")
                        continue
                    for item in json.loads(response.stdout):
                        if (
                            (
                                index == 0
                                and item.startswith("orbit-")
                                and item.endswith(".service")
                            )
                            or (
                                index == 1
                                and item.startswith("orbit-process-")
                                and item.endswith(".service")
                                and item not in own_units
                            )
                            or (index == 1 and item.startswith("orbit-schedule-") and item.endswith((".service", ".timer")))
                            or (index == 4 and item.startswith("orbit-schedule-") and item.endswith(".timer"))
                            or (index == 5 and item.endswith((".sh", ".complete")))
                            or (index == 2 and item not in own_ids)
                            or (index == 3 and (item in {"orbit-mail-1025.socket", "orbit-mail-2525.socket"}
                                               or item.startswith("orbit-node-service-")))
                            or (index == 1 and item.startswith("orbit-node-service-"))
                        ):
                            (links if directory.endswith(".wants") else remove).append(name + directory + "/" + item)
                # The enablement links go first and without --force: Incus 7.0.2 resolves the path for --force, so a link whose
                # unit file is already gone (this delete's own unit files, or an earlier failed clone) fails as "file does not exist".
                if links:
                    change(project + ["file", "delete"] + links)
                if remove:
                    change(project + ["file", "delete", "--force"] + remove)
                boot_timer.__exit__()
                boot_timer = timed("boot_files")
                boot_timer.__enter__()
                change(
                    project
                    + [
                        "file",
                        "push",
                        "--uid",
                        "0",
                        "--gid",
                        "0",
                        "--mode",
                        "0444",
                        "-",
                        name + "/etc/machine-id",
                    ],
                    read["config"]["volatile.uuid"].replace("-", "") + "\n",
                )
                # Rule: only a copy that is not running gets these files; a running copy may already hold its own value from the
                # Node services apply below, which runs after the start and so writes last. An intent without the key has none.
                # The seed may trust an older root under another hashed name: read it before any push, and that name goes, so no
                # stale trust stays.
                for item in [item for item in boot if "replaces" in item]:
                    old = run(project + ["file", "pull", name + item["replaces"], "-"], check=False)
                    stale = run(["openssl", "x509", "-noout", "-subject_hash"], old.stdout, check=False) if old.returncode == 0 else None
                    if stale is not None and stale.returncode == 0 and re.fullmatch(r"[0-9a-f]{8}", stale.stdout.strip()):
                        stale_path = str(Path(item["link"]).parent) + "/" + stale.stdout.strip() + ".0"
                        if stale_path != item["link"]:
                            # Only an absent file is no failure; anything else would leave the old root trusted.
                            gone = run(project + ["file", "delete", name + stale_path], check=False)
                            if gone.returncode and "does not exist" not in gone.stderr:
                                raise RuntimeError("instance.clone_trust_unavailable")
                            changes.append("incus")
                for item in boot:
                    if "link" in item:
                        change(project + ["file", "create", "--type=symlink", "--force", name + item["link"], item["target"]])
                        continue
                    if "entry" in item:
                        nss_store(item)
                        continue
                    if "bundle" in item:
                        trust_bundle(item)
                        continue
                    change(project + ["file", "push", "--create-dirs", "--uid", str(item["uid"]), "--gid", str(item["gid"]),
                                      "--mode", item["mode"], "-", name + item["path"]], item["content"])
                proxy_drop_ins()
            boot_timer.__exit__()
            firewall_timer = timed("firewall")
            firewall_timer.__enter__()
            for rule in container.get("firewall", []):
                argv = (
                    ["ufw"]
                    + (["route"] if rule.get("route") == "yes" else [])
                    + [rule["rule"], "in", "on", rule["interface_in"]]
                )
                if rule.get("interface_out"):
                    argv += ["out", "on", rule["interface_out"]]
                argv += [
                    "from",
                    rule["from_ip"],
                    "to",
                    rule["to_ip"],
                    "port",
                    rule["to_port"],
                    "proto",
                    rule["proto"],
                    "comment",
                    rule["comment"],
                ]
                result = run(argv)
                if "Rule added" in result.stdout or "Rules updated" in result.stdout:
                    changes.append("firewall")
            firewall_timer.__exit__()
            check_timer = timed("network_check")
            check_timer.__enter__()
            effective = run(
                ["incus", "query", f"/1.0/instances/{name}?project={container['project']}"]
            ).stdout
            checked = run(
                ["python3", "-c", network_check, container["network"], "eth0"]
                + ([json.dumps(container["allowed_devices"]), json.dumps(container["config"])] if "allowed_devices" in container else [])
                # The one volume besides the root that the guard admits, with exactly its keys, as the Ansible start passes it. A copy
                # without an inner volume passes no third argument, as before, and the guard admits no volume.
                + ([json.dumps({container["inner_volume"]["device"]: {**container["inner_volume"]["config"], "size": container["inner_volume"]["size"]}})]
                   if "allowed_devices" in container and "inner_volume" in container else []),
                effective,
                check=False,
            )
            if checked.returncode == 2:
                raise RuntimeError("instance.nic_filters_missing")
            if checked.returncode:
                raise RuntimeError("instance.container_network_unsafe")
            check_timer.__exit__()
            with timed("start"):
                if read["status"] != "Running":
                    change(project + ["start", name])
            # After the start, so the copy's hosts template has already run, and before the create reports anything.
            with timed("private_names"):
                private_names_hosts()
            services_timer = timed("node_services")
            services_timer.__enter__()
            if container.get("node_services"):
                bundle = json.loads(sys.argv[6])
                applied = run(["python3", "-c", bundle["node_services"],
                               json.dumps(container), json.dumps(bundle)], check=False)
                if applied.returncode:
                    code = applied.stderr.removesuffix("\n")
                    try:
                        evidence = json.loads(applied.stdout)
                        identity = evidence.get("node_service_target", {})
                        public = {key: value for key, value in identity.items() if key in {"id", "process_id", "port"}
                                  and type(value) is int and value > 0}
                        process_name = identity.get("process_name", "")
                        if isinstance(process_name, str) and re.fullmatch(r"[A-Za-z0-9][A-Za-z0-9_.-]{0,63}", process_name):
                            public["process_name"] = process_name
                        if code in {"process.target_inactive", "instance.host_unavailable", "instance.bridge_busy",
                                    "instance.node_service_port_in_use", "instance.node_service_unit_conflict",
                                    "instance.node_service_unreachable"} and evidence.get("error_code") == code and public:
                            print("orbit-node-service-failure=" + json.dumps({"error_code": code, "node_service_target": public}), file=sys.stderr)
                    except (ValueError, TypeError, AttributeError):
                        pass
                    raise RuntimeError(code if code in {
                        "process.target_inactive", "instance.host_unavailable", "instance.bridge_busy",
                        "instance.node_service_port_in_use", "instance.node_service_unit_conflict",
                        "instance.node_service_unreachable"} else "instance.node_service_apply_failed")
            services_timer.__exit__()
            mail_timer = timed("mail")
            mail_timer.__enter__()
            if container.get("mail") is not None:
                try:
                    bundle = json.loads(sys.argv[6])
                    attempted = run(["python3", "-c", bundle["mail"],
                                     json.dumps(container), json.dumps(bundle)], check=False)
                    if attempted.returncode:
                        code = attempted.stderr.removesuffix("\n")
                        mail = {"status": "failed", "reason": code if code in
                                {"instance.host_unavailable", "process.target_inactive"} else "instance.mail_update_failed"}
                    else:
                        mail = json.loads(attempted.stdout)
                        if mail.get("status") not in {"applied", "unchanged", "skipped"} or (
                            mail["status"] == "skipped" and mail.get("reason") not in {"mail_port_in_use", "mail_unit_conflict"}
                        ):
                            raise ValueError("invalid mail outcome")
                except Exception:  # noqa: BLE001 - mail is advisory; keep arbitrary tool output private.
                    mail = {"status": "failed", "reason": "instance.mail_update_failed"}
                print("orbit-mail=" + mail["status"] + (":" + mail["reason"] if "reason" in mail else ""))
            mail_timer.__exit__()
            # Git credentials remain on the Node, and leave even when a read/fetch fails. The Node's mirror is made only for a fetch:
            # a branch the seed holds needs none.
            mirror = Path(container["mirror"])

            def make_mirror():
                mirror.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
                if not (mirror / "HEAD").exists():
                    change(["git", "init", "--quiet", "--bare", str(mirror)])

            branch_timer = timed("branch")
            branch_timer.__enter__()
            git = [
                "git",
                "-c",
                "core.hooksPath=/dev/null",
                "-c",
                "core.fsmonitor=false",
                "-C",
                str(mirror),
            ]
            remote_git = (
                [
                    "timeout",
                    "80",
                    "env",
                    "GIT_CONFIG_NOSYSTEM=1",
                    "GIT_CONFIG_GLOBAL=/dev/null",
                ]
                + git
                + [
                    "-c",
                    "credential.helper=",
                    "-c",
                    "credential.helper=store --file=" + str(credential),
                    "-c",
                    "http.followRedirects=false",
                    "-c",
                    "url.https://github.com/.insteadOf=git@github.com:",
                    "-c",
                    "url.https://github.com/.insteadOf=ssh://git@github.com/",
                    "-c",
                    "protocol.allow=never",
                    "-c",
                    "protocol." + container["protocols"][0] + ".allow=always",
                ]
            )
            if "branch_selection" in container:
                select_branch(container, git, remote_git, run, make_mirror)
            else:
                # A create by name only: GitHub says whether the origin has the branch. A branch it lacks starts at the seed's commit.
                branch = "refs/heads/" + container["branch"]
                # `ls-remote` needs no repository: it runs with the same options outside the mirror, which may not exist.
                outside = remote_git[: remote_git.index("-C")] + remote_git[remote_git.index("-C") + 2 :]
                remote = run(
                    outside
                    + ["ls-remote", "--heads", "--", container["repository"], branch]
                ).stdout.strip()
                if remote:
                    make_mirror()
                    run(
                        remote_git
                        + [
                            "fetch",
                            "--quiet",
                            "--no-tags",
                            "--",
                            container["repository"],
                            "+" + branch + ":" + branch,
                        ]
                    )
            branch_timer.__exit__()
        finally:
            credential.unlink(missing_ok=True)
    elif phase == "club-key":
        # The nested Gateway's Club key, from SSH stdin into the copy's /run, a tmpfs that no snapshot holds
        # (docs/reference/development-containers.md#the-club-key-of-the-nested-gateway). The value is only subprocess input.
        club = container["club_key"]
        if not club.get("available") or not secret:
            raise RuntimeError("instance.nested_club_key_delivery_failed")
        if run(project + ["exec", name, "--", "findmnt", "--noheadings", "--output", "FSTYPE", "--target", "/run"]).stdout.strip() != "tmpfs":
            raise RuntimeError("instance.nested_club_key_not_tmpfs")
        written = run(project + ["exec", name, "--", "sh", "-c", json.loads(sys.argv[6])["club_key"], "sh", club["directory"], club["file"],
                                 str(club["owner"]), str(club["group"]), club["tmpfiles"], club["gateway"], club["serve_unit"]], secret, check=False)
        outcome = written.stdout.strip()
        if written.returncode or outcome not in {"changed", "changed_serve_pending", "unchanged"}:
            raise RuntimeError("instance.nested_club_key_not_tmpfs" if written.returncode == 3 else "instance.nested_club_key_delivery_failed")
        if outcome != "unchanged":
            changes.append("club-key")
        club_outcome = outcome
    elif phase == "services":
        # The rest of the workspace reads only the Process environment files of the one framed vault value.
        print(json.dumps(inside("services", json.dumps({"processes": json.loads(secret)["processes"]}))))
        return
    elif phase == "check":
        print(json.dumps(inside("check", "")))
        return
    elif phase in {"workspace", "checkout"}:
        # Admission frames the private values and the clone's key names in one vault copy for native SSH stdin. `workspace` forwards
        # all of it; `checkout` and `services` read the same value and forward only their half, so two steps need no second copy.
        workspace = json.loads(secret)
        environment = workspace["environment"]
        processes = workspace["processes"]
        # A value framed before the merge names existed has none; the guest then writes the render.
        merge = workspace.get("merge")
        private = {"environment": environment, "merge": merge}
        if phase == "workspace":
            private = {"environment": environment, "processes": processes, "merge": merge}
        git = [
            "git",
            "-c",
            "core.hooksPath=/dev/null",
            "-c",
            "core.fsmonitor=false",
            "-C",
            container["mirror"],
        ]
        target, from_seed = branch_commit()
        container["expected_commit"] = target
        bundle = folder / "workspace.bundle"
        try:
            # Only a fetched branch goes in as a bundle; the seed's own repository holds a branch at the seed's commit.
            if not from_seed:
                container["bundle"] = "/tmp/orbit-workspace-" + folder.name + ".bundle"
                with timed("bundle"):
                    run(
                        git
                        + [
                            "bundle",
                            "create",
                            "--quiet",
                            str(bundle),
                            "refs/heads/" + container["branch"],
                        ]
                    )
                    run(
                        project
                        + [
                            "file",
                            "push",
                            "--uid",
                            "1000",
                            "--gid",
                            "2760",
                            "--mode",
                            "0640",
                            str(bundle),
                            name + container["bundle"],
                        ]
                    )
            with timed("guest"):
                output = inside(phase, json.dumps(private))
            # The guest's commit is its first checkout's, so a retry never reports a later commit of the user.
            print("orbit-commit=" + output.pop("commit"))
            print(json.dumps({**output, "timings": timings}))
        finally:
            bundle.unlink(missing_ok=True)
        return
    elif phase in {"t3", "sessions"}:
        cached = folder / "t3.json"

        def part(name):
            # The part that runs now, written before it starts, so a failure or a timeout leaves it for the create result:
            # clone-session-copy copies this file when clone-t3 fails. Success removes it before sessions reads the file.
            owner = private_folder()
            cached.write_text(json.dumps({"t3_part": name}))
            cached.chmod(0o600)
            os.chown(cached, owner.pw_uid, owner.pw_gid)

        def expected_commit():
            container["expected_commit"] = branch_commit()[0]

        if phase == "t3":
            # One step, two guest phases in order: the agent's files from the model key on stdin, then T3's first start, which
            # reads no private input. The step prints only T3's receipt; a failure names its own phase.
            part("agent")
            with timed("agent"):
                inside("agent", secret)
            part("t3")
            expected_commit()
            with timed("t3"):
                output = inside("t3", "")
            cached.unlink()
        else:
            expected_commit()
            with timed("guest"):
                output = inside(phase, cached.read_text() if cached.exists() else "")
        reply = output.pop("private_reply", None)
        if reply is not None and not cached.exists():
            owner = private_folder()
            cached.write_text(json.dumps(reply))
            cached.chmod(0o600)
            os.chown(cached, owner.pw_uid, owner.pw_gid)
        print(json.dumps({**output, "timings": timings}))
        return
    print(
        json.dumps(
            {
                "phase": phase,
                "changed": bool(changes),
                **({"club_key": club_outcome} if club_outcome is not None else {}),
                **({"timings": timings} if phase == "container" else {}),
                "completed_at": datetime.datetime.now(datetime.UTC).isoformat(),
            }
        )
    )


try:
    main()
except Exception as error:  # noqa: BLE001 - redact private subprocess failures at the command boundary.
    message = str(error)
    code = (
        message
        if message.startswith("instance.") and "\n" not in message
        else "instance.container_phase_failed"
    )
    print(code, file=sys.stderr)
    raise SystemExit(1)
