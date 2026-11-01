"""Disposable official SSH provider lifecycle; never contacts a fleet Node."""
import hashlib
import json
import sys
from pathlib import Path

import yaml
from native_harness import NativeTestCase, ROOT


class TaskVmLifecycleFixture(NativeTestCase):
    native_timeout = 120

    def setUp(self):
        super().setUp()
        self.cli("extension", "pull", "@swamp/ssh@2026.10.23.1")
        self.copy_behaviors("operations/task-vm-lifecycle")
        self.copy_behaviors("operations/node-bootstrap")
        self.state = self.repo / "state.json"
        self.state.write_text(json.dumps({"status": "Stopped", "exists": True}))
        self.calls = self.repo / "calls.jsonl"
        self.block = self.repo / "block"
        self.pending = self.repo / "pending"
        self.lost = self.repo / "lost"
        self.guest = self.repo / "guest"
        (self.guest / ".local/bin").mkdir(parents=True)
        (self.guest / ".local/bin/pi-server").write_bytes(b"fixture-pi")
        fake = self.repo / "ssh"
        fake.write_text("#!" + sys.executable + "\n" + '''import json,shlex,subprocess,sys
from pathlib import Path
args=shlex.split(sys.argv[-1]);body=sys.stdin.buffer.read()
state=Path(STATE);calls=Path(CALLS)
with calls.open('a') as output:output.write(json.dumps({'argv':args,'stdin':body.decode()})+'\\n')
if Path(BLOCK).exists():raise SystemExit(255)
if '/usr/bin/incus' in args:
    value=json.loads(state.read_text());idx=args.index('/usr/bin/incus');action=args[idx+3]
    if action=='storage':
        if 'list' in args: print(json.dumps([{'name':name,'type':'custom'} for name in value.get('volumes',[])]))
        elif 'delete' in args:
            value['volumes']=[name for name in value.get('volumes',[]) if name!=args[-1]];state.write_text(json.dumps(value))
        raise SystemExit(0)
    if action=='exec':
        if '/usr/bin/cat' in args:print('ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIGaNG9xwGkyymXqkaSEKl6+u5VLBHPbi0ga0ya7sdCtG')
        raise SystemExit(0)
    if action=='list':
        print(json.dumps([{'name':'tvm-42','type':'virtual-machine','status':value['status']}] if value['exists'] else []))
    else:
        if not Path(PENDING).exists():
            if action=='start':value['status']='Running'
            elif action=='stop':value['status']='Stopped'
            elif action=='delete':value['exists']=False
            else:raise SystemExit(9)
            state.write_text(json.dumps(value))
        if Path(LOST).exists():raise SystemExit(255)
    raise SystemExit(0)
if args[0]=='/usr/bin/python3':
    if 'urllib.request' in args[-1]:print('{"pi_ready":true}');raise SystemExit(0)
    args[-1]=args[-1].replace('/home/orbit',GUEST)
result=subprocess.run(args,input=body,capture_output=True)
sys.stdout.buffer.write(result.stdout);sys.stderr.buffer.write(result.stderr)
raise SystemExit(result.returncode)
'''.replace("STATE", repr(str(self.state))).replace("CALLS", repr(str(self.calls)))
                        .replace("BLOCK", repr(str(self.block))).replace("PENDING", repr(str(self.pending)))
                        .replace("LOST", repr(str(self.lost))).replace("GUEST", repr(str(self.guest))))
        fake.chmod(0o755)
        for filename in ("workflow-task-vm-provider-power.yaml", "workflow-operation-task-vm-resume.yaml", "workflow-operation-task-park.yaml", "workflow-task-vm-ssh-prepare.yaml"):
            workflow = yaml.safe_load((ROOT / "workflows" / filename).read_text())
            for job in workflow["jobs"]:
                self.assertEqual(job.pop("target"), "orbit-node-209")
                self.assertNotIn("labels", job)
                for step in job["steps"]:
                    self.assertEqual(step.pop("target"), "orbit-node-209")
                    self.assertNotIn("labels", step)
                    task = step["task"]
                    if task.get("behaviorType") == "@swamp/ssh":
                        task["globalArgs"]["sshBinary"] = str(fake)
            (self.repo / "workflows" / filename).write_text(yaml.safe_dump(workflow, sort_keys=False))
        ssh = {"control_node_id": 209, "identity_file": "/run/credentials/orbit-node-worker.service/proxy-ssh-identity", "public_key": "ssh-ed25519 PUBLIC", "target": {"name": "target", "host": "192.0.2.42", "user": "orbit", "port": 22, "host_keys": ["ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIGaNG9xwGkyymXqkaSEKl6+u5VLBHPbi0ga0ya7sdCtG"]}}
        self.provider = {"task_vm_id": 42, "name": "tvm-42", "provider": "incus", "provider_id": None, "vm": {"project": "tasks", "name": "tvm-42", "pool":"tasks"}, "host_ssh_execution": ssh}
        self.envelope = {"operation_id": 7002, "node_id": 99, "kind": "task_vm.resume", "intent": self.provider, "execution_secrets": {}, "action": "start"}
        self.ssh = ssh

    def run_workflow(self, name="task-vm-provider-power", success=True):
        args = ["workflow", "run", name]
        for key, value in self.envelope.items():
            args += ["--input", key + "=" + (value if isinstance(value, str) else json.dumps(value))]
        result = self.cli(*args, success=success)
        return json.loads(result.stdout)



class TaskVmLifecycleNative(TaskVmLifecycleFixture):
    def test_power_start_stop_and_absent_delete_are_idempotent(self):
        self.cli("workflow", "validate", "task-vm-provider-power")
        for action in ("start", "start", "stop", "stop", "delete", "delete"):
            self.envelope["action"] = action
            run = self.run_workflow()
            self.assertEqual(run["status"], "succeeded")
            history = json.loads(self.cli("workflow", "history", "get", run["id"]).stdout)
            receipt = history["jobs"][0]["steps"][-1]["outputs"]
            self.assertEqual(receipt["action"], action)
            self.assertTrue(receipt["settled"])
        calls = [json.loads(row)["argv"] for row in self.calls.read_text().splitlines()]
        self.assertEqual(sum("start" in row for row in calls), 1)
        self.assertEqual(sum("stop" in row for row in calls), 1)
        self.assertEqual(sum("delete" in row for row in calls), 1)

    def test_one_shot_unsettled_fails_and_native_resume_does_not_repeat_start(self):
        self.pending.touch()
        run = self.run_workflow(success=False)
        self.assertEqual(run["status"], "failed")
        self.pending.unlink()
        self.state.write_text(json.dumps({"exists": True, "status": "Running"}))
        resumed = json.loads(self.cli("workflow", "resume", "task-vm-provider-power", "--run", run["id"], "--from", "incus-after", "--job", "provider").stdout)
        self.assertEqual(resumed["status"], "succeeded")
        calls = [json.loads(row)["argv"] for row in self.calls.read_text().splitlines()]
        self.assertEqual(sum("start" in row for row in calls), 1)

    def test_lost_action_reply_uses_actual_power_observation(self):
        self.lost.touch()
        run = self.run_workflow()
        self.assertEqual(run["status"], "succeeded")
        self.assertEqual(json.loads(self.state.read_text())["status"], "Running")

    def test_resume_root_checks_guest_artifact_and_prints_own_stdout(self):
        self.envelope.pop("action")
        self.envelope["intent"] = {"task_vm_id": 42, "guest_node_id": 142, "eligible": True,"mode":"resume", "provider_input": self.provider, "ssh_execution": None, "ssh_identity": {k:v for k,v in self.ssh.items() if k!="target"},"node":{"node_id":142,"wireguard_ip":"10.44.0.142"},"provider":"incus","name":"tvm-42","user":"orbit", "runtime_json": json.dumps({"pi_sha256": hashlib.sha256(b"fixture-pi").hexdigest(), "pi_port": 3774})}
        run = self.run_workflow("operation-task-vm-resume")
        history = json.loads(self.cli("workflow", "history", "get", run["id"]).stdout)
        result = json.loads(history["jobs"][0]["steps"][-1]["outputs"]["stdout"])
        self.assertTrue(result.pop("ssh_host_key")["ssh_host_fingerprint"].startswith("SHA256:"))
        self.assertEqual(result, {"task_vm_id": 42, "guest_node_id": 142, "resumed": True, "ready": True,"ssh_ready":True})
        calls=[json.loads(row)["argv"] for row in self.calls.read_text().splitlines()]
        self.assertTrue(any("openssh-server" in row for row in calls))
        self.assertTrue(any("ssh.service" in row for row in calls))
        self.assertTrue(any(any("authorized_keys" in part for part in row) for row in calls))
        (self.guest / ".local/bin/pi-server").write_bytes(b"bad")
        run = self.run_workflow("operation-task-vm-resume", success=False)
        self.assertEqual(run["status"], "failed")
        self.envelope["intent"]["mode"]="converge"
        run=self.run_workflow("operation-task-vm-resume")
        history=json.loads(self.cli("workflow","history","get",run["id"]).stdout)
        result=json.loads(history["jobs"][0]["steps"][-1]["outputs"]["stdout"])
        self.assertTrue(result["ssh_ready"])
        self.assertTrue(result["converged"])
        self.assertNotIn("resumed",result)

    def test_park_root_preserves_group_receipt_and_original_envelope(self):
        self.envelope.pop("action")
        self.envelope["kind"] = "tasks.park"
        self.envelope["intent"] = {"task_vm_id": 42, "guest_node_id": 142, "group_id": 51, "instance_id": 61, "project_id": 71, "eligible": True, "provider_input": self.provider}
        self.state.write_text(json.dumps({"exists": True, "status": "Running"}))
        run = self.run_workflow("operation-task-park")
        history = json.loads(self.cli("workflow", "history", "get", run["id"]).stdout)
        self.assertEqual(history["inputs"]["node_id"], 99)
        self.assertEqual(history["inputs"]["kind"], "tasks.park")
        result = json.loads(history["jobs"][0]["steps"][-1]["outputs"]["stdout"])
        self.assertEqual(result, {"task_vm_id": 42, "group_id": 51, "instance_id": 61, "project_id": 71, "parked": True, "stop_confirmed": True})
        self.envelope["intent"]["eligible"] = False
        run = self.run_workflow("operation-task-park", success=False)
        self.assertEqual(run["status"], "failed")
