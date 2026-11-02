"""Actual native runtime DAG and official SSH, with disposable SSH/SCP process boundaries."""
import hashlib
import json
import sys
from pathlib import Path

import yaml
from native_harness import ROOT, NativeTestCase


class TaskVmRuntimeNative(NativeTestCase):
    native_timeout = 120

    def setUp(self):
        super().setUp()
        self.cli("extension", "pull", "@swamp/ssh@2026.10.23.1")
        self.events = self.repo / "events.jsonl"
        fake = self.repo / "ssh"
        # Stdin is recorded by digest only: the harness fails the test when private bytes reach any scratch file.
        fake.write_text("#!" + sys.executable + "\n" + '''import hashlib,json,os,shlex,sys
from pathlib import Path
argv=shlex.split(sys.argv[-1]);body=sys.stdin.read()
with Path(EVENTS).open('a') as out:out.write(json.dumps({'argv':argv,'stdin':hashlib.sha256(body.encode()).hexdigest() if body else None,'ssh_argv':sys.argv[1:]})+'\\n')
if '/capabilities' in ' '.join(argv) and os.environ['FIXTURE_FAIL']=='true':raise SystemExit(19)
'''.replace("EVENTS", repr(str(self.events))))
        fake.chmod(0o755)
        scp = self.repo / "scp"
        # The fetched build lands where the copy step names it, so the digest check reads real bytes.
        scp.write_text("#!" + sys.executable + "\nimport json,os,sys\nfrom pathlib import Path\nwith Path(" + repr(str(self.events)) + ").open('a') as out:out.write(json.dumps({'copy':sys.argv[1:]})+'\\n')\ndst=sys.argv[-1]\nif ':' not in dst:Path(dst).write_bytes(os.environ['FIXTURE_BUILD'].encode())\n")
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
        for key in ("task-vm-pi-models-42", "task-vm-pi-token-42"):
            self.cli("vault", "put", "orbit-control-inputs", key, input=secret)
        self.secrets = (secret,)
        self.build = "pinned pi build"
        self.native_env.update(FIXTURE_BUILD=self.build, FIXTURE_FAIL="false")
        public = "ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIGaNG9xwGkyymXqkaSEKl6+u5VLBHPbi0ga0ya7sdCtG"
        ssh = {"control_node_id":209,"identity_file":"/etc/orbit/swamp-role-worker/ssh-identity","public_key":public,
               "target":{"name":"target","host":"192.0.2.42","port":22,"user":"orbit","host_keys":[public]}}
        self.inputs = {"operation_id":7005,"node_id":9,"kind":"tasks.provision",
                       "intent":{"task_vm_id":42,"guest_node_id":142,"user":"orbit","ssh_execution":ssh,
                                 "runtime":{"pi_sha256":"a"*64,"origin":"http://192.0.2.6","process":{"process_id":33,"unit_name":"orbit-process-33.service","unit_path":"/etc/systemd/system/orbit-process-33.service"}},
                                 "pi_artifact":{"path":"/cache/pi.bin","sha256":hashlib.sha256(self.build.encode()).hexdigest(),"ssh_execution":ssh},"process_unit":"[Service]\nExecStart=/home/orbit/.local/bin/pi-server\n",
                                 "certificate":"-----BEGIN CERTIFICATE-----\nAA==\n-----END CERTIFICATE-----\n","pi_url":"http://10.44.0.142:3774"},
                       "execution_secrets":{key:{"vault":"orbit-control-inputs","key":name} for key,name in {"pi_models":"task-vm-pi-models-42","pi_token":"task-vm-pi-token-42"}.items()}}

    def run_runtime(self, success=True):
        run = json.loads(self.cli("workflow", "run", "task-vm-runtime", "--input", json.dumps(self.inputs), success=success).stdout)
        history = json.loads(self.cli("workflow", "history", "get", run["id"]).stdout)
        calls = [json.loads(line) for line in self.events.read_text().splitlines()]
        return run, history, calls

    def test_pinned_build_files_and_service_reach_the_guest_through_pinned_ssh(self):
        run, history, calls = self.run_runtime()
        self.assertEqual(run["status"], "succeeded")
        receipt = json.loads(history["jobs"][0]["steps"][-1]["outputs"]["stdout"])
        self.assertEqual(receipt, {"task_vm_id":42,"guest_node_id":142,"process_id":33,"ready":True})
        commands = [call for call in calls if "argv" in call]
        joined = [" ".join(call["argv"]) for call in commands]
        secret = self.secrets[0]
        digest = hashlib.sha256(secret.encode()).hexdigest()
        # Vault values reach the guest only on stdin, into 0600 files of the managed account.
        self.assertEqual(sum(call["stdin"] == digest for call in commands), 2)
        self.assertTrue(any("/home/orbit/.pi/agent/models.json" in line and "0600" in line for line in joined))
        self.assertTrue(any(line.endswith("/dev/stdin /home/orbit/.pi/agent/orbit-token") for line in joined))
        self.assertTrue(any("sha256sum -c" in line and "/home/orbit/.local/bin/pi-server" in line for line in joined))
        # The trust directory may not exist on the image yet.
        self.assertTrue(any("install -D -m 0644 -o root -g root /dev/stdin /usr/local/share/ca-certificates/orbit-root.crt" in line for line in joined))
        self.assertTrue(any("update-ca-certificates" in line for line in joined))
        self.assertTrue(any(line.endswith("/etc/systemd/system/orbit-process-33.service") for line in joined))
        self.assertTrue(any(call["argv"][-1] == "http://10.44.0.142:3774" for call in commands))
        self.assertNotIn(secret, json.dumps(history))
        self.assertFalse(any("python3" in line for line in joined))
        for call in calls:
            if "ssh_argv" in call:
                self.assertIn("StrictHostKeyChecking=yes", call["ssh_argv"])
        self.assertFalse(Path("/tmp/orbit-pi-" + run["id"] + ".bin").exists())

    def test_a_build_with_another_digest_never_reaches_the_guest(self):
        self.native_env["FIXTURE_BUILD"] = "tampered build"
        run, _history, calls = self.run_runtime(success=False)
        self.assertEqual(run["status"], "failed")
        self.assertFalse(any("copy" in call and ":" in call["copy"][-1] for call in calls))
        self.assertFalse(any("argv" in call for call in calls))
        self.assertFalse(Path("/tmp/orbit-pi-" + run["id"] + ".bin").exists())

    def test_failed_probe_cleans_the_stage_and_never_returns_ready(self):
        self.native_env["FIXTURE_FAIL"] = "true"
        run, history, _calls = self.run_runtime(success=False)
        self.assertEqual(run["status"], "failed")
        steps = {step["name"]: step for step in history["jobs"][0]["steps"]}
        self.assertEqual(steps["result"]["status"], "skipped")
        self.assertEqual(steps["Remove the staged build"]["status"], "succeeded")
