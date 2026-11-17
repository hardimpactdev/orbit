"""Check Node-side clone command diagnostics without Incus or private output."""

import ast
import contextlib
import copy
import errno
import io
import json
import os
import pwd
import runpy
import shutil
import stat
import struct
import subprocess
import tempfile
import unittest
import urllib.parse
from pathlib import Path
from unittest.mock import patch

SCRIPT = Path(__file__).parents[1] / "scripts/instance_clone.py"
NETWORK_CHECK = SCRIPT.parents[1] / "ansible/instance_network.py"
NODE_SERVICES = SCRIPT.parents[1] / "ansible/instance_node_services.py"
MACHINE_NAME = SCRIPT.parents[1] / "ansible/instance_machine_name.py"
# The copy's presented machine name (InstanceMachineName); the `127.0.1.1` line of its hosts file names it after the clone's pass.
HOSTNAME = "feature.tool"
NAMED_LINE = "127.0.1.1\tfeature.tool feature instance-7"
# The clone's one exec after the start.
READ_SCRIPT = 'printf "%s\\n" "$1" > /etc/hostname && hostname "$1" && cat -- /etc/hosts'
# The renderer's commands: the clone runs the bundle's instance_node_services.py with `proxy-files` before the copy's first start
# and with `hosts` right after it, once Incus has rendered the hosts template.
RENDERS = ("hosts", "proxy-files")
# The stock image's hosts.tpl, which Incus renders into /etc/hosts at a copy's first start.
HOSTS_TEMPLATE = ("127.0.1.1\t{name}\n127.0.0.1\tlocalhost\n::1\t\tlocalhost ip6-localhost ip6-loopback\n"
                  "ff02::1\t\tip6-allnodes\nff02::2\t\tip6-allrouters\n")


def fetched_mirror(test):
    """A Node mirror that a fetch filled: it exists, and the faked `git rev-parse` answers its branch's commit."""
    folder = tempfile.TemporaryDirectory()
    test.addCleanup(folder.cleanup)
    Path(folder.name, "HEAD").write_text("ref: refs/heads/proof\n")
    return folder.name


class BranchSelectionTest(unittest.TestCase):
    """Real, unprivileged Git: source tips differ from the cloned default seed."""

    def setUp(self):
        self.folder = tempfile.TemporaryDirectory()
        self.addCleanup(self.folder.cleanup)
        self.root = Path(self.folder.name)
        self.remote = self.root / "remote"
        self.mirror = self.root / "mirror"
        self.git("init", "--quiet", "--initial-branch=main", str(self.remote))
        self.git("-C", str(self.remote), "config", "user.email", "fixture@example.test")
        self.git("-C", str(self.remote), "config", "user.name", "Fixture")
        (self.remote / "source").write_text("seed")
        self.git("-C", str(self.remote), "add", "source")
        self.git("-C", str(self.remote), "commit", "--quiet", "-m", "seed")
        self.seed = self.git("-C", str(self.remote), "rev-parse", "HEAD").stdout.strip()
        self.git("-C", str(self.remote), "switch", "--quiet", "-c", "release")
        (self.remote / "source").write_text("base tip")
        self.git("-C", str(self.remote), "commit", "--quiet", "-am", "base tip")
        self.tip = self.git("-C", str(self.remote), "rev-parse", "HEAD").stdout.strip()

    def git(self, *args, check=True):
        return subprocess.run(["git", *args], check=check, capture_output=True, text=True,
                              env=dict(os.environ, GIT_CONFIG_NOSYSTEM="1", GIT_CONFIG_GLOBAL="/dev/null", GIT_TERMINAL_PROMPT="0"), timeout=5)

    def prepare(self, mode="new", target="fix-login", base="release", sha=None, legacy=False):
        overrides = {"repository": str(self.remote), "mirror": str(self.mirror), "protocols": ["file"], "branch": target,
                     "clone": {"source": "instance-1", "snapshot": "seed-abc", "t3": {"device": "t3"},
                               "seed_commit": self.seed}}
        if not legacy:
            overrides["branch_selection"] = {"mode": mode, "target": target, "base": base, "source_sha": sha or self.tip}
        _, _, error = CloneTest().clone(overrides=overrides, real_git=True)
        if error:
            raise RuntimeError(error.strip())

    def checkout(self, branch):
        """The workspace phase's rule: a branch in the Node's mirror comes in as a bundle; a branch the mirror lacks starts at the seed's
        commit, which the seed's own repository (a checkout at the seed's commit) holds."""
        workspace = self.root / "workspace"
        self.git("clone", "--quiet", "--no-checkout", str(self.remote), str(workspace))
        self.git("-C", str(workspace), "checkout", "--quiet", "--detach", self.seed)
        held = self.git("-C", str(self.mirror), "rev-parse", "--verify", "--quiet", "refs/heads/" + branch, check=False) \
            if (self.mirror / "HEAD").exists() else None
        if held is not None and held.returncode == 0:
            bundle = self.root / "workspace.bundle"
            self.git("-C", str(self.mirror), "bundle", "create", str(bundle), "refs/heads/" + branch)
            self.git("-C", str(workspace), "fetch", "--quiet", str(bundle), "+refs/heads/" + branch + ":refs/remotes/origin/" + branch)
            self.git("-C", str(workspace), "checkout", "--quiet", "-B", branch, "refs/remotes/origin/" + branch)
        else:
            self.git("-C", str(workspace), "checkout", "--quiet", "-B", branch, self.seed)
        return self.git("-C", str(workspace), "rev-parse", "HEAD").stdout.strip(), self.git("-C", str(workspace), "branch", "--show-current").stdout.strip()

    def test_legacy_new_branch_still_uses_seed_without_selection(self):
        self.prepare(legacy=True)
        # A branch the origin lacks fetches nothing and makes no Node mirror: the seed holds its commit.
        self.assertFalse(self.mirror.exists())
        self.assertEqual(self.checkout("fix-login"), (self.seed, "fix-login"))

    def test_a_new_branch_at_the_seeds_commit_reads_no_network_and_makes_no_mirror(self):
        # The admission read GitHub; an unreachable origin proves that the clone does not.
        self.remote.rename(self.root / "gone")
        self.prepare(base="main", sha=self.seed)
        self.assertFalse(self.mirror.exists())
        self.remote = self.root / "gone"
        self.assertEqual(self.checkout("fix-login"), (self.seed, "fix-login"))

    def test_legacy_existing_remote_branch_still_uses_its_tip(self):
        self.prepare(target="release", legacy=True)
        self.assertEqual(self.checkout("release"), (self.tip, "release"))

    def test_new_branch_uses_fetched_base_tip_not_seed(self):
        self.assertNotEqual(self.seed, self.tip)
        self.prepare()
        self.assertEqual(self.checkout("fix-login"), (self.tip, "fix-login"))

    def test_existing_branch_uses_its_remote_tip(self):
        self.prepare(mode="existing", target="release", base=None)
        self.assertEqual(self.checkout("release"), (self.tip, "release"))

    def test_resume_keeps_admitted_commit_when_remote_advances(self):
        self.prepare()
        (self.remote / "source").write_text("later tip")
        self.git("-C", str(self.remote), "commit", "--quiet", "-am", "later")
        self.prepare()
        self.assertEqual(self.checkout("fix-login"), (self.tip, "fix-login"))

    def test_deleted_existing_branch_never_falls_back_to_seed_or_local_ref(self):
        self.prepare(mode="existing", target="release", base=None)
        self.git("-C", str(self.remote), "switch", "--quiet", "main")
        self.git("-C", str(self.remote), "branch", "-D", "release")
        with self.assertRaisesRegex(RuntimeError, "instance.clone_branch_not_found"):
            self.prepare(mode="existing", target="release", base=None)

    def test_deleted_base_is_not_a_new_branch_at_seed(self):
        self.git("-C", str(self.remote), "switch", "--quiet", "main")
        self.git("-C", str(self.remote), "branch", "-D", "release")
        with self.assertRaisesRegex(RuntimeError, "instance.clone_branch_not_found"):
            self.prepare()

    def test_a_target_made_on_github_after_admission_starts_at_the_admitted_commit_and_its_push_is_refused(self):
        # The admission found no `fix-login`; someone pushes one before the copy. The clone runs no `ls-remote` again, so it starts
        # at the admitted commit, and a later push of its branch is refused as non-fast-forward (`tasks.not_fast_forward`).
        self.git("-C", str(self.remote), "switch", "--quiet", "-c", "fix-login", "main")
        (self.remote / "other").write_text("someone else")
        self.git("-C", str(self.remote), "add", "other")
        self.git("-C", str(self.remote), "commit", "--quiet", "-m", "someone else")
        self.git("-C", str(self.remote), "switch", "--quiet", "main")
        self.prepare()
        self.assertEqual(self.checkout("fix-login"), (self.tip, "fix-login"))
        pushed = self.git("-C", str(self.root / "workspace"), "push", "--porcelain", str(self.remote), "fix-login:fix-login", check=False)
        self.assertNotEqual(pushed.returncode, 0)
        self.assertRegex(pushed.stdout + pushed.stderr, r"non-fast-forward|fetch first")

    def test_a_target_made_on_github_at_an_older_commit_is_fast_forwarded_and_loses_no_commit(self):
        # The branch made after admission holds no commit the copy lacks: the push fast-forwards it.
        self.git("-C", str(self.remote), "branch", "fix-login", self.seed)
        self.prepare()
        self.assertEqual(self.checkout("fix-login"), (self.tip, "fix-login"))
        pushed = self.git("-C", str(self.root / "workspace"), "push", "--porcelain", str(self.remote), "fix-login:fix-login", check=False)
        self.assertEqual(pushed.returncode, 0, pushed.stderr)
        self.assertEqual(self.git("-C", str(self.remote), "rev-parse", "fix-login").stdout.strip(), self.tip)
        self.assertEqual(self.git("-C", str(self.remote), "merge-base", "--is-ancestor", self.seed, "fix-login", check=False).returncode, 0)

    def test_unreadable_remote_fails_without_creating_a_branch(self):
        self.remote.rename(self.root / "gone")
        with self.assertRaisesRegex(RuntimeError, "instance.clone_source_unavailable"):
            self.prepare()
        self.assertNotEqual(self.git("-C", str(self.mirror), "rev-parse", "--verify", "refs/heads/fix-login", check=False).returncode, 0)

    def test_unreachable_frozen_commit_fails_without_using_current_tip(self):
        with self.assertRaisesRegex(RuntimeError, "instance.clone_source_unavailable"):
            self.prepare(sha="f" * 40)


# NestedFleetInnerVolume::Config: the keys of a copy's inner volume.
INNER_CONFIG = {"zfs.block_mode": "true", "block.filesystem": "btrfs", "block.mount_options": "user_subvol_rm_allowed,discard",
                "security.shifted": "true"}


class IdmapTest(unittest.TestCase):
    MAP = [{"Isuid": True, "Isgid": False, "Hostid": 131072, "Nsid": 0, "Maprange": 65536},
           {"Isuid": False, "Isgid": True, "Hostid": 131072, "Nsid": 0, "Maprange": 65536}]

    def helper(self):
        # Load the phase's imports and definitions, without executing its command-line main.
        tree = ast.parse(SCRIPT.read_text())
        module = ast.Module(body=[node for node in tree.body if isinstance(node, (ast.Import, ast.ImportFrom, ast.FunctionDef, ast.ClassDef))], type_ignores=[])
        namespace = {}
        exec(compile(module, str(SCRIPT), "exec"), namespace)
        return namespace["unshift_clone_rootfs"]

    def run_helper(self, root, source=None, own=None, status="Stopped", driver="dir"):
        container = {"name": "instance-7", "project": "orbit-instances", "root_disk_pool": "orbit-instances",
                     "clone": {"source": "instance-1"}}
        calls = []

        def run(argv):
            calls.append(argv)
            data = {"driver": driver, "config": {"source": str(root.parents[2])}} if "/storage-pools/" in argv[-1] else {
                "config": {"volatile.last_state.idmap": json.dumps(self.MAP if source is None else source)}}
            return subprocess.CompletedProcess(argv, 0, json.dumps(data), "")

        changed = self.helper()(container, {"status": status, "config": {"volatile.last_state.idmap": json.dumps(own or [])}}, run)
        return changed, calls

    def test_unshift_preserves_modes_links_acls_and_capabilities_and_can_repeat(self):
        with tempfile.TemporaryDirectory() as folder:
            root = Path(folder) / "containers/orbit-instances_instance-7/rootfs"
            root.mkdir(parents=True)
            file = root / "program"
            file.write_text("program")
            file.chmod(0o6755)
            link = root / "link"
            link.symlink_to("/outside")
            other = root / "outside-map"
            other.write_text("ordinary")
            paths = [root, file, link, other]
            owners = {str(path): (131072, 131079) for path in paths}
            owners[str(other)] = (70000, 80000)
            acl = struct.pack("<IHHIHHIHHI", 2, 2, 7, 131075, 8, 5, 131076, 1, 7, 0xffffffff)
            cap = struct.pack("<6I", 0x03000001, 1, 2, 3, 4, 131072)
            attrs = {(str(file), "security.capability"): cap,
                     (str(file), "system.posix_acl_access"): acl,
                     (str(root), "system.posix_acl_default"): acl}
            real_lstat = os.lstat
            chowns = []

            def lstat(path, **kwargs):
                held = list(real_lstat(path, **kwargs))
                held[4:6] = owners[str(path)]
                return os.stat_result(held)

            def chown(path, uid, gid):
                chowns.append((str(path), uid, gid))
                owners[str(path)] = (uid, gid)
                if not stat.S_ISLNK(real_lstat(path).st_mode):
                    os.chmod(path, stat.S_IMODE(real_lstat(path).st_mode) & ~0o6000)
                attrs.pop((str(path), "security.capability"), None)

            def getxattr(path, name, **kwargs):
                self.assertNotEqual(str(path), str(link), "Never read a symlink's target metadata")
                if (str(path), name) not in attrs:
                    raise OSError(errno.ENODATA, "missing")
                return attrs[str(path), name]

            with patch("os.lstat", lstat), patch("os.lchown", chown), patch("os.getxattr", getxattr), patch("os.setxattr", side_effect=lambda p, n, v, **kw: attrs.update({(str(p), n): v})):
                changed, _ = self.run_helper(root)
                self.assertTrue(changed)
                self.assertEqual(owners[str(file)], (0, 7))
                self.assertEqual(owners[str(link)], (0, 7))
                self.assertEqual(owners[str(other)], (70000, 80000))
                self.assertEqual(stat.S_IMODE(real_lstat(file).st_mode), 0o6755)
                self.assertEqual(struct.unpack_from("<I", attrs[str(file), "security.capability"], 20)[0], 0)
                for path, name in ((file, "system.posix_acl_access"), (root, "system.posix_acl_default")):
                    self.assertEqual(struct.unpack_from("<I", attrs[str(path), name], 8)[0], 3)
                    self.assertEqual(struct.unpack_from("<I", attrs[str(path), name], 16)[0], 4)
                    self.assertEqual(struct.unpack_from("<I", attrs[str(path), name], 24)[0], 0xffffffff)
                count = len(chowns)
                changed, _ = self.run_helper(root)
                self.assertFalse(changed)
                self.assertEqual(len(chowns), count)

    def test_unshift_translates_split_ranges_and_xattrs_without_changing_the_owner(self):
        ranges = self.MAP + [{"Isuid": True, "Isgid": False, "Hostid": 300000, "Nsid": 1000, "Maprange": 10},
                             {"Isuid": False, "Isgid": True, "Hostid": 400000, "Nsid": 2000, "Maprange": 10}]
        with tempfile.TemporaryDirectory() as folder:
            root = Path(folder) / "containers/orbit-instances_instance-7/rootfs"
            root.mkdir(parents=True)
            attrs = {"security.capability": struct.pack("<6I", 0x03000000, 1, 2, 3, 4, 300000),
                     "system.posix_acl_access": struct.pack("<IHHIHHI", 2, 2, 7, 300003, 8, 5, 400004)}

            def getxattr(path, name, **kwargs):
                if name not in attrs:
                    raise OSError(errno.ENOTSUP, "unsupported")
                return attrs[name]

            with patch("os.getxattr", getxattr), patch("os.setxattr", side_effect=lambda p, n, v, **kw: attrs.update({n: v})), patch("os.lchown") as chown:
                changed, _ = self.run_helper(root, source=ranges)
                self.assertTrue(changed)
                chown.assert_not_called()
                self.assertEqual(struct.unpack_from("<I", attrs["security.capability"], 20)[0], 1000)
                self.assertEqual(struct.unpack_from("<I", attrs["system.posix_acl_access"], 8)[0], 1003)
                self.assertEqual(struct.unpack_from("<I", attrs["system.posix_acl_access"], 16)[0], 2004)
                # A v2 capability has no rootid; its data remains byte-for-byte unchanged.
                cap_v2 = struct.pack("<5I", 0x02000001, 1, 2, 3, 4)
                attrs["security.capability"] = cap_v2
                changed, _ = self.run_helper(root, source=ranges)
                self.assertFalse(changed)
                self.assertEqual(attrs["security.capability"], cap_v2)

    def test_running_copy_or_an_empty_source_map_does_no_filesystem_work(self):
        for source, own, status in (([], [], "Stopped"), (self.MAP, self.MAP, "Stopped"), (self.MAP, [], "Running")):
            with self.subTest(source=source, own=own, status=status), patch("os.lstat") as lstat:
                changed, calls = self.run_helper(Path("/unused/containers/orbit-instances_instance-7/rootfs"), source=source, own=own, status=status)
                self.assertFalse(changed)
                lstat.assert_not_called()
                self.assertFalse(any("/storage-pools/" in call[-1] for call in calls))

    def test_an_unreadable_rootfs_or_xattr_refuses_by_name(self):
        with tempfile.TemporaryDirectory() as folder:
            root = Path(folder) / "containers/orbit-instances_instance-7/rootfs"
            with self.assertRaisesRegex(RuntimeError, "^instance.clone_idmap_unshift_failed$"):
                self.run_helper(root)
            root.mkdir(parents=True)
            with patch("os.getxattr", side_effect=PermissionError(errno.EACCES, "denied")):
                with self.assertRaisesRegex(RuntimeError, "^instance.clone_idmap_unshift_failed$"):
                    self.run_helper(root)


class CloneTest(unittest.TestCase):
    def clone(self, damaged=None, nic_acl="orbitinst0-proxy", mail=None, overrides=None, real_git=False, mail_code=None, transcript=None, node_service_code=None, rootfs=None, existing=None, network_check=None, volumes=None, stdin="PRIVATE-INPUT", before_start=None):
        calls = []
        self.inputs = {}
        self.decoding = {}
        real_run = subprocess.run
        with tempfile.TemporaryDirectory() as folder:
            root = Path(folder) / "containers/orbit-instances_instance-7/rootfs"
            # The nested guard reads the volumes of its disks with `incus query`: this fake answers from `volumes`, by
            # `<kind>/<name>`, and gives any other volume no keys.
            (Path(folder) / "bin").mkdir()
            held_volumes = dict(volumes or {})
            (Path(folder) / "volumes.json").write_text(json.dumps(held_volumes))
            (Path(folder) / "bin" / "incus").write_text(
                "#!/usr/bin/env python3\nimport json, sys\n"
                f"volumes = json.load(open({str(Path(folder) / 'volumes.json')!r}))\n"
                "route = sys.argv[-1].split('?')[0].split('/volumes/')[1]\n"
                "print(json.dumps({'config': volumes.get(route, {})}))\n")
            (Path(folder) / "bin" / "incus").chmod(0o755)
            guard_env = {**os.environ, "PATH": str(Path(folder) / "bin") + os.pathsep + os.environ["PATH"]}
            for path, content in (rootfs or {}).items():
                (root / path).parent.mkdir(parents=True, exist_ok=True)
                if content.startswith("->"):
                    (root / path).symlink_to(content[2:])
                else:
                    (root / path).write_text(content)
            container = {
                "mail": mail,
                "name": "instance-7",
                "machine_name": {"hostname": HOSTNAME, "pretty": HOSTNAME},
                "project": "orbit-instances",
                "network": "orbitinst0",
                "nic_acl": nic_acl,
                "acl": "instance-7",
                "acl_rules": {},
                "address": "10.253.0.2",
                "config": {},
                "root_disk_pool": "orbit-instances",
                "root_disk_size": "40GiB",
                "processes": [],
                "mirror": folder + "/mirror",
                "protocols": ["https"],
                "branch": "main",
                "repository": "https://github.com/test/repo.git",
                "clone": {
                    "source": "instance-1",
                    "snapshot": "seed-abc",
                    "t3": {"device": "t3"},
                },
            }
            container.update(overrides or {})
            read = existing
            # The copy's files that change while the clone runs: /etc/hosts, which file pushes write and the copy's own Incus
            # template rewrites at its first start, as the stock image's metadata says (`/etc/hosts: when: [create, copy]`).
            files = container.setdefault("fixture_files", {})
            templates = {"/etc/hosts": ["create", "copy"]} if container.get("fixture_hosts_template", True) else {}
            copied = []

            def command(argv, **kwargs):
                nonlocal read
                calls.append(argv)
                self.inputs[id(argv)] = kwargs.get("input")
                self.decoding[id(argv)] = kwargs.get("errors")
                if real_git and argv[0] in {"git", "timeout"}:
                    return real_run(argv, **kwargs)
                if argv[:2] == ["python3", "-c"] and argv[3:] in ([render] for render in RENDERS) and argv[2] != MACHINE_NAME.read_text():
                    # The one renderer: the bundle's instance_node_services.py, whose real source answers here.
                    return real_run(["python3", "-c", NODE_SERVICES.read_text(), *argv[3:]], **kwargs)
                if argv[:2] == ["python3", "-c"]:
                    return real_run(argv, **{**kwargs, "env": {**kwargs.get("env", os.environ), "PATH": guard_env["PATH"]}})
                if argv[0] == "openssl":
                    return real_run(argv, **kwargs)
                output, status = "", 0
                if argv[3:5] == ["file", "push"] and argv[-1] in (container["name"] + "/etc/hosts", container["name"] + "/etc/hostname"):
                    files[argv[-1][len(container["name"]):]] = kwargs.get("input")
                if argv[3:4] == ["start"] and before_start:
                    before_start(root)
                if argv[3:4] == ["start"] and copied and "copy" in templates.get("/etc/hosts", []):
                    # Incus runs the `copy` templates once, at the first start after the copy, before pid 1: hosts.tpl.
                    copied.clear()
                    files["/etc/hosts"] = HOSTS_TEMPLATE.format(name=container["name"])
                if argv[3:4] == ["exec"] and argv[-3:] == [READ_SCRIPT, "sh", HOSTNAME]:
                    # One exec: /etc/hostname, the kernel hostname, then the hosts file.
                    if container.get("fixture_hosts_read_error"):
                        return subprocess.CompletedProcess(argv, 1, "", "Error: Failed to retrieve PID of executing child process")
                    files["/etc/hostname"] = HOSTNAME + "\n"
                    files["kernel"] = HOSTNAME
                    held = files.get("/etc/hosts")
                    return subprocess.CompletedProcess(argv, 0 if isinstance(held, str) else 1, held if isinstance(held, str) else "",
                                                       "" if isinstance(held, str) else "cat: /etc/hosts: No such file or directory")
                if argv[0] == "curl" and argv[3] == "-":
                    # A file's bytes and its headers on stderr: from `fixture_files` (path: content, or the headers and content),
                    # else missing.
                    held = container.get("fixture_files", {}).get(urllib.parse.unquote(argv[-1].split("&path=", 1)[1]))
                    if held is None:
                        return subprocess.CompletedProcess(argv, 0, "", "404    ")
                    held = held if isinstance(held, dict) else {"content": held}
                    headers = {"type": "file", "uid": "0", "gid": "0", "mode": "0644", **held}
                    return subprocess.CompletedProcess(argv, 0, held["content"], "200 {type} {uid} {gid} {mode}".format(**headers))
                if argv[0] == "curl":
                    # The files API's type header, read from the copy's real root file system; a link is never followed.
                    local = root / urllib.parse.unquote(argv[-1].split("&path=", 1)[1]).lstrip("/")
                    kind = None if rootfs is None or not os.path.lexists(local) else "symlink" if local.is_symlink() \
                        else "directory" if local.is_dir() else "file"
                    return subprocess.CompletedProcess(argv, 0, f"200 {kind}" if kind else "404 ", "")
                if argv[3:5] == ["file", "pull"] and "fixture_pull" in container:
                    # What the copy holds at a path, as the seed left it; any other path is missing.
                    held = container["fixture_pull"].get("/" + argv[5].split("/", 1)[1])
                    return subprocess.CompletedProcess(argv, 0 if held is not None else 1, held or "", "" if held is not None else "Error: file does not exist")
                if rootfs is not None and argv[:2] == ["incus", "query"] and "/files?" in argv[2]:
                    # The copy's files as Incus lists them, read from a real root file system.
                    directory = root / argv[2].split("&path=", 1)[1].lstrip("/")
                    if not directory.is_dir():
                        return subprocess.CompletedProcess(argv, 1, "", "Error: Not Found")
                    return subprocess.CompletedProcess(argv, 0, json.dumps(sorted(p.name for p in directory.iterdir())), "")
                if rootfs is not None and argv[3:5] == ["file", "delete"]:
                    # As Incus 7.0.2 (pkg/sftp 1.13.11): --force stats the path, following a link inside the container, so a
                    # dangling link fails with "file does not exist"; a plain delete unlinks. Every path is tried.
                    errors = []
                    for target in argv[5:]:
                        if target.startswith("-"):
                            continue
                        path = target[target.index("/"):]
                        resolved = path
                        for _ in range(40):
                            if not (root / resolved.lstrip("/")).is_symlink():
                                break
                            link = os.readlink(root / resolved.lstrip("/"))
                            resolved = link if link.startswith("/") else resolved.rsplit("/", 1)[0] + "/" + link
                        local = root / path.lstrip("/")
                        if ("--force" in argv and not os.path.lexists(root / resolved.lstrip("/"))) or not os.path.lexists(local):
                            errors.append("Error: file does not exist")
                        elif local.is_dir() and not local.is_symlink():
                            shutil.rmtree(local)
                        else:
                            local.unlink()
                    return subprocess.CompletedProcess(argv, 1 if errors else 0, "", "\n".join(errors))
                if argv[:2] == ["incus", "query"] and "/volumes/custom/" in argv[2]:
                    # The default's inner volume and its seed snapshots, and the copy's own volume, absent unless a failed copy left it.
                    if "snapshots?" in argv[2]:
                        return subprocess.CompletedProcess(argv, 0, json.dumps([{"name": name} for name in container.get("fixture_volume_seeds", ["seed-abc"])]), "")
                    return subprocess.CompletedProcess(argv, 0 if container.get("fixture_volume_left") else 1, "{}" if container.get("fixture_volume_left") else "",
                                                       "" if container.get("fixture_volume_left") else "Error: Storage pool volume not found")
                if argv[3:6] == ["storage", "volume", "copy"]:
                    # As Incus 7.0.2: a volume copied from a snapshot takes the snapshot's config. A seed snapshot that a seed copy
                    # moved to a CI Node records `size: 0`.
                    held_volumes["custom/" + argv[-1].split("/")[-1]] = dict(container.get("fixture_volume_seed_config", {}))
                    (Path(folder) / "volumes.json").write_text(json.dumps(held_volumes))
                if argv[3:6] == ["storage", "volume", "set"]:
                    key, value = argv[-1].split("=", 1)
                    held_volumes.setdefault("custom/" + argv[-2], {})[key] = value
                    (Path(folder) / "volumes.json").write_text(json.dumps(held_volumes))
                if argv[3:4] == ["copy"] and container.get("fixture_copy_fails"):
                    return subprocess.CompletedProcess(argv, 1, "", "Error: Failed creating instance from snapshot")
                if argv[:2] == ["incus", "query"] and "/1.0/network-acls/" in argv[2]:
                    # The copy's own ACL by its name, as Incus answers it: the held ACL, a missing one, or another failure.
                    acl = container.get("fixture_acl")
                    if acl == "error":
                        # Another 404 of Incus, such as a missing project, is no missing ACL.
                        return subprocess.CompletedProcess(argv, 1, "", "Error: Project not found")
                    return subprocess.CompletedProcess(argv, 0 if acl else 1, json.dumps(acl) if acl else "", "" if acl else "Error: Network ACL not found")
                if argv[:2] == ["incus", "query"]:
                    if argv[2].startswith("/1.0/storage-pools/"):
                        output = json.dumps({"driver": container.get("fixture_pool_driver", "dir"), "config": {"source": folder}})
                    elif argv[2] == "/1.0/instances/instance-1?project=orbit-instances":
                        output = json.dumps({"config": {"volatile.last_state.idmap": json.dumps(container.get("fixture_source_idmap", []))}})
                    elif "snapshots?" in argv[2]:
                        output = json.dumps(container.get("fixture_snapshots", [{"name": "seed-abc"}]))
                    elif "sockets.target.wants" in argv[2]:
                        output = json.dumps(["orbit-mail-1025.socket", "orbit-mail-2525.socket", "orbit-node-service-3308.socket", "project.socket"])
                    elif "timers.target.wants" in argv[2]:
                        if container.get("fixture_schedule_listing_error"):
                            return subprocess.CompletedProcess(argv, 1, "", "Error: Permission denied")
                        output = json.dumps(["orbit-schedule-default.timer", "project.timer"])
                    elif argv[2].endswith("/etc/systemd/system"):
                        output = json.dumps(["orbit-schedule-default.timer", "orbit-schedule-default.service"])
                    elif argv[2].endswith("/etc/orbit/schedules"):
                        output = json.dumps(["default.sh", "default.complete"])
                    elif "files?" in argv[2]:
                        output = "[]"
                    else:
                        output, status = (json.dumps(read), 0) if read else ("", 1)
                elif argv[3:4] == ["copy"]:
                    copied.append(True)
                    device = {"type": "nic", "network": "orbitinst0"}
                    for arg in argv:
                        if arg.startswith("eth0,"):
                            key, value = arg[5:].split("=", 1)
                            device[key] = value
                    # Incus names the instance in its own record; the NIC check's report reads it.
                    read = {
                        "name": container["name"],
                        "status": "Stopped",
                        "devices": {"t3": {}},
                        "expanded_devices": {"eth0": device},
                        "config": {"volatile.uuid": "abc-123"},
                    }
                    if damaged:
                        damaged(read)
                return subprocess.CompletedProcess(argv, status, output, "")

            output, error = io.StringIO(), io.StringIO()
            with (
                patch(
                    "sys.argv",
                    [
                        str(SCRIPT),
                        json.dumps(container),
                        "guest",
                        (NETWORK_CHECK.read_text().split('if __name__ == "__main__":')[0] + (NETWORK_CHECK.parent / "instance_nested.py").read_text()) if container.get("allowed_devices") else (network_check or NETWORK_CHECK.read_text()),
                        pwd.getpwuid(os.getuid()).pw_name,
                        folder + "/private",
                        json.dumps({"node_services": node_service_code or 'print(\'{"status":"unchanged"}\')', "mail": mail_code or 'print(\'{"status":"unchanged"}\')',
                                    "machine_name": MACHINE_NAME.read_text()}),
                        "container",
                    ],
                ),
                patch("sys.stdin", io.StringIO(stdin)),
                patch("subprocess.run", command),
                patch("os.chown"),
                contextlib.redirect_stdout(output),
                contextlib.redirect_stderr(error),
            ):
                try:
                    runpy.run_path(str(SCRIPT), run_name="__main__")
                except SystemExit as result:
                    self.assertEqual(result.code, 1)
            self.assertFalse(Path(folder + "/private/git-credential").exists())
            if transcript is not None:
                transcript.append(output.getvalue())
            self.hosts = files.get("/etc/hosts")
            moved = Path(folder + "/private/seed-commit")
            self.seed_commit = moved.read_text() if moved.exists() else None
            self.hostname = (files.get("/etc/hostname"), files.get("kernel"))
            return calls, read, error.getvalue()

    def test_a_copy_with_a_stripped_idmap_unshifts_its_rootfs_before_start(self):
        touched = []
        real_lstat = os.lstat

        def shifted(path, **kwargs):
            held = real_lstat(path, **kwargs)
            if str(path).endswith("/etc/hostname"):
                held = list(held)
                held[4:6] = [131072, 131072]
                return os.stat_result(held)
            return held

        def starting(root):
            self.assertIn((str(root / "etc/hostname"), 0, 0), touched)

        with patch("os.lstat", shifted), patch("os.lchown", side_effect=lambda p, u, g: touched.append((str(p), u, g))):
            calls, _, error = self.clone(rootfs={"etc/hostname": "default"},
                                        overrides={"fixture_source_idmap": IdmapTest.MAP}, before_start=starting)
        self.assertEqual(error, "")
        pool = next(i for i, call in enumerate(calls) if call[:2] == ["incus", "query"] and "/storage-pools/" in call[2])
        start = next(i for i, call in enumerate(calls) if call[3:4] == ["start"])
        self.assertLess(pool, start)

    def test_a_stripped_idmap_on_another_pool_driver_refuses_before_start(self):
        calls, _, error = self.clone(overrides={"fixture_source_idmap": IdmapTest.MAP, "fixture_pool_driver": "zfs"})
        self.assertEqual(error, "instance.clone_idmap_unshift_unsupported\n")
        self.assertFalse(any(call[3:4] == ["start"] for call in calls))

    def test_a_clone_with_no_missing_idmap_never_opens_its_pool(self):
        for source, own in (([], []), ([], IdmapTest.MAP), (IdmapTest.MAP, IdmapTest.MAP)):
            with self.subTest(source=source, own=own), patch("os.lchown") as chown:
                calls, _, error = self.clone(overrides={"fixture_source_idmap": source},
                                            damaged=lambda read: read["config"].update({"volatile.last_state.idmap": json.dumps(own)}))
                self.assertEqual(error, "")
                chown.assert_not_called()
                self.assertFalse(any(call[:2] == ["incus", "query"] and "/storage-pools/" in call[2] for call in calls))

    def test_a_default_without_any_seed_refuses_by_its_own_code_before_the_copy(self):
        calls, _, error = self.clone(overrides={"fixture_snapshots": [{"name": "other"}]})
        self.assertIn("instance.default_seed_missing", error)
        self.assertFalse(any("copy" in call for call in calls))

    def test_a_retake_of_the_admitted_seed_still_running_after_the_wait_refuses_as_moved(self):
        # The wait step's bound passed between the retake's delete and its rename: the admitted seed's `next-` is still there.
        for snapshots in ([{"name": "next-abc"}], [{"name": "next-abc"}, {"name": "seed-old", "created_at": "2026-11-16T03:00:00Z"}]):
            calls, _, error = self.clone(overrides={"fixture_snapshots": snapshots})
            self.assertIn("instance.default_seed_moved", error)
            self.assertFalse(any("copy" in call for call in calls))
            self.assertIsNone(self.seed_commit)

    def test_a_seed_that_a_deployment_replaced_is_copied_and_the_branch_follows_its_commit(self):
        # The retake took a seed at another commit and deleted the admitted one: the copy takes the new seed and records its commit.
        calls, _, error = self.clone(overrides={"fixture_snapshots": [{"name": "seed-def", "created_at": "2026-11-17T03:10:00Z"}]})
        self.assertNotIn("instance.default_seed_moved", error)
        self.assertEqual([call for call in calls if call[3:4] == ["copy"]][0][4], "instance-1/seed-def")
        self.assertEqual(self.seed_commit, "def\n")
        # The admitted seed whole beside a retake that runs: it is copied, and nothing is recorded.
        calls, _, error = self.clone(overrides={"fixture_snapshots": [{"name": "seed-abc"}, {"name": "next-abc"}]})
        self.assertEqual([call for call in calls if call[3:4] == ["copy"]][0][4], "instance-1/seed-abc")
        self.assertIsNone(self.seed_commit)

    def test_a_normal_copy_of_its_admitted_seed_reads_the_snapshots_once_and_records_nothing(self):
        calls, _, error = self.clone()
        self.assertEqual(len([call for call in calls if call[:2] == ["incus", "query"] and "/instances/instance-1/snapshots?" in call[2]]), 1)
        self.assertEqual([call for call in calls if call[3:4] == ["copy"]][0][4], "instance-1/seed-abc")
        self.assertIsNone(self.seed_commit)

    def test_a_sleeping_default_needs_no_wake_to_read_its_idmap_and_copy_its_snapshot(self):
        # A default sleeps frozen, and Incus may even report a lost freeze as Running (Instance 2454 on 2026-11-17). The copy never
        # uses the default's state, never execs into it and never starts, stops, freezes or unfreezes it. It reads only its
        # snapshot list and recorded ID map, and copies one snapshot.
        calls, _, error = self.clone()
        self.assertEqual(error, "")
        named = [arg for call in calls for arg in call if "instance-1" in arg]
        self.assertTrue(named)
        for arg in named:
            self.assertTrue("/instances/instance-1/snapshots?" in arg or arg.startswith("instance-1/seed-")
                            or arg == "/1.0/instances/instance-1?project=orbit-instances", arg)

    def test_a_replaced_seed_copies_the_newest_seed(self):
        calls, _, error = self.clone(overrides={"fixture_snapshots": [{"name": "seed-old", "created_at": "2026-11-16T03:00:00Z"},
                                                                      {"name": "seed-def", "created_at": "2026-11-17T03:00:00Z"}]})
        self.assertNotIn("instance.default_seed_moved", error)
        self.assertEqual([call for call in calls if call[3:4] == ["copy"]][0][4], "instance-1/seed-def")
        self.assertEqual(self.seed_commit, "def\n")

    def test_schedule_cleanup_listing_error_refuses_before_clone_boot(self):
        calls, _, error = self.clone(overrides={"fixture_schedule_listing_error": True})
        self.assertNotEqual(error, "")
        self.assertFalse(any(call[:4] == ["incus", "--project", "orbit-instances", "start"] for call in calls))

    def test_clone_writes_proxy_and_own_acl_and_reject_defaults_before_start(self):
        calls, read, error = self.clone()
        self.assertEqual(error, "")
        nic = read["expanded_devices"]["eth0"]
        self.assertEqual(nic["security.acls"], "orbitinst0-proxy,instance-7")
        self.assertEqual(nic["security.acls.default.egress.action"], "reject")
        self.assertEqual(nic["security.acls.default.ingress.action"], "reject")
        # The copy's NIC accepts only its own IPv4 and MAC address: the clone reads FILTERS from the check's source it receives.
        self.assertEqual(nic["security.ipv4_filtering"], "true")
        self.assertEqual(nic["security.mac_filtering"], "true")
        _, read, error = self.clone(network_check='FILTERS = {"security.ipv4_filtering": "true"}\n' + NETWORK_CHECK.read_text().replace("FILTERS = {", "_FILTERS = {", 1))
        self.assertEqual(error, "")
        self.assertNotIn("security.mac_filtering", read["expanded_devices"]["eth0"])
        start = ["incus", "--project", "orbit-instances", "start", "instance-7"]
        self.assertIn(start, calls)
        links = next(call for call in calls if call[3:5] == ['file', 'delete'] and '--force' not in call)
        files = next(call for call in calls if call[3:5] == ['file', 'delete'] and '--force' in call)
        # Every enablement link goes in its own plain delete, before its unit file goes.
        self.assertTrue(all('.target.wants/' in path for path in links[5:]))
        self.assertFalse(any('.target.wants/' in path for path in files))
        self.assertLess(calls.index(links), calls.index(files))
        deleted = links + files
        # The seed's mail sockets stay enabled: their units are the Node's, the same for every copy.
        self.assertNotIn('instance-7/etc/systemd/system/sockets.target.wants/orbit-mail-1025.socket', deleted)
        self.assertNotIn('instance-7/etc/systemd/system/sockets.target.wants/orbit-mail-2525.socket', deleted)
        self.assertNotIn('instance-7/etc/systemd/system/sockets.target.wants/project.socket', deleted)
        for path in ["systemd/system/timers.target.wants/orbit-schedule-default.timer",
                     "systemd/system/orbit-schedule-default.timer", "systemd/system/orbit-schedule-default.service",
                     "orbit/schedules/default.sh", "orbit/schedules/default.complete"]:
            self.assertIn("instance-7/etc/" + path, deleted)
        self.assertNotIn("instance-7/etc/systemd/system/timers.target.wants/project.timer", deleted)
        self.assertLess(calls.index(files), calls.index(start))
        check = next(i for i, call in enumerate(calls) if call[:2] == ["python3", "-c"])
        self.assertLess(check, calls.index(start))

    def test_the_copys_own_acl_is_read_by_its_name_and_never_from_the_list_of_every_acl(self):
        acl = ["incus", "query", "/1.0/network-acls/instance-7?project=default"]
        create = ["incus", "network", "acl", "create", "instance-7", "--project", "default"]
        calls, _, error = self.clone()
        self.assertEqual(error, "")
        self.assertIn(acl, calls)
        self.assertFalse(any(call[:2] == ["incus", "query"] and "/1.0/network-acls?" in call[2] for call in calls))
        # A missing ACL is created and set before the copy.
        self.assertLess(calls.index(create), next(i for i, call in enumerate(calls) if call[3:4] == ["copy"]))
        # An ACL that holds the rules already changes nothing.
        rules = {"description": "Orbit Instance 7", "egress": [], "ingress": []}
        calls, _, error = self.clone(overrides={"acl_rules": rules, "fixture_acl": {"name": "instance-7", **rules}})
        self.assertEqual(error, "")
        self.assertFalse(any(call[:3] == ["incus", "network", "acl"] for call in calls))
        # Any other failure of the read refuses before the copy; it never creates an ACL over one it could not read.
        calls, _, error = self.clone(overrides={"fixture_acl": "error"})
        self.assertEqual(error, "instance.container_container_failed: command incus exited 1 (incus query /1.0/network-acls/instance-7?project=default):\n"
                                "Error: Project not found\n")
        self.assertFalse(any(call[3:4] == ["copy"] or call[:3] == ["incus", "network", "acl"] for call in calls))

    def test_the_container_phase_prints_the_seconds_of_each_part(self):
        transcript = []
        _, _, error = self.clone(transcript=transcript)
        self.assertEqual(error, "")
        printed = json.loads(transcript[0].strip().splitlines()[-1])
        self.assertEqual(set(printed["timings"]), {"acl", "copy", "units", "boot_files", "firewall", "network_check", "idmap", "start", "private_names",
                                                   "node_services", "mail", "branch", "t3_files"})
        self.assertTrue(all(isinstance(value, (int, float)) and value >= 0 for value in printed["timings"].values()))

    BOOT_FILES = [
        {"path": "/etc/systemd/system.conf.d/99-orbit-node-services.conf", "uid": 0, "gid": 0, "mode": "0644",
         "content": "# Managed by Orbit: attached Node service.\n[Manager]\nDefaultEnvironment=NO_PROXY=a no_proxy=a\n"},
        {"path": "/etc/profile.d/99-orbit-node-services.sh", "uid": 0, "gid": 0, "mode": "0644",
         "content": "# Managed by Orbit: attached Node service.\nexport NO_PROXY=a no_proxy=a\n"},
    ]

    def clone_with(self, clone, **kwargs):
        overrides = kwargs.pop("overrides", {})
        return self.clone(overrides={**overrides, "clone": {"source": "instance-1", "snapshot": "seed-abc", "t3": {"device": "t3"}, **clone}},
                          **kwargs)

    @staticmethod
    def pushes(calls):
        return [call for call in calls if call[3:5] == ["file", "push"] and call[-1].startswith("instance-7/etc/")
                and "99-orbit-node-services" in call[-1]]

    def test_boot_files_reach_the_copy_before_its_first_start_and_before_the_node_services_apply(self):
        calls, _, error = self.clone_with({"boot_files": self.BOOT_FILES}, overrides={"node_services": [{"port": 3308}]})
        self.assertEqual(error, "")
        pushes = self.pushes(calls)
        self.assertEqual([call[-1] for call in pushes], ["instance-7" + item["path"] for item in self.BOOT_FILES])
        for call in pushes:
            self.assertEqual(call[3:12], ["file", "push", "--create-dirs", "--uid", "0", "--gid", "0", "--mode", "0644"])
        # The exact rendered content goes in as the push's input.
        self.assertEqual([self.inputs[id(call)] for call in pushes], [item["content"] for item in self.BOOT_FILES])
        copy = next(i for i, call in enumerate(calls) if "copy" in call)
        start = calls.index(["incus", "--project", "orbit-instances", "start", "instance-7"])
        apply = next(i for i, c in enumerate(calls) if c[:2] == ["python3", "-c"] and c[2] == 'print(\'{"status":"unchanged"}\')'
                     and c[-1] not in RENDERS)
        self.assertTrue(all(copy < calls.index(call) < start < apply for call in pushes))

    FRAME = "#orbit-model-environment"
    MODEL = "ANTHROPIC_BASE_URL=http://10.253.0.1:8317\nANTHROPIC_AUTH_TOKEN=PLACEHOLDER\n"
    T3_FILES = {
        "t3": {"device": "t3", "condition": "/etc/orbit/instance"},
        "model_environment": {"path": "/home/agent/.config/orbit/model.env", "uid": 2750, "gid": 2750, "mode": "0600",
                              "frame": "#orbit-model-environment"},
        "files": [
            {"path": "/etc/orbit/instance", "before_start": True, "uid": 0, "gid": 0, "mode": "0644", "content": "ORBIT_INSTANCE_ID=7\n"},
            {"path": "/home/agent/.codex/config.toml", "before_start": True, "uid": 2750, "gid": 2750, "mode": "0600", "content": "codex\n"},
            {"path": "/home/agent/.claude.json", "before_start": True, "uid": 2750, "gid": 2750, "mode": "0600",
             "json": {"hasCompletedOnboarding": True}},
            {"path": "/home/agent/.claude/CLAUDE.md.note", "uid": 2750, "gid": 2750, "mode": "0600", "content": "not before the start\n"},
            {"path": "/home/agent/.t3/userdata/settings.json", "before_start": True, "uid": 2750, "gid": 2750, "mode": "0600",
             "json": {"linkedEnvironmentsEnabled": True}},
            {"path": "/etc/systemd/system/t3.service.d/10-orbit-boot.conf", "before_start": True, "uid": 0, "gid": 0, "mode": "0644",
             "content": "[Unit]\nDefaultDependencies=no\n"},
        ],
    }
    # The seed's agent home: the folders a default holds, without T3's state folder (a default never runs T3).
    T3_ROOT = {"home/agent/.config/orbit/.keep": "", "home/agent/.codex/.keep": "", "etc/orbit/.keep": "", "etc/systemd/system/.keep": ""}
    T3_TARGETS = ["/etc/orbit/instance", "/home/agent/.config/orbit/model.env", "/home/agent/.codex/config.toml", "/home/agent/.claude.json",
                  "/home/agent/.claude/CLAUDE.md.note", "/home/agent/.t3", "/home/agent/.t3/userdata",
                  "/home/agent/.t3/userdata/settings.json", "/etc/systemd/system/t3.service.d",
                  "/etc/systemd/system/t3.service.d/10-orbit-boot.conf"]

    def t3_writes(self, calls):
        return [call for call in calls if call[3:5] in (["file", "push"], ["file", "create"])
                and any(call[-1] == "instance-7" + path for path in self.T3_TARGETS)]

    def test_t3_files_reach_the_copy_before_its_first_start_with_the_file_t3_waits_for_last(self):
        # A Gateway that frames the model environment after the credential line: the copy's boot starts T3.
        transcript = []
        calls, _, error = self.clone_with(self.T3_FILES, rootfs=self.T3_ROOT, transcript=transcript,
                                               stdin="https://x-access-token:PRIVATE@github.com\n" + self.FRAME + "\n" + self.MODEL)
        self.assertEqual(error, "")
        writes = self.t3_writes(calls)
        self.assertEqual([call[-1] for call in writes], ["instance-7/home/agent/.config/orbit/model.env", "instance-7/home/agent/.codex/config.toml",
                                                          "instance-7/home/agent/.claude.json", "instance-7/home/agent/.t3", "instance-7/home/agent/.t3/userdata",
                                                          "instance-7/home/agent/.t3/userdata/settings.json",
                                                          "instance-7/etc/systemd/system/t3.service.d",
                                                          "instance-7/etc/systemd/system/t3.service.d/10-orbit-boot.conf", "instance-7/etc/orbit/instance"])
        self.assertEqual(self.inputs[id(writes[0])], self.MODEL)
        self.assertEqual(writes[0][5:11], ["--uid", "2750", "--gid", "2750", "--mode", "0600"])
        self.assertEqual(self.inputs[id(writes[1])], "codex\n")
        self.assertEqual(writes[1][5:11], ["--uid", "2750", "--gid", "2750", "--mode", "0600"])
        self.assertEqual(json.loads(self.inputs[id(writes[2])]), {"hasCompletedOnboarding": True})
        self.assertEqual(writes[3][3:12], ["file", "create", "--type=directory", "--uid", "2750", "--gid", "2750", "--mode", "0700"])
        self.assertEqual(json.loads(self.inputs[id(writes[5])]), {"linkedEnvironmentsEnabled": True})
        # T3's drop-in folder is root's, 0755, and the drop-in goes in before the file T3 waits for.
        self.assertEqual(writes[6][3:12], ["file", "create", "--type=directory", "--uid", "0", "--gid", "0", "--mode", "0755"])
        self.assertEqual(self.inputs[id(writes[7])], "[Unit]\nDefaultDependencies=no\n")
        self.assertEqual(writes[7][5:11], ["--uid", "0", "--gid", "0", "--mode", "0644"])
        self.assertEqual(self.inputs[id(writes[8])], "ORBIT_INSTANCE_ID=7\n")
        start = calls.index(["incus", "--project", "orbit-instances", "start", "instance-7"])
        self.assertTrue(all(calls.index(call) < start for call in writes))
        # The model environment goes only into its file: never onto a command line or into another input.
        self.assertFalse(any("PLACEHOLDER" in " ".join(call) for call in calls))
        self.assertEqual([call for call in calls if "PLACEHOLDER" in str(self.inputs.get(id(call)))], [writes[0]])
        self.assertEqual(json.loads(transcript[0].strip().splitlines()[-1])["timings"]["t3_files"], 1)

    def test_t3_files_merge_into_the_seeds_settings_and_a_public_repository_frames_no_line(self):
        held = {"content": '{"projectSettingsFolded":true}\n', "uid": "2750", "gid": "2750", "mode": "0600"}
        # A seed `.claude.json` that already holds the key with the agent part's owner and mode stays; one with another owner is
        # written again, so T3 never boots with a file the agent part would still change.
        merged = {"content": '{"hasCompletedOnboarding":true,"theme":"dark"}\n', "uid": "2750", "gid": "2750", "mode": "0600"}
        for claude, written in [(merged, False), ({**merged, "uid": "0", "gid": "0", "mode": "0644"}, True)]:
            calls, _, error = self.clone_with(self.T3_FILES, rootfs={**self.T3_ROOT, "home/agent/.t3/userdata/.keep": ""},
                                              overrides={"fixture_files": {"/home/agent/.t3/userdata/settings.json": held,
                                                                           "/home/agent/.claude.json": claude}},
                                              stdin=self.FRAME + "\n" + self.MODEL)
            self.assertEqual(error, "")
            self.assertEqual(any(call[-1] == "instance-7/home/agent/.claude.json" for call in self.t3_writes(calls)), written)
            if written:
                push = next(call for call in self.t3_writes(calls) if call[-1] == "instance-7/home/agent/.claude.json")
                self.assertEqual(json.loads(self.inputs[id(push)]), {"hasCompletedOnboarding": True, "theme": "dark"})
                self.assertEqual(push[5:11], ["--uid", "2750", "--gid", "2750", "--mode", "0600"])
        writes = [call for call in self.t3_writes(calls) if call[-1] != "instance-7/home/agent/.claude.json"]
        self.assertEqual([call[-1] for call in writes], ["instance-7/home/agent/.config/orbit/model.env", "instance-7/home/agent/.codex/config.toml",
                                                          "instance-7/home/agent/.t3/userdata/settings.json",
                                                          "instance-7/etc/systemd/system/t3.service.d",
                                                          "instance-7/etc/systemd/system/t3.service.d/10-orbit-boot.conf", "instance-7/etc/orbit/instance"])
        self.assertEqual(json.loads(self.inputs[id(writes[2])]), {"projectSettingsFolded": True, "linkedEnvironmentsEnabled": True})

    def test_t3_files_refuse_a_linked_folder_or_settings_by_their_own_code_before_the_start(self):
        for rootfs, files in [({**self.T3_ROOT, "home/agent/.t3": "->/tmp"}, {}),
                              ({**self.T3_ROOT, "home/agent/.t3/userdata/.keep": ""},
                               {"/home/agent/.t3/userdata/settings.json": {"content": "", "type": "symlink"}})]:
            with self.subTest(link=next(iter(files), "/home/agent/.t3")):
                calls, _, error = self.clone_with(self.T3_FILES, rootfs=rootfs, overrides={"fixture_files": files},
                                                  stdin=self.FRAME + "\n" + self.MODEL)
                self.assertEqual(error, "instance.clone_t3_files_refused\n")
                self.assertNotIn(["incus", "--project", "orbit-instances", "start", "instance-7"], calls)
                self.assertFalse(any(call[-1] == "instance-7/etc/orbit/instance" for call in calls))

    def test_an_older_gateways_bare_line_or_a_running_copy_gets_no_t3_files(self):
        # An older Gateway's run: its stdin is the credential line alone, with or without a frame in the intent; or a copy that already
        # runs. The T3 step starts T3, as before.
        bare = {**self.T3_FILES, "model_environment": {k: v for k, v in self.T3_FILES["model_environment"].items() if k != "frame"}}
        running = {"name": "instance-7", "status": "Running", "devices": {"t3": {}}, "config": {"volatile.uuid": "abc-123"},
               "expanded_devices": {"eth0": {"type": "nic", "network": "orbitinst0", "ipv4.address": "10.253.0.2",
                                             "security.acls": "orbitinst0-proxy,instance-7",
                                             "security.acls.default.egress.action": "reject",
                                             "security.acls.default.ingress.action": "reject",
                                             "security.ipv4_filtering": "true", "security.mac_filtering": "true"}}}
        for clone, stdin, existing in [(bare, "https://x-access-token:PRIVATE@github.com\n", None),
                                       (self.T3_FILES, "https://x-access-token:PRIVATE@github.com\n", None),
                                       (self.T3_FILES, self.FRAME + "\n" + self.MODEL, running)]:
            with self.subTest(running=existing is not None, frame="frame" in clone["model_environment"]):
                transcript = []
                calls, _, error = self.clone_with(clone, rootfs=self.T3_ROOT, stdin=stdin, existing=existing, transcript=transcript)
                self.assertEqual(error, "")
                self.assertEqual(self.t3_writes(calls), [])
                self.assertEqual(json.loads(transcript[0].strip().splitlines()[-1])["timings"]["t3_files"], 0)

    def test_the_browser_files_and_an_empty_linker_cache_reach_a_copy_of_an_old_seed_before_its_first_start(self):
        # A seed taken before the runtime folder had its browser libraries lacks these files. The copy gets them, and an empty
        # /etc/ld.so.cache, before it starts: the stock ldconfig.service rebuilds an empty cache at that first boot.
        browser = [
            {"path": "/etc/ld.so.conf.d/zz-orbit-browser.conf", "uid": 0, "gid": 0, "mode": "0644",
             "content": "# Managed by Orbit.\n/opt/orbit-runtime/current/lib\n"},
            {"path": "/etc/fonts/fonts.conf", "uid": 0, "gid": 0, "mode": "0644", "content": "<fontconfig/>\n"},
            {"path": "/etc/gitconfig", "uid": 0, "gid": 0, "mode": "0644", "content": "[safe]\n\tdirectory = /srv/workspace\n"},
            {"path": "/etc/ld.so.cache", "uid": 0, "gid": 0, "mode": "0644", "content": ""},
            # Playwright's browsers beside its package (InstanceContainer::BrowserEnvironment), for units and login shells.
            {"path": "/etc/systemd/system.conf.d/91-orbit-browsers.conf", "uid": 0, "gid": 0, "mode": "0644",
             "content": "# Managed by Orbit.\n[Manager]\nDefaultEnvironment=PLAYWRIGHT_BROWSERS_PATH=0\n"},
            {"path": "/etc/profile.d/91-orbit-browsers.sh", "uid": 0, "gid": 0, "mode": "0644",
             "content": "# Managed by Orbit.\nexport PLAYWRIGHT_BROWSERS_PATH=0\n"},
        ]
        calls, _, error = self.clone_with({"boot_files": browser})
        self.assertEqual(error, "")
        targets = ["instance-7" + item["path"] for item in browser]
        pushes = [call for call in calls if call[3:5] == ["file", "push"] and call[-1] in targets]
        self.assertEqual([call[-1] for call in pushes], targets)
        self.assertEqual([self.inputs[id(call)] for call in pushes], [item["content"] for item in browser])
        start = calls.index(["incus", "--project", "orbit-instances", "start", "instance-7"])
        self.assertTrue(all(calls.index(call) < start for call in pushes))

    TRUST = {"source": "/etc/orbit/agent/ca.pem", "path": "/usr/local/share/ca-certificates/orbit-managed-root-ca.crt",
             "hash_directory": "/etc/ssl/certs", "uid": 0, "gid": 0, "mode": "0644"}

    @staticmethod
    def certificate(name):
        with tempfile.TemporaryDirectory() as folder:
            subprocess.run(["openssl", "req", "-x509", "-newkey", "rsa:2048", "-nodes", "-days", "2", "-subj", "/CN=" + name,
                            "-keyout", folder + "/key.pem", "-out", folder + "/cert.pem"], check=True, capture_output=True)
            pem = Path(folder + "/cert.pem").read_text()
        digest = subprocess.run(["openssl", "x509", "-noout", "-subject_hash"], input=pem, text=True, capture_output=True, check=True)
        return pem, digest.stdout.strip()

    # The seed's CA bundle: the public roots only, as a seed taken before the Orbit root reached it holds.
    PUBLIC_BUNDLE = "-----BEGIN CERTIFICATE-----\nUFVCTElD\n-----END CERTIFICATE-----\n"
    BUNDLE = "/etc/ssl/certs/ca-certificates.crt"

    def clone_trusting(self, node_ca, clone, bundle=PUBLIC_BUNDLE, **kwargs):
        """A clone whose Node holds `node_ca` (None: the file is missing) at the one allowed source path, and whose seed holds `bundle`
        (None: no bundle) as its CA bundle."""
        overrides = kwargs.pop("overrides", {})
        kwargs["overrides"] = {**overrides, "fixture_files": {**({} if bundle is None else {self.BUNDLE: bundle}),
                                                              **overrides.get("fixture_files", {})}}
        original = Path.read_text

        def read_text(path, *args, **kw):
            if str(path) == self.TRUST["source"]:
                if node_ca is None:
                    raise FileNotFoundError(str(path))
                return node_ca
            return original(path, *args, **kw)

        with patch("pathlib.Path.read_text", read_text):
            return self.clone_with(clone, **kwargs)

    def test_the_node_root_ca_reaches_the_copy_under_its_hashed_name_before_its_first_start(self):
        pem, digest = self.certificate("Orbit Test Root")
        calls, _, error = self.clone_trusting(pem, {"boot_files": [self.TRUST]})
        self.assertEqual(error, "")
        pushes = [call for call in calls if call[3:5] == ["file", "push"] and call[-1].endswith("orbit-managed-root-ca.crt")]
        self.assertEqual([call[-1] for call in pushes], ["instance-7/usr/local/share/ca-certificates/orbit-managed-root-ca.crt"])
        self.assertEqual([self.inputs[id(call)] for call in pushes], [pem])
        # The links that `update-ca-certificates` makes, so its rehash keeps them.
        links = [call[3:] for call in calls if call[3:5] == ["file", "create"]]
        self.assertEqual(links, [
            ["file", "create", "--type=symlink", "--force", "instance-7/etc/ssl/certs/orbit-managed-root-ca.pem",
             "/usr/local/share/ca-certificates/orbit-managed-root-ca.crt"],
            ["file", "create", "--type=symlink", "--force", f"instance-7/etc/ssl/certs/{digest}.0", "/etc/ssl/certs/orbit-managed-root-ca.pem"],
        ])
        start = calls.index(["incus", "--project", "orbit-instances", "start", "instance-7"])
        self.assertTrue(all(calls.index(call) < start for call in pushes + [c for c in calls if c[3:5] == ["file", "create"]]))

    def test_an_intent_cannot_smuggle_a_delete_through_a_plain_boot_file(self):
        calls, _, error = self.clone_with({"boot_files": [{"path": "/etc/x", "content": "x", "uid": 0, "gid": 0, "mode": "0644",
                                                            "replaces": "/etc/passwd", "link": "/etc/y", "target": "/etc/shadow"}]})
        self.assertEqual(error, "")
        self.assertFalse(any(call[3:5] in (["file", "delete"], ["file", "create"]) and "/etc/" in call[-1] and call[-1].endswith(("y", "shadow")) for call in calls))
        self.assertIn("instance-7/etc/x", [call[-1] for call in calls if call[3:5] == ["file", "push"]])

    def test_another_source_path_and_a_missing_node_ca_fail_by_name_before_the_copy(self):
        pem, _ = self.certificate("Orbit Test Root")
        for case, node_ca, entry, code in (("another source", pem, {**self.TRUST, "source": "/etc/shadow"}, "instance.clone_boot_source_refused"),
                                           ("missing Node file", None, self.TRUST, "instance.clone_trust_unavailable"),
                                           ("not a certificate", "not a certificate\n", self.TRUST, "instance.clone_trust_unavailable")):
            with self.subTest(case=case):
                calls, _, error = self.clone_trusting(node_ca, {"boot_files": [entry]})
                self.assertEqual(error, code + "\n")
                self.assertFalse(any("copy" in call for call in calls))
                self.assertFalse(any(call[3:5] == ["file", "push"] for call in calls))

    def test_a_seed_that_trusts_an_older_root_loses_its_old_hashed_name(self):
        old, old_digest = self.certificate("Orbit Old Root")
        new, new_digest = self.certificate("Orbit New Root")
        calls, _, error = self.clone_trusting(new, {"boot_files": [self.TRUST]},
                                              overrides={"fixture_pull": {self.TRUST["path"]: old}})
        self.assertEqual(error, "")
        delete = ["incus", "--project", "orbit-instances", "file", "delete", f"instance-7/etc/ssl/certs/{old_digest}.0"]
        self.assertIn(delete, calls)
        first_push = next(i for i, call in enumerate(calls) if call[3:5] == ["file", "push"] and "ca-certificates" in call[-1])
        self.assertLess(calls.index(delete), first_push)
        self.assertIn(f"instance-7/etc/ssl/certs/{new_digest}.0", [call[-2] for call in calls if call[3:5] == ["file", "create"]])

    def bundle_pushes(self, calls):
        return [call for call in calls if call[3:5] == ["file", "push"] and call[-1] == "instance-7" + self.BUNDLE]

    def test_a_seed_bundle_without_the_root_gets_it_once_with_its_owner_and_mode_before_the_first_start(self):
        pem, _ = self.certificate("Orbit Test Root")
        # No newline at the end of the seed's bundle: the root still starts on its own line.
        # An owner and mode that differ from the hosts push's root 0644: the push must keep what it read.
        held = {"content": self.PUBLIC_BUNDLE.rstrip("\n"), "uid": "5", "gid": "7", "mode": "0664"}
        calls, _, error = self.clone_trusting(pem, {"boot_files": [self.TRUST]}, bundle=held)
        self.assertEqual(error, "")
        pushes = self.bundle_pushes(calls)
        self.assertEqual([call[3:] for call in pushes],
                         [["file", "push", "--uid", "5", "--gid", "7", "--mode", "0664", "-", "instance-7" + self.BUNDLE]])
        self.assertEqual(self.inputs[id(pushes[0])], self.PUBLIC_BUNDLE + pem)
        self.assertEqual(self.inputs[id(pushes[0])].count(pem), 1)
        # The bundle is read through the local API's socket, after the root's own file and links, and before the start.
        read = next(call for call in calls if call[0] == "curl" and call[-1].endswith(urllib.parse.quote(self.BUNDLE)))
        self.assertIn("/var/lib/incus/unix.socket", read)
        start = calls.index(["incus", "--project", "orbit-instances", "start", "instance-7"])
        root = next(i for i, call in enumerate(calls) if call[3:5] == ["file", "push"] and call[-1].endswith("orbit-managed-root-ca.crt"))
        self.assertLess(root, calls.index(read))
        self.assertLess(calls.index(pushes[0]), start)

    def test_a_seed_bundle_with_the_root_stays_as_it_is(self):
        pem, _ = self.certificate("Orbit Test Root")
        # The same certificate with other line breaks is still the root: it is never added twice.
        lines = pem.splitlines()
        rewrapped = "\n".join([lines[0], "".join(lines[1:-1]), lines[-1]]) + "\n"
        for case, bundle in (("as written", self.PUBLIC_BUNDLE + pem), ("rewrapped", rewrapped + self.PUBLIC_BUNDLE)):
            with self.subTest(case=case):
                calls, _, error = self.clone_trusting(pem, {"boot_files": [self.TRUST]}, bundle=bundle)
                self.assertEqual(error, "")
                self.assertEqual(self.bundle_pushes(calls), [])
                self.assertIn(["incus", "--project", "orbit-instances", "start", "instance-7"], calls)

    def test_a_copy_without_a_regular_bundle_refuses_the_create_before_its_first_start(self):
        pem, _ = self.certificate("Orbit Test Root")
        for case, bundle in (("missing", None), ("a link", {"content": "/elsewhere", "type": "symlink"})):
            with self.subTest(case=case):
                calls, _, error = self.clone_trusting(pem, {"boot_files": [self.TRUST]}, bundle=bundle)
                self.assertEqual(error, "instance.clone_trust_bundle_refused\n")
                self.assertEqual(self.bundle_pushes(calls), [])
                self.assertNotIn(["incus", "--project", "orbit-instances", "start", "instance-7"], calls)

    def test_a_clone_without_the_trust_entry_reads_no_bundle(self):
        calls, _, error = self.clone_with({"boot_files": self.BOOT_FILES})
        self.assertEqual(error, "")
        self.assertFalse(any(call[0] == "curl" and call[-1].endswith(urllib.parse.quote(self.BUNDLE)) for call in calls))

    NAMES = ({"name": "feature.tool.test", "host": "10.253.0.1", "port": 443}, {"name": "api.feature.tool.test", "host": "10.253.0.1", "port": 443})
    BLOCK = "# BEGIN Orbit private names\n10.253.0.1 feature.tool.test\n10.253.0.1 api.feature.tool.test\n# END Orbit private names\n"

    def renderer(self, command, plan):
        result = subprocess.run(["python3", "-c", NODE_SERVICES.read_text(), command], input=json.dumps(plan), text=True,
                                capture_output=True, check=True)
        return json.loads(result.stdout)

    # The one exec of the pass: the kernel hostname, then the hosts file.
    READ = ["incus", "--project", "orbit-instances", "exec", "instance-7", "--", "sh", "-c", READ_SCRIPT, "sh", HOSTNAME]

    def hosts_pushes(self, calls):
        return [call for call in calls if call[3:5] == ["file", "push"] and call[-1] == "instance-7/etc/hosts"]

    def test_the_plans_private_names_survive_the_first_starts_hosts_template_with_env_syncs_bytes(self):
        # The seed's own block, then the copy's Incus template rewrites /etc/hosts at the first start: only a write after that start
        # stays. The clone reads the file as env:sync does, renders it with the one renderer and pushes it with root and 0644.
        seed = "127.0.0.1 localhost\n# BEGIN Orbit private names\n10.44.0.3 reverb.orbit\n# END Orbit private names\n"
        calls, _, error = self.clone_with({}, overrides={"private_names": self.NAMES, "fixture_files": {"/etc/hosts": seed}})
        self.assertEqual(error, "")
        template = HOSTS_TEMPLATE.format(name="instance-7")
        named = template.replace("127.0.1.1\tinstance-7", NAMED_LINE)
        self.assertEqual(self.hosts, named + self.BLOCK)
        # Both renderers ran on the template, each keeping the other's lines, and the name reached the file and the kernel.
        self.assertEqual(self.hosts, self.renderer("hosts", {"hosts": template, "private_names": self.NAMES})["content"]
                         .replace("127.0.1.1\tinstance-7", NAMED_LINE))
        self.assertEqual(self.hostname, (HOSTNAME + "\n", HOSTNAME))
        pushes = self.hosts_pushes(calls)
        self.assertEqual([call[3:] for call in pushes],
                         [["file", "push", "--uid", "0", "--gid", "0", "--mode", "0644", "-", "instance-7/etc/hosts"]])
        start = calls.index(["incus", "--project", "orbit-instances", "start", "instance-7"])
        read = calls.index(self.READ)
        self.assertLess(start, read)
        self.assertLess(read, calls.index(pushes[0]))
        # The private names and the machine name share the pass's one exec, between the start and the push.
        self.assertEqual([call for call in calls[start:calls.index(pushes[0])] if call[3:4] == ["exec"]], [self.READ])
        # The exec writes /etc/hostname itself: no Incus call pushes it.
        self.assertFalse(any(call[3:5] == ["file", "push"] and call[-1] == "instance-7/etc/hostname" for call in calls))
        # The clone runs the bundle's renderer, not a copy of it.
        render = next(call for call in calls if call[:2] == ["python3", "-c"] and call[3:] == ["hosts"])
        self.assertEqual(render[2], 'print(\'{"status":"unchanged"}\')')

    def test_a_clone_without_names_writes_no_block_and_drops_a_seed_block_the_template_left(self):
        calls, _, error = self.clone_with({}, overrides={"fixture_files": {"/etc/hosts": "127.0.0.1 localhost\n" + self.BLOCK}})
        self.assertEqual(error, "")
        self.assertEqual(self.hosts, HOSTS_TEMPLATE.format(name="instance-7").replace("127.0.1.1\tinstance-7", NAMED_LINE))
        self.assertEqual(len(self.hosts_pushes(calls)), 1)
        # A copy whose metadata has no hosts template keeps the seed's file: its block goes, every other line stays.
        calls, _, error = self.clone_with({}, overrides={"fixture_hosts_template": False,
                                                         "fixture_files": {"/etc/hosts": "127.0.0.1 localhost\n" + self.BLOCK}})
        self.assertEqual(error, "")
        self.assertEqual(self.hosts, NAMED_LINE + "\n127.0.0.1 localhost\n")
        # A copy without a hosts file and without names gets one with its name line alone.
        calls, _, error = self.clone_with({}, overrides={"fixture_hosts_template": False})
        self.assertEqual(error, "")
        self.assertEqual(self.hosts, NAMED_LINE + "\n")

    def test_a_retry_on_a_running_copy_with_the_block_in_place_writes_nothing(self):
        running = {"name": "instance-7", "status": "Running", "devices": {"t3": {}}, "config": {"volatile.uuid": "abc-123"},
                   "expanded_devices": {"eth0": {"type": "nic", "network": "orbitinst0", "ipv4.address": "10.253.0.2",
                                                 "security.acls": "orbitinst0-proxy,instance-7",
                                                 "security.acls.default.egress.action": "reject",
                                                 "security.acls.default.ingress.action": "reject",
                                                 "security.ipv4_filtering": "true", "security.mac_filtering": "true"}}}
        held = HOSTS_TEMPLATE.format(name="instance-7").replace("127.0.1.1\tinstance-7", NAMED_LINE) + self.BLOCK
        calls, _, error = self.clone_with({}, existing=running, overrides={"private_names": self.NAMES,
                                                                           "fixture_files": {"/etc/hosts": held, "/etc/hostname": HOSTNAME + "\n"}})
        self.assertEqual(error, "")
        self.assertEqual(self.hosts_pushes(calls), [])
        # The hostname file is in place too: no push.
        self.assertFalse(any(call[3:5] == ["file", "push"] and call[-1] == "instance-7/etc/hostname" for call in calls))
        self.assertEqual(self.hosts, held)
        # The phase did not start this copy: its mail part may not read a silent systemd as a boot (instance_mail.py).
        calls, _, error = self.clone(mail={"port": 1025}, existing=running)
        self.assertEqual(error, "")
        mail_call = next(call for call in calls if call[:2] == ["python3", "-c"] and '"status":"unchanged"' in call[2]
                         and call[-1] not in RENDERS)
        self.assertNotEqual(mail_call[-1], "started")
        self.assertNotIn(["incus", "--project", "orbit-instances", "start", "instance-7"], calls)

    def test_a_failed_hosts_read_after_the_start_fails_the_create_by_its_own_name(self):
        calls, _, error = self.clone_with({}, overrides={"private_names": self.NAMES, "fixture_hosts_read_error": True})
        self.assertEqual(error, "instance.clone_private_names_failed\n")
        self.assertIn(["incus", "--project", "orbit-instances", "start", "instance-7"], calls)
        self.assertEqual(self.hosts_pushes(calls), [])

    def test_the_composed_proxy_value_reaches_both_drop_ins_before_the_first_start_with_the_one_writers_bytes(self):
        proxy = {"NO_PROXY": "localhost,10.253.0.1,feature.tool.test", "no_proxy": "localhost,10.253.0.1,feature.tool.test"}
        calls, _, error = self.clone_with({"proxy_environment": proxy}, overrides={"node_services": [{"port": 3308}]})
        self.assertEqual(error, "")
        pushes = self.pushes(calls)
        files = self.renderer("proxy-files", {"proxy_environment": proxy})["files"]
        self.assertEqual([call[-1] for call in pushes], ["instance-7/" + path for path in files])
        for call in pushes:
            self.assertEqual(call[3:12], ["file", "push", "--create-dirs", "--uid", "0", "--gid", "0", "--mode", "0644"])
        self.assertEqual([self.inputs[id(call)] for call in pushes], list(files.values()))
        self.assertEqual(files["etc/profile.d/99-orbit-node-services.sh"],
                         "# Managed by Orbit: attached Node service.\nexport NO_PROXY=" + proxy["NO_PROXY"] + " no_proxy=" + proxy["no_proxy"] + "\n")
        start = calls.index(["incus", "--project", "orbit-instances", "start", "instance-7"])
        apply = next(i for i, c in enumerate(calls) if c[:2] == ["python3", "-c"] and c[2] == 'print(\'{"status":"unchanged"}\')'
                     and c[-1] not in RENDERS)
        self.assertTrue(all(calls.index(call) < start < apply for call in pushes))

    STORE = {"home": "/home/agent", "directories": ["/home/agent/.pki", "/home/agent/.pki/nssdb"], "path": "/home/agent/.pki/nssdb/pkcs11.txt",
             "entry": "library=/opt/orbit-runtime/current/pkcs11/p11-kit-trust.so\nname=Orbit system trust\n\n",
             "uid": 2750, "gid": 2750, "mode": "0600", "directory_mode": "0700", "library": "libp11-kit0", "version": "0.26.2-2"}

    @staticmethod
    def dpkg(version):
        return "Package: libc6\nStatus: install ok installed\nArchitecture: amd64\nVersion: 2.42\n" + (
            "" if version is None else f"\nPackage: libp11-kit0\nStatus: install ok installed\nArchitecture: amd64\nVersion: {version}\n")

    def store_calls(self, calls):
        return [call for call in calls if call[3:5] in (["file", "create"], ["file", "push"]) and "/home/agent/.pki" in call[-1]]

    def store_clone(self, rootfs, held=None, version="0.26.2-2"):
        pulls = {"/var/lib/dpkg/status": self.dpkg(version)}
        if held is not None:
            pulls[self.STORE["path"]] = held
        return self.clone_with({"boot_files": [self.STORE]}, rootfs=rootfs, overrides={"fixture_pull": pulls})

    def test_agents_nss_store_names_the_trust_module_in_a_copy_without_one_before_its_first_start(self):
        calls, _, error = self.store_clone({"home/agent/.bashrc": ""})
        self.assertEqual(error, "")
        writes = self.store_calls(calls)
        self.assertEqual([call[3:] for call in writes], [
            ["file", "create", "--type=directory", "--uid", "2750", "--gid", "2750", "--mode", "0700", "instance-7/home/agent/.pki"],
            ["file", "create", "--type=directory", "--uid", "2750", "--gid", "2750", "--mode", "0700", "instance-7/home/agent/.pki/nssdb"],
            ["file", "push", "--uid", "2750", "--gid", "2750", "--mode", "0600", "-", "instance-7/home/agent/.pki/nssdb/pkcs11.txt"],
        ])
        self.assertEqual(self.inputs[id(writes[-1])], self.STORE["entry"])
        # Every path's type comes from the local API's header, through its socket.
        self.assertIn("/var/lib/incus/unix.socket", next(call for call in calls if call[0] == "curl"))
        start = calls.index(["incus", "--project", "orbit-instances", "start", "instance-7"])
        self.assertTrue(all(calls.index(call) < start for call in writes))

    def test_a_seed_store_without_the_entry_gets_it_appended_and_a_store_with_it_is_left_alone(self):
        own = "library=\nname=NSS Internal PKCS #11 Module\nNSS=Flags=internal\n"
        rootfs = {"home/agent/.pki/nssdb/pkcs11.txt": own}
        calls, _, error = self.store_clone(rootfs, held=own)
        self.assertEqual(error, "")
        writes = self.store_calls(calls)
        self.assertEqual([call[-1] for call in writes], ["instance-7/home/agent/.pki/nssdb/pkcs11.txt"])
        self.assertEqual(self.inputs[id(writes[0])], own + "\n" + self.STORE["entry"])
        calls, _, error = self.store_clone(rootfs, held=own + "\n" + self.STORE["entry"])
        self.assertEqual(error, "")
        self.assertEqual(self.store_calls(calls), [])

    def test_a_copy_without_home_agent_gets_no_nss_store(self):
        calls, _, error = self.store_clone({"etc/hostname": "copy\n"})
        self.assertEqual(error, "")
        self.assertEqual(self.store_calls(calls), [])
        self.assertIn(["incus", "--project", "orbit-instances", "start", "instance-7"], calls)

    def test_a_planted_path_of_another_type_refuses_the_create_before_any_store_write(self):
        for case, rootfs in (("symlinked store", {"home/agent/.pki/nssdb/pkcs11.txt": "->/etc/passwd"}),
                             ("a file where the store folder goes", {"home/agent/.pki/nssdb": "planted"}),
                             ("a folder named like the store", {"home/agent/.pki/nssdb/pkcs11.txt/x": ""}),
                             ("a symlinked folder", {"home/agent/.pki": "->/etc"})):
            with self.subTest(case=case):
                calls, _, error = self.store_clone(rootfs)
                self.assertEqual(error, "instance.clone_browser_trust_refused\n")
                self.assertEqual(self.store_calls(calls), [])
                self.assertNotIn(["incus", "--project", "orbit-instances", "start", "instance-7"], calls)

    def test_the_copys_dpkg_status_and_store_are_read_without_a_decode_failure(self):
        calls, _, error = self.store_clone({"home/agent/.pki/nssdb/pkcs11.txt": "x"}, held="x\n")
        self.assertEqual(error, "")
        reads = [call for call in calls if call[3:5] == ["file", "pull"] and call[5].endswith(("/var/lib/dpkg/status", "pkcs11.txt"))]
        self.assertEqual(len(reads), 2)
        self.assertTrue(all(self.decoding[id(call)] == "surrogateescape" for call in reads))

    def test_a_copy_whose_libp11_kit0_is_not_the_modules_version_refuses_the_create(self):
        for version in ("0.25.5-3", None):
            with self.subTest(version=version):
                calls, _, error = self.store_clone({"home/agent/.bashrc": ""}, version=version)
                self.assertEqual(error, "instance.clone_browser_trust_mismatch\n")
                self.assertEqual(self.store_calls(calls), [])

    def test_a_running_copy_and_a_clone_without_boot_files_push_none(self):
        running = {"name": "instance-7", "status": "Running", "devices": {"t3": {}}, "config": {"volatile.uuid": "abc-123"},
                   "expanded_devices": {"eth0": {"type": "nic", "network": "orbitinst0", "ipv4.address": "10.253.0.2",
                                                 "security.acls": "orbitinst0-proxy,instance-7",
                                                 "security.acls.default.egress.action": "reject",
                                                 "security.acls.default.ingress.action": "reject",
                                                 "security.ipv4_filtering": "true", "security.mac_filtering": "true"}}}
        for case, clone, existing in (("retry on a running copy", {"boot_files": self.BOOT_FILES}, running),
                                      ("normal clone", {}, None),
                                      # An intent admitted by the previous Gateway release: the files are in clone.files,
                                      # which the agent phase writes, and the key is absent.
                                      ("old intent", {"files": self.BOOT_FILES}, None)):
            with self.subTest(case=case):
                calls, _, error = self.clone_with(clone, existing=existing)
                self.assertEqual(error, "")
                self.assertEqual(self.pushes(calls), [])
                started = ["incus", "--project", "orbit-instances", "start", "instance-7"] in calls
                self.assertEqual(started, existing is None)

    def test_clone_node_target_is_fatal_before_workspace_and_strips_inherited_enablement(self):
        _, _, error = self.clone(overrides={'node_services': [{'port': 3308}]},
                                 node_service_code="raise SystemExit('instance.node_service_unreachable')")
        self.assertEqual(error, 'instance.node_service_unreachable\n')
        calls, _, error = self.clone(overrides={'node_services': [{'port': 3308}]})
        self.assertEqual(error, '')
        removed = next(c for c in calls if 'file' in c and 'delete' in c)
        self.assertIn('instance-7/etc/systemd/system/sockets.target.wants/orbit-node-service-3308.socket', removed)
        start = calls.index(['incus', '--project', 'orbit-instances', 'start', 'instance-7'])
        apply = next(i for i, c in enumerate(calls) if c[:2] == ['python3', '-c'] and c[2] == 'print(\'{"status":"unchanged"}\')'
                     and c[-1] not in RENDERS)
        self.assertLess(start, apply)

    def test_clone_of_a_default_with_a_node_service_target_strips_it_on_the_first_try_and_on_a_retry(self):
        system = "etc/systemd/system/"
        seed = {system + "orbit-node-service-3308.service": "[Service]\n",
                system + "orbit-node-service-3308.socket": "[Socket]\n",
                system + "sockets.target.wants/orbit-node-service-3308.socket": "->/etc/systemd/system/orbit-node-service-3308.socket",
                system + "sockets.target.wants/orbit-mail-1025.socket": "->/etc/systemd/system/orbit-mail-1025.socket",
                system + "orbit-mail-1025.socket": "[Socket]\n",
                system + "multi-user.target.wants/orbit-frankenphp.service": "->/etc/systemd/system/orbit-frankenphp.service",
                system + "orbit-frankenphp.service": "[Service]\n"}
        # A retry: the failed clone already removed the unit files, so the socket link dangles.
        retry = {path: content for path, content in seed.items() if not path.endswith("orbit-node-service-3308.socket") or ".wants/" in path}
        retry.pop(system + "orbit-node-service-3308.service")
        for case, files in (("first", seed), ("retry", retry)):
            with self.subTest(case=case):
                calls, _, error = self.clone(overrides={"node_services": [{"port": 3308}]}, rootfs=files)
                self.assertEqual(error, "")
                deletes = [c[5:] for c in calls if c[3:5] == ["file", "delete"]]
                removed = [path for paths in deletes for path in paths if not path.startswith("-")]
                for path in ("sockets.target.wants/orbit-node-service-3308.socket", "multi-user.target.wants/orbit-frankenphp.service"):
                    self.assertIn("instance-7/" + system + path, removed)
                # The seed's mail socket stays enabled; the mail apply finds it in place.
                self.assertNotIn("instance-7/" + system + "sockets.target.wants/orbit-mail-1025.socket", removed)
                if case == "first":
                    self.assertIn("instance-7/" + system + "orbit-node-service-3308.socket", removed)
                    self.assertIn("instance-7/" + system + "orbit-node-service-3308.service", removed)
                self.assertNotIn("instance-7/" + system + "orbit-frankenphp.service", removed)
                self.assertIn(["incus", "--project", "orbit-instances", "start", "instance-7"], calls)

    def test_clone_target_failure_receipt_keeps_public_identity_and_bounds_tool_output(self):
        target = {"id": 7, "process_id": 19, "process_name": "fixture-db", "port": 3308}
        for code in ("instance.node_service_unreachable", "PRIVATE-TOOL-OUTPUT"):
            evidence = {"error_code": code, "node_service_target": target,
                        "private": "PRIVATE-CREDENTIAL"}
            script = ("import json,sys; print(" + repr(json.dumps(evidence)) + "); "
                      "raise SystemExit(" + repr(code) + ")")
            transcript = []
            calls, _, error = self.clone(overrides={"node_services": [target]},
                                        node_service_code=script, transcript=transcript)
            if code == "instance.node_service_unreachable":
                marker, terminal = error.splitlines()
                self.assertEqual(json.loads(marker.removeprefix("orbit-node-service-failure=")),
                                 {"error_code": code, "node_service_target": target})
                self.assertEqual(terminal, code)
            else:
                self.assertEqual(error, "instance.node_service_apply_failed\n")
            self.assertNotIn("PRIVATE", error + "".join(transcript))
            self.assertFalse(any("workspace" in str(call) for call in calls if call[0] != "python3"))

    def test_clone_launch_applies_mail_without_another_playbook(self):
        fixture = BranchSelectionTest()
        fixture.setUp()
        try:
            selected = {"repository": str(fixture.remote), "mirror": str(fixture.mirror), "protocols": ["file"], "branch": "mail-branch",
                        "branch_selection": {"mode": "new", "target": "mail-branch", "base": "release", "source_sha": fixture.tip}}
            for selection in [None, selected]:
                with self.subTest(branch_selection=selection is not None):
                    calls, _, error = self.clone(mail={"port": 1025}, overrides=selection, real_git=selection is not None)
                    self.assertEqual(error, "")
                    self.assertFalse(any("ansible-playbook" in call for call in calls))
                    mail_call = next(i for i, call in enumerate(calls) if call[:2] == ["python3", "-c"] and '"status":"unchanged"' in call[2]
                                     and call[-1] not in RENDERS)
                    start = calls.index(["incus", "--project", "orbit-instances", "start", "instance-7"])
                    self.assertLess(start, mail_call)
                    # The phase started this copy, so the mail part may leave linked sockets to the copy's boot (instance_mail.py).
                    self.assertEqual(calls[mail_call][-1], "started")
            self.assertEqual(fixture.git("-C", str(fixture.mirror), "rev-parse", "refs/heads/mail-branch").stdout.strip(), fixture.tip)
        finally:
            fixture.doCleanups()

    def test_mail_failure_keeps_clone_launch_and_later_branch_work_successful(self):
        for code in ["instance.host_unavailable", "process.target_inactive", "private tool output"]:
            transcript = []
            source = "import sys;sys.stderr.write(" + repr(code + "\n") + ");sys.exit(1)"
            calls, _, error = self.clone(mail={"port": 1025}, mail_code=source, transcript=transcript)
            self.assertEqual(error, "")
            reason = code if code != "private tool output" else "instance.mail_update_failed"
            self.assertIn("orbit-mail=failed:" + reason, transcript[0])
            self.assertNotIn("private tool output", transcript[0])
            self.assertTrue(any("ls-remote" in call for call in calls))

    def test_clone_uses_the_admitted_common_acl_name(self):
        _, read, error = self.clone(nic_acl="admitted-proxy")
        self.assertEqual(error, "")
        self.assertEqual(
            read["expanded_devices"]["eth0"]["security.acls"],
            "admitted-proxy,instance-7",
        )

    def test_clone_refuses_unsafe_effective_nics_before_start(self):
        safe_nic = {
            "type": "nic",
            "network": "orbitinst0",
            "security.acls": "instance-7",
            "security.acls.default.egress.action": "reject",
        }
        for damage in [
            lambda read: read["expanded_devices"]["eth0"].update(
                {"security.acls": " instance-7, orbitinst0-egress "}
            ),
            lambda read: read["expanded_devices"]["eth0"].update({"security.acls": ""}),
            lambda read: read["expanded_devices"]["eth0"].update(
                {"security.acls.default.egress.action": "allow"}
            ),
            lambda read: read["expanded_devices"].update(
                {"eth1": copy.deepcopy(safe_nic)}
            ),
            lambda read: read["expanded_devices"].pop("eth0"),
        ]:
            with self.subTest(damage=damage):
                calls, _, error = self.clone(damage)
                self.assertEqual(error, "instance.container_network_unsafe\n")
                self.assertFalse(any("start" in call for call in calls))

    def test_nic_check_refuses_a_nic_without_filters_with_exit_2_and_exempts_a_nic_without_its_own_address(self):
        stock = {"type": "nic", "network": "orbitinst0", "ipv4.address": "10.253.0.2", "security.port_isolation": "true",
                 "security.acls": "orbitinst0-proxy,instance-7", "security.acls.default.egress.action": "reject",
                 "security.acls.default.ingress.action": "reject"}
        filters = {"security.ipv4_filtering": "true", "security.mac_filtering": "true"}

        def check(device, name="instance-7"):
            container = {"name": name, "expanded_devices": {"eth0": device}}
            return subprocess.run(["python3", str(NETWORK_CHECK), "orbitinst0", "eth0"], input=json.dumps(container),
                                  capture_output=True, text=True, check=False)

        # An Instance NIC without both filters refuses with exit 2, which every caller maps to instance.nic_filters_missing.
        for partial in [{}, {"security.ipv4_filtering": "true"}, {"security.mac_filtering": "true"}, {"security.ipv4_filtering": "false",
                                                                                                         "security.mac_filtering": "true"}]:
            result = check({**stock, **partial})
            self.assertEqual(result.returncode, 2, partial)
            self.assertEqual(result.stdout, "instance-7/eth0 lacks security.ipv4_filtering and security.mac_filtering\n")
        # With both filters it passes, silently.
        self.assertEqual((check({**stock, **filters}).returncode, check({**stock, **filters}).stdout), (0, ""))
        # A real defect keeps its own exit, filters or not.
        for keys in [{}, filters]:
            self.assertEqual(check({**stock, **keys, "security.acls": ""}).returncode, 1)
        # The clone probe: the profile's eth0 has no address of its own, so it takes no filter and passes on its ACLs alone.
        profile = {key: value for key, value in stock.items() if key != "ipv4.address"}
        self.assertEqual(check(profile, "orbit-clone-probe-copy").returncode, 0)
        self.assertEqual(check({**profile, "security.acls": ""}, "orbit-clone-probe-copy").returncode, 1)

    def test_clone_refuses_a_copy_without_filters_with_the_filters_code_before_start(self):
        for key in ["security.ipv4_filtering", "security.mac_filtering"]:
            with self.subTest(key=key):
                calls, _, error = self.clone(lambda read, key=key: read["expanded_devices"]["eth0"].pop(key))
                self.assertEqual(error, "instance.nic_filters_missing\n")
                self.assertFalse(any("start" in call for call in calls))

    def test_a_check_source_without_literal_filters_refuses_the_copy_with_a_named_code(self):
        source = NETWORK_CHECK.read_text()
        for broken in [source.replace("FILTERS = {", "_FILTERS = {", 1),
                       source.replace("FILTERS = {", "FILTERS = dict(**{", 1).replace('"true"}\n', '"true"})\n', 1)]:
            with self.subTest(source=broken[:0]):
                calls, _, error = self.clone(network_check=broken)
                self.assertEqual(error, "instance.nic_filters_missing\n")
                self.assertFalse(any("copy" in call for call in calls))

    def test_the_three_holders_of_the_nic_filters_are_equal(self):
        # One holder per language: Python (the shared NIC check, which the native clone reads), Ansible and the Gateway.
        namespace = {}
        exec(NETWORK_CHECK.read_text().split('if __name__ == "__main__":')[0], namespace)
        python = namespace["FILTERS"]
        ansible = json.loads((NETWORK_CHECK.parent / "instance-nic-filters.json").read_text())
        php = (SCRIPT.parents[2] / "apps/gateway/app/Domain/Instances/InstanceHostIntent.php").read_text()
        constant = php.split("public const array NicFilters = [", 1)[1].split("];", 1)[0]
        gateway = dict(pair.strip().replace("'", "").split(" => ") for pair in constant.split(","))
        self.assertEqual(python, {"security.ipv4_filtering": "true", "security.mac_filtering": "true"})
        self.assertEqual(ansible, python)
        self.assertEqual(gateway, python)

    def test_nested_guard_refuses_a_nic_without_filters_with_the_filters_code(self):
        program = NETWORK_CHECK.read_text().split('if __name__ == "__main__":')[0] + (NETWORK_CHECK.parent / "instance_nested.py").read_text()
        nic = {"type": "nic", "network": "orbitinst0", "ipv4.address": "10.253.0.2", "security.acls": "orbitinst0-proxy,instance-7",
               "security.acls.default.egress.action": "reject", "security.acls.default.ingress.action": "reject"}
        allowed = {"eth0": {**nic, "security.ipv4_filtering": "true", "security.mac_filtering": "true"}}

        def check(device):
            container = {"name": "instance-7", "expanded_config": {}, "expanded_devices": {"eth0": device}}
            return subprocess.run(["python3", "-c", program, "orbitinst0", "eth0", json.dumps(allowed), "{}"],
                                  input=json.dumps(container), capture_output=True, text=True, check=False).returncode

        # The exact device check leaves the filters out, so a NIC without them gets the filters' own exit, not the guard's.
        self.assertEqual(check(dict(nic)), 2)
        self.assertEqual(check(dict(allowed["eth0"])), 0)
        # Any other change to the NIC is the guard's refusal.
        self.assertEqual(check({**allowed["eth0"], "ipv4.address": "10.253.0.9"}), 1)

    def test_normal_clone_uses_the_exact_original_checker_program_and_argv(self):
        calls, _, error = self.clone()
        self.assertEqual(error, "")
        self.assertIn(["python3", "-c", NETWORK_CHECK.read_text(), "orbitinst0", "eth0"], calls)

    def test_nested_clone_starts_only_with_exactly_the_written_config_and_devices(self):
        allowed = {
            "root": {"type": "disk", "path": "/", "pool": "orbit-instances", "size": "40GiB"},
            "eth0": {"type": "nic", "network": "orbitinst0", "ipv4.address": "10.253.0.2",
                     "security.acls": "orbitinst0-proxy,instance-7", "security.acls.default.egress.action": "reject",
                     "security.acls.default.ingress.action": "reject",
                     "security.ipv4_filtering": "true", "security.mac_filtering": "true"},
            "orbit-runtime": {"type": "disk", "source": "/opt/orbit-runtime", "path": "/opt/orbit-runtime", "readonly": "true", "shift": "true"},
            "orbit-agent": {"type": "disk", "source": "/opt/orbit-agent", "path": "/opt/orbit-agent", "readonly": "true", "shift": "true"},
            "t3": {"type": "proxy", "listen": "tcp:10.44.0.7:3800", "connect": "tcp:10.253.0.2:3773", "nat": "true"},
        }
        # InstancePlacement::config of the nested project over the common limits, as the Gateway writes it.
        config = {"limits.cpu": "4", "limits.memory": "12GiB", "limits.processes": "4096", "security.nesting": "true",
                  "security.syscalls.intercept.mknod": "true", "security.syscalls.intercept.setxattr": "true",
                  "security.idmap.isolated": "true", "security.idmap.size": "101000000", "boot.autostart": "false"}
        damages = {
            "raw.lxc": ("raw.lxc", "lxc.apparmor.profile=unconfined"),
            "privileged": ("security.privileged", "true"),
            "kernel-modules": ("linux.kernel_modules", "overlay"),
            "mount-intercept": ("security.syscalls.intercept.mount", "true"),
            "bpf-intercept": ("security.syscalls.intercept.bpf", "true"),
            "shared-idmap": ("security.idmap.isolated", "false"),
            "idmap-size": ("security.idmap.size", "65536"),
            "autostart": ("boot.autostart", "true"),
            "user-key": ("user.note", "x"),
        }
        # A seed copied from a default with an inner volume carries its disk: a clone has no inner volume, so the guard refuses it.
        inner = {"type": "disk", "pool": "orbit-instances", "source": "inner-1", "path": "/srv/inner-pool"}
        for damage in ["clean", *damages, "missing-isolation", "extra-device", "changed-host-path", "writable-share",
                       "delegated-root", "inner-volume"]:
            def inherited(read):
                # What Incus adds itself, and the project profile's environment.
                read["expanded_config"] = {**config, "volatile.uuid": "abc-123", "image.os": "ubuntu",
                                           "environment.HTTP_PROXY": "http://10.253.0.1:3128"}
                read["expanded_devices"] = copy.deepcopy(allowed)
                if damage in damages:
                    key, value = damages[damage]
                    read["expanded_config"][key] = value
                elif damage == "missing-isolation":
                    del read["expanded_config"]["security.idmap.isolated"]
                elif damage == "changed-host-path":
                    read["expanded_devices"]["orbit-runtime"]["source"] = "/fast"
                elif damage == "extra-device":
                    read["expanded_devices"]["host"] = {"type": "disk", "source": "/fast", "path": "/host"}
                elif damage == "writable-share":
                    del read["expanded_devices"]["orbit-runtime"]["readonly"]
                elif damage == "inner-volume":
                    read["expanded_devices"]["inner-pool"] = dict(inner)
            with self.subTest(damage=damage):
                # The root volume read by the guard; delegated, it would hand the container ZFS on its dataset.
                volumes = {"container/instance-7": {"zfs.delegate": "true"}} if damage == "delegated-root" else {}
                calls, _, error = self.clone(inherited, overrides={"project": "orbit-instances-nested", "allowed_devices": allowed, "config": config},
                                                  volumes=volumes)
                starts = [call for call in calls if "start" in call]
                # The copy writes the same config that the guard admits.
                copied = next(call for call in calls if "copy" in call)
                self.assertEqual({copied[i + 1] for i, arg in enumerate(copied) if arg == "--config"}, {k + "=" + v for k, v in config.items()})
                if damage == "clean":
                    self.assertEqual(error, "")
                    self.assertEqual(starts, [["incus", "--project", "orbit-instances-nested", "start", "instance-7"]])
                else:
                    self.assertEqual(error, "instance.container_network_unsafe\n")
                    self.assertFalse(starts)
        self.shared_folder_forms()

    def shared_folder_forms(self):
        """Part of the nested clone case above, whose CI file is at its cost limit."""
        # docs/reference/development-containers.md#instance-host-in-the-fleet: the Gateway's allowed devices carry the Node's form,
        # `shift=false` on the Instance fleet Node and `shift=true` on every other Node; the guard admits that form only.
        config = {"security.idmap.isolated": "true"}

        def devices(shift):
            return {
                "root": {"type": "disk", "path": "/", "pool": "orbit-instances", "size": "40GiB"},
                "eth0": {"type": "nic", "network": "orbitinst0", "ipv4.address": "10.253.0.2",
                         "security.acls": "orbitinst0-proxy,instance-7", "security.acls.default.egress.action": "reject",
                         "security.acls.default.ingress.action": "reject",
                         "security.ipv4_filtering": "true", "security.mac_filtering": "true"},
                "orbit-runtime": {"type": "disk", "source": "/opt/orbit-runtime", "path": "/opt/orbit-runtime", "readonly": "true", "shift": shift},
                "orbit-agent": {"type": "disk", "source": "/opt/orbit-agent", "path": "/opt/orbit-agent", "readonly": "true", "shift": shift},
                "t3": {"type": "proxy", "listen": "tcp:10.44.0.7:3800", "connect": "tcp:10.253.0.2:3773", "nat": "true"},
            }

        for node, shift, other in [("fleet", "false", "true"), ("real", "true", "false")]:
            for folder in [None, "orbit-runtime", "orbit-agent"]:
                def effective(read):
                    read["expanded_config"] = {**config, "volatile.uuid": "abc-123"}
                    read["expanded_devices"] = devices(shift)
                    if folder:
                        read["expanded_devices"][folder]["shift"] = other
                with self.subTest(node=node, folder=folder):
                    calls, _, error = self.clone(effective, overrides={"project": "orbit-instances-nested", "allowed_devices": devices(shift),
                                                                       "config": config})
                    starts = [call for call in calls if "start" in call]
                    if folder is None:
                        self.assertEqual(error, "")
                        self.assertEqual(starts, [["incus", "--project", "orbit-instances-nested", "start", "instance-7"]])
                    else:
                        self.assertEqual(error, "instance.container_network_unsafe\n")
                        self.assertFalse(starts)

    def inner_clone(self, **fixture):
        """A copy of a nested-fleet default with an inner volume (docs/reference/development-containers.md#the-inner-volume)."""
        allowed = {
            "root": {"type": "disk", "path": "/", "pool": "orbit-instances", "size": "40GiB"},
            "eth0": {"type": "nic", "network": "orbitinst0", "ipv4.address": "10.253.0.2",
                     "security.acls": "orbitinst0-proxy,instance-7", "security.acls.default.egress.action": "reject",
                     "security.acls.default.ingress.action": "reject",
                     "security.ipv4_filtering": "true", "security.mac_filtering": "true"},
            "t3": {"type": "proxy", "listen": "tcp:10.44.0.7:3800", "connect": "tcp:10.253.0.2:3773", "nat": "true"},
            "inner-pool": {"type": "disk", "pool": "orbit-instances", "source": "inner-7", "path": "/srv/inner-pool"},
        }
        config = {"security.idmap.isolated": "true"}

        def effective(read):
            read["expanded_config"] = {**config, "volatile.uuid": "abc-123"}
            read["expanded_devices"] = copy.deepcopy(allowed)

        inner = {"name": "inner-7", "pool": "orbit-instances", "size": "30GiB", "config": INNER_CONFIG, "device": "inner-pool",
                 "path": "/srv/inner-pool", "inner_pool": {"name": "inner", "source": "/srv/inner-pool/pool"}}
        overrides = {"project": "orbit-instances-nested", "allowed_devices": allowed, "config": config, "inner_volume": inner,
                     "clone": {"source": "instance-1", "snapshot": "seed-abc", "inner_volume": "inner-1", "t3": {"device": "t3"}},
                     "fixture_volume_seed_config": {**INNER_CONFIG, "size": "30GiB", "volatile.uuid": "v"}, **fixture}
        return self.clone(effective, overrides=overrides)

    def test_a_copy_with_an_inner_volume_copies_the_volume_seed_then_the_container_on_its_own_volume_before_the_start(self):
        calls, _, error = self.inner_clone()
        self.assertEqual(error, "")
        project = ["incus", "--project", "orbit-instances-nested"]
        volume_copy = calls.index(project + ["storage", "volume", "copy", "orbit-instances/inner-1/seed-abc", "orbit-instances/inner-7"])
        container_copy = next(index for index, call in enumerate(calls) if call[3:4] == ["copy"])
        start = calls.index(project + ["start", "instance-7"])
        self.assertLess(volume_copy, container_copy)
        self.assertLess(container_copy, start)
        self.assertEqual(calls[container_copy][-2:], ["--device", "inner-pool,source=inner-7"])
        # The guard admits the copy's volume with exactly its keys, as the Ansible start passes them.
        guard = next(call for call in calls if call[:2] == ["python3", "-c"] and len(call) == 8)
        self.assertEqual(json.loads(guard[7]), {"inner-pool": {**INNER_CONFIG, "size": "30GiB"}})
        self.assertFalse(any(call[3:6] == ["storage", "volume", "delete"] for call in calls))

    def test_a_copy_from_a_seed_snapshot_that_records_size_0_sets_the_intents_size_before_the_container_and_starts(self):
        # A seed that a seed copy moved to a CI Node: Incus 7.0.2 records `size: 0` in each snapshot it sends of a volume with a
        # file system (docs/reference/development-containers.md#the-inner-volume).
        calls, _, error = self.inner_clone(fixture_volume_seed_config={**INNER_CONFIG, "size": "0", "volatile.uuid": "v"})
        self.assertEqual(error, "")
        project = ["incus", "--project", "orbit-instances-nested"]
        volume_copy = calls.index(project + ["storage", "volume", "copy", "orbit-instances/inner-1/seed-abc", "orbit-instances/inner-7"])
        size = calls.index(project + ["storage", "volume", "set", "orbit-instances", "inner-7", "size=30GiB"])
        container_copy = next(index for index, call in enumerate(calls) if call[3:4] == ["copy"])
        self.assertEqual(size, volume_copy + 1)
        self.assertLess(size, container_copy)
        self.assertIn(project + ["start", "instance-7"], calls)

    def test_a_copy_with_an_inner_volume_refuses_a_seed_whose_volume_half_is_gone_before_any_change(self):
        calls, _, error = self.inner_clone(fixture_volume_seeds=["seed-older"])
        self.assertEqual(error, "instance.default_seed_incomplete\n")
        self.assertFalse(any("copy" in call or "storage" in call for call in calls))

    def test_a_failed_container_copy_deletes_the_volume_it_copied_and_never_starts(self):
        calls, _, error = self.inner_clone(fixture_copy_fails=True)
        self.assertNotEqual(error, "")
        project = ["incus", "--project", "orbit-instances-nested"]
        copied = calls.index(project + ["storage", "volume", "copy", "orbit-instances/inner-1/seed-abc", "orbit-instances/inner-7"])
        self.assertIn(project + ["storage", "volume", "delete", "orbit-instances", "inner-7"], calls[copied:])
        self.assertFalse(any(call[3:4] == ["start"] for call in calls))

    def test_a_volume_left_by_a_failed_copy_without_its_container_is_copied_again_from_the_seed(self):
        calls, _, error = self.inner_clone(fixture_volume_left=True)
        self.assertEqual(error, "")
        project = ["incus", "--project", "orbit-instances-nested"]
        delete = calls.index(project + ["storage", "volume", "delete", "orbit-instances", "inner-7"])
        self.assertEqual(calls[delete + 1], project + ["storage", "volume", "copy", "orbit-instances/inner-1/seed-abc", "orbit-instances/inner-7"])

    def test_a_copy_without_an_inner_volume_makes_no_volume_call_and_copies_the_container_as_before(self):
        # A normal Instance's copy, and a nested-fleet copy of a default without an inner volume (Instance 2454's shape).
        for shape, overrides in [("normal", {}), ("nested without volume", {"project": "orbit-instances-nested",
                                                                             "allowed_devices": {}, "config": {}})]:
            with self.subTest(shape=shape):
                calls, _, _ = self.clone(overrides=overrides)
                self.assertFalse(any("storage" in call or "/volumes/custom/" in " ".join(call) for call in calls))
                container_copy = next(call for call in calls if call[3:4] == ["copy"])
                self.assertFalse(any(arg.startswith("inner-pool") for arg in container_copy))
                guards = [call for call in calls if call[:2] == ["python3", "-c"]]
                self.assertTrue(all(len(call) <= 7 for call in guards))

    def test_nested_guard_admits_the_live_shape_of_the_first_nested_container(self):
        # The expanded config of the first nested-fleet container on a real Node, made before `boot.autostart`; the guard's
        # allowed config is that intent's `config`. Its next start writes `boot.autostart` first, so only that key is missing now.
        namespace = {}
        exec(NETWORK_CHECK.read_text().split('if __name__ == "__main__":')[0]
             + (NETWORK_CHECK.parent / "instance_nested.py").read_text().split('if __name__ == "__main__":')[0], namespace)
        proxy = "http://10.253.0.1:3128"
        live = {
            **{"environment." + key: value for key, value in {
                "HTTP_PROXY": proxy, "HTTPS_PROXY": proxy, "http_proxy": proxy, "https_proxy": proxy,
                "NO_PROXY": "localhost,127.0.0.1,::1,10.253.0.1", "no_proxy": "localhost,127.0.0.1,::1,10.253.0.1",
                "NODE_USE_ENV_PROXY": "1", "VP_HOME": "/var/lib/orbit/vite-plus-home", "VP_NO_UPDATE_CHECK": "1",
                "PATH": "/opt/orbit-runtime/current/bin:/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin"}.items()},
            "image.architecture": "amd64", "image.os": "ubuntu", "image.release": "resolute", "image.serial": "20261111_07:42",
            "limits.cpu": "4", "limits.memory": "12GiB", "limits.processes": "4096",
            "security.idmap.isolated": "true", "security.idmap.size": "101000000", "security.nesting": "true",
            "security.syscalls.intercept.mknod": "true", "security.syscalls.intercept.setxattr": "true",
            "volatile.base_image": "c53d38334f56", "volatile.idmap.base": "6898240", "volatile.last_state.power": "RUNNING",
            "volatile.uuid": "17955fcf-4ca2-4b9b-b93e-3f74908cca98",
        }
        allowed = {"limits.cpu": "4", "limits.memory": "12GiB", "limits.processes": "4096", "security.nesting": "true",
                   "security.syscalls.intercept.mknod": "true", "security.syscalls.intercept.setxattr": "true",
                   "security.idmap.isolated": "true", "security.idmap.size": "101000000", "boot.autostart": "false"}
        check = namespace["nested_unsafe_config"]
        self.assertEqual(check(live, allowed), "config boot.autostart")
        self.assertEqual(check({**live, "boot.autostart": "false"}, allowed), "")

    def test_guest_and_node_command_names_stay_in_sync(self):
        def commands(path):
            tree = ast.parse(path.read_text())
            return next(
                ast.literal_eval(node.value)
                for node in tree.body
                if isinstance(node, ast.Assign)
                and any(
                    isinstance(target, ast.Name) and target.id == "GUEST_COMMANDS"
                    for target in node.targets
                )
            )

        self.assertEqual(
            commands(SCRIPT), commands(SCRIPT.with_name("instance_clone_guest.py"))
        )

    def invoke(self, stderr, phase="check", fail="incus", evidence=None, stdin="PRIVATE-INPUT"):
        account = pwd.getpwuid(os.getuid())
        with tempfile.TemporaryDirectory() as folder:
            container = {
                "name": "instance-proof",
                "project": "orbit-instances",
                "mirror": folder + "/mirror",
                "branch": "proof",
                "php": None,
            }
            # The Node's mirror holds the branch: the phases read the start commit there.
            Path(folder, "mirror").mkdir()
            Path(folder, "mirror", "HEAD").write_text("ref: refs/heads/proof\n")

            def command(argv, **_kwargs):
                if argv[0] != fail:
                    # A guest phase answers JSON (the T3 step's agent part runs before its Node git read); other commands a commit.
                    return subprocess.CompletedProcess(argv, 0, "{}" if "-c" in argv else "abc123\n", "")
                return subprocess.CompletedProcess(argv, 7, json.dumps(evidence) if evidence else "PRIVATE-STDOUT", stderr)

            output, error = io.StringIO(), io.StringIO()
            with (
                patch(
                    "sys.argv",
                    [
                        str(SCRIPT),
                        json.dumps(container),
                        "guest",
                        (NETWORK_CHECK.read_text().split('if __name__ == "__main__":')[0] + (NETWORK_CHECK.parent / "instance_nested.py").read_text()) if container.get("allowed_devices") else NETWORK_CHECK.read_text(),
                        account.pw_name,
                        folder,
                        phase,
                    ],
                ),
                patch("sys.stdin", io.StringIO(stdin)),
                patch("subprocess.run", command),
                patch("os.chown") as ownership,
                contextlib.redirect_stdout(output),
                contextlib.redirect_stderr(error),
                self.assertRaises(SystemExit) as exit_status,
            ):
                runpy.run_path(str(SCRIPT), run_name="__main__")
            self.assertEqual(exit_status.exception.code, 1)
            self.assertNotIn("PRIVATE", output.getvalue() + error.getvalue())
            # Only the T3 step leaves its part's name in the private folder; no phase leaves a unit receipt there.
            if phase != 't3':
                self.assertFalse((Path(folder) / 't3.json').exists())
            if evidence and phase == 'check':
                # The check keeps no private folder: its public receipt is its own output.
                printed = json.loads(output.getvalue())
                self.assertEqual(printed, evidence)
                return printed
            self.assertEqual(output.getvalue(), "")
            return error.getvalue()

    def assert_structured_process_failure_reaches_the_completion_receipt(self):
        import importlib.util
        spec = importlib.util.spec_from_file_location('guest_fixture', SCRIPT.parents[1] / 'tests/instance_clone_guest_test.py')
        fixture = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(fixture)
        # Execute the production guest entry point; only its system boundaries are faked.
        _, output, _ = fixture.CloneGuestTest().readiness('check', entrypoint=True, inactive=True)
        evidence = json.loads(output)
        printed = self.invoke('token=ghp_PRIVATE_TOKEN_12345678901234567890', phase='check', evidence=evidence)
        script = """
import { completeResult } from './swamp/extensions/behaviors/operations/instance-dev-command/complete_result.ts';
const stdout = Deno.args[0];
const result = completeResult({instance_id: 7, route: null}, {stage: 'succeeded', database: 'skipped', fleet: 'skipped', services: 'succeeded',
    check: {status: 'succeeded', outputs: {stdout, exitCode: 1}}, route: 'skipped', certificates: [], route_observation: {}});
console.log(JSON.stringify(JSON.parse(result.stdout)));
"""
        result = subprocess.run(['deno', 'eval', '--no-check', script, json.dumps(printed)],
                                cwd=SCRIPT.parents[2], text=True, capture_output=True, check=False, timeout=20)
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        receipt = json.loads(result.stdout)
        self.assertEqual([receipt['failed_step'], receipt['error_code']], ['check', evidence['error_code']])
        self.assertEqual(receipt['process_failure'], evidence['process_failure'])
        self.assertFalse(receipt['ready'])
        self.assertNotIn('PRIVATE', json.dumps(receipt))

    def test_preserves_only_the_guest_command_and_exit_code(self):
        for command in ["curl", "git", "mkdir", "php", "systemctl", "t3"]:
            with self.subTest(command=command):
                diagnostic = (
                    "instance.container_check_failed: command "
                    + command
                    + " exited 17\n"
                )
                self.assertEqual(self.invoke(diagnostic), diagnostic)
        self.assert_structured_process_failure_reaches_the_completion_receipt()

    def test_a_failed_command_names_what_ran_and_keeps_only_public_stderr(self):
        # Any first line that is not the guest's own diagnostic makes the code line "command incus exited N", with what ran; the
        # stderr follows only as its redacted end. The stdin's private text never appears (`invoke` asserts no PRIVATE).
        head = "instance.container_check_failed: command incus exited 7 (incus exec instance-proof python3 guest check):\n"
        for stderr, kept in [
            ("Error: Instance is not running", "Error: Instance is not running"),
            ("instance.container_agent_failed: command php exited 17", "instance.container_agent_failed: command php exited 17"),
            ("instance.container_check_failed: command php ARGV exited 17", "instance.container_check_failed: command php ARGV exited 17"),
            ("echo PRIVATE-INPUT\ntoken=ghp_abcdefghijklmnopqrstuvwxyz0123", "echo [REDACTED]\ntoken=[REDACTED]"),
        ]:
            with self.subTest(stderr=stderr):
                self.assertEqual(self.invoke(stderr), head + kept + "\n")
        # The guest's own line stays the code line; the lines after it are its command's redacted stderr.
        self.assertEqual(self.invoke("instance.container_check_failed: command php exited 17\nPHP Fatal error: x\nPRIVATE-INPUT"),
                         "instance.container_check_failed: command php exited 17:\nPHP Fatal error: x\n[REDACTED]\n")
        # The Conn Server's program is a guest command too (docs/reference/development-containers.md#container-server): its failure keeps
        # the guest's line, not "command incus exited".
        self.assertEqual(self.invoke("instance.container_check_failed: command conn-server exited 17"),
                         "instance.container_check_failed: command conn-server exited 17\n")
        # A Node command names its executable and what ran, then the end of its words.
        self.assertRegex(
            # In the T3 step only the checkout runs Git on the Node, so the failure names the checkout.
            self.invoke("fatal: bad object\nPRIVATE-INPUT", phase="t3", fail="git", stdin=json.dumps({"environment": "PRIVATE-INPUT", "processes": {}})),
            r"\Ainstance\.container_checkout_failed: command git exited 7 \(git /\S+/mirror rev-parse refs/heads/proof\^\{commit\}\):\n"
            r"fatal: bad object\n\[REDACTED\]\n\Z",
        )


class CopyStepRedactionTest(unittest.TestCase):
    """The copy step's stdin is the launch copy's private text (CloneStage): the credential line, the model frame and the model
    environment. Swamp masks a vault value only as a whole, so a bare token or model key in a command's output is kept out of the
    Operation by the script's own redaction: every failure detail goes through `private_values` over the stdin and `public_text`."""

    @staticmethod
    def functions():
        import ast
        tree = ast.parse(SCRIPT.read_text())
        keep = [node for node in tree.body if isinstance(node, (ast.Import, ast.ImportFrom))
                or (isinstance(node, ast.FunctionDef) and node.name in {"private_values", "public_text"})
                or (isinstance(node, ast.Assign) and any(getattr(target, "id", "") == "SHORTEST_VALUE" for target in node.targets))]
        scope = {}
        exec(compile(ast.Module(body=keep, type_ignores=[]), str(SCRIPT), "exec"), scope)
        return scope["private_values"], scope["public_text"]

    def test_the_bare_token_and_model_key_never_reach_a_failure_detail(self):
        private_values, public_text = self.functions()
        token, key = "ghs_" + "T" * 36, "orbit-model-" + "m" * 52
        stdin = (f"https://x-access-token:{token}@github.com\n#orbit-model-environment\nANTHROPIC_BASE_URL=http://10.253.0.1:8319\n"
                 f"ANTHROPIC_AUTH_TOKEN={key}\nORBIT_MODEL_KEY={key}\nDISABLE_TELEMETRY=1\n")
        stderr = "\n".join([f"fatal: could not read from https://x-access-token:{token}@github.com/o/r.git", f"bare {token} here",
                            f"model {key}", f"a piece {key[10:30]}", "Error: the last public line"])
        detail = public_text(stderr, private_values(stdin), 4096)
        for secret in [token, key, key[10:30], token[4:20]]:
            self.assertNotIn(secret, detail)
        self.assertIn("Error: the last public line", detail)
        self.assertIn("bare [REDACTED] here", detail)


class ServicesPhaseFailureTest(unittest.TestCase):
    """The services phase (docs/reference/development-containers.md#a-failed-clone-phase-names-its-command): one guest exec and no
    Incus file call, and a failure names what ran and the redacted end of its stderr."""

    EXEC = "incus exec instance-proof python3 guest services"
    APP_KEY = "base64:c2VjcmV0LWFwcC1rZXktdmFsdWUtMTIzNA=="
    DB_PASSWORD = "s3cret-Xq9vT2wLp7zK"

    def services(self, guest=(0, "")):
        """Run the services phase with the guest exec's exit and stderr; return (exit, calls, stdout, stderr)."""
        calls = []
        stdin = json.dumps({"processes": {"/etc/orbit/processes/7/vp-dev.env": "APP_KEY=" + self.APP_KEY + "\nDB_PASSWORD="
                                          + self.DB_PASSWORD + "\n"}})

        def command(argv, **kwargs):
            calls.append(argv)
            code, stderr = guest
            return subprocess.CompletedProcess(argv, code, '{"phase": "services", "changes": []}' if code == 0 else "", stderr)

        container = {"name": "instance-proof", "project": "orbit-instances"}
        output, error, code = io.StringIO(), io.StringIO(), 0
        with tempfile.TemporaryDirectory() as folder, patch(
            "sys.argv", [str(SCRIPT), json.dumps(container), "guest", "network", pwd.getpwuid(os.getuid()).pw_name, folder,
                         json.dumps({"node": "print('helper')", "workspace_owner": "print()"}), "services"],
        ), patch("sys.stdin", io.StringIO(stdin)), patch("subprocess.run", command), contextlib.redirect_stdout(output), \
                contextlib.redirect_stderr(error):
            try:
                runpy.run_path(str(SCRIPT), run_name="__main__")
            except SystemExit as exit_status:
                code = exit_status.code
        return code, calls, output.getvalue(), error.getvalue()

    def test_the_phase_is_one_guest_exec_and_a_failure_names_its_command_and_the_end_of_its_stderr(self):
        # Completion 22240 failed when the helper's `incus file push` met Incus's file helper as it exited after 15 s idle.
        code, calls, output, error = self.services()
        self.assertEqual((code, error, json.loads(output)["phase"]), (0, "", "services"))
        self.assertEqual([call[3:5] for call in calls], [["exec", "instance-proof"]])
        self.assertEqual(json.loads(calls[0][-1])["node_runtime"], "print('helper')")
        # A failed guest command names itself and the redacted end of its stderr.
        guest = (1, "instance.container_services_failed: command systemctl exited 1\nvp-dev: DB_PASSWORD=" + self.DB_PASSWORD
                 + " ghp_abcdefghijklmnopqrstuvwxyz\nFailed to connect to system scope bus\n")
        code, _, output, error = self.services(guest)
        self.assertEqual((code, output), (1, ""))
        self.assertEqual(error, "instance.container_services_failed: command systemctl exited 1:\nvp-dev: [REDACTED] [REDACTED]\n"
                                "Failed to connect to system scope bus\n")
        # A failed exec names incus, what ran and Incus's words. The real words of a forkfile that exits as a file call reaches it (Incus 7.0.2, reproduced on Beast 2026-11-14), and others.
        for stderr in ["Error: error receiving version packet from server: server unexpectedly closed connection: unexpected EOF\n",
                       "Error: Failed to retrieve PID of executing child process\n", "Error: Instance is not running\n"]:
            with self.subTest(stderr=stderr):
                code, calls, _, error = self.services((255, stderr))
                self.assertEqual((code, len(calls)), (1, 1))
                self.assertEqual(error, "instance.container_services_failed: command incus exited 255 (" + self.EXEC + "):\n" + stderr)
        # A guest that failed by an error of its own names that error's type.
        _, _, _, error = self.services((1, "instance.container_services_failed: FileNotFoundError: No such file or directory: /x\n"))
        self.assertEqual(error, "instance.container_services_failed: command incus exited 1 (" + self.EXEC + "):\n"
                                "instance.container_services_failed: FileNotFoundError: No such file or directory: /x\n")

    def test_the_detail_holds_no_private_value_and_the_end_of_a_long_stderr(self):
        stderr = "\n".join([
            "loading APP_KEY=" + self.APP_KEY,
            "password " + self.DB_PASSWORD + " refused",
            "a piece: " + self.DB_PASSWORD[2:14],
            "remote https://x-access-token:ghs_abcdefghijklmnopqrstuvwxyz@github.com/o/r.git",
            "Authorization: Bearer abcdefghijklmnop",
            "github_pat_abcdefghijklmnopqrstuvwxyz0123",
            "-----BEGIN PRIVATE KEY-----", "MIIEvQIBADANBg", "-----END PRIVATE KEY-----",
            "api_key=plain-value-123 done",
            "Error: the last public line",
        ])
        code, _, _, error = self.services((1, stderr))
        self.assertEqual(code, 1)
        for secret in [self.APP_KEY, self.DB_PASSWORD, self.DB_PASSWORD[2:14], "ghs_abcdefghijklmnopqrstuvwxyz", "abcdefghijklmnop",
                       "github_pat_", "MIIEvQIBADANBg", "plain-value-123"]:
            self.assertNotIn(secret, error)
        self.assertEqual(error.splitlines()[1:], [
            "loading [REDACTED]", "password [REDACTED] refused", "remote https://[REDACTED]@github.com/o/r.git",
            "Authorization: [REDACTED]", "[REDACTED]", "[REDACTED]", "api_key=[REDACTED] done", "Error: the last public line"])
        # A long stderr keeps its end, in whole lines, within the detail's size.
        stderr = "".join("line %05d %s\n" % (number, "x" * 40) for number in range(2000))
        code, _, _, error = self.services((1, stderr))
        lines = error.splitlines()
        self.assertEqual(code, 1)
        self.assertLessEqual(len(error.encode()), 4001)
        # The bound counts UTF-8 bytes: wide characters keep the code line inside the Gateway's 4,096 bytes.
        _, _, _, wide = self.services((1, "".join("ü" * 50 + "\n" for _ in range(200))))
        self.assertLessEqual(len(wide.encode()), 4001)
        self.assertTrue(wide.startswith("instance.container_services_failed: command incus exited 1 ("))
        self.assertEqual(lines[0], "instance.container_services_failed: command incus exited 1 (" + self.EXEC + "):")
        self.assertEqual(lines[-1], "line 01999 " + "x" * 40)
        self.assertTrue(all(line.startswith("line ") and len(line) == 51 for line in lines[1:]))


class InnerVolumeGuardTest(unittest.TestCase):
    """The nested guard's volume check (docs/reference/development-containers.md#the-inner-volume), with the volumes it reads given."""

    INNER = {"zfs.block_mode": "true", "block.filesystem": "btrfs", "block.mount_options": "user_subvol_rm_allowed,discard",
             "security.shifted": "true", "size": "30GiB"}

    def check(self, inner=None, root=None, allowed=True):
        namespace = {}
        exec(compile((NETWORK_CHECK.parent / "instance_nested.py").read_text().split('if __name__ == "__main__":')[0], "instance_nested.py", "exec"), namespace)
        devices = {"root": {"type": "disk", "path": "/", "pool": "orbit-instances"},
                   "orbit-runtime": {"type": "disk", "source": "/opt/orbit-runtime", "path": "/opt/orbit-runtime"},
                   "inner-pool": {"type": "disk", "pool": "orbit-instances", "source": "inner-7", "path": "/srv/inner-pool"}}
        volumes = {"container/instance-7": root or {}, "custom/inner-7": inner}
        read = lambda container, device: volumes.get(("container/" + container["name"]) if device["path"] == "/" else "custom/" + device["source"])
        return namespace["nested_unsafe_volume"]({"name": "instance-7", "project": "orbit-instances-nested", "expanded_devices": devices},
                                                 {"inner-pool": self.INNER} if allowed else {}, read)

    def test_admits_exactly_the_keys_with_incus_own_and_empty_values(self):
        self.assertEqual(self.check({**self.INNER, "volatile.uuid": "x", "volatile.idmap.last": "[]", "block.create_options": ""}), "")

    def test_refuses_a_changed_missing_or_added_key_and_names_it(self):
        changed = {**self.INNER, "block.mount_options": "discard"}
        missing = {key: value for key, value in self.INNER.items() if key != "security.shifted"}
        cases = [(changed, "block.mount_options"), (missing, "security.shifted"), ({**self.INNER, "block.create_options": "-O no-holes"}, "block.create_options"),
                 ({**self.INNER, "size": "300GiB"}, "size"), ({**self.INNER, "zfs.delegate": "true"}, "zfs.delegate")]
        for inner, key in cases:
            with self.subTest(key=key):
                self.assertEqual(self.check(inner), "volume inner-pool " + key)

    def test_refuses_a_delegated_root_an_unread_volume_and_a_volume_the_intent_does_not_name(self):
        self.assertEqual(self.check(self.INNER, root={"zfs.delegate": "true"}), "volume root zfs.delegate")
        self.assertEqual(self.check(None), "volume inner-pool unreadable")
        self.assertEqual(self.check(self.INNER, allowed=False), "volume inner-pool")


class RootSizeGuardTest(unittest.TestCase):
    """The nested guard's root disk rule (docs/reference/development-containers.md#the-root-disk): every key but `size` is exact; an
    allowed size admits a `<n>GiB` of at least that size; an allowed root without a size admits only a root without one."""

    def check(self, has, allowed):
        namespace = {}
        exec(compile(NETWORK_CHECK.read_text().split('if __name__ == "__main__":')[0]
                     + (NETWORK_CHECK.parent / "instance_nested.py").read_text().split('if __name__ == "__main__":')[0], "instance_nested.py", "exec"),
             namespace)
        root = lambda size, **keys: {"type": "disk", "path": "/", "pool": "orbit-instances", **keys, **({} if size is None else {"size": size})}
        own = has if isinstance(has, dict) else root(has)
        return namespace["nested_unsafe_device"]({"expanded_config": {}, "expanded_devices": {"root": own}}, {"root": root(allowed)}, {})

    def test_admits_the_probe_profile_root_a_copy_and_a_grown_default(self):
        # One subtest per shape: the CI budget of this file holds two more cases (.github/ci/fixtures/swamp/tests/instance_clone_test.py.toml).
        shapes = {"host clone probe: the profile root, no size on either side": (None, None),
                  "normal nested copy: 40GiB at 40GiB": ("40GiB", "40GiB"),
                  "grown default: 80GiB at its 80GiB intent": ("80GiB", "80GiB"),
                  "grown default after the setting went down: 80GiB at 64GiB": ("80GiB", "64GiB")}
        for shape, (has, allowed) in shapes.items():
            with self.subTest(shape=shape):
                self.assertEqual(self.check(has, allowed), "")

    def test_refuses_a_size_where_none_is_allowed_a_missing_smaller_or_odd_size_and_another_key(self):
        disk = {"type": "disk", "path": "/", "pool": "orbit-instances"}
        shapes = {"a size where the allowed root has none": ("40GiB", None),
                  "no size where one is allowed": (None, "40GiB"),
                  "a smaller size": ("40GiB", "80GiB"),
                  "an odd own size": ("50GB", "40GiB"),
                  "an odd allowed size": ("80GB", "80GB"),
                  "a leading zero": ("040GiB", "40GiB"),
                  "an empty allowed size": ("40GiB", ""),
                  "full-width digits": ("\uff14\uff10GiB", "40GiB"),
                  "another pool, no size": ({**disk, "pool": "other"}, None),
                  "an added key, no size": ({**disk, "readonly": "true"}, None),
                  "another pool, with a size": ({**disk, "pool": "other", "size": "40GiB"}, "40GiB"),
                  "an added key, with a size": ({**disk, "readonly": "true", "size": "40GiB"}, "40GiB")}
        for shape, (has, allowed) in shapes.items():
            with self.subTest(shape=shape):
                self.assertEqual(self.check(has, allowed), "root")


class T3PhaseTest(unittest.TestCase):
    """The `t3` phase, one step until the thread is usable: the checkout beside the agent part and T3's answer, then the sessions check.
    Its stdin is the workspace's private JSON, which reaches only the checkout; the model environment is already in the copy."""

    KEY = "fake-private-env-5c1e"
    VALUE = {"environment": "KEY=" + KEY + "\n", "processes": {}, "merge": None}
    SESSIONS = {"gateway": {"token": "fake-gateway-session-1a"}, "swamp": {"token": "fake-swamp-session-2b"}}

    def phase(self, phase="t3", fail=None, timeout=None, cached=None, clone=None):
        account = pwd.getpwuid(os.getuid())
        calls = []

        def command(argv, **kwargs):
            calls.append((argv, kwargs.get("input")))
            # A guest call is `incus exec … -- python3 -c <guest> <member>`; the Node's own Git calls carry `-c` options too.
            member = argv[argv.index("python3") + 3] if "python3" in argv else None
            if member is not None and member == timeout:
                # The outer `timeout 240` ends the step here: nothing after this line of the script runs.
                raise KeyboardInterrupt
            if fail == "bundle" and "bundle" in argv and member is None:
                # The Node's own Git call of the checkout fails.
                return subprocess.CompletedProcess(argv, 1, "", "PRIVATE-STDERR")
            if member is not None and member == fail:
                return subprocess.CompletedProcess(argv, 1, "PRIVATE-STDOUT", "instance.container_" + member + "_failed: command php exited 17")
            if member == "checkout":
                return subprocess.CompletedProcess(argv, 0, json.dumps({"phase": member, "changes": [], "environment": {"copied": ["KEY"]},
                                                                        "commit": "f" * 40}), "")
            if member == "sessions":
                # The guest checks the sessions it got on stdin and answers the whole registration reply.
                return subprocess.CompletedProcess(argv, 0, json.dumps({"phase": member, "checked_at": "2026-11-13T21:00:01+00:00",
                                                                        "private_reply": {"environment": "{}", **{
                    name: {"token": json.loads(kwargs.get("input"))[name]["token"], "status": 200, "body": "{}"} for name in self.SESSIONS}}}), "")
            if member == "t3":
                # The guest's T3 part issues both sessions while T3 starts; their tokens come back only as its private reply.
                return subprocess.CompletedProcess(argv, 0, json.dumps({"phase": member, "ready_at": "2026-11-13T21:00:00+00:00",
                                                                        "at_boot": True, "private_reply": self.SESSIONS}), "")
            if member is not None:
                return subprocess.CompletedProcess(argv, 0, json.dumps({"phase": member, "ready_at": "2026-11-13T21:00:00+00:00"}), "")
            return subprocess.CompletedProcess(argv, 0, "abc123\n", "")

        container = {"name": "instance-proof", "project": "orbit-instances", "mirror": fetched_mirror(self), "branch": "proof", "php": None,
                     **({} if clone is None else {"clone": clone})}
        self.calls = calls
        output, error = io.StringIO(), io.StringIO()
        code = 0
        with tempfile.TemporaryDirectory() as folder, (
            patch("sys.argv", [str(SCRIPT), json.dumps(container), "guest", NETWORK_CHECK.read_text(), account.pw_name, folder, phase])
        ), patch("sys.stdin", io.StringIO(json.dumps(self.VALUE))), patch("subprocess.run", command), patch("os.chown"), \
                contextlib.redirect_stdout(output), contextlib.redirect_stderr(error):
            if cached is not None:
                (Path(folder) / "t3.json").write_text(json.dumps(cached))
            try:
                runpy.run_path(str(SCRIPT), run_name="__main__")
            except SystemExit as result:
                code = result.code
            except KeyboardInterrupt:
                code = 124
            left = Path(folder) / "t3.json"
            self.left = left.read_text() if left.exists() else None
        for argv, content in calls:
            self.assertNotIn(self.KEY, json.dumps(argv))
            # The private environment reaches only the checkout's guest call.
            if self.KEY in str(content):
                self.assertEqual(argv[argv.index("python3") + 3], "checkout")
        self.assertNotIn(self.KEY, output.getvalue() + error.getvalue() + (self.left or ""))
        guest = [(argv[argv.index("python3") + 3], content) for argv, content in calls if "python3" in argv]
        return code, guest, output.getvalue(), error.getvalue()

    def receipt(self):
        """The real create_result over the run folder that clone-session-copy fills with the step's t3.json."""
        with tempfile.TemporaryDirectory(prefix="orbit-ansible-", dir="/tmp") as folder:
            if self.left is not None:
                (Path(folder) / "t3.json").write_text(self.left)
            script = """
import { behavior } from './swamp/extensions/behaviors/operations/instance-dev-command/assets.ts';
const created = await behavior.methods.create_result.execute({
    folder: Deno.args[0], intent: {instance_id: 7, setup_steps: [], container: {clone: {}}},
    checkout: null, database: 'succeeded', setup: 'skipped', processes: [], route: 'skipped',
    container: {launch: 'succeeded', workspace: {status: 'failed'},
        deploy: [], processes: 'succeeded', seed: 'skipped', t3: 'failed', register: 'skipped'},
}, {writeResource: async () => {}});
console.log(created.stdout);
"""
            result = subprocess.run(["deno", "eval", "--no-check", script, folder],
                                    cwd=SCRIPT.parents[2], text=True, capture_output=True, check=False, timeout=30)
            self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
            receipt = json.loads(result.stdout)
            return receipt["failed_step"], receipt["error_code"]

    def test_runs_the_checkout_beside_the_agent_and_t3_then_checks_the_sessions(self):
        content = {"folder": "/home/agent/.config/orbit/agent", "files": [{"path": "AGENTS.md", "content": "PROJECT-TEXT"}]}
        code, guest, output, error = self.phase(clone={"agent_instructions": content})
        self.assertEqual((code, error), (0, ""))
        # Only the agent part gets the agent folder's content in its one argument (development-containers.md#agent-instructions).
        intents = {argv[argv.index("python3") + 3]: json.loads(argv[argv.index("python3") + 4]) for argv, _ in self.calls if "python3" in argv}
        self.assertEqual(intents["agent"]["clone"]["agent_instructions"], content)
        for member in ("checkout", "t3", "sessions"):
            self.assertNotIn("agent_instructions", intents[member]["clone"], member)
        members = [member for member, _ in guest]
        # The checkout runs beside the agent part and T3; the agent part comes before T3, and the sessions check comes last.
        self.assertEqual(sorted(members), ["agent", "checkout", "sessions", "t3"])
        self.assertLess(members.index("agent"), members.index("t3"))
        self.assertEqual(members[-1], "sessions")
        self.assertEqual(dict(guest)["agent"], "")
        self.assertEqual(dict(guest)["t3"], "")
        self.assertEqual(json.loads(dict(guest)["checkout"])["environment"], self.VALUE["environment"])
        self.assertEqual(json.loads(dict(guest)["sessions"]), self.SESSIONS)
        # The checkout's commit and JSON line, then T3's receipt with the time the sessions were checked; no token reaches the output.
        lines = output.splitlines()
        self.assertEqual(lines[0], "orbit-commit=" + "f" * 40)
        self.assertEqual(json.loads(lines[1])["phase"], "checkout")
        self.assertEqual(set(json.loads(lines[1])["timings"]), {"bundle", "guest"})
        receipt = json.loads(lines[2])
        self.assertEqual([receipt["phase"], receipt["ready_at"], receipt["checked_at"]], ["t3", "2026-11-13T21:00:00+00:00", "2026-11-13T21:00:01+00:00"])
        self.assertNotIn("private_reply", receipt)
        # Whether the copy's boot had started T3 is a part of `timings`, 1 or 0; the guest's own key does not cross.
        self.assertEqual(receipt["timings"]["at_boot"], 1)
        self.assertEqual(set(receipt["timings"]), {"agent", "t3", "at_boot", "sessions"})
        self.assertNotIn("at_boot", {key for key in receipt if key != "timings"})
        # The registration reads the checked reply from the private t3.json.
        registered = json.loads(self.left)
        self.assertEqual(set(registered), {"environment", "gateway", "swamp"})
        self.assertEqual(registered["gateway"]["status"], 200)
        for session in self.SESSIONS.values():
            self.assertNotIn(session["token"], output + error)

    def test_a_failed_agent_part_names_the_agent_and_keeps_the_agent_code(self):
        code, guest, output, error = self.phase(fail="agent")
        self.assertEqual(code, 1)
        self.assertNotIn("t3", [member for member, _ in guest])
        self.assertEqual(output, "")
        # The Operation's failure detail is this stderr line; the code comes from the part the step left behind.
        self.assertEqual(error, "instance.container_agent_failed: command php exited 17\n")
        self.assertEqual(self.receipt(), ("t3", "instance.create_agent_failed"))

    def test_a_failed_t3_part_names_t3_and_keeps_the_t3_code(self):
        code, guest, output, error = self.phase(fail="t3")
        self.assertEqual(code, 1)
        self.assertNotIn("sessions", [member for member, _ in guest])
        self.assertEqual(error, "instance.container_t3_failed: command php exited 17\n")
        self.assertEqual(self.receipt(), ("t3", "instance.t3_unready"))

    def test_a_failed_checkout_names_the_workspace(self):
        code, guest, output, error = self.phase(fail="checkout")
        self.assertEqual(code, 1)
        # T3's part still ends; the sessions are never checked.
        self.assertNotIn("sessions", [member for member, _ in guest])
        self.assertEqual(json.loads(self.left), {"t3_part": "checkout"})
        self.assertEqual(error, "instance.container_checkout_failed: command php exited 17\n")
        self.assertEqual(self.receipt(), ("workspace", "instance.create_workspace_failed"))

    def test_a_node_command_of_the_checkout_names_the_checkout(self):
        code, guest, _, error = self.phase(fail="bundle")
        self.assertEqual(code, 1)
        self.assertEqual(json.loads(self.left), {"t3_part": "checkout"})
        # The code line names the checkout and what ran; the stderr's redacted end follows it.
        self.assertTrue(error.startswith("instance.container_checkout_failed: command git exited 1 (git "), error)
        self.assertNotIn("sessions", [member for member, _ in guest])

    def test_a_failed_sessions_check_names_the_registration(self):
        code, _, output, error = self.phase(fail="sessions")
        self.assertEqual(code, 1)
        self.assertEqual(json.loads(self.left), {"t3_part": "sessions"})
        self.assertEqual(self.receipt(), ("register", "instance.create_register_failed"))
        for session in self.SESSIONS.values():
            self.assertNotIn(session["token"], output + error)

    def test_a_timeout_names_the_part_that_was_running(self):
        for part, code in [("agent", "instance.create_agent_failed"), ("t3", "instance.t3_unready")]:
            with self.subTest(part=part):
                self.assertEqual(self.phase(timeout=part)[0], 124)
                self.assertEqual(json.loads(self.left), {"t3_part": part})
                self.assertEqual(self.receipt(), ("t3", code))

    def test_the_agent_and_sessions_phases_alone_are_no_steps(self):
        for phase in ("agent", "sessions"):
            with self.subTest(phase=phase):
                code, guest, _, error = self.phase(phase=phase)
                self.assertEqual((code, guest, error), (1, [], "instance.clone_phase_invalid\n"))


class WorkspacePartsPhaseTest(unittest.TestCase):
    """`checkout`, `services` and `check`: each reads its half of the one framed vault value and calls one guest phase."""

    VALUE = {"environment": "KEY=private-env\n", "processes": {"/etc/orbit/processes/1/a.env": "QUEUE=private-q\n"},
             "merge": {"own": ["DB_DATABASE"], "drop": [], "guard": []}}
    EVIDENCE = {"error_code": "instance.container_process_inactive", "process_failure": {"unit": "orbit-process-1.service"}}

    def phase(self, phase, fail=False, mirror=True, rev_parse=0, stdin=None, moved_seed=None):
        account = pwd.getpwuid(os.getuid())
        calls = []

        def command(argv, **kwargs):
            calls.append((argv, kwargs.get("input")))
            if "rev-parse" in argv and rev_parse:
                # The Node's mirror lacks the branch (1), or Git fails on it (any other code).
                return subprocess.CompletedProcess(argv, rev_parse, "", "PRIVATE-STDERR")
            member = argv[argv.index("python3") + 3] if "python3" in argv else None
            # `fail`: every guest call fails with a dead unit's receipt; a member name fails only that call, and `"check-incus"` fails
            # the check as Incus itself does, with no receipt.
            if member is not None and fail == "services-detail" and member == "services":
                return subprocess.CompletedProcess(argv, 1, "", "Traceback (most recent call last):\n"
                                                   "subprocess.CalledProcessError: Command returned non-zero exit status 1.\n"
                                                   "Job for php-fpm.service failed because the control process exited with error code.\n")
            if member is not None and fail == "check-incus" and member == "check":
                return subprocess.CompletedProcess(argv, 1, "", "Error: Instance is not running PRIVATE-STDERR")
            if member is not None and (fail is True or fail == member):
                return subprocess.CompletedProcess(argv, 1, json.dumps(self.EVIDENCE), "instance.container_process_inactive\n")
            if member in {"checkout", "workspace"}:
                return subprocess.CompletedProcess(argv, 0, json.dumps({"phase": member, "changes": [], "environment": {}, "commit": "first"}), "")
            if member is not None:
                return subprocess.CompletedProcess(argv, 0, json.dumps({"phase": member, "changes": []}), "")
            return subprocess.CompletedProcess(argv, 0, "abc123\n", "")

        # A fetched branch is in the Node's mirror; a new branch at the seed's commit has no mirror.
        container = {"name": "instance-proof", "project": "orbit-instances", "branch": "proof", "php": None,
                     "mirror": fetched_mirror(self) if mirror else "/nowhere/mirror", "clone": {"seed_commit": "c" * 40}}
        self.calls = calls
        output, error = io.StringIO(), io.StringIO()
        code = 0
        with tempfile.TemporaryDirectory() as folder, (
            patch("sys.argv", [str(SCRIPT), json.dumps(container), "guest", NETWORK_CHECK.read_text(), account.pw_name, folder,
                               json.dumps({"node": "node-helper-source", "workspace_owner": "owner-pass-source"}), phase])
        ), patch("sys.stdin", io.StringIO(stdin if stdin is not None else json.dumps(self.VALUE) if phase != "check" else "")), \
                patch("subprocess.run", command), \
                patch("os.chown"), contextlib.redirect_stdout(output), contextlib.redirect_stderr(error):
            # The container phase copied a newer seed after a retake and recorded its commit here.
            if moved_seed is not None:
                (Path(folder) / "seed-commit").write_text(moved_seed + "\n")
            try:
                runpy.run_path(str(SCRIPT), run_name="__main__")
            except SystemExit as result:
                code = result.code
            left = [entry for entry in Path(folder).iterdir() if entry.name != "seed-commit"]
        guest = [(argv[argv.index("python3") + 3], json.loads(content) if content else content) for argv, content in calls if "python3" in argv]
        pushed = [argv[-1] for argv, _ in calls if "file" in argv and "push" in argv]
        self.assertNotIn("private", error.getvalue())
        self.assertEqual(left, [])
        return code, guest, pushed, output.getvalue(), error.getvalue()

    def test_checkout_pushes_the_bundle_and_passes_only_the_env_half(self):
        code, guest, pushed, output, _ = self.phase("checkout")
        self.assertEqual(code, 0)
        self.assertEqual(guest, [("checkout", {"environment": self.VALUE["environment"], "merge": self.VALUE["merge"]})])
        self.assertEqual(len(pushed), 1)
        self.assertTrue(pushed[0].startswith("instance-proof/tmp/orbit-workspace-"))
        self.assertTrue(output.startswith("orbit-commit=first\n"))
        self.assertNotIn("private", output)

    def test_a_branch_the_mirror_lacks_starts_at_the_seeds_commit_without_a_bundle(self):
        code, _, pushed, output, _ = self.phase("checkout", mirror=False)
        self.assertEqual(code, 0)
        self.assertEqual(pushed, [])
        self.assertFalse(any("bundle" in argv for argv, _ in self.calls))
        intent = json.loads(next(argv for argv, _ in self.calls if "python3" in argv)[-1])
        self.assertEqual(intent["expected_commit"], "c" * 40)
        self.assertNotIn("bundle", intent)
        printed = json.loads(output.splitlines()[1])
        self.assertEqual(set(printed["timings"]), {"guest"})

    def test_a_mirror_without_the_branch_starts_at_the_seed_and_a_git_failure_fails_the_phase(self):
        for phase in ("checkout",):
            with self.subTest(phase=phase):
                code, _, pushed, _, _ = self.phase(phase, rev_parse=1)
                self.assertEqual((code, pushed), (0, []))
                intent = json.loads(next(argv for argv, _ in self.calls if "python3" in argv)[-1])
                self.assertEqual(intent["expected_commit"], "c" * 40)
                # Any other Git failure never falls back to the seed: the phase fails by its own name, what ran and Git's words.
                code, guest, _, _, error = self.phase(phase, rev_parse=128)
                self.assertEqual((code, guest), (1, []))
                self.assertRegex(error, r"\Ainstance\.container_" + phase + r"_failed: command git exited 128 \(git /\S+ rev-parse "
                                 r"refs/heads/proof\^\{commit\}\):\nPRIVATE-STDERR\n\Z")

    def test_a_branch_the_mirror_lacks_starts_at_the_commit_of_the_seed_that_the_copy_took(self):
        # The copy waited for a retake and copied the new seed: the branch starts at that seed's commit, which its repository holds.
        code, _, pushed, _, _ = self.phase("checkout", mirror=False, moved_seed="d" * 40)
        self.assertEqual((code, pushed), (0, []))
        intent = json.loads(next(argv for argv, _ in self.calls if "python3" in argv)[-1])
        self.assertEqual(intent["expected_commit"], "d" * 40)

    def test_a_fetched_branch_prints_the_seconds_of_its_bundle_and_its_checkout(self):
        _, _, _, output, _ = self.phase("checkout")
        self.assertEqual(set(json.loads(output.splitlines()[1])["timings"]), {"bundle", "guest"})

    def test_services_install_the_node_helper_and_pass_only_the_process_files(self):
        code, guest, pushed, output, _ = self.phase("services")
        self.assertEqual(code, 0)
        self.assertEqual(guest, [("services", {"processes": self.VALUE["processes"]})])
        # The helper goes in the guest's intent, which writes it: the phase makes no Incus file call.
        self.assertEqual(pushed, [])
        self.assertFalse(any("file" in argv for argv, _ in self.calls))
        intent = json.loads(next(argv for argv, _ in self.calls if "python3" in argv)[-1])
        self.assertEqual(intent["node_runtime"], "node-helper-source")
        self.assertEqual(json.loads(output), {"phase": "services", "changes": []})

    def test_workspace_still_passes_the_whole_value_in_one_guest_call(self):
        code, guest, pushed, output, _ = self.phase("workspace")
        self.assertEqual(code, 0)
        self.assertEqual(guest, [("workspace", self.VALUE)])
        # Only the bundle is pushed; the helper goes in the guest's intent.
        self.assertEqual(len(pushed), 1)
        self.assertEqual(json.loads(next(argv for argv, _ in self.calls if "python3" in argv)[-1])["node_runtime"], "node-helper-source")
        self.assertTrue(output.startswith("orbit-commit=first\n"))

    def test_check_reads_no_private_input_and_passes_a_dead_units_code_and_receipt(self):
        code, guest, pushed, output, error = self.phase("check")
        self.assertEqual((code, guest, pushed, json.loads(output)), (0, [("check", "")], [], {"phase": "check", "changes": []}))
        code, _, _, output, error = self.phase("check", fail=True)
        self.assertEqual((code, json.loads(output), error), (1, self.EVIDENCE, "instance.container_process_inactive\n"))

    def test_services_of_the_release_before_read_the_process_files_of_the_new_completion_copy(self):
        # On the release that first frames the completion's one copy, its workflow still stages from the input step and gives the whole
        # copy to `services`, which reads its top-level `processes`; a completion in flight across that release or a rollback is safe.
        processes = self.VALUE["processes"]
        copy = json.dumps({"stage": {"script": SCRIPT.read_text()}, "private": json.dumps({"processes": processes}), "processes": processes})
        code, guest, _, output, error = self.phase("services", stdin=copy)
        self.assertEqual((code, error, guest), (0, "", [("services", {"processes": processes})]))
        self.assertEqual(json.loads(output), {"phase": "services", "changes": []})

    def test_services_keep_a_failures_detail_when_the_copy_also_holds_the_release_scripts(self):
        # Redaction drops every stderr line that shares a value with the secret: the scripts must not be part of it.
        processes = self.VALUE["processes"]
        copy = json.dumps({"stage": {"script": SCRIPT.read_text(), "guest": NETWORK_CHECK.read_text()},
                           "private": json.dumps({"processes": processes}), "processes": processes})
        code, _, _, _, error = self.phase("services", fail="services-detail", stdin=copy)
        self.assertEqual(code, 1)
        self.assertEqual(error, "instance.container_services_failed: command incus exited 1 (incus exec instance-proof python3 guest services):\n"
                                "Traceback (most recent call last):\n"
                                "subprocess.CalledProcessError: Command returned non-zero exit status 1.\n"
                                "Job for php-fpm.service failed because the control process exited with error code.\n")

    def test_complete_runs_the_services_then_the_check_in_one_phase_and_passes_only_the_process_files(self):
        # The completion's one step (docs/reference/development-containers.md#complete-a-clone): its stdin is the copy's private text.
        code, guest, pushed, output, error = self.phase("complete", stdin=json.dumps({"processes": self.VALUE["processes"]}))
        self.assertEqual((code, error, pushed), (0, "", []))
        self.assertEqual(guest, [("services", {"processes": self.VALUE["processes"]}), ("check", "")])
        self.assertEqual([json.loads(line) for line in output.splitlines()],
                         [{"phase": "services", "changes": []}, {"phase": "check", "changes": []}])

    def test_complete_keeps_a_dead_units_receipt_as_its_output_and_ends_0(self):
        # A failed step keeps no output, so the receipt crosses only when the phase ends 0; `finish` turns it into the check's failure.
        code, guest, _, output, error = self.phase("complete", fail="check", stdin=json.dumps({"processes": self.VALUE["processes"]}))
        self.assertEqual((code, [member for member, _ in guest]), (0, ["services", "check"]))
        self.assertEqual([json.loads(line) for line in output.splitlines()], [{"phase": "services", "changes": []}, self.EVIDENCE])
        self.assertEqual(error, "instance.container_process_inactive\n")

    def test_complete_ends_0_without_a_check_line_when_the_check_fails_otherwise(self):
        code, _, _, output, error = self.phase("complete", fail="check-incus", stdin=json.dumps({"processes": self.VALUE["processes"]}))
        self.assertEqual((code, [json.loads(line) for line in output.splitlines()]), (0, [{"phase": "services", "changes": []}]))
        self.assertTrue(error.startswith("instance.container_check_failed: command incus exited 1"), error)

    def test_complete_fails_on_a_failed_services_part_and_never_runs_the_check(self):
        code, guest, _, output, error = self.phase("complete", fail="services", stdin=json.dumps({"processes": self.VALUE["processes"]}))
        self.assertEqual((code, [member for member, _ in guest], output), (1, ["services"], ""))
        self.assertTrue(error.startswith("instance.container_services_failed"), error)


class CompletionCopyLaunchTest(unittest.TestCase):
    """The completion's one copy (CloneStage::completion) through the real launcher: `stage` stages the public part, and the `complete`
    phase reads only `private`, the Process files. The copy's top-level `processes` is what the workflow of the release before reads."""

    PROCESSES = {"/etc/orbit/processes/1/a.env": "QUEUE=private-q\nSECOND=private-two\n"}

    def launch(self, value):
        launcher = (SCRIPT.parent / "instance_clone_launch.py").read_text()
        account = pwd.getpwuid(os.getuid())
        container = {"name": "instance-proof", "project": "orbit-instances", "branch": "proof", "php": None, "mirror": "/nowhere",
                     "clone": {"seed_commit": "c" * 40}, "instance_id": 8}
        stage = {"launcher": launcher, "script": SCRIPT.read_text(), "container": container, "guest": "guest",
                 "network": NETWORK_CHECK.read_text(), "account": account.pw_name, "bundle": {"node": "node-helper-source", "workspace_owner": "owner-pass-source"}}
        frame = value(stage)
        calls = []

        def command(argv, **kwargs):
            calls.append((argv[argv.index("python3") + 3] if "python3" in argv else None, kwargs.get("input")))
            member = argv[argv.index("python3") + 3] if "python3" in argv else None
            return subprocess.CompletedProcess(argv, 0, json.dumps({"phase": member, "changes": []}), "")

        output, error = io.StringIO(), io.StringIO()
        code = 0
        with tempfile.TemporaryDirectory() as parent:
            folder = Path(parent) / "orbit-clone-8-run"
            with patch("sys.argv", ["launch.py", str(folder), "stage", "complete"]), patch("sys.stdin", io.StringIO(frame)), \
                    patch("subprocess.run", command), patch("os.chown"), contextlib.redirect_stdout(output), contextlib.redirect_stderr(error):
                try:
                    runpy.run_path(str(SCRIPT.parent / "instance_clone_launch.py"), run_name="__main__")
                except SystemExit as result:
                    code = result.code
            staged = sorted(path.name for path in folder.iterdir()) if folder.exists() else []
        return code, calls, output.getvalue(), error.getvalue(), staged

    def test_the_completion_copy_stages_the_scripts_and_gives_the_services_only_the_process_files(self):
        def value(stage):
            private = json.dumps({"processes": self.PROCESSES}, separators=(",", ":"))
            return json.dumps({"stage": stage, "private": private, "processes": self.PROCESSES}, separators=(",", ":"))

        self.assertNotIn("\n", value({"script": SCRIPT.read_text()}))
        code, calls, output, error, staged = self.launch(value)
        self.assertEqual((code, error, staged), (0, "", ["clone.py", "input.json", "launch.py"]))
        self.assertEqual([member for member, _ in calls if member], ["services", "check"])
        self.assertEqual(json.loads(next(content for member, content in calls if member == "services")), {"processes": self.PROCESSES})
        self.assertEqual([json.loads(line)["phase"] for line in output.splitlines()], ["services", "check"])

    def test_a_copy_without_its_private_text_fails_before_any_command(self):
        code, calls, output, error, _ = self.launch(lambda stage: json.dumps({"stage": stage, "processes": self.PROCESSES}))
        self.assertEqual((code, calls, output, error), (1, [], "", "instance.clone_input_failed\n"))


class ClubKeyPhaseTest(unittest.TestCase):
    """A nested-fleet clone's Club key: SSH stdin of the `club-key` phase reaches only the delivery script's stdin."""

    CLUB_SCRIPT = SCRIPT.parents[1] / "ansible/instance_nested_club_key.sh"
    VALUE = "fake-club-key-7f3a9c"

    def phase(self, fstype="tmpfs", available=True, outcome="changed", code=0, stdin=VALUE):
        calls = []

        def command(argv, **kwargs):
            calls.append((argv, kwargs.get("input")))
            if "findmnt" in argv:
                return subprocess.CompletedProcess(argv, 0, fstype + "\n", "")
            return subprocess.CompletedProcess(argv, code, outcome + "\n", "")

        container = {"name": "instance-9", "project": "orbit-instances-nested", "mirror": "/nonexistent",
                     "club_key": {"directory": "/run/orbit-club", "file": "swamp-club-api-key", "owner": 1000, "group": 1000,
                                  "tmpfiles": "/etc/tmpfiles.d/orbit-club.conf", "gateway": "nested-gateway",
                                  "serve_unit": "orbit-swamp-serve", "available": available}}
        output, error = io.StringIO(), io.StringIO()
        with tempfile.TemporaryDirectory() as folder, (
            patch("sys.argv", [str(SCRIPT), json.dumps(container), "guest", "network", "orbit", folder + "/private",
                               json.dumps({"club_key": self.CLUB_SCRIPT.read_text()}), "club-key"])
        ), patch("sys.stdin", io.StringIO(stdin)), patch("subprocess.run", command), contextlib.redirect_stdout(output), contextlib.redirect_stderr(error):
            try:
                runpy.run_path(str(SCRIPT), run_name="__main__")
            except SystemExit as result:
                self.assertEqual(result.code, 1)
            self.assertFalse(Path(folder + "/private").exists())
        for argv, _ in calls:
            self.assertNotIn(self.VALUE, json.dumps(argv))
        self.assertNotIn(self.VALUE, output.getvalue() + error.getvalue())
        return calls, output.getvalue(), error.getvalue()

    def test_delivers_the_key_only_as_the_scripts_stdin_after_the_tmpfs_check(self):
        calls, output, error = self.phase()
        self.assertEqual(error, "")
        self.assertEqual(json.loads(output)["changed"], True)
        self.assertEqual(json.loads(output)["club_key"], "changed")
        self.assertEqual([argv[:6] for argv, _ in calls], [
            ["incus", "--project", "orbit-instances-nested", "exec", "instance-9", "--"],
            ["incus", "--project", "orbit-instances-nested", "exec", "instance-9", "--"]])
        self.assertIn("findmnt", calls[0][0])
        argv, given = calls[1]
        self.assertEqual(argv[6:9], ["sh", "-c", self.CLUB_SCRIPT.read_text()])
        self.assertEqual(argv[9:], ["sh", "/run/orbit-club", "swamp-club-api-key", "1000", "1000", "/etc/tmpfiles.d/orbit-club.conf",
                                    "nested-gateway", "orbit-swamp-serve"])
        self.assertEqual(given, self.VALUE)

    def test_the_same_key_changes_nothing(self):
        _, output, _ = self.phase(outcome="unchanged")
        self.assertEqual(json.loads(output)["changed"], False)
        self.assertEqual(json.loads(output)["club_key"], "unchanged")

    def test_a_key_written_while_the_inner_incus_was_not_ready_says_so(self):
        _, output, error = self.phase(outcome="changed_serve_pending")
        self.assertEqual(error, "")
        self.assertEqual(json.loads(output)["changed"], True)
        self.assertEqual(json.loads(output)["club_key"], "changed_serve_pending")

    def test_refuses_a_run_that_is_not_a_tmpfs_before_it_passes_the_value(self):
        calls, _, error = self.phase(fstype="zfs")
        self.assertEqual(error, "instance.nested_club_key_not_tmpfs\n")
        self.assertEqual(len(calls), 1)

    def test_names_a_failed_delivery_without_its_output(self):
        _, _, error = self.phase(code=4, outcome="")
        self.assertEqual(error, "instance.nested_club_key_delivery_failed\n")
        _, _, error = self.phase(code=3, outcome="")
        self.assertEqual(error, "instance.nested_club_key_not_tmpfs\n")

    def test_refuses_without_a_vault_copy_or_value_and_runs_nothing(self):
        for available, stdin in [(False, self.VALUE), (True, "")]:
            with self.subTest(available=available):
                calls, _, error = self.phase(available=available, stdin=stdin)
                self.assertEqual(error, "instance.nested_club_key_delivery_failed\n")
                self.assertEqual(calls, [])


if __name__ == "__main__":
    unittest.main()
