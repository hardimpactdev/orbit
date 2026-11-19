"""One native clone phase on its Node. Public intent is argv; private input is stdin."""

import ast
import datetime
import errno
import json
import os
import pwd
import re
import shutil
import stat
import struct
import subprocess
import sys
import time
import urllib.parse
from concurrent.futures import ThreadPoolExecutor
from pathlib import Path

# The Node files a `boot_files` entry may name as its `source`: the Orbit root CA that each Node's base converge writes for its agent
# (docs/reference/development-containers.md#trust). No intent can make a clone copy any other host file into a container.
BOOT_SOURCES = ("/etc/orbit/agent/ca.pem",)

# The local Incus API, whose files endpoint names a path's type in `X-Incus-Type` without following a link
# (docs/reference/development-containers.md#trust).
INCUS_SOCKET = "/var/lib/incus/unix.socket"

# Mirrored by the guest emitter; the clone tests keep the two sets in sync.
GUEST_COMMANDS = ("conn-server", "orbit-app-user", "curl", "git", "mkdir", "php", "python3", "systemctl", "t3")

# A failed command's detail: its code line, what it did and the end of its stderr, in at most this many UTF-8 bytes, so the native
# step's wrapper ("exec failed on 1/1 host(s): target (exit 1: ...)") still fits the Gateway's 4,096-byte detail.
DETAIL_BYTES = 4000
# The shortest private value that is replaced; shorter ones would blank ordinary words (the Gateway's LogRedactor::ShortestValue).
SHORTEST_VALUE = 8


class PhaseFailure(RuntimeError):
    """A phase's failure with its public detail: the code line, then the redacted end of the command's stderr."""

    def __init__(self, line, detail=""):
        super().__init__(line)
        self.line = line
        self.detail = detail


def private_values(*texts):
    """Every private value a phase holds: each string of its framed stdin JSON (or the text itself), each line of it and each value
    of a KEY=VALUE line, at least SHORTEST_VALUE characters long, longest first."""
    values = set()

    def add(text):
        for piece in [text, *text.splitlines()]:
            for value in (piece, piece.split("=", 1)[1].strip().strip("'\"") if "=" in piece else ""):
                if len(value.strip()) >= SHORTEST_VALUE:
                    values.add(value.strip())

    def walk(item):
        if isinstance(item, dict):
            for value in item.values():
                walk(value)
        elif isinstance(item, list):
            for value in item:
                walk(value)
        elif isinstance(item, str):
            add(item)

    for text in texts:
        if not text:
            continue
        try:
            walk(json.loads(text))
        except ValueError:
            pass
        add(text)
    return sorted(values, key=len, reverse=True)


def public_text(text, values, room):
    """The end of a command's stderr that may leave the Node, in at most `room` UTF-8 bytes. Known private values are replaced in one
    pass; credentials in URLs, Authorization headers and `password=`-style pairs are replaced by shape; a line that still holds an
    8-character piece of a private value is dropped; only whole lines are kept. The Gateway redacts the detail once more."""
    text = text.encode("utf-8", "surrogateescape").decode("utf-8", "replace")[-65536:]
    text = re.sub(r"\x1b\[[0-?]*[ -/]*[@-~]|[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]", "", text)
    if values:
        text = re.sub("|".join(re.escape(value) for value in values), "[REDACTED]", text)
    # The shapes of the Gateway's CommandActivityInputSanitizer::redactText, applied here because native run history keeps stderr.
    text = re.sub(r"-----BEGIN [A-Z0-9 ]+-----.*?(?:-----END [A-Z0-9 ]+-----|\Z)", "[REDACTED]", text, flags=re.S)
    text = re.sub(r"(?i)\b([a-z][a-z0-9+.-]*://)[^/@\s]+@", r"\1[REDACTED]@", text)
    text = re.sub(r"(?i)\b((?:proxy-)?authorization)\s*:\s*\S+(?:\s+\S+)?", r"\1: [REDACTED]", text)
    text = re.sub(r"(?i)\bbearer\s+\S{8,}", "Bearer [REDACTED]", text)
    text = re.sub(r"(?<![A-Za-z0-9])(?:gh[pousr]_[A-Za-z0-9_]{16,}|github_pat_[A-Za-z0-9_]{20,})(?![A-Za-z0-9])", "[REDACTED]", text)
    text = re.sub(r"(?<![A-Fa-f0-9])[A-Fa-f0-9]{64}(?![A-Fa-f0-9])", "[REDACTED]", text)
    text = re.sub(r"(?i)\b(\w*(?:password|passwd|secret|token|api[_-]?key|credential)\w*)\s*([=:])\s*(?:\"[^\"]*\"|'[^']*'|[^\s,;&]+)",
                  r"\1\2[REDACTED]", text)
    # Pieces come from the values themselves, not from whole KEY=VALUE lines or files, whose keys are public words.
    pieces = {value[i:i + SHORTEST_VALUE] for value in values if "\n" not in value and not re.match(r"[A-Za-z_][A-Za-z0-9_]*=", value)
              for i in range(len(value) - SHORTEST_VALUE + 1)}
    kept, used = [], 0
    for line in reversed(text.strip().splitlines()):
        if any(line[i:i + SHORTEST_VALUE] in pieces for i in range(len(line) - SHORTEST_VALUE + 1)):
            continue
        used += len(line.encode()) + 1
        if used > room + 1:
            break
        kept.append(line)
    return "\n".join(reversed(kept))


def purpose(argv):
    """What a command did, in public words: its program and plain arguments, such as `incus file push - instance-7/usr/...`.
    Options, their values, `-c` programs and configuration pairs, long values and values with `=` never appear."""
    words = []
    skip = False
    for word in argv:
        if skip:
            skip = False
        elif word in {"--project", "--uid", "--gid", "--mode", "-c", "--output", "--write-out", "--unix-socket"}:
            skip = True
        elif not word.startswith("-") and ("=" not in word or word.startswith("/")) and "\n" not in word and len(word) <= 120:
            words.append(word)
        if len(words) == 8:
            break
    return " ".join(words)



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


def root_device(container, run):
    """The root device to write and admit: the bundle's instance_root_disk.py, the one rule that create and start also run, given
    the driver of the root pool."""
    intent = {"type": "disk", "path": "/", "pool": container["root_disk_pool"], "size": container["root_disk_size"]}
    pool = json.loads(run(["incus", "query", "/1.0/storage-pools/" + urllib.parse.quote(container["root_disk_pool"], safe="")]).stdout)
    return json.loads(run(["python3", "-c", json.loads(sys.argv[6])["root_disk"], json.dumps(intent), pool["driver"]]).stdout)


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


def unshift_clone_rootfs(container, read, run):
    """Normalize a stopped local copy whose restricted project stripped its on-disk idmap.

    Incus retains the source's shifted files on non-idmapped mounts but strips the unsafe
    volatile key during copy. Unshift those files so its first start can apply its own map.
    Sources using idmapped mounts record [] and need no repair. Never change Incus config.
    """
    if read["status"] != "Stopped":
        return False
    try:
        if json.loads(read.get("config", {}).get("volatile.last_state.idmap", "[]")):
            return False
        source = json.loads(run(["incus", "query", "/1.0/instances/" + urllib.parse.quote(container["clone"]["source"], safe="")
                                 + "?project=" + urllib.parse.quote(container["project"], safe="")]).stdout)
        ranges = json.loads(source["config"].get("volatile.last_state.idmap", "[]"))
        if not ranges:
            return False
        pool = json.loads(run(["incus", "query", "/1.0/storage-pools/" + urllib.parse.quote(container["root_disk_pool"], safe="")]).stdout)
    except (OSError, ValueError, KeyError, TypeError) as error:
        raise RuntimeError("instance.clone_idmap_unshift_failed") from error
    if pool.get("driver") != "btrfs":
        raise RuntimeError("instance.clone_idmap_unshift_unsupported")

    try:
        root = Path(pool["config"]["source"]) / "containers" / (container["project"] + "_" + container["name"]) / "rootfs"
        uids = [(r["Hostid"], r["Nsid"], r["Maprange"]) for r in ranges if r["Isuid"]]
        gids = [(r["Hostid"], r["Nsid"], r["Maprange"]) for r in ranges if r["Isgid"]]

        def back(ident, maps):
            for host, ns, size in maps:
                if host <= ident < host + size:
                    return ident - host + ns
            return ident

        def acl(value):
            out = bytearray(value)
            for at in range(4, len(value) - 7, 8):
                tag, _perm, ident = struct.unpack_from("<HHI", value, at)
                if tag in (0x02, 0x08):  # Named USER and GROUP entries; object entries have no ID.
                    struct.pack_into("<I", out, at + 4, back(ident, uids if tag == 0x02 else gids))
            return bytes(out)

        def xattr(path, name):
            try:
                return os.getxattr(path, name, follow_symlinks=False)
            except OSError as error:
                if error.errno not in (errno.ENODATA, errno.ENOTSUP):
                    raise
                return None

        changed = False

        def one(path):
            nonlocal changed
            held = os.lstat(path)
            link = stat.S_ISLNK(held.st_mode)
            uid, gid = back(held.st_uid, uids), back(held.st_gid, gids)
            owner_changed = (uid, gid) != (held.st_uid, held.st_gid)
            # chown can discard capabilities and clear setuid/setgid. Capture all metadata first.
            attrs = {} if link else {name: xattr(path, name) for name in
                                     ("security.capability", "system.posix_acl_access", "system.posix_acl_default")}
            if owner_changed:
                os.lchown(path, uid, gid)
                if not link:
                    os.chmod(path, stat.S_IMODE(held.st_mode))
                changed = True
            for name, value in attrs.items():
                if value is None:
                    continue
                rewritten = value
                if name == "security.capability":
                    # VFS_CAP_REVISION_3 stores the namespace's root ID at offset 20.
                    if len(value) == 24 and struct.unpack_from("<I", value)[0] & 0xff000000 == 0x03000000:
                        rewritten = value[:20] + struct.pack("<I", back(struct.unpack_from("<I", value, 20)[0], uids))
                else:
                    rewritten = acl(value)
                if owner_changed or rewritten != value:
                    os.setxattr(path, name, rewritten, follow_symlinks=False)
                    changed = True

        if not stat.S_ISDIR(os.lstat(root).st_mode):
            raise OSError("rootfs is not a directory")
        one(root)

        def walk_error(error):
            raise error

        for top, dirs, files in os.walk(root, followlinks=False, onerror=walk_error):
            for name in dirs + files:
                one(os.path.join(top, name))
        return changed
    except (OSError, ValueError, KeyError, TypeError, struct.error) as error:
        raise RuntimeError("instance.clone_idmap_unshift_failed") from error


def main():
    container = json.loads(sys.argv[1])
    guest = sys.argv[2]
    network_check = sys.argv[3]
    account = sys.argv[4]
    folder = Path(sys.argv[5])
    phase = sys.argv[-1]
    if phase not in {"container", "club-key", "workspace", "checkout", "services", "check", "complete", "t3", "cleanup"}:
        raise ValueError("instance.clone_phase_invalid")
    name = container["name"]
    project = ["incus", "--project", container["project"]]
    changes = []
    # The Club key script's own word, which the club-key phase prints for the Operation's result.
    club_outcome = None
    # The T3 step runs the checkout too, so the checkout's own bound holds there as well.
    workspace_deadline = time.monotonic() + 115 if phase in {"workspace", "checkout", "services", "complete", "t3"} else None
    secret = sys.stdin.read()
    if phase == "services":
        # The completion's one copy also holds the release scripts (docs/reference/development-containers.md#complete-a-clone). The
        # phase keeps only the Process files, so a failure's redaction weighs them alone, not every script line.
        secret = json.dumps({"processes": json.loads(secret)["processes"]})
    # Seconds per part of this phase, printed with its result; names only (docs/reference/development-containers.md#create).
    timings = {}

    class timed:
        # `into`: the T3 step's checkout runs beside its T3 part and keeps its own seconds, printed as the checkout's.
        def __init__(self, part, into=None):
            self.part = part
            self.into = timings if into is None else into

        def __enter__(self):
            self.started = time.monotonic()

        def __exit__(self, *_):
            self.into[self.part] = round(self.into.get(self.part, 0) + time.monotonic() - self.started, 2)

    def failure(code, argv, result, content=None):
        """A failed command as the phase's failure: its code line, what it did, and the redacted end of its stderr."""
        values = private_values(secret, content if isinstance(content, str) else None)
        line = code + " (" + public_text(purpose(argv), values, 400) + ")"
        return PhaseFailure(line, public_text(result.stderr or "", values, DETAIL_BYTES - len(line.encode()) - 2))

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
            # Tools may echo private input in stderr: only its redacted end leaves, after the code line.
            raise failure("instance.container_" + phase + "_failed: command " + Path(argv[0]).name + " exited " + str(result.returncode),
                          argv, result, content)
        return result

    def branch_commit():
        """The clone's start commit and whether it comes from the seed: the mirror's branch when a fetch put it there, else the seed's
        commit, which the seed's own repository holds. Every phase reads it the same way, so no phase passes it on. Only a branch the
        mirror lacks (`rev-parse` exit 1) starts at the seed; any other Git failure fails the phase."""
        mirror = Path(container["mirror"])
        if (mirror / "HEAD").exists():
            argv = ["git", "-c", "core.hooksPath=/dev/null", "-c", "core.fsmonitor=false", "-C", str(mirror), "rev-parse", "--verify",
                    "--quiet", "refs/heads/" + container["branch"] + "^{commit}"]
            held = run(argv, check=False)
            if held.returncode == 0:
                return held.stdout.strip(), False
            if held.returncode != 1:
                raise failure("instance.container_" + phase + "_failed: command git exited " + str(held.returncode), argv, held)
        # The container phase records the commit of a newer seed that it copied after a retake; the copy holds that seed's files.
        moved = folder / "seed-commit"
        seed = moved.read_text().strip() if moved.is_file() else container.get("clone", {}).get("seed_commit")
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

    def inside(member, private="", intent=None):
        # `intent`: the checkout's own copy of the intent, so the T3 part beside it never reads a dict that it changes.
        intent = container if intent is None else intent
        # Only the agent part reads the agent folder's content (development-containers.md#agent-instructions); every other member's one
        # argument stays without it, beside the Node runtime helper that the workspace and services parts carry.
        if member != "agent" and "agent_instructions" in intent.get("clone", {}):
            intent = {**intent, "clone": {k: v for k, v in intent["clone"].items() if k != "agent_instructions"}}
        if member in {"workspace", "services"}:
            # The guest writes the shipped Node runtime helper itself, in this one exec: no Incus file call, whose helper may exit as
            # the request reaches it (docs/reference/development-containers.md#a-failed-clone-phase-names-its-command). The services
            # part runs root's workspace pass, the program a deployment runs (ADR 0268).
            bundle = json.loads(sys.argv[6])
            intent = {**intent, "node_runtime": bundle["node"], "workspace_owner": bundle["workspace_owner"]}
        payload = ["python3", "-c", guest, member, json.dumps(intent)]
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
            # The guest's own diagnostic line becomes the code line; any other first line, such as Incus's own error, makes it
            # "command incus exited N". The rest of the stderr follows only as its redacted end.
            first, _, rest = (result.stderr or "").strip().partition("\n")
            if re.fullmatch(
                r"instance\.container_"
                + member
                + r"_failed: command (?:"
                + "|".join(GUEST_COMMANDS)
                + r") exited -?[0-9]+",
                first,
            ):
                values = private_values(secret, private)
                raise PhaseFailure(first, public_text(rest, values, DETAIL_BYTES - len(first.encode()) - 2))
            raise failure("instance.container_" + member + "_failed: command incus exited " + str(result.returncode),
                          project + ["exec", name, "--", "python3", "guest", member], result, private)
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

    def file_type(path, refused="instance.clone_browser_trust_refused"):
        """What a path is in the copy, from the files API's `X-Incus-Type` header, or None when it is missing."""
        result = run(["curl", "--silent", "--output", "/dev/null", "--write-out", "%{http_code} %header{x-incus-type}", "--unix-socket",
                      INCUS_SOCKET, files_url(path)], check=False)
        code, _, kind = result.stdout.strip().partition(" ")
        if code == "404":
            return None
        if result.returncode or code != "200":
            raise RuntimeError(refused)
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
        """The access plan's private names and the presented machine name in the running copy, as env:sync and the container phase
        write them (tasks/instance-private-names.yml, tasks/instance-machine-name.yml). One exec writes /etc/hostname, sets the kernel
        hostname and reads /etc/hosts; instance_node_services.py `hosts` renders the private names block and instance_machine_name.py
        `hosts` the `127.0.1.1` line, each keeping the other's lines; /etc/hosts is pushed as root with 0644 only when it changes. It runs
        after the start, because the copy's own Incus templates (`/etc/hosts` and `/etc/hostname`: `when: [create, copy]`, copied from
        its default) rewrite both files at the first start. T3 starts with the copy's boot, so it has started by then: its label comes
        from `/etc/machine-info`, a boot file written before the start, so it is right from T3's first start. A seed's block goes when the plan has no name. Any failure, the
        hostname's among them, fails the create by its own name (docs/reference/development-containers.md#what-a-clone-has-at-its-first-start)."""
        try:
            hostname = container["machine_name"]["hostname"]
            read = run(project + ["exec", name, "--", "sh", "-c", 'printf "%s\\n" "$1" > /etc/hostname && hostname "$1" && cat -- /etc/hosts',
                                  "sh", hostname], check=False)
            if read.returncode and "No such file or directory" not in read.stderr:
                raise RuntimeError("instance.clone_private_names_failed")
            hosts = read.stdout if read.returncode == 0 else ""
            composed = json.loads(run(renderer("hosts"), json.dumps({"hosts": hosts, "private_names": container.get("private_names", [])})).stdout)
            named = json.loads(run(["python3", "-c", json.loads(sys.argv[6])["machine_name"], "hosts"],
                                   json.dumps({"hosts": composed["content"], "hostname": hostname, "container": name})).stdout)
            if named["content"].splitlines() != hosts.splitlines():
                change(project + ["file", "push", "--uid", "0", "--gid", "0", "--mode", "0644", "-", name + "/etc/hosts"], named["content"])
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

    def start_input():
        """The credential line and, from a Gateway that frames it (`model_environment.frame`), the model environment after the frame
        line. Without the frame the copy gets no T3 files, and the T3 step's agent part refuses: nothing else writes the model environment."""
        frame = container.get("clone", {}).get("model_environment", {}).get("frame")
        if frame:
            line, marker, model = secret.partition(frame + "\n")
            if marker and (line == "" or line.endswith("\n")):
                return line, model
        return secret, None

    def t3_start_files(model):
        """T3's files before the copy's first start, so the copy's boot starts T3 (docs/reference/development-containers.md#t3-starts-with-
        the-copy): the model environment, the `before_start` files and last the file T3's unit waits for. The copy is stopped, so nothing
        reads a half-written file. A JSON file merges into the seed's own, as the agent part does; a missing folder is made for its
        owner, 0700 for `agent` and 0755 for root. The agent part then finds them all in place."""
        clone = container["clone"]
        condition = clone["t3"]["condition"]
        files = [dict(clone["model_environment"], content=model)] + sorted(
            [item for item in clone["files"] if item.get("before_start")], key=lambda item: item["path"] == condition)
        for item in files:
            content = item.get("content")
            if "json" in item:
                held = file_read(item["path"], "instance.clone_t3_files_refused")
                old = json.loads(held["content"]) if held is not None else {}
                # A seed file that holds the keys with the agent part's owner and mode stays as it is.
                if held is not None and old | item["json"] == old and (held["uid"], held["gid"], held["mode"]) == (
                        str(item["uid"]), str(item["gid"]), item["mode"].zfill(4)):
                    continue
                content = json.dumps(old | item["json"], separators=(",", ":")) + "\n"
            parents = []
            for parent in Path(item["path"]).parents:
                if str(parent) in ("/", "/home", "/etc"):
                    break
                # A folder that is a link, or not a folder, is refused: a push would follow it out of the agent's home.
                kind = file_type(str(parent), "instance.clone_t3_files_refused")
                if kind not in (None, "directory"):
                    raise RuntimeError("instance.clone_t3_files_refused")
                if kind == "directory":
                    break
                parents.append(str(parent))
            for parent in reversed(parents):
                change(project + ["file", "create", "--type=directory", "--uid", str(item["uid"]), "--gid", str(item["gid"]),
                                  "--mode", "0755" if item["uid"] == 0 else "0700", name + parent])
            change(project + ["file", "push", "--uid", str(item["uid"]), "--gid", str(item["gid"]), "--mode", item["mode"], "-",
                              name + item["path"]], content)

    def checkout(part, value):
        """The checkout part of the workspace phase (`workspace` forwards the Process files too): the bundle when the mirror holds the
        branch, then the guest's checkout. Returns the lines it prints: the commit and its JSON line with its own seconds."""
        seconds = {}
        # Admission frames the private values and the clone's key names in one vault copy for native SSH stdin. `workspace` forwards
        # all of it; `checkout` and `services` read the same value and forward only their half, so two steps need no second copy.
        workspace = json.loads(value)
        environment = workspace["environment"]
        processes = workspace["processes"]
        # A value framed before the merge names existed has none; the guest then writes the render.
        merge = workspace.get("merge")
        private = {"environment": environment, "merge": merge}
        if part == "workspace":
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
        intent = dict(container, expected_commit=target)
        bundle = folder / "workspace.bundle"
        try:
            # Only a fetched branch goes in as a bundle; the seed's own repository holds a branch at the seed's commit.
            if not from_seed:
                intent["bundle"] = "/tmp/orbit-workspace-" + folder.name + ".bundle"
                with timed("bundle", seconds):
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
                            "2750",
                            "--gid",
                            str(container["workspace_ids"]["group"]),
                            "--mode",
                            "0640",
                            str(bundle),
                            name + intent["bundle"],
                        ]
                    )
            with timed("guest", seconds):
                output = inside(part, json.dumps(private), intent)
            # The guest's commit is its first checkout's, so a retry never reports a later commit of the user.
            return ["orbit-commit=" + output.pop("commit"), json.dumps({**output, "timings": seconds})]
        finally:
            bundle.unlink(missing_ok=True)

    if phase == "container":
        boot = boot_files()
        private_folder()
        line, model = start_input()
        credential = folder / "git-credential"
        credential.write_text(line)
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
            acl_query = ["incus", "query", f"/1.0/network-acls/{urllib.parse.quote(container['acl'])}?project=default"]
            held = run(acl_query, check=False)
            # Incus answers a missing ACL with 404 "Network ACL not found"; anything else refuses before the copy.
            if held.returncode and "network acl not found" not in held.stderr.lower():
                raise failure("instance.container_" + phase + "_failed: command incus exited " + str(held.returncode), acl_query, held)
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
                # The step before this one waited, by curl's own retry, up to 120 s for the admitted seed: a retake at its commit has a
                # moment between the delete and the rename (docs/reference/development-containers.md#a-retake-keeps-the-seed). This
                # reads the snapshots once. A default with no seed at all refuses by its own code; the admitted seed's `next-` still
                # there is a retake that did not end within the wait; a seed that a deployment replaced by a newer one is copied.
                snapshot = clone["snapshot"]
                if not any(name.startswith(("seed-", "next-")) for name in names):
                    raise RuntimeError("instance.default_seed_missing")
                if snapshot not in names:
                    seeds = [row for row in snapshots if row["name"].startswith("seed-")]
                    if "next-" + snapshot[len("seed-"):] in names or not seeds:
                        raise RuntimeError("instance.default_seed_moved")
                    snapshot = max(seeds, key=lambda row: row.get("created_at") or "")["name"]
                if snapshot != clone["snapshot"]:
                    # The later phases start the branch at this seed's commit, which its own repository holds.
                    (folder / "seed-commit").write_text(snapshot[len("seed-"):] + "\n")
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
                    if seeds.returncode or snapshot not in [row["name"].split("/")[-1] for row in json.loads(seeds.stdout)]:
                        raise RuntimeError("instance.default_seed_incomplete")
                    # No container yet: a volume of this name is left from a failed copy, so it is copied again from this seed.
                    if run(["incus", "query", volumes + inner["name"] + f"?project={container['project']}"], check=False).returncode == 0:
                        change(project + ["storage", "volume", "delete", inner["pool"], inner["name"]])
                    change(project + ["storage", "volume", "copy", inner["pool"] + "/" + clone["inner_volume"] + "/" + snapshot,
                                      inner["pool"] + "/" + inner["name"]])
                    # The copy takes the snapshot's config, and a seed that a seed copy moved to a CI Node records `size: 0`; the
                    # zvol keeps its size, so this writes only the record that the start guard requires.
                    change(project + ["storage", "volume", "set", inner["pool"], inner["name"], "size=" + inner["size"]])
                argv = project + [
                    "copy",
                    clone["source"] + "/" + snapshot,
                    name,
                ]
                for key, value in container["config"].items():
                    argv += ["--config", key + "=" + value]
                for key, value in root_device(container, run).items():
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
                            # The seed's mail sockets stay enabled: their units are the Node's, the same for every copy, and the
                            # mail apply below finds them in place (docs/reference/development-containers.md#mail).
                            or (index == 3 and item.startswith("orbit-node-service-"))
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
            # 1 when the copy's boot starts T3: this run carried the model environment and the copy was not running yet.
            timings["t3_files"] = 0
            if model is not None and read["status"] != "Running":
                with timed("t3_files_seconds"):
                    t3_start_files(model)
                timings["t3_files"] = 1
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
                + ([json.dumps({**container["allowed_devices"], "root": root_device(container, run)}), json.dumps(container["config"])]
                   if "allowed_devices" in container else [])
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
            with timed("idmap"):
                try:
                    if unshift_clone_rootfs(container, read, run):
                        changes.append("idmap")
                except Exception:
                    # chown can clear modes/caps before their restore fails. The new
                    # ownership cannot tell a retry what was lost; copy the seed again.
                    run(project + ["delete", name, "--force"], check=False)
                    raise
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
                    # `started`: this phase has just started the copy, so a silent systemd is its boot (instance_mail.py).
                    attempted = run(["python3", "-c", bundle["mail"], json.dumps(container), json.dumps(bundle)]
                                    + (["started"] if read["status"] != "Running" else []), check=False)
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
    elif phase == "complete":
        # The completion's one step on the Node (docs/reference/development-containers.md#complete-a-clone): the services part, then
        # the check, in one dispatch. A services failure fails the phase, as its own step did. The check's outcome is the output: its
        # own line on a pass, the dead unit's public receipt (printed by `inside`), or no line on any other failure. The phase then
        # ends 0, so the step keeps that output; `finish` reads it as it read the check step's (complete_result.ts checkOutcome).
        print(json.dumps(inside("services", json.dumps({"processes": json.loads(secret)["processes"]}))), flush=True)
        # The check gets its own bound, as its own step had: slow services never leave it without time.
        workspace_deadline = time.monotonic() + 55
        try:
            print(json.dumps(inside("check", "")))
        except PhaseFailure as error:
            print(error.line + (":\n" + error.detail if error.detail else ""), file=sys.stderr)
        except Exception as error:  # noqa: BLE001 - the check's code line only, as the command boundary prints it.
            message = str(error)
            print(message if message.startswith("instance.") and "\n" not in message else "instance.container_check_failed", file=sys.stderr)
        return
    elif phase in {"workspace", "checkout"}:
        print("\n".join(checkout(phase, secret)))
        return
    elif phase == "t3":
        # One step until the thread is usable (docs/reference/development-containers.md#create): the checkout beside the agent part and
        # T3's answer, then the check of both sessions against the running T3 and of the checkout. The model environment is already in
        # the copy (the container phase wrote it before the first start); stdin is the workspace's private JSON.
        cached = folder / "t3.json"
        def write_private(value):
            owner = private_folder()
            cached.write_text(json.dumps(value))
            cached.chmod(0o600)
            os.chown(cached, owner.pw_uid, owner.pw_gid)

        def part(name):
            # The part that runs now, written before it starts, so a failure or a timeout leaves it for the create result:
            # clone-session-copy copies this file when this step fails. The checkout's name is written only when it failed and the
            # T3 part did not; a failed T3 part keeps its own name. Success replaces the file with the checked sessions.
            write_private({"t3_part": name})

        part("agent")
        with ThreadPoolExecutor(max_workers=1) as pool:
            started = pool.submit(checkout, "checkout", secret)
            with timed("agent"):
                inside("agent", "")
            part("t3")
            with timed("t3"):
                output = inside("t3", "")
            try:
                checked_out = started.result()
            except BaseException as error:
                part("checkout")
                # A Node command of the checkout names the checkout, as its guest part does.
                prefix = "instance.container_t3_failed"
                if isinstance(error, PhaseFailure) and error.line.startswith(prefix):
                    raise PhaseFailure("instance.container_checkout_failed" + error.line[len(prefix):], error.detail) from None
                if isinstance(error, RuntimeError) and str(error).startswith(prefix):
                    raise RuntimeError("instance.container_checkout_failed" + str(error)[len(prefix):]) from None
                raise
        # 1 when T3 already ran as the step began: the copy's boot started it.
        timings["at_boot"] = 1 if output.pop("at_boot") else 0
        issued = output.pop("private_reply")
        # The sessions check gets its own bound, as its own step had: a slow checkout never leaves it without time.
        workspace_deadline = time.monotonic() + 55
        part("sessions")
        with timed("sessions"):
            checked = inside("sessions", json.dumps(issued))
        write_private(checked.pop("private_reply"))
        # The checkout's two lines, then T3's receipt with the time the sessions were checked.
        print("\n".join(checked_out))
        print(json.dumps({**output, "checked_at": checked["checked_at"], "timings": timings}))
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
except PhaseFailure as error:
    # The Operation's error detail: the code line, then the redacted end of the failed command's stderr.
    print(error.line + (":\n" + error.detail if error.detail else ""), file=sys.stderr)
    raise SystemExit(1) from None
except Exception as error:  # noqa: BLE001 - redact private subprocess failures at the command boundary.
    message = str(error)
    code = (
        message
        if message.startswith("instance.") and "\n" not in message
        else "instance.container_phase_failed"
    )
    print(code, file=sys.stderr)
    raise SystemExit(1)
