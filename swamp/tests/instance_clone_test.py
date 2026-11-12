"""Check Node-side clone command diagnostics without Incus or private output."""

import ast
import contextlib
import copy
import io
import json
import os
import pwd
import runpy
import subprocess
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

SCRIPT = Path(__file__).parents[1] / "scripts/instance_clone.py"
NETWORK_CHECK = SCRIPT.parents[1] / "ansible/instance_network.py"


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
        self.git("init", "--quiet", "--bare", str(self.mirror))
    def git(self, *args, check=True):
        return subprocess.run(["git", *args], check=check, capture_output=True, text=True,
                              env=dict(os.environ, GIT_CONFIG_NOSYSTEM="1", GIT_CONFIG_GLOBAL="/dev/null", GIT_TERMINAL_PROMPT="0"), timeout=5)

    def prepare(self, mode="new", target="fix-login", base="release", sha=None, legacy=False):
        overrides = {"repository": str(self.remote), "mirror": str(self.mirror), "protocols": ["file"], "branch": target,
                     "clone": {"source": "instance-1", "snapshot": "seed", "t3": {"device": "t3"},
                               "seed_commit": self.seed, "source_mirror": str(self.remote / ".git")}}
        if not legacy:
            overrides["branch_selection"] = {"mode": mode, "target": target, "base": base, "source_sha": sha or self.tip}
        _, _, error = CloneTest().clone(overrides=overrides, real_git=True)
        if error:
            raise RuntimeError(error.strip())

    def checkout(self, branch):
        # The same mirror bundle and checkout used by clone-workspace's guest.
        bundle = self.root / "workspace.bundle"
        workspace = self.root / "workspace"
        self.git("-C", str(self.mirror), "bundle", "create", str(bundle), "refs/heads/" + branch)
        self.git("init", "--quiet", str(workspace))
        self.git("-C", str(workspace), "fetch", "--quiet", str(bundle), "refs/heads/" + branch)
        self.git("-C", str(workspace), "checkout", "--quiet", "-B", branch, "FETCH_HEAD")
        return self.git("-C", str(workspace), "rev-parse", "HEAD").stdout.strip(), self.git("-C", str(workspace), "branch", "--show-current").stdout.strip()

    def test_legacy_new_branch_still_uses_seed_without_selection(self):
        self.prepare(legacy=True)
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

    def test_target_created_after_admission_is_not_silently_reused(self):
        self.git("-C", str(self.remote), "branch", "fix-login")
        with self.assertRaisesRegex(RuntimeError, "instance.clone_branch_exists"):
            self.prepare()

    def test_unreadable_remote_fails_without_creating_a_branch(self):
        self.remote.rename(self.root / "gone")
        with self.assertRaisesRegex(RuntimeError, "instance.clone_source_unavailable"):
            self.prepare()
        self.assertNotEqual(self.git("-C", str(self.mirror), "rev-parse", "--verify", "refs/heads/fix-login", check=False).returncode, 0)

    def test_unreachable_frozen_commit_fails_without_using_current_tip(self):
        with self.assertRaisesRegex(RuntimeError, "instance.clone_source_unavailable"):
            self.prepare(sha="f" * 40)


class CloneTest(unittest.TestCase):
    def clone(self, damaged=None, nic_acl="orbitinst0-proxy", overrides=None, real_git=False):
        calls = []
        real_run = subprocess.run
        with tempfile.TemporaryDirectory() as folder:
            container = {
                "name": "instance-7",
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
                    "snapshot": "seed",
                    "t3": {"device": "t3"},
                },
            }
            container.update(overrides or {})
            read = None

            def command(argv, **kwargs):
                nonlocal read
                calls.append(argv)
                if real_git and argv[0] in {"git", "timeout"}:
                    return real_run(argv, **kwargs)
                if argv[:2] == ["python3", "-c"]:
                    return real_run(argv, **kwargs)
                output, status = "", 0
                if argv[:2] == ["incus", "query"]:
                    if "snapshots?" in argv[2]:
                        output = json.dumps([{"name": "seed"}])
                    elif "sockets.target.wants" in argv[2]:
                        output = json.dumps(["orbit-mail-1025.socket", "orbit-mail-2525.socket", "project.socket"])
                    elif "network-acls?" in argv[2] or "files?" in argv[2]:
                        output = "[]"
                    else:
                        output, status = (json.dumps(read), 0) if read else ("", 1)
                elif "copy" in argv:
                    device = {"type": "nic", "network": "orbitinst0"}
                    for arg in argv:
                        if arg.startswith("eth0,"):
                            key, value = arg[5:].split("=", 1)
                            device[key] = value
                    read = {
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
                        NETWORK_CHECK.read_text(),
                        pwd.getpwuid(os.getuid()).pw_name,
                        folder + "/private",
                        "container",
                    ],
                ),
                patch("sys.stdin", io.StringIO("PRIVATE-INPUT")),
                patch("subprocess.run", command),
                contextlib.redirect_stdout(output),
                contextlib.redirect_stderr(error),
            ):
                try:
                    runpy.run_path(str(SCRIPT), run_name="__main__")
                except SystemExit as result:
                    self.assertEqual(result.code, 1)
            self.assertFalse(Path(folder + "/private/git-credential").exists())
            return calls, read, error.getvalue()

    def test_clone_writes_proxy_and_own_acl_and_reject_defaults_before_start(self):
        calls, read, error = self.clone()
        self.assertEqual(error, "")
        nic = read["expanded_devices"]["eth0"]
        self.assertEqual(nic["security.acls"], "orbitinst0-proxy,instance-7")
        self.assertEqual(nic["security.acls.default.egress.action"], "reject")
        self.assertEqual(nic["security.acls.default.ingress.action"], "reject")
        start = ["incus", "--project", "orbit-instances", "start", "instance-7"]
        self.assertIn(start, calls)
        deleted = next(call for call in calls if 'delete' in call and '--force' in call)
        self.assertIn('instance-7/etc/systemd/system/sockets.target.wants/orbit-mail-1025.socket', deleted)
        self.assertIn('instance-7/etc/systemd/system/sockets.target.wants/orbit-mail-2525.socket', deleted)
        self.assertNotIn('instance-7/etc/systemd/system/sockets.target.wants/project.socket', deleted)
        self.assertLess(calls.index(deleted), calls.index(start))
        check = next(i for i, call in enumerate(calls) if call[:2] == ["python3", "-c"])
        self.assertLess(check, calls.index(start))

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

    def invoke(self, stderr, phase="sessions", fail="incus"):
        with tempfile.TemporaryDirectory() as folder:
            container = {
                "name": "instance-proof",
                "project": "orbit-instances",
                "mirror": "/private/mirror",
                "branch": "proof",
                "php": None,
            }

            def command(argv, **_kwargs):
                if argv[0] != fail:
                    return subprocess.CompletedProcess(argv, 0, "abc123\n", "")
                return subprocess.CompletedProcess(argv, 7, "PRIVATE-STDOUT", stderr)

            output, error = io.StringIO(), io.StringIO()
            with (
                patch(
                    "sys.argv",
                    [
                        str(SCRIPT),
                        json.dumps(container),
                        "guest",
                        NETWORK_CHECK.read_text(),
                        "agent",
                        folder,
                        phase,
                    ],
                ),
                patch("sys.stdin", io.StringIO("PRIVATE-INPUT")),
                patch("subprocess.run", command),
                contextlib.redirect_stdout(output),
                contextlib.redirect_stderr(error),
                self.assertRaises(SystemExit) as exit_status,
            ):
                runpy.run_path(str(SCRIPT), run_name="__main__")
            self.assertEqual(exit_status.exception.code, 1)
            self.assertEqual(output.getvalue(), "")
            self.assertNotIn("PRIVATE", error.getvalue())
            return error.getvalue()

    def test_preserves_only_the_guest_command_and_exit_code(self):
        for command in ["curl", "git", "mkdir", "php", "systemctl", "t3"]:
            with self.subTest(command=command):
                diagnostic = (
                    "instance.container_sessions_failed: command "
                    + command
                    + " exited 17\n"
                )
                self.assertEqual(self.invoke(diagnostic), diagnostic)

    def test_rejects_private_or_mismatched_guest_diagnostics(self):
        for stderr in [
            "instance.container_sessions_failed: command SECRET_TOKEN_abcd1234 exited 17",
            "PRIVATE-STDERR",
            "instance.container_sessions_failed: command php exited 17\nPRIVATE-STDERR",
            "instance.container_agent_failed: command php exited 17",
            "instance.container_sessions_failed: command php PRIVATE-ARGV exited 17",
        ]:
            with self.subTest(stderr=stderr):
                self.assertEqual(
                    self.invoke(stderr),
                    "instance.container_sessions_failed: command incus exited 7\n",
                )

    def test_node_command_failure_names_executable_and_exit_without_output(self):
        self.assertEqual(
            self.invoke("PRIVATE-STDERR", phase="t3", fail="git"),
            "instance.container_t3_failed: command git exited 7\n",
        )


if __name__ == "__main__":
    unittest.main()
