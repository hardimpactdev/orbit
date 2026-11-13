"""Check Node-side clone command diagnostics without Incus or private output."""

import ast
import contextlib
import copy
import io
import json
import os
import pwd
import runpy
import shutil
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
    def clone(self, damaged=None, nic_acl="orbitinst0-proxy", mail=None, overrides=None, real_git=False, mail_code=None, transcript=None, node_service_code=None, rootfs=None, existing=None):
        calls = []
        self.inputs = {}
        real_run = subprocess.run
        with tempfile.TemporaryDirectory() as folder:
            root = Path(folder) / "rootfs"
            for path, content in (rootfs or {}).items():
                (root / path).parent.mkdir(parents=True, exist_ok=True)
                if content.startswith("->"):
                    (root / path).symlink_to(content[2:])
                else:
                    (root / path).write_text(content)
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
            read = existing

            def command(argv, **kwargs):
                nonlocal read
                calls.append(argv)
                self.inputs[id(argv)] = kwargs.get("input")
                if real_git and argv[0] in {"git", "timeout"}:
                    return real_run(argv, **kwargs)
                if argv[:2] == ["python3", "-c"]:
                    return real_run(argv, **kwargs)
                output, status = "", 0
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
                        (NETWORK_CHECK.read_text().split('if __name__ == "__main__":')[0] + (NETWORK_CHECK.parent / "instance_nested.py").read_text()) if container.get("allowed_devices") else NETWORK_CHECK.read_text(),
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
        links = next(call for call in calls if call[3:5] == ['file', 'delete'] and '--force' not in call)
        files = next(call for call in calls if call[3:5] == ['file', 'delete'] and '--force' in call)
        # Every enablement link goes in its own plain delete, before its unit file goes.
        self.assertTrue(all('.target.wants/' in path for path in links[5:]))
        self.assertFalse(any('.target.wants/' in path for path in files))
        self.assertLess(calls.index(links), calls.index(files))
        deleted = links + files
        self.assertIn('instance-7/etc/systemd/system/sockets.target.wants/orbit-mail-1025.socket', deleted)
        self.assertIn('instance-7/etc/systemd/system/sockets.target.wants/orbit-mail-2525.socket', deleted)
        self.assertNotIn('instance-7/etc/systemd/system/sockets.target.wants/project.socket', deleted)
        for path in ["systemd/system/timers.target.wants/orbit-schedule-default.timer",
                     "systemd/system/orbit-schedule-default.timer", "systemd/system/orbit-schedule-default.service",
                     "orbit/schedules/default.sh", "orbit/schedules/default.complete"]:
            self.assertIn("instance-7/etc/" + path, deleted)
        self.assertNotIn("instance-7/etc/systemd/system/timers.target.wants/project.timer", deleted)
        self.assertLess(calls.index(files), calls.index(start))
        check = next(i for i, call in enumerate(calls) if call[:2] == ["python3", "-c"])
        self.assertLess(check, calls.index(start))

    BOOT_FILES = [
        {"path": "/etc/systemd/system.conf.d/99-orbit-node-services.conf", "uid": 0, "gid": 0, "mode": "0644",
         "content": "# Managed by Orbit: attached Node service.\n[Manager]\nDefaultEnvironment=NO_PROXY=a no_proxy=a\n"},
        {"path": "/etc/profile.d/99-orbit-node-services.sh", "uid": 0, "gid": 0, "mode": "0644",
         "content": "# Managed by Orbit: attached Node service.\nexport NO_PROXY=a no_proxy=a\n"},
    ]

    def clone_with(self, clone, **kwargs):
        overrides = kwargs.pop("overrides", {})
        return self.clone(overrides={**overrides, "clone": {"source": "instance-1", "snapshot": "seed", "t3": {"device": "t3"}, **clone}},
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
        apply = next(i for i, c in enumerate(calls) if c[:2] == ["python3", "-c"] and c[2] == 'print(\'{"status":"unchanged"}\')')
        self.assertTrue(all(copy < calls.index(call) < start < apply for call in pushes))

    def test_a_running_copy_and_a_clone_without_boot_files_push_none(self):
        running = {"status": "Running", "devices": {"t3": {}}, "config": {"volatile.uuid": "abc-123"},
                   "expanded_devices": {"eth0": {"type": "nic", "network": "orbitinst0", "ipv4.address": "10.253.0.2",
                                                 "security.acls": "orbitinst0-proxy,instance-7",
                                                 "security.acls.default.egress.action": "reject",
                                                 "security.acls.default.ingress.action": "reject"}}}
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
        apply = next(i for i, c in enumerate(calls) if c[:2] == ['python3', '-c'] and c[2] == 'print(\'{"status":"unchanged"}\')')
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
                for path in ("sockets.target.wants/orbit-node-service-3308.socket", "multi-user.target.wants/orbit-frankenphp.service",
                             "sockets.target.wants/orbit-mail-1025.socket"):
                    self.assertIn("instance-7/" + system + path, removed)
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

    def test_normal_clone_uses_the_exact_original_checker_program_and_argv(self):
        calls, _, error = self.clone()
        self.assertEqual(error, "")
        self.assertIn(["python3", "-c", NETWORK_CHECK.read_text(), "orbitinst0", "eth0"], calls)

    def test_nested_clone_starts_only_with_exactly_the_written_config_and_devices(self):
        allowed = {
            "root": {"type": "disk", "path": "/", "pool": "orbit-instances", "size": "40GiB"},
            "eth0": {"type": "nic", "network": "orbitinst0", "ipv4.address": "10.253.0.2",
                     "security.acls": "orbitinst0-proxy,instance-7", "security.acls.default.egress.action": "reject",
                     "security.acls.default.ingress.action": "reject"},
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
        for damage in ["clean", *damages, "missing-isolation", "extra-device", "changed-host-path", "writable-share"]:
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
            with self.subTest(damage=damage):
                calls, _, error = self.clone(inherited, overrides={"project": "orbit-instances-nested", "allowed_devices": allowed, "config": config})
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
        deploy: [], processes: 'succeeded', seed: 'skipped', t3: 'succeeded', register: 'failed'},
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


class T3PhaseTest(unittest.TestCase):
    """The `t3` phase runs the agent's guest phase with the model key on stdin, then T3's first start without it, in one call."""

    KEY = "fake-model-key-5c1e"

    def phase(self, phase="t3", fail=None, timeout=None):
        account = pwd.getpwuid(os.getuid())
        calls = []

        def command(argv, **kwargs):
            calls.append((argv, kwargs.get("input")))
            member = argv[argv.index("-c") + 2] if "-c" in argv else None
            if member is not None and member == timeout:
                # The outer `timeout 240` ends the step here: nothing after this line of the script runs.
                raise KeyboardInterrupt
            if member is not None and member == fail:
                return subprocess.CompletedProcess(argv, 1, "PRIVATE-STDOUT", "instance.container_" + member + "_failed: command php exited 17")
            if member is not None:
                return subprocess.CompletedProcess(argv, 0, json.dumps({"phase": member, "ready_at": "2026-11-13T21:00:00+00:00"}), "")
            return subprocess.CompletedProcess(argv, 0, "abc123\n", "")

        container = {"name": "instance-proof", "project": "orbit-instances", "mirror": "/private/mirror", "branch": "proof", "php": None}
        output, error = io.StringIO(), io.StringIO()
        code = 0
        with tempfile.TemporaryDirectory() as folder, (
            patch("sys.argv", [str(SCRIPT), json.dumps(container), "guest", NETWORK_CHECK.read_text(), account.pw_name, folder, phase])
        ), patch("sys.stdin", io.StringIO(self.KEY)), patch("subprocess.run", command), patch("os.chown"), \
                contextlib.redirect_stdout(output), contextlib.redirect_stderr(error):
            try:
                runpy.run_path(str(SCRIPT), run_name="__main__")
            except SystemExit as result:
                code = result.code
            except KeyboardInterrupt:
                code = 124
            left = Path(folder) / "t3.json"
            self.left = left.read_text() if left.exists() else None
        for argv, _ in calls:
            self.assertNotIn(self.KEY, json.dumps(argv))
        self.assertNotIn(self.KEY, output.getvalue() + error.getvalue() + (self.left or ""))
        guest = [(argv[argv.index("-c") + 2], content) for argv, content in calls if "-c" in argv]
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
    container: {launch: 'succeeded', workspace: {status: 'succeeded', outputs: {stdout: 'orbit-commit=' + 'a'.repeat(40)}},
        deploy: [], processes: 'succeeded', seed: 'skipped', t3: 'failed', register: 'skipped'},
}, {writeResource: async () => {}});
console.log(created.stdout);
"""
            result = subprocess.run(["deno", "eval", "--no-check", script, folder],
                                    cwd=SCRIPT.parents[2], text=True, capture_output=True, check=False, timeout=30)
            self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
            receipt = json.loads(result.stdout)
            return receipt["failed_step"], receipt["error_code"]

    def test_runs_agent_then_t3_and_passes_the_key_only_to_the_agent(self):
        code, guest, output, error = self.phase()
        self.assertEqual(code, 0)
        self.assertEqual(guest, [("agent", self.KEY), ("t3", "")])
        # The step prints T3's receipt alone, which the create result reads as the time T3 answered; sessions finds no t3.json.
        self.assertEqual(json.loads(output)["phase"], "t3")
        self.assertEqual(error, "")
        self.assertIsNone(self.left)

    def test_a_failed_agent_part_names_the_agent_and_keeps_the_agent_code(self):
        code, guest, output, error = self.phase(fail="agent")
        self.assertEqual(code, 1)
        self.assertEqual([member for member, _ in guest], ["agent"])
        self.assertEqual(output, "")
        # The Operation's failure detail is this stderr line; the code comes from the part the step left behind.
        self.assertEqual(error, "instance.container_agent_failed: command php exited 17\n")
        self.assertEqual(self.receipt(), ("t3", "instance.create_agent_failed"))

    def test_a_failed_t3_part_names_t3_and_keeps_the_t3_code(self):
        code, guest, output, error = self.phase(fail="t3")
        self.assertEqual(code, 1)
        self.assertEqual([member for member, _ in guest], ["agent", "t3"])
        self.assertEqual(error, "instance.container_t3_failed: command php exited 17\n")
        self.assertEqual(self.receipt(), ("t3", "instance.t3_unready"))

    def test_a_timeout_names_the_part_that_was_running(self):
        for part, code in [("agent", "instance.create_agent_failed"), ("t3", "instance.t3_unready")]:
            with self.subTest(part=part):
                self.assertEqual(self.phase(timeout=part)[0], 124)
                self.assertEqual(json.loads(self.left), {"t3_part": part})
                self.assertEqual(self.receipt(), ("t3", code))

    def test_the_agent_phase_alone_is_no_longer_a_step(self):
        code, guest, _, error = self.phase(phase="agent")
        self.assertEqual((code, guest, error), (1, [], "instance.clone_phase_invalid\n"))


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
