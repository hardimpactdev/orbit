"""Native F9 command failure parity on the local disposable checkout."""

import hashlib
import json
import os
import pwd
import runpy
import signal
import subprocess
import sys
import tempfile
import time
import unittest
from pathlib import Path

HERE = Path(__file__).resolve().parent
ENVIRONMENT = HERE.parents[1] / "task-repository/scripts/project_environment.py"


class CommandParity(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix="deploy-commands-", dir=Path.home())
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        for args in (["init", "--initial-branch=main"], ["config", "user.name", "Proof"],
                     ["config", "user.email", "proof@example.test"], ["commit", "--allow-empty", "-m", "initial"]):
            subprocess.run(["git", "-C", str(self.root), *args], check=True, capture_output=True)
        commit = subprocess.check_output(["git", "-C", str(self.root), "rev-parse", "HEAD"], text=True).strip()
        self.intent = {"instance_id": 1, "commit": commit, "deployment": {
            "variant": "development", "project_id": 1, "node_user": pwd.getpwuid(os.getuid()).pw_name,
            "home": str(self.root), "branch": "main", "steps": []}}

    def run_phase(self, commands, required=None, seconds=2, alter_digest=False, runtime=None):
        self.intent["deployment"]["steps"] = [{"name": "step-" + str(index), "phase": "before_activation",
            "timeout_seconds": seconds, "required": True if required is None else required[index],
            "command_digest": hashlib.sha256(command.encode()).hexdigest()} for index, command in enumerate(commands)]
        if alter_digest:
            self.intent["deployment"]["steps"][-1]["command_digest"] = "0" * 64
        public = {"intent": self.intent, "phase": "before_activation", "source": {"commit": self.intent["commit"]},
                  "runtime": runtime}
        body = ENVIRONMENT.read_text() + "\n" + HERE.joinpath("before_activation.py").read_text()
        result = subprocess.run([sys.executable, "-c", body, json.dumps(public)],
                                input=json.dumps(commands), text=True, capture_output=True, timeout=8, check=False)
        self.assertIn(result.returncode, [0, 1], result.stderr)
        outcome = json.loads(result.stdout)
        self.assertEqual(result.returncode == 0, outcome["ready"])
        return outcome, result

    def test_order_and_application_account_are_preserved_in_one_phase(self):
        result, _ = self.run_phase(["id -un > account; printf before > phases", "printf after >> phases"])
        self.assertTrue(result["ready"])
        self.assertEqual((self.root / "phases").read_text(), "beforeafter")
        self.assertEqual((self.root / "account").read_text().strip(), pwd.getpwuid(os.getuid()).pw_name)

    def test_existing_vp_bin_775_is_executable_by_an_unpinned_development_command(self):
        store = self.root / "store"
        (store / "bin").mkdir(parents=True)
        (store / "bin/vp").write_text("#!/bin/sh\nprintf 'proof-vp-version\\n'\n")
        (store / "bin/vp").chmod(0o755)
        (store / "bin").chmod(0o775)
        environment = {"PATH": "/usr/bin:/bin"}
        definitions = runpy.run_path(str(HERE / "before_activation.py"))
        definitions["development_scope_environment"](pwd.getpwuid(os.getuid()), environment, [store])
        process = subprocess.run(["/bin/bash", "--noprofile", "--norc", "-eu", "-c", "vp --version"],
                                 env=environment, capture_output=True, text=True, check=True)
        self.assertEqual(process.stdout, "proof-vp-version\n")
        self.assertEqual(environment["VP_HOME"], str(store))
        self.assertEqual((store / "bin").stat().st_mode & 0o777, 0o775)

    def test_required_failure_stops_later_commands(self):
        result, _ = self.run_phase(["exit 7", "touch forbidden"])
        self.assertEqual(result["error_code"], "deployment.step_failed")
        self.assertEqual(result["failed_step"], "before_activation.step-0")
        self.assertFalse((self.root / "forbidden").exists())

    def test_best_effort_failure_continues_and_counts_warning(self):
        result, _ = self.run_phase(["exit 42", "touch continued; head -c 20000 /dev/zero | tr '\\0' x"], required=[False, True])
        self.assertTrue(result["ready"])
        self.assertEqual(result["warnings"], 1)
        self.assertIn("Best-effort step failed (exit 42): step-0", result["output_tail"])
        self.assertTrue((self.root / "continued").exists())

    def test_complete_bundle_is_checked_before_first_effect(self):
        result, _ = self.run_phase(["touch forbidden", "true"], alter_digest=True)
        self.assertEqual(result["error_code"], "instance.step_not_admitted")
        self.assertFalse((self.root / "forbidden").exists())

    def test_dotenv_and_seed_are_available_but_never_exposed(self):
        (self.root / ".env").write_text('TOKEN="secret-environment-sentinel"\n')
        result, process = self.run_phase([
            'test "$TOKEN" = secret-environment-sentinel; test "$ORBIT_SEED_COMMIT" = ' + self.intent["commit"]
            + '; printf "$TOKEN"; printf safe-diagnostic >&2; exit 2'])
        self.assertEqual(result["error_code"], "deployment.step_failed")
        self.assertIn("safe-diagnostic", result["output_tail"])
        self.assertNotIn("secret-environment-sentinel", process.stdout + process.stderr)

    def test_each_command_retains_its_timeout_and_later_commands_do_not_run(self):
        result, _ = self.run_phase(["sleep 30", "touch forbidden"], seconds=1)
        self.assertEqual(result["error_code"], "deployment.step_failed")
        self.assertTrue(result["commands"][0]["timed_out"])
        self.assertEqual(result["commands"][0]["exit_code"], 143)
        self.assertFalse((self.root / "forbidden").exists())

    def test_a_command_cannot_turn_another_checkout_branch_into_a_successful_seed(self):
        result, _ = self.run_phase(["git checkout -b unexpected"])
        self.assertEqual(result["error_code"], "deployment.branch_changed")

    def test_private_command_bytes_never_enter_the_shell_arguments(self):
        command = 'echo $$ > shell.pid; sleep 1 # private-command-argv-sentinel'
        self.intent["deployment"]["steps"] = [{"name": "proof", "phase": "before_activation", "required": True,
            "timeout_seconds": 3, "command_digest": hashlib.sha256(command.encode()).hexdigest()}]
        request = {"intent": self.intent, "phase": "before_activation", "source": {"commit": self.intent["commit"]}}
        body = ENVIRONMENT.read_text() + "\n" + HERE.joinpath("before_activation.py").read_text()
        child = subprocess.Popen([sys.executable, "-c", body, json.dumps(request)], stdin=subprocess.PIPE,
                                 stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        child.stdin.write(json.dumps([command]).encode())
        child.stdin.close()
        try:
            # Test-only synchronization with a bounded local child, never a workflow poll.
            deadline = time.monotonic() + 3
            while not (self.root / "shell.pid").exists() and time.monotonic() < deadline:
                time.sleep(0.01)
            pid = int((self.root / "shell.pid").read_text())
            argv = Path(f"/proc/{pid}/cmdline").read_bytes()
            self.assertNotIn(b"private-command-argv-sentinel", argv)
            path = Path(argv.split(b"\0")[-2].decode())
            self.assertEqual(path.stat().st_mode & 0o777, 0o600)
            self.assertEqual(child.wait(timeout=5), 0)
            self.assertFalse(path.exists())
        finally:
            if child.poll() is None:
                child.kill()
                child.wait()
            child.stdout.close()
            child.stderr.close()

    def test_pinned_vp_wrapper_can_close_inherited_descriptors_without_losing_the_private_program(self):
        store = self.root / "vp"
        (store / "bin").mkdir(parents=True)
        wrapper = store / "bin/vp"
        wrapper.write_text(f"#!{sys.executable}\nimport os,sys\nos.closerange(3,256)\n"
                           "args=sys.argv[sys.argv.index('/bin/bash'):]\nos.execv(args[0],args)\n")
        wrapper.chmod(0o755)
        result, _ = self.run_phase(["printf complete > proof"], runtime={"store": str(store),
            "node_version": "24.22.0", "package_manager": "npm", "package_manager_version": "11.12.0"})
        self.assertTrue(result["ready"])
        self.assertEqual((self.root / "proof").read_text(), "complete")

    def test_invalid_dotenv_refuses_before_app_commands(self):
        (self.root / ".env").write_text('TOKEN="unterminated\n')
        result, _ = self.run_phase(["touch forbidden"])
        self.assertEqual(result["error_code"], "instance.step_not_admitted")
        self.assertFalse((self.root / "forbidden").exists())

    def test_native_termination_cannot_leave_a_private_command_file(self):
        command = 'printf "%s" "$0" > command.path; echo $$ > shell.pid; sleep 30 # termination-private-sentinel'
        self.intent["deployment"]["steps"] = [{"name": "proof", "phase": "before_activation", "required": True,
            "timeout_seconds": 35, "command_digest": hashlib.sha256(command.encode()).hexdigest()}]
        request = {"intent": self.intent, "phase": "before_activation", "source": {"commit": self.intent["commit"]}}
        body = ENVIRONMENT.read_text() + "\n" + HERE.joinpath("before_activation.py").read_text()
        child = subprocess.Popen([sys.executable, "-c", body, json.dumps(request)], stdin=subprocess.PIPE,
                                 stdout=subprocess.PIPE, stderr=subprocess.PIPE, start_new_session=True)
        child.stdin.write(json.dumps([command]).encode())
        child.stdin.close()
        try:
            deadline = time.monotonic() + 3
            while not (self.root / "shell.pid").exists() and time.monotonic() < deadline:
                time.sleep(0.01)
            self.assertTrue((self.root / "shell.pid").exists())
            path = Path((self.root / "command.path").read_text())
            self.assertEqual(path.stat().st_mode & 0o777, 0o600)
            os.killpg(child.pid, signal.SIGTERM)
            child.wait(timeout=5)
            self.assertFalse(path.exists())
        finally:
            try:
                os.killpg(child.pid, signal.SIGKILL)
            except ProcessLookupError:
                pass
            child.wait()
            child.stdout.close()
            child.stderr.close()


if __name__ == "__main__":
    unittest.main()
