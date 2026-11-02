"""Actual native runtime DAG and official SSH, with disposable SSH/SCP process boundaries."""
import hashlib
import json
import sys

import yaml

from native_harness import NativeTestCase, ROOT


class TaskVmRuntimeNative(NativeTestCase):
    native_timeout = 120

    def setUp(self):
        super().setUp()
        self.cli("extension", "pull", "@swamp/ssh@2026.10.23.1")
        self.copy_behaviors("operations/task-vm-pi")
        self.events = self.repo / "events.jsonl"
        fake = self.repo / "ssh"
        fake.write_text("#!" + sys.executable + "\n" + '''import hashlib,json,os,shlex,sys
from pathlib import Path
argv=shlex.split(sys.argv[-1]);body=sys.stdin.read()
private=any(name in ' '.join(argv) for name in ('pi_model.py','pi_token.py'))
if private and hashlib.sha256(body.encode()).hexdigest()!=os.environ['FIXTURE_SECRET_SHA']:raise SystemExit(17)
with Path(EVENTS).open('a') as out:out.write(json.dumps({'argv':argv,'private_stdin':private,'ssh_argv':sys.argv[1:]})+'\\n')
if any('pi_inspect.py' in arg for arg in argv):print(json.dumps({'task_vm_id':42,'sha256':'a'*64,'current':os.environ['FIXTURE_CURRENT']=='true'}))
elif '/capabilities' in ' '.join(argv) and os.environ['FIXTURE_FAIL']=='true':raise SystemExit(19)
else:print('{"ready":true}')
'''.replace("EVENTS", repr(str(self.events))))
        fake.chmod(0o755)
        scp = self.repo / "scp"
        scp.write_text("#!" + sys.executable + "\nimport json,sys\nfrom pathlib import Path\nwith Path(" + repr(str(self.events)) + ").open('a') as out:out.write(json.dumps({'copy':sys.argv[1:]})+'\\n')\n")
        scp.chmod(0o755)
        workflow = yaml.safe_load((ROOT / "workflows/workflow-task-vm-runtime.yaml").read_text())
        for job in workflow["jobs"]:
            self.assertEqual(job.pop("labels"), {"role": "swamp"})
            for step in job["steps"]:
                self.assertEqual(step.pop("labels"), {"role": "swamp"})
                task = step["task"]
                if task.get("behaviorType") == "@swamp/ssh":
                    task["globalArgs"]["sshBinary"] = str(fake)
                    task["globalArgs"]["scpBinary"] = str(scp)
        (self.repo / "workflows/workflow-task-vm-runtime.yaml").write_text(yaml.safe_dump(workflow, sort_keys=False))
        self.cli("vault", "create", "local_encryption", "orbit-control-inputs")
        secret = "runtime_native_private_fixture_" + "c" * 32
        for key in ("task-vm-model-key-42", "task-vm-pi-token-42"):
            self.cli("vault", "put", "orbit-control-inputs", key, input=secret)
        self.secrets = (secret,)
        self.native_env.update(FIXTURE_SECRET_SHA=hashlib.sha256(secret.encode()).hexdigest(), FIXTURE_CURRENT="false", FIXTURE_FAIL="false")
        public = "ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIGaNG9xwGkyymXqkaSEKl6+u5VLBHPbi0ga0ya7sdCtG"
        ssh = {"control_node_id":209,"identity_file":"/etc/orbit/swamp-role-worker/ssh-identity","public_key":public,
               "target":{"name":"target","host":"192.0.2.42","port":22,"user":"orbit","host_keys":[public]}}
        self.inputs = {"operation_id":7005,"node_id":9,"kind":"tasks.provision",
                       "intent":{"task_vm_id":42,"guest_node_id":142,"user":"orbit","ssh_execution":ssh,
                                 "runtime":{"pi_sha256":"a"*64,"origin":"http://192.0.2.6","process":{"process_id":33,"unit_name":"orbit-process-33.service","unit_path":"/etc/systemd/system/orbit-process-33.service"}},
                                 "pi_artifact":{"path":"/cache/pi.bin","sha256":"a"*64,"ssh_execution":ssh},"process_unit":"[Service]\nExecStart=/home/orbit/.local/bin/pi-server\n","models_json":"[]","certificate_json":"{}","runtime_json":"{\"pi_port\":3775}"},
                       "execution_secrets":{key:{"vault":"orbit-control-inputs","key":name} for key,name in {"model_key":"task-vm-model-key-42","pi_token":"task-vm-pi-token-42"}.items()}}

    def run_runtime(self, success=True):
        run = json.loads(self.cli("workflow", "run", "task-vm-runtime", "--input", json.dumps(self.inputs), success=success).stdout)
        history = json.loads(self.cli("workflow", "history", "get", run["id"]).stdout)
        calls = [json.loads(line) for line in self.events.read_text().splitlines()]
        return run, history, calls

    def test_public_artifact_unit_pin_and_vault_inputs_reach_official_ssh(self):
        run, history, calls = self.run_runtime()
        self.assertEqual(run["status"], "succeeded")
        receipt = json.loads(history["jobs"][0]["steps"][-1]["outputs"]["stdout"])
        self.assertEqual(receipt, {"task_vm_id":42,"guest_node_id":142,"process_id":33,"ready":True})
        self.assertEqual(sum(call.get("private_stdin", False) for call in calls), 2)
        self.assertEqual(sum("copy" in call for call in calls), 3)
        commands = [call["argv"] for call in calls if "argv" in call]
        self.assertTrue(any(argv[-2:] == ["enable","orbit-process-33.service"] for argv in commands))
        self.assertTrue(any("/etc/systemd/system/orbit-process-33.service" in argv for argv in commands))
        self.assertTrue(any(argv[:3] == ["sudo","-n","--"] and "/usr/bin/rm" in argv for argv in commands))
        for call in calls:
            if "ssh_argv" in call:
                self.assertIn("StrictHostKeyChecking=yes", call["ssh_argv"])
        self.assertFalse(any(__import__('pathlib').Path('/tmp').glob('orbit-task-vm-runtime-' + run['id'])))

    def test_current_artifact_skips_binary_copies_but_configures_process(self):
        self.native_env["FIXTURE_CURRENT"] = "true"
        run, _, calls = self.run_runtime()
        self.assertEqual(run["status"], "succeeded")
        self.assertEqual(sum("copy" in call for call in calls), 1)
        self.assertEqual(sum(call.get("private_stdin", False) for call in calls), 2)

    def test_failed_probe_runs_cleanup_and_never_returns_ready(self):
        self.native_env["FIXTURE_FAIL"] = "true"
        run, history, calls = self.run_runtime(success=False)
        self.assertEqual(run["status"], "failed")
        steps = {step["name"]: step for step in history["jobs"][0]["steps"]}
        self.assertEqual(steps["result"]["status"], "skipped")
        self.assertEqual(steps["cleanup-local"]["status"], "succeeded")
        self.assertEqual(steps["cleanup-guest"]["status"], "succeeded")
