"""Exercise bundle deployments with real git repositories and a local SSH transport."""
import importlib.machinery
import importlib.util
import contextlib
import concurrent.futures
import io
import json
import os
from pathlib import Path
import shlex
import signal
import subprocess
import sys
import tempfile
import unittest
from unittest.mock import patch

SCRIPT = Path(__file__).resolve().parents[1] / "bin/deploy-services"
loader = importlib.machinery.SourceFileLoader("deploy_services", str(SCRIPT))
spec = importlib.util.spec_from_loader(loader.name, loader)
deploy = importlib.util.module_from_spec(spec)
loader.exec_module(deploy)


class DeployTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.root = Path(self.tmp.name)
        self.origin = self.root / "origin"
        self.source = self.root / "source"
        self.target = self.root / "services"
        self.calls = []
        self.active = []
        self.active_responses = []
        self.events = []
        self.corrupt_bundle = False
        self.wrong_sha = False
        self.fail_update = False
        self.fail_cleanup = False
        self.rewrite_bundle_ref = False
        self.schedules = [
            {"workflowId": "groups", "workflowName": "groups-driver", "paused": False, "orphaned": False},
            {"workflowId": "projects", "workflowName": "maintenance-projects", "paused": False, "orphaned": False},
            {"workflowId": "historical", "workflowName": "subtasks-driver", "paused": True, "orphaned": False},
            {"workflowId": "orphan", "workflowName": "removed", "paused": False, "orphaned": True},
        ]
        self.initial_schedules = json.loads(json.dumps(self.schedules))
        self.snapshot_paths = []
        self.fail_pause = set()
        self.fail_resume = set()
        self.fail_health = False
        self.fail_restart = False
        self.active_error = None
        self.add_schedule_on_update = False
        self.active_timeouts = []
        self.marker = self.root / "deploy-state/in-progress.json"
        self.running = True
        self.stop_reply_lost = False
        self.restart_reply_lost = False
        self.stop_ineffective = False
        self.signal_on_active = None
        self.signal_on_resume = None
        self.second_run_on_active = False
        self.second_run_error = None
        self.fail_pause_before_apply = False
        self.clock = 0
        self.named_temporary_file = tempfile.NamedTemporaryFile
        self.git("init", "-q", "-b", "main", str(self.origin))
        self.git("-C", str(self.origin), "config", "user.name", "Test")
        self.git("-C", str(self.origin), "config", "user.email", "test@example.test")
        (self.origin / "version").write_text("one")
        self.git("-C", str(self.origin), "add", ".")
        self.git("-C", str(self.origin), "commit", "-qm", "First")
        self.git("clone", "-q", str(self.origin), str(self.source))
        (self.source / "bin").mkdir()
        restore = self.root / "restore.bundle"
        self.git("-C", str(self.origin), "bundle", "create", str(restore), "main")
        self.git("clone", "-q", "-b", "main", str(restore), str(self.target))
        (self.target / ".swamp").mkdir()
        (self.target / ".swamp/history").write_text("retained")
        self.process = {"id": 300, "name": "swamp-serve", "runtime": "systemd",
                        "user": "orbit", "working_directory": str(self.target)}
        self.original_run = deploy.run

    def git(self, *args):
        return subprocess.check_output(["git", *args], text=True, stderr=subprocess.PIPE).strip()

    def orbit(self, *args):
        self.calls.append(args[0])
        if args[0].startswith("process:") and args[0] != "process:list":
            self.events.append(args[0])
        if args[0] == "node:show":
            return {"status": "active", "user": "orbit", "wireguard_ip": "10.44.0.3"}
        if args[0] == "process:list":
            return {"processes": [self.process]}
        if args[0] == "process:restart" and self.fail_restart:
            raise RuntimeError("Restart failed")
        if args[0] == "process:stop":
            if not self.stop_ineffective:
                self.running = False
            if self.stop_reply_lost:
                raise RuntimeError("Stop reply lost")
        if args[0] == "process:restart":
            self.running = True
            if self.restart_reply_lost:
                raise RuntimeError("Restart reply lost")
        return {"status": "active"}

    def transport(self, argv, *, source=None, timeout=None):
        if argv[0] == "ssh":
            if source == deploy.CHECK_HOST:
                return ""
            if source == deploy.STATE:
                return "ActiveState=active\nMainPID=123\n" if self.running else "ActiveState=inactive\nMainPID=0\n"
            if source in (deploy.SCHEDULES, deploy.LIST_STOPPED):
                self.events.append("snapshot")
                return json.dumps({"schedules": self.schedules})
            if source in (deploy.PAUSE, deploy.RESUME, deploy.RESUME_STOPPED):
                workflow = shlex.split(argv[-1])[-1]
                pause = source == deploy.PAUSE
                self.events.append(("pause-" if pause else "resume-") + workflow)
                snapshot = Path(json.loads(self.marker.read_text())["snapshot"])
                self.assertEqual(snapshot.stat().st_mode & 0o777, 0o600)
                self.assertEqual(json.loads(snapshot.read_text())["schedules"], self.initial_schedules)
                schedule = next(s for s in self.schedules if s["workflowId"] == workflow)
                self.assertFalse(next(s for s in self.initial_schedules if s["workflowId"] == workflow)["paused"])
                if source == deploy.RESUME_STOPPED:
                    self.events.append("resume-offline")
                    self.assertEqual(shlex.split(argv[-1])[-2], str(self.target))
                # A lost reply after pausing still needs restoration.
                if not (pause and self.fail_pause_before_apply):
                    schedule["paused"] = pause
                if not pause and self.signal_on_resume:
                    os.kill(os.getpid(), self.signal_on_resume)
                if workflow in (self.fail_pause if pause else self.fail_resume):
                    raise RuntimeError("Schedule transport failed")
                return "{}"
            if source == deploy.ACTIVE:
                self.events.append("active")
                if self.second_run_on_active:
                    self.second_run_on_active = False
                    before = self.calls.count("process:stop")
                    try:
                        self.execute()
                    except RuntimeError as error:
                        self.second_run_error = str(error)
                    else:
                        self.fail("A concurrent deployment must be refused")
                    self.assertEqual(self.calls.count("process:stop"), before)
                if self.signal_on_active:
                    os.kill(os.getpid(), self.signal_on_active)
                self.active_timeouts.append(timeout)
                if self.active_error:
                    raise self.active_error
                runs = self.active_responses.pop(0) if self.active_responses else self.active
                return json.dumps({"runs": runs})
            if source == deploy.PREFLIGHT:
                self.events.append("preflight")
            if source == deploy.UPDATE:
                self.events.append("update")
                if self.add_schedule_on_update:
                    self.schedules[0]["workflowName"] = "renamed-driver"
                    self.schedules.append({"workflowId": "new", "workflowName": "new-workflow",
                                           "paused": False, "orphaned": False})
                if self.fail_update:
                    if isinstance(self.fail_update, BaseException):
                        raise self.fail_update
                    raise RuntimeError("Checkout update failed")
                if self.rewrite_bundle_ref:
                    self.git("-C", str(self.target), "update-ref", "refs/remotes/bundle/main",
                             "HEAD")
            if source == deploy.HEALTH:
                if self.fail_health:
                    raise RuntimeError("Readiness failed")
                return '{"authenticated":true}'
            if source == 'rm -f -- "$1"\n' and self.fail_cleanup:
                incoming = Path(shlex.split(argv[-1])[-1])
                self.addCleanup(incoming.unlink, missing_ok=True)
                raise RuntimeError("Cleanup unavailable")
            return self.original_run(shlex.split(argv[-1]), source=source, timeout=timeout)
        if argv[0] == "scp":
            self.events.append("scp")
            data = b"bad bundle" if self.corrupt_bundle else Path(argv[-2]).read_bytes()
            Path(argv[-1].split(":", 1)[1]).write_bytes(data)
            return ""
        if self.wrong_sha and argv[-2:] == ["rev-parse", "origin/main"]:
            return "0" * 40
        return self.original_run(argv, source=source, timeout=timeout)

    def execute(self):
        def snapshot_file(**kwargs):
            snapshot = self.named_temporary_file(dir=self.root, **kwargs)
            self.snapshot_paths.append(Path(snapshot.name))
            return snapshot

        def sleep(seconds):
            self.clock += seconds

        with patch.object(deploy, "APP", str(self.target)), \
                patch.object(deploy, "LOCK_FILE", str(self.marker)), \
                patch.object(deploy, "__file__", str(self.source / "bin/deploy-services")), \
                patch.object(deploy, "orbit", self.orbit), \
                patch.object(deploy, "run", self.transport), \
                patch.object(deploy.tempfile, "NamedTemporaryFile", snapshot_file), \
                patch.object(deploy.time, "monotonic", lambda: self.clock), \
                patch.object(deploy.time, "sleep", sleep), \
                patch.object(deploy, "DRAIN_TIMEOUT", 3):
            return deploy.deploy()

    def change_main(self):
        (self.origin / "version").write_text("next " + str(self.clock))
        self.git("-C", str(self.origin), "commit", "-qam", "Next")

    def test_concurrent_marker_creation_has_one_winner_and_names_its_snapshot(self):
        owners = [{"host": name, "pid": 123, "snapshot": str(self.root / f"{name}.json")}
                  for name in ("client-a", "client-b")]

        def acquire(owner):
            return subprocess.run(["bash", "-s", "--", str(self.marker), json.dumps(owner)],
                                  input=deploy.ACQUIRE, capture_output=True, text=True)

        with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
            results = list(pool.map(acquire, owners))
        self.assertEqual(sorted(r.returncode for r in results), [0, 1])
        winner = json.loads(self.marker.read_text())
        self.assertEqual(self.marker.stat().st_mode & 0o777, 0o600)
        loser = next(r for r in results if r.returncode)
        self.assertIn(winner["snapshot"], loser.stderr)
        self.assertIn(winner["host"], loser.stderr)

    def test_second_full_deployment_is_refused_while_the_first_has_paused_schedules(self):
        self.change_main()
        self.second_run_on_active = True
        result = self.execute()
        self.assertIn(result["scheduleSnapshot"], self.second_run_error)
        self.assertIn("already in progress", self.second_run_error)
        self.assertEqual(self.events.count("preflight"), 1)
        self.assertEqual(self.calls.count("process:stop"), 1)
        self.assertEqual(self.calls.count("process:restart"), 1)
        self.assertEqual(self.schedules, self.initial_schedules)
        self.assertFalse(self.marker.exists())

    def test_sigkill_preserves_the_prior_snapshot_and_blocks_even_a_no_change_rerun(self):
        paused_file = self.root / "paused-schedules.json"
        program = f'''
import importlib.machinery, importlib.util, json, os, signal
from pathlib import Path
loader = importlib.machinery.SourceFileLoader("fixture_deploy", {str(SCRIPT)!r})
spec = importlib.util.spec_from_loader(loader.name, loader)
d = importlib.util.module_from_spec(spec)
loader.exec_module(d)
d.LOCK_FILE = {str(self.marker)!r}
d.tempfile.tempdir = {str(self.root)!r}
def remote(script, *args, timeout=None):
    return d.run(["bash", "-s", "--", *args], source=script, timeout=timeout)
with d.deployment_lock(remote) as progress:
    schedules = json.loads({json.dumps(self.initial_schedules)!r})
    with open(progress["snapshot"], "w") as snapshot:
        json.dump({{"schedules": schedules}}, snapshot)
        snapshot.flush()
        os.fsync(snapshot.fileno())
    progress["restored"] = False
    for schedule in schedules:
        if not schedule["orphaned"]:
            schedule["paused"] = True
    Path({str(paused_file)!r}).write_text(json.dumps(schedules))
    os.kill(os.getpid(), signal.SIGKILL)
'''
        killed = subprocess.run([sys.executable, "-B", "-"], input=program,
                                text=True, capture_output=True, timeout=20)
        self.assertEqual(killed.returncode, -signal.SIGKILL, killed.stderr)
        marker = self.marker.read_bytes()
        snapshot = Path(json.loads(marker)["snapshot"])
        self.assertEqual(json.loads(snapshot.read_text())["schedules"], self.initial_schedules)
        self.schedules = json.loads(paused_file.read_text())
        with self.assertRaisesRegex(RuntimeError, "needs recovery") as raised:
            self.execute()
        self.assertIn(str(snapshot), str(raised.exception))
        self.assertEqual(self.marker.read_bytes(), marker)
        self.assertNotIn("preflight", self.events)
        self.assertNotIn("process:stop", self.calls)
        self.assertTrue(all(s["paused"] for s in self.schedules if not s["orphaned"]))

    def test_resume_failure_keeps_the_marker_and_refuses_a_no_change_retry(self):
        self.change_main()
        self.fail_resume.add("groups")
        with self.assertRaisesRegex(RuntimeError, "Schedule resume failed"):
            self.execute()
        marker = self.marker.read_bytes()
        prior = json.loads(marker)["snapshot"]
        self.assertEqual((self.target / "version").read_text(), "next 0")
        with self.assertRaisesRegex(RuntimeError, "needs recovery") as raised:
            self.execute()
        self.assertIn(prior, str(raised.exception))
        self.assertEqual(self.marker.read_bytes(), marker)
        self.assertEqual(self.calls.count("process:stop"), 1)

    def test_sigterm_and_sighup_restore_before_exit_and_restore_the_previous_handlers(self):
        self.change_main()
        previous = {number: signal.getsignal(number) for number in (signal.SIGTERM, signal.SIGHUP)}
        for number in previous:
            with self.subTest(signal=signal.Signals(number).name):
                self.signal_on_active = number
                with self.assertRaisesRegex(RuntimeError, signal.Signals(number).name):
                    self.execute()
                self.assertEqual(self.schedules, self.initial_schedules)
                self.assertFalse(self.marker.exists())
                self.assertNotIn("process:stop", self.calls)
                self.assertEqual(signal.getsignal(number), previous[number])

    def test_sighup_during_resumption_is_ignored_until_restoration_finishes(self):
        self.change_main()
        self.signal_on_resume = signal.SIGHUP
        self.assertEqual(self.execute()["status"], "ok")
        self.assertEqual(self.schedules, self.initial_schedules)
        self.assertFalse(self.marker.exists())

    def test_lost_stop_reply_uses_observed_stopped_state_for_restoration(self):
        self.change_main()
        self.stop_reply_lost = True
        with self.assertRaisesRegex(RuntimeError, "Stop reply lost"):
            self.execute()
        self.assertEqual(self.events.count("resume-offline"), 2)
        self.assertNotIn("update", self.events)
        self.assertFalse(self.marker.exists())

    def test_lost_restart_reply_uses_observed_running_state_for_restoration(self):
        self.change_main()
        self.restart_reply_lost = True
        with self.assertRaisesRegex(RuntimeError, "Restart reply lost"):
            self.execute()
        self.assertNotIn("resume-offline", self.events)
        self.assertEqual(self.schedules, self.initial_schedules)
        self.assertFalse(self.marker.exists())

    def test_successful_stop_reply_cannot_authorize_an_update_of_a_running_checkout(self):
        self.change_main()
        self.stop_ineffective = True
        with self.assertRaisesRegex(RuntimeError, "still running after stop"):
            self.execute()
        self.assertNotIn("update", self.events)
        self.assertEqual((self.target / "version").read_text(), "one")
        self.assertEqual(self.schedules, self.initial_schedules)

    def test_unconfirmed_pause_never_resumes_untouched_schedules_or_discards_recovery(self):
        self.change_main()
        self.fail_pause.add("groups")
        self.fail_pause_before_apply = True
        with self.assertRaisesRegex(RuntimeError, "Pause outcome is uncertain"):
            self.execute()
        self.assertFalse(any(e.startswith("resume-") for e in self.events))
        self.assertEqual(self.schedules, self.initial_schedules)
        self.assertTrue(self.marker.exists())

    def test_bundle_temporary_name_is_unique_with_the_bsd_mktemp_template_rules(self):
        self.assertRegex(deploy.INCOMING.rstrip(), r"X{6,}$")
        paths = [Path(self.original_run(["bash", "-s"], source=deploy.INCOMING).strip())
                 for _ in range(2)]
        for path in paths:
            self.addCleanup(path.unlink, missing_ok=True)
            self.assertTrue(path.is_file())
            self.assertNotIn("XXXXXXXX", path.name)
        self.assertNotEqual(*paths)

    def test_deploys_fetched_main_preserves_state_and_repeats(self):
        # A stale source checkout must still ship the newest origin/main.
        (self.origin / "version").write_text("two")
        self.git("-C", str(self.origin), "commit", "-qam", "Second")
        expected = self.git("-C", str(self.origin), "rev-parse", "HEAD")
        for changed in (True, False):
            self.calls.clear()
            self.events.clear()
            result = self.execute()
            self.assertEqual(result["sha"], expected)
            self.assertEqual(result["changed"], changed)
            self.assertEqual((self.target / "version").read_text(), "two")
            self.assertEqual((self.target / ".swamp/history").read_text(), "retained")
            if changed:
                self.assertEqual(self.calls[-2:], ["process:stop", "process:restart"])
                self.assertLess(self.events.index("scp"), self.events.index("preflight"))
                self.assertEqual(self.events[self.events.index("process:stop") - 1], "active")
                self.assertLess(self.events.index("pause-projects"), self.events.index("active"))
                self.assertEqual(self.events[-2:], ["resume-groups", "resume-projects"])
                self.assertEqual(result["scheduleSnapshot"], str(self.snapshot_paths[-1]))
            else:
                self.assertNotIn("process:stop", self.calls)
                self.assertNotIn("process:restart", self.calls)
                self.assertNotIn("snapshot", self.events)
                self.assertIsNone(result["scheduleSnapshot"])
            self.assertEqual(self.schedules, self.initial_schedules)
            self.assertFalse(self.marker.exists())
        self.assertEqual(self.git("-C", str(self.target), "ls-files"), "version")

    def test_fast_forward_preserves_unrelated_untracked_model(self):
        self.execute()
        model = self.target / "models/@swamp/git/git-wt-bump-apps-e2e.yaml"
        model.parent.mkdir(parents=True)
        model.write_text("historical model")
        (self.origin / "version").write_text("two")
        self.git("-C", str(self.origin), "commit", "-qam", "Second")
        self.calls.clear()
        result = self.execute()
        self.assertTrue(result["changed"])
        self.assertEqual(self.calls[-2:], ["process:stop", "process:restart"])
        self.assertEqual((self.target / "version").read_text(), "two")
        self.assertEqual(model.read_text(), "historical model")

    def test_readiness_authenticates_through_swamp_without_reading_secret_values(self):
        # This executable stands in for Swamp, which owns token-file handling.
        cli = self.root / "swamp"
        cli.write_text("#!/usr/bin/env python3\nimport json, sys\n"
                       "assert '--server' in sys.argv\n"
                       "assert sys.argv[sys.argv.index('--token-file')+1] == '/private/token'\n"
                       "print(json.dumps({'runs': []}))\n")
        cli.chmod(0o755)
        result = self.original_run(["bash", "-s", "--", str(cli), "/private/token"],
                                   source=deploy.HEALTH)
        self.assertEqual(json.loads(result), {"authenticated": True})
        result = self.original_run(["bash", "-s", "--", str(cli), "/private/token"],
                                   source=deploy.ACTIVE)
        self.assertEqual(json.loads(result), {"runs": []})

    def test_readiness_retries_timeouts_invalid_json_and_failed_commands(self):
        program = deploy.HEALTH.split("<<'HEALTH_PY'\n")[1].split("\nHEALTH_PY")[0]
        output = io.StringIO()
        with patch("sys.argv", ["-", "/swamp", "/private/token"]), \
                patch("subprocess.run", side_effect=[
                    subprocess.TimeoutExpired("swamp", 10),
                    subprocess.CompletedProcess([], 0, "not JSON"),
                    subprocess.CompletedProcess([], 1, ""),
                    subprocess.CompletedProcess([], 0, '{"runs": []}'),
                ]) as command, patch("time.sleep") as sleep, contextlib.redirect_stdout(output):
            exec(compile(program, "readiness", "exec"), {})
        self.assertEqual(json.loads(output.getvalue()), {"authenticated": True})
        self.assertEqual(command.call_count, 4)
        self.assertEqual(sleep.call_count, 3)

    def test_schedule_commands_use_native_cli_and_local_restore_ignores_server_environment(self):
        cli = self.root / "swamp-schedules"
        cli.write_text("#!/usr/bin/env python3\nimport json, os, sys\n"
                       "print(json.dumps({'args': sys.argv[1:], 'remoteEnv': "
                       "bool(os.getenv('SWAMP_SERVER_URL') or os.getenv('SWAMP_SERVE_URL'))}))\n")
        cli.chmod(0o755)
        for script in (deploy.SCHEDULES, deploy.PAUSE, deploy.RESUME):
            result = self.original_run(["bash", "-s", "--", str(cli), "/private/token", "original-id"],
                                       source=script)
            args = json.loads(result)["args"]
            self.assertEqual(args[:2], ["workflow", "schedule"])
            self.assertEqual(args[args.index("--server") + 1], "ws://127.0.0.1:9417")
            self.assertEqual(args[args.index("--token-file") + 1], "/private/token")
            if script != deploy.SCHEDULES:
                self.assertEqual(args[3], "original-id")
        with patch.dict(os.environ, {"SWAMP_SERVER_URL": "ws://must-not-connect",
                                     "SWAMP_SERVE_URL": "ws://must-not-connect"}):
            result = json.loads(self.original_run(
                ["bash", "-s", "--", str(cli), "/repo", "original-id"], source=deploy.RESUME_STOPPED))
        self.assertEqual(result["args"], ["workflow", "schedule", "resume", "original-id", "--repo-dir", "/repo", "--json"])
        self.assertFalse(result["remoteEnv"])

    def test_active_run_prevents_stop_or_update(self):
        self.change_main()
        self.active = [{"id": "busy"}]
        with self.assertRaisesRegex(RuntimeError, "active runs"):
            self.execute()
        self.assertNotIn("process:stop", self.calls)
        self.assertEqual((self.target / "version").read_text(), "one")
        self.assertEqual(self.clock, 3)
        self.assertTrue(all(timeout <= 3 for timeout in self.active_timeouts))
        self.assertEqual(self.schedules, self.initial_schedules)

    def test_partial_pause_failure_restores_the_snapshot_and_leaves_serve_running(self):
        self.change_main()
        self.fail_pause.add("groups")
        with self.assertRaisesRegex(RuntimeError, "Schedule transport failed"):
            self.execute()
        self.assertEqual([e for e in self.events if e.startswith(("pause-", "resume-"))],
                         ["pause-groups", "resume-groups"])
        self.assertEqual(self.schedules, self.initial_schedules)
        self.assertNotIn("process:stop", self.calls)
        self.assertTrue(self.snapshot_paths[-1].exists())

    def test_snapshot_persistence_failure_happens_before_any_pause(self):
        self.change_main()
        with patch.object(deploy.os, "fsync", side_effect=OSError("disk unavailable")):
            with self.assertRaisesRegex(OSError, "disk unavailable"):
                self.execute()
        self.assertFalse(any(event.startswith("pause-") for event in self.events))
        self.assertNotIn("process:stop", self.calls)

    def test_malformed_schedule_list_is_refused_before_mutating_any_schedule(self):
        self.change_main()
        for invalid in ("false", None):
            with self.subTest(paused=invalid):
                self.schedules[0]["paused"] = invalid
                with self.assertRaisesRegex(RuntimeError, "schedule list is unreadable"):
                    self.execute()
                self.assertFalse(self.marker.exists())
                self.assertFalse(any(event.startswith("pause-") for event in self.events))
                self.assertNotIn("process:stop", self.calls)

    def test_idle_read_timeout_restores_schedules_without_stopping_serve(self):
        self.change_main()
        self.active_error = subprocess.TimeoutExpired("ssh", 3)
        with self.assertRaises(subprocess.TimeoutExpired):
            self.execute()
        self.assertEqual(self.schedules, self.initial_schedules)
        self.assertNotIn("process:stop", self.calls)

    def test_unreadable_active_response_restores_schedules_without_stopping_serve(self):
        self.change_main()
        self.active = None
        with self.assertRaisesRegex(RuntimeError, "active-run response is unreadable"):
            self.execute()
        self.assertEqual(self.schedules, self.initial_schedules)
        self.assertNotIn("process:stop", self.calls)

    def test_resume_failures_attempt_every_saved_id_and_fail_a_successful_update(self):
        self.change_main()
        self.fail_resume.update({"groups", "projects"})
        with self.assertRaisesRegex(RuntimeError, "Schedule resume failed") as raised:
            self.execute()
        for text in ("groups-driver (groups)", "maintenance-projects (projects)", str(self.snapshot_paths[-1])):
            self.assertIn(text, str(raised.exception))
        self.assertEqual(self.events[-2:], ["resume-groups", "resume-projects"])
        self.assertIn("process:restart", self.calls)
        self.assertEqual((self.target / "version").read_text(), "next 0")

    def test_deploy_and_resume_failures_report_both_and_retain_the_snapshot(self):
        self.change_main()
        self.fail_update = True
        self.fail_resume.add("groups")
        with self.assertRaisesRegex(RuntimeError, "Checkout update failed.*Schedule resume failed") as raised:
            self.execute()
        self.assertIn(str(self.snapshot_paths[-1]), str(raised.exception))
        self.assertEqual(self.events.count("resume-offline"), 2)
        self.assertIn("resume-projects", self.events)
        self.assertNotIn("process:restart", self.calls)

    def test_readiness_failure_restores_schedules_through_the_restarted_server(self):
        self.change_main()
        self.fail_health = True
        with self.assertRaisesRegex(RuntimeError, "Readiness failed"):
            self.execute()
        self.assertEqual(self.schedules, self.initial_schedules)
        self.assertIn("process:restart", self.calls)
        self.assertNotIn("resume-offline", self.events)

    def test_restart_failure_restores_persisted_schedules_while_serve_is_stopped(self):
        self.change_main()
        self.fail_restart = True
        with self.assertRaisesRegex(RuntimeError, "Restart failed"):
            self.execute()
        self.assertEqual(self.schedules, self.initial_schedules)
        self.assertEqual(self.events.count("resume-offline"), 2)

    def test_interrupted_update_restores_persisted_schedule_state(self):
        self.change_main()
        self.fail_update = KeyboardInterrupt("operator interruption")
        with self.assertRaises(KeyboardInterrupt):
            self.execute()
        self.assertEqual(self.schedules, self.initial_schedules)
        self.assertEqual(self.events.count("resume-offline"), 2)

    def test_restore_uses_original_ids_and_never_touches_a_new_schedule(self):
        self.change_main()
        self.add_schedule_on_update = True
        self.execute()
        self.assertEqual(self.events[-2:], ["resume-groups", "resume-projects"])
        self.assertNotIn("resume-new", self.events)
        self.assertTrue(next(s for s in self.schedules if s["workflowId"] == "historical")["paused"])

    def test_deployment_with_no_enabled_schedules_does_not_resume_any(self):
        self.change_main()
        for schedule in self.schedules:
            schedule["paused"] = True
        self.initial_schedules = json.loads(json.dumps(self.schedules))
        self.execute()
        self.assertFalse(any(event.startswith(("pause-", "resume-")) for event in self.events))
        self.assertIn("process:restart", self.calls)

    def test_wrong_process_account_prevents_remote_work(self):
        self.process["user"] = "root"
        with self.assertRaisesRegex(RuntimeError, "user orbit"):
            self.execute()
        self.assertNotIn("process:stop", self.calls)

    def test_runs_present_after_preflight_are_drained_before_stop(self):
        (self.origin / "version").write_text("two")
        self.git("-C", str(self.origin), "commit", "-qam", "Second")
        self.active_responses = [[{"id": "just-started"}], []]
        self.execute()
        self.assertEqual(self.events.count("active"), 2)
        self.assertIn("process:stop", self.calls)
        self.assertEqual((self.target / "version").read_text(), "two")
        self.assertEqual(self.clock, 2)

    def test_divergent_checkout_keeps_serve_running(self):
        self.execute()
        self.git("-C", str(self.target), "config", "user.name", "Test")
        self.git("-C", str(self.target), "config", "user.email", "test@example.test")
        (self.target / "local").write_text("local commit")
        self.git("-C", str(self.target), "add", "local")
        self.git("-C", str(self.target), "commit", "-qm", "Local")
        self.calls.clear()
        with self.assertRaises(RuntimeError):
            self.execute()
        self.assertNotIn("process:stop", self.calls)
        self.assertNotIn("process:restart", self.calls)
        self.assertEqual((self.target / ".swamp/history").read_text(), "retained")

    def test_bad_bundle_or_wrong_commit_keeps_serve_running(self):
        self.execute()
        for fault in ("corrupt_bundle", "wrong_sha"):
            with self.subTest(fault=fault):
                self.calls.clear()
                setattr(self, fault, True)
                with self.assertRaises(RuntimeError):
                    self.execute()
                setattr(self, fault, False)
                self.assertNotIn("process:stop", self.calls)
                self.assertEqual((self.target / "version").read_text(), "one")

    def test_untracked_collisions_keep_serve_running(self):
        self.execute()
        # Include spaces, newlines, a file blocking a new directory, and a
        # directory whose contents would be replaced by a new file.
        for path, local in (("new model\nname.yaml", "new model\nname.yaml"),
                            ("models/new.yaml", "models"),
                            ("replaced", "replaced/local.yaml")):
            (self.origin / path).parent.mkdir(parents=True, exist_ok=True)
            (self.origin / path).write_text("from main")
        self.git("-C", str(self.origin), "add", ".")
        self.git("-C", str(self.origin), "commit", "-qm", "Add models")
        for local in ("new model\nname.yaml", "models", "replaced/local.yaml"):
            with self.subTest(local=local):
                (self.target / local).parent.mkdir(parents=True, exist_ok=True)
                (self.target / local).write_text("keep local")
                self.calls.clear()
                with self.assertRaisesRegex(RuntimeError, "Untracked path conflicts"):
                    self.execute()
                self.assertNotIn("process:stop", self.calls)
                self.assertEqual((self.target / "version").read_text(), "one")
                self.assertEqual((self.target / local).read_text(), "keep local")
                (self.target / local).unlink()

    def test_missing_checkout_or_unborn_head_requires_manual_restore(self):
        for state in ("missing", "unborn"):
            with self.subTest(state=state):
                self.target = self.root / state
                self.target.mkdir()
                (self.target / ".swamp").mkdir()
                self.process["working_directory"] = str(self.target)
                if state == "unborn":
                    self.git("init", "-q", "-b", "main", str(self.target))
                (self.target / ".swamp.yaml").write_text("keep local")
                with self.assertRaisesRegex(RuntimeError, "existing HEAD"):
                    self.execute()
                self.assertNotIn("process:stop", self.calls)
                self.assertEqual((self.target / ".swamp.yaml").read_text(), "keep local")
                if state == "missing":
                    self.assertFalse((self.target / ".git").exists())

    def test_update_uses_validated_sha_after_bundle_ref_changes(self):
        (self.origin / "version").write_text("two")
        self.git("-C", str(self.origin), "commit", "-qam", "Second")
        expected = self.git("-C", str(self.origin), "rev-parse", "HEAD")
        self.rewrite_bundle_ref = True
        result = self.execute()
        self.assertEqual(result["sha"], expected)
        self.assertEqual(self.git("-C", str(self.target), "rev-parse", "HEAD"), expected)
        self.assertEqual((self.target / "version").read_text(), "two")

    def test_cleanup_failure_keeps_original_result_or_error(self):
        self.fail_cleanup = True
        for fails in (False, True):
            with self.subTest(deploy_fails=fails):
                self.clock += 1
                self.change_main()
                self.fail_update = fails
                stderr = io.StringIO()
                with contextlib.redirect_stderr(stderr):
                    if fails:
                        with self.assertRaisesRegex(RuntimeError, "Checkout update failed"):
                            self.execute()
                    else:
                        self.assertEqual(self.execute()["status"], "ok")
                self.assertIn("Bundle cleanup failed", stderr.getvalue())
                self.assertIn("Cleanup unavailable", stderr.getvalue())

    def test_failure_after_stop_leaves_serve_stopped(self):
        (self.origin / "version").write_text("two")
        self.git("-C", str(self.origin), "commit", "-qam", "Second")
        self.fail_update = True
        with self.assertRaisesRegex(RuntimeError, "Checkout update failed"):
            self.execute()
        self.assertIn("process:stop", self.calls)
        self.assertNotIn("process:restart", self.calls)


if __name__ == "__main__":
    unittest.main()
