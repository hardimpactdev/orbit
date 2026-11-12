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
    def clone(self, damaged=None, nic_acl="orbitinst0-proxy", mail=None, overrides=None, real_git=False, mail_code=None, transcript=None, node_service_code=None):
        calls = []
        real_run = subprocess.run
        with tempfile.TemporaryDirectory() as folder:
            container = {
                "mail": mail,
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
                        output = json.dumps(["orbit-mail-1025.socket", "orbit-mail-2525.socket", "orbit-node-service-3308.socket", "project.socket"])
                    elif "timers.target.wants" in argv[2]:
                        if container.get("fixture_schedule_listing_error"):
                            return subprocess.CompletedProcess(argv, 1, "", "Error: Permission denied")
                        output = json.dumps(["orbit-schedule-default.timer", "project.timer"])
                    elif argv[2].endswith("/etc/systemd/system"):
                        output = json.dumps(["orbit-schedule-default.timer", "orbit-schedule-default.service"])
                    elif argv[2].endswith("/etc/orbit/schedules"):
                        output = json.dumps(["default.sh", "default.complete"])
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
                        json.dumps({"node_services": node_service_code or 'print(\'{"status":"unchanged"}\')', "mail": mail_code or 'print(\'{"status":"unchanged"}\')'}),
                        "container",
                    ],
                ),
                patch("sys.stdin", io.StringIO("PRIVATE-INPUT")),
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
            return calls, read, error.getvalue()

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
        start = ["incus", "--project", "orbit-instances", "start", "instance-7"]
        self.assertIn(start, calls)
        deleted = next(call for call in calls if 'delete' in call and '--force' in call)
        self.assertIn('instance-7/etc/systemd/system/sockets.target.wants/orbit-mail-1025.socket', deleted)
        self.assertIn('instance-7/etc/systemd/system/sockets.target.wants/orbit-mail-2525.socket', deleted)
        self.assertNotIn('instance-7/etc/systemd/system/sockets.target.wants/project.socket', deleted)
        for path in ["systemd/system/timers.target.wants/orbit-schedule-default.timer",
                     "systemd/system/orbit-schedule-default.timer", "systemd/system/orbit-schedule-default.service",
                     "orbit/schedules/default.sh", "orbit/schedules/default.complete"]:
            self.assertIn("instance-7/etc/" + path, deleted)
        self.assertNotIn("instance-7/etc/systemd/system/timers.target.wants/project.timer", deleted)
        self.assertLess(calls.index(deleted), calls.index(start))
        check = next(i for i, call in enumerate(calls) if call[:2] == ["python3", "-c"])
        self.assertLess(check, calls.index(start))

    def test_clone_node_target_is_fatal_before_workspace_and_strips_inherited_enablement(self):
        _, _, error = self.clone(overrides={'node_services': [{'port': 3308}]},
                                 node_service_code="raise SystemExit('instance.node_service_unreachable')")
        self.assertEqual(error, 'instance.node_service_unreachable\n')
        calls, _, error = self.clone(overrides={'node_services': [{'port': 3308}]})
        self.assertEqual(error, '')
        removed = next(c for c in calls if 'file' in c and 'delete' in c)
        self.assertIn('instance-7/etc/systemd/system/sockets.target.wants/orbit-node-service-3308.socket', removed)
        start = calls.index(['incus', '--project', 'orbit-instances', 'start', 'instance-7'])
        apply = next(i for i, c in enumerate(calls) if c[:2] == ['python3', '-c'] and c[2] == 'print(\'{"status":"unchanged"}\')')
        self.assertLess(start, apply)

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
                    mail_call = next(i for i, call in enumerate(calls) if call[:2] == ["python3", "-c"] and '"status":"unchanged"' in call[2])
                    start = calls.index(["incus", "--project", "orbit-instances", "start", "instance-7"])
                    self.assertLess(start, mail_call)
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

    def invoke(self, stderr, phase="sessions", fail="incus", evidence=None):
        account = pwd.getpwuid(os.getuid())
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
                return subprocess.CompletedProcess(argv, 7, json.dumps(evidence) if evidence else "PRIVATE-STDOUT", stderr)

            output, error = io.StringIO(), io.StringIO()
            with (
                patch(
                    "sys.argv",
                    [
                        str(SCRIPT),
                        json.dumps(container),
                        "guest",
                        NETWORK_CHECK.read_text(),
                        account.pw_name,
                        folder,
                        phase,
                    ],
                ),
                patch("sys.stdin", io.StringIO("PRIVATE-INPUT")),
                patch("subprocess.run", command),
                patch("os.chown") as ownership,
                contextlib.redirect_stdout(output),
                contextlib.redirect_stderr(error),
                self.assertRaises(SystemExit) as exit_status,
            ):
                runpy.run_path(str(SCRIPT), run_name="__main__")
            self.assertEqual(exit_status.exception.code, 1)
            self.assertEqual(output.getvalue(), "")
            self.assertNotIn("PRIVATE", error.getvalue())
            if evidence:
                ownership.assert_any_call(Path(folder) / 't3.json', account.pw_uid, account.pw_gid)
                recorded = json.loads((Path(folder) / 't3.json').read_text())
                self.assertEqual(recorded, evidence)
                self.assertEqual((Path(folder) / 't3.json').stat().st_mode & 0o777, 0o600)
                return recorded
            return error.getvalue()

    def assert_structured_process_failure_reaches_create_terminal_receipt(self):
        import importlib.util
        spec = importlib.util.spec_from_file_location('guest_fixture', SCRIPT.parents[1] / 'tests/instance_clone_guest_test.py')
        fixture = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(fixture)
        # Execute the production guest entry point; only its system boundaries are faked.
        _, output, _ = fixture.CloneGuestTest().readiness('sessions', entrypoint=True, inactive=True)
        evidence = json.loads(output)
        self.invoke('token=ghp_PRIVATE_TOKEN_12345678901234567890', evidence=evidence)
        with tempfile.TemporaryDirectory() as temporary:
            folder = Path(temporary)
            (folder / 't3.json').write_text(json.dumps(evidence))
            script = """
import { behavior } from './swamp/extensions/behaviors/operations/instance-dev-command/assets.ts';
const folder = Deno.args[0];
const ctx = {writeResource: async () => {}};
const created = await behavior.methods.create_result.execute({
    folder, intent: {instance_id: 7, setup_steps: [], container: {clone: {}}},
    checkout: null, database: 'succeeded', setup: 'skipped', processes: [], route: 'skipped',
    container: {launch: 'succeeded', workspace: {status: 'succeeded', outputs: {stdout: 'orbit-commit=' + 'a'.repeat(40)}},
        deploy: [], processes: 'succeeded', seed: 'skipped', agent: 'succeeded', t3: 'succeeded', register: 'failed'},
}, ctx);
console.log(JSON.stringify(JSON.parse(created.stdout)));
"""
            result = subprocess.run(['deno', 'eval', '--no-check', script, str(folder)],
                                    cwd=SCRIPT.parents[2], text=True, capture_output=True, check=False, timeout=20)
            self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
            receipt = json.loads(result.stdout)
            self.assertEqual(receipt['error_code'], evidence['error_code'])
            self.assertEqual(receipt['process_failure'], evidence['process_failure'])
            self.assertFalse(receipt['ready'])
            self.assertNotIn('PRIVATE', json.dumps(receipt))

    def test_preserves_only_the_guest_command_and_exit_code(self):
        for command in ["curl", "git", "mkdir", "php", "systemctl", "t3"]:
            with self.subTest(command=command):
                diagnostic = (
                    "instance.container_sessions_failed: command "
                    + command
                    + " exited 17\n"
                )
                self.assertEqual(self.invoke(diagnostic), diagnostic)
        self.assert_structured_process_failure_reaches_create_terminal_receipt()

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
