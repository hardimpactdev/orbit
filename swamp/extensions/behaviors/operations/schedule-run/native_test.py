"""Run the production Schedule DAG with official SSH and harmless local transport fixtures."""

import grp
import hashlib
import importlib.util
import json
import os
import pwd
import shutil
import sys
import uuid
from pathlib import Path

import yaml

ROOT = Path(__file__).resolve().parents[4]
spec = importlib.util.spec_from_file_location(
    "native_harness", ROOT / "tests/native_harness.py"
)
assert spec and spec.loader
harness = importlib.util.module_from_spec(spec)
spec.loader.exec_module(harness)


class ScheduleSshFixture(harness.NativeTestCase):
    def setUp(self):
        super().setUp()
        self.copy_behaviors("operations/schedule-run")
        contract = (
            self.repo
            / "extensions/behaviors/operations/processes-schedules/contract.ts"
        )
        contract.parent.mkdir(parents=True)
        shutil.copy(
            ROOT / "extensions/behaviors/operations/processes-schedules/contract.ts",
            contract,
        )
        shutil.copy(
            ROOT
            / "extensions/behaviors/operations/processes-schedules/unit-templates.json",
            contract.parent,
        )
        official = self.repo / ".swamp/pulled-extensions/@swamp/ssh"
        official.parent.mkdir(parents=True)
        shutil.copytree(ROOT / ".swamp/pulled-extensions/@swamp/ssh", official)
        inventory = json.loads(
            (ROOT / "extensions/models/upstream_extensions.json").read_text()
        )
        path = self.repo / "extensions/models/upstream_extensions.json"
        path.parent.mkdir(parents=True)
        path.write_text(json.dumps({"@swamp/ssh": inventory["@swamp/ssh"]}))
        self.calls = self.repo / "calls.jsonl"
        self.journal = self.repo / "journal"
        self.logger = self.repo / "journal-fixture"
        self.logger.write_text(
            "#!"
            + sys.executable
            + "\nimport sys\nfrom pathlib import Path\nPath("
            + repr(str(self.journal))
            + ").write_bytes(sys.stdin.buffer.read())\n"
        )
        self.logger.chmod(0o755)
        self.ssh = self.repo / "ssh-fixture"
        self.ssh.write_text(
            "#!"
            + sys.executable
            + "\n"
            + """import json,shlex,subprocess,sys
from pathlib import Path
args=sys.argv[1:]
if '-M' in args or '-O' in args:raise SystemExit(0)
argv=shlex.split(args[-1])
with Path(CALLS).open('a') as f:f.write(json.dumps(argv)+'\\n')
if argv[0]=='/usr/bin/sudo':argv=argv[argv.index('--')+1:]
argv=[a.replace('/usr/bin/systemd-cat',LOGGER) for a in argv]
r=subprocess.run(argv,input=sys.stdin.buffer.read(),capture_output=True)
sys.stdout.buffer.write(r.stdout);sys.stderr.buffer.write(r.stderr)
raise SystemExit(r.returncode)
""".replace("CALLS", repr(str(self.calls))).replace("LOGGER", repr(str(self.logger)))
        )
        self.ssh.chmod(0o755)
        self.scp = self.repo / "scp-fixture"
        self.scp.write_text(
            "#!"
            + sys.executable
            + '\nimport shutil,sys\nfrom pathlib import Path\nsrc=Path(sys.argv[-2]);dst=Path(sys.argv[-1].split(":",1)[1])\nshutil.copytree(src,dst/src.name,dirs_exist_ok=True)\n'
        )
        self.scp.chmod(0o755)
        (self.repo / "existing-key").write_text("fixture identity not consumed")
        self.workflow = yaml.safe_load(
            (ROOT / "workflows/workflow-operation-schedule-run.yaml").read_text()
        )
        for job in self.workflow["jobs"]:
            self.assertEqual(job.pop("labels"), {"role": "swamp"})
            self.assertNotIn("labels", job)
            for step in job["steps"]:
                self.assertEqual(step.pop("labels"), {"role": "swamp"})
                self.assertNotIn("labels", step)
                if step["task"]["behaviorType"] == "@swamp/ssh":
                    step["task"]["globalArgs"].update(
                        sshBinary=str(self.ssh), scpBinary=str(self.scp)
                    )
        self.path = self.repo / "workflows/workflow-operation-schedule-run.yaml"
        self.path.write_text(
            yaml.safe_dump(self.workflow, sort_keys=False).replace(
                "/tmp/orbit-schedule-run-", str(self.repo / "assets-")
            )
        )
        account = pwd.getpwuid(os.getuid())
        self.inputs = {
            "operation_id": 17,
            "node_id": 9,
            "kind": "schedule.run",
            "intent": {
                "schedule": {
                    "id": "123e4567-e89b-42d3-a456-426614174000",
                    "user": account.pw_name,
                    "group": grp.getgrgid(account.pw_gid).gr_name,
                    "home": account.pw_dir,
                    "shell": account.pw_shell,
                    "working_directory": str(self.repo),
                    "login_shell": False,
                    "timeout_seconds": 2,
                    "command_hash": "a" * 64,
                },
                "ssh_execution": {
                    "control_node_id": 209,
                    "identity_file": str(self.repo / "existing-key"),
                    "public_key": "ssh-ed25519 public-fixture",
                    "target": {
                        "name": "target",
                        "host": "198.51.100.9",
                        "user": "orbit",
                        "port": 22,
                        "host_keys": [
                            "ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIJ111111111111111111111111111111111111111111"
                        ],
                    },
                },
            },
            "execution_secrets": {
                "command": {
                    "vault": "orbit-control-inputs",
                    "key": "schedule-run-operation-17",
                }
            },
        }
        self.cli("vault", "create", "local_encryption", "orbit-control-inputs")
        self.set_command("printf schedule-journal-" + uuid.uuid4().hex)

    def set_command(self, command):
        self.secrets = (command,)
        self.inputs["intent"]["schedule"]["command_hash"] = hashlib.sha256(
            command.encode()
        ).hexdigest()
        self.cli(
            "vault",
            "put",
            "orbit-control-inputs",
            "schedule-run-operation-17",
            input=command,
        )

    def run_schedule(self, success=True):
        reply = self.cli(
            "workflow",
            "run",
            "operation-schedule-run",
            "--stdin",
            input=json.dumps(self.inputs),
            success=success,
        )
        decoder = json.JSONDecoder()
        for n, char in enumerate(reply.stdout):
            if char == "{":
                try:
                    self.run = decoder.raw_decode(reply.stdout[n:])[0]
                    return self.run
                except ValueError:
                    pass
        self.fail("No native result")

    def history(self):
        return json.loads(self.cli("workflow", "history", "get", self.run["id"]).stdout)

    def result(self):
        step = next(
            s for s in self.history()["jobs"][0]["steps"] if s["name"] == "result"
        )
        return json.loads(step["outputs"]["stdout"])


class ScheduleRunNative(ScheduleSshFixture):
    def test_command_uses_only_stdin_and_journals_output_before_public_success(self):
        run = self.run_schedule()
        self.assertEqual(run["status"], "succeeded")
        self.assertEqual(self.result(), {"ready": True})
        self.assertTrue(self.journal.read_text().startswith("schedule-journal-"))
        calls = [json.loads(line) for line in self.calls.read_text().splitlines()]
        execution = next(a for a in calls if "/usr/bin/timeout" in a)
        self.assertIn("--chdir=" + str(self.repo), execution)
        self.assertIn("pipefail", execution)
        self.assertEqual(execution[-1], "-s")
        self.assertFalse(list(self.repo.glob("assets-*")))
        self.assert_private_absent(json.dumps(self.history()), self.calls.read_text())

    def test_failed_command_and_timeout_return_bounded_errors_without_business_output(
        self,
    ):
        for command in ["printf private-output; exit 1", "sleep 3"]:
            with self.subTest(command=command):
                self.set_command(command + " # " + uuid.uuid4().hex)
                self.run_schedule(success=False)
                self.assertEqual(self.result(), {"error_code": "schedule.run_failed"})
                self.assert_private_absent(json.dumps(self.history()))

    def test_wrong_hash_skips_execution_and_retains_assets(self):
        self.inputs["intent"]["schedule"]["command_hash"] = "a" * 64
        self.run_schedule(success=False)
        self.assertEqual(self.result(), {"error_code": "schedule.artifact_conflict"})
        self.assertFalse(self.journal.exists())
        self.assertTrue(list(self.repo.glob("assets-*")))

    def test_native_resume_reuses_assets_after_directory_recovery(self):
        directory = self.repo / "unavailable-directory"
        self.inputs["intent"]["schedule"]["working_directory"] = str(directory)
        self.run_schedule(success=False)
        self.assertEqual(self.result(), {"error_code": "schedule.target_unavailable"})
        directory.mkdir()
        self.cli(
            "workflow",
            "resume",
            "operation-schedule-run",
            "--run",
            self.run["id"],
            "--from",
            "directory",
        )
        self.assertEqual(self.result(), {"ready": True})
        self.assertTrue(self.journal.exists())
        self.assertFalse(list(self.repo.glob("assets-*")))

    def test_unavailable_context_or_directory_refuses_before_command(self):
        for field, value in [
            ("home", "/foreign-home"),
            ("working_directory", "/does-not-exist"),
        ]:
            with self.subTest(field=field):
                original = self.inputs["intent"]["schedule"][field]
                self.inputs["intent"]["schedule"][field] = value
                self.run_schedule(success=False)
                self.assertEqual(
                    self.result(), {"error_code": "schedule.target_unavailable"}
                )
                self.assertFalse(self.journal.exists())
                self.inputs["intent"]["schedule"][field] = original

    def test_login_shell_and_upper_timeout_are_native_public_arguments(self):
        self.inputs["intent"]["schedule"].update(
            login_shell=True, timeout_seconds=86400
        )
        self.run_schedule()
        execution = next(
            json.loads(line)
            for line in self.calls.read_text().splitlines()
            if "/usr/bin/timeout" in line
        )
        self.assertIn("86400s", execution)
        self.assertEqual(execution[-2:], ["--login", "-s"])
