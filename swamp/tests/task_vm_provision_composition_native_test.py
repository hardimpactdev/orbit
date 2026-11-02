"""Actual209 parent DAG and official SSH at disposable provider/runtime boundaries."""
import json
import sys
import unittest

import yaml
from native_harness import ROOT, NativeTestCase


class TaskVmProvisionComposition(NativeTestCase):
    native_timeout = 120

    def setUp(self):
        super().setUp()
        self.cli("extension", "pull", "@swamp/ssh@2026.10.23.1")
        self.copy_behaviors("operations/task-vm-lifecycle", "operations/node-peer-command", "operations/node-hub-command", "operations/node-bootstrap")
        self.events = self.repo / "events.jsonl"
        self.verify_fault = self.repo / "verify-fault"
        public = "ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIGaNG9xwGkyymXqkaSEKl6+u5VLBHPbi0ga0ya7sdCtG"
        self.peer = "A" * 43 + "="
        fake = self.repo / "ssh"
        fake.write_text("#!" + sys.executable + "\n" + '''import json,shlex,sys
from pathlib import Path
argv=shlex.split(sys.argv[-1]);body=sys.stdin.read();program=' '.join(argv)+'\\n'+body
with Path(EVENTS).open('a') as output:output.write(json.dumps({'argv':argv,'body':body,'ssh_argv':sys.argv[1:]})+'\\n')
if '/etc/ssh/ssh_host_ed25519_key.pub' in argv:print(PUBLIC)
elif 'observer_ready' in program and 'hashlib.file_digest' in program:print(json.dumps({'observer_ready':True,'observer_digest':'e'*64}))
elif 'def snapshot(data)' in program:print(json.dumps({'directory':'/run/orbit-peer-backup-fixture','present':[],'active':False,'enabled':'disabled','resolver':{},'recorded_resolver':None}))
elif 'def prepare(data)' in program:print(json.dumps({'public_key':PEER}))
elif 'def restore(data)' in program:print('{"restored":true}')
elif 'def cleanup(data)' in program:print('{"cleaned":true}')
elif 'def render(data)' in program:print('{"published":true}')
elif 'def record(data)' in program:print('{"dns_recorded":true}')
elif 'context_node_id' in body:print('{"schema_version":1,"hub_id":1,"context_node_id":142,"projected":true,"restored":false,"changed":true}')
elif 'def verify(data)' in program:
    import io
    from types import SimpleNamespace
    from unittest.mock import patch
    namespace={'__name__':'bootstrap_verify_fixture'}
    exec(argv[-1],namespace)
    user=argv[argv.index('-u')+1] if '-u' in argv else 'root'
    if Path(VERIFY_FAULT).exists():user='foreign'
    account=SimpleNamespace(pw_name=user,pw_gid=1000,pw_dir='/home/'+user)
    with patch('pwd.getpwuid',return_value=account), patch('os.getuid',return_value=1000 if user!='root' else 0), patch('grp.getgrgid',return_value=SimpleNamespace(gr_name=user)), patch('pathlib.Path.open',return_value=io.BytesIO(b'fixture observer')), patch('hashlib.file_digest',return_value=SimpleNamespace(hexdigest=lambda:'b'*64)), patch('subprocess.run') as sudo:
        print(json.dumps(namespace['verify'](namespace['BOOTSTRAP_INPUT'])))
        assert sudo.call_args.args[0]==['/usr/bin/sudo','-n','true']
else:print('{"ready":true}')
'''.replace("EVENTS", repr(str(self.events))).replace("VERIFY_FAULT", repr(str(self.verify_fault))).replace("PUBLIC", repr(public)).replace("PEER", repr(self.peer)))
        fake.chmod(0o755)
        for filename in ("workflow-task-vm-provision.yaml", "workflow-operation-task-vm-provision.yaml", "workflow-task-vm-ssh-prepare.yaml", "workflow-task-vm-incus-first-visit.yaml", "workflow-task-vm-enrollment.yaml"):
            value = yaml.safe_load((ROOT / "workflows" / filename).read_text())
            for job in value["jobs"]:
                self.assertEqual(job.pop("labels"), {"role": "swamp"})
                self.assertNotIn("labels", job)
                for step in job["steps"]:
                    self.assertEqual(step.pop("labels"), {"role": "swamp"})
                    self.assertNotIn("labels", step)
                    task = step["task"]
                    if task.get("behaviorType") == "@swamp/ssh":
                        task["globalArgs"]["sshBinary"] = str(fake)
            (self.repo / "workflows" / filename).write_text(yaml.safe_dump(value, sort_keys=False))
        # Only these effect boundaries are substituted. Their real native suites
        # cover create/power and Pi installation separately; no fleet VM is used.
        self.fixture("task-vm-provider-incus", "provider", {"provider_id": None, "address": "10.240.9.42", "image_fingerprint": "a" * 64, "running": True, "settled": True})
        self.fixture("task-vm-model-issue", "result", {"task_vm_id": 42, "issued": True}, stdout=True)
        self.fixture("task-vm-model-revoke", "result", {"task_vm_id": 42, "revoked": True}, stdout=True)
        self.fixture("task-vm-runtime", "result", {"task_vm_id": 42, "guest_node_id": 142, "process_id": 33, "ready": True}, stdout=True)
        identity = {"control_node_id": 209, "identity_file": "/etc/orbit/swamp-role-worker/ssh-identity", "public_key": public}
        ssh = {**identity, "target": {"name": "target", "host": "192.0.2.9", "user": "orbit", "port": 22, "host_keys": [public]}}
        self.intent = {"eligible": True, "task_vm_id": 42, "guest_node_id": 142, "provider": "incus", "name": "tvm-42", "user": "orbit",
                       "vm": {"project": "tasks", "name": "tvm-42"}, "ssh_identity": identity, "host_ssh_execution": ssh, "hub_ssh_execution": ssh,
                       "node": {"node_id": 142, "wireguard_ip": "10.44.0.142"},
                       "runtime": {"process": {"process_id": 33}}, "bootstrap": {"user": "orbit", "public_key": public, "releases": ["resolute"], "packages": ["wireguard-tools"], "dns_policy": "managed"},
                       "agent_sha256": "b" * 64, "agent_artifact_url": "https://example.test/observer", "observer_digest_json": "null",
                       "agent": {"configuration": "public configuration", "certificate": "public certificate", "unit": "public unit"},
                       "network_input": {"existing_key": None, "peer": {"server_public_key": self.peer, "endpoint": "192.0.2.1:51820", "address": "10.44.0.142/16", "subnet": "10.44.0.0/16", "dns_server": "10.44.0.1", "domains": ["orbit"], "operator_dns": None, "retain": False}},
                       "hub_input": {"schema_version": 1, "hub_id": 1, "hub_address": "10.44.0.1/16", "port": 51820, "context_node_id": 142, "inventory": [], "before": None, "desired": {"node_id": 142, "name": "tvm-42", "address": "10.44.0.142", "public_key": None}},
                       "pi_artifact": {"path": "/tmp/pi", "sha256": "a" * 64, "ssh_execution": ssh}, "process_unit": "public unit", "models_json": "{}", "certificate_json": "{}", "runtime_json": "{}",
                       "model_intent": {"task_vm_id": 42, "gateway_node_id": 14, "model_router_node_id": 6, "model_origin": "http://192.0.2.6"}}
        self.inputs = {"operation_id": 7005, "node_id": 9, "kind": "task_vm.provision", "intent": self.intent,
                       "execution_secrets": {key: {"vault": "orbit-control-inputs", "key": name} for key, name in {"model_key": "task-vm-model-key-42", "pi_token": "task-vm-pi-token-42", "model_router_management": "model-router-management-node-6", "model_router_anchor": "model-router-anchor-node-6"}.items()}}

    def fixture(self, name, step, value, stdout=False):
        workflow = {"id": str(__import__("uuid").uuid4()), "name": name, "inputs": {"type": "object", "additionalProperties": True}, "jobs": [{"name": "fixture", "steps": [{"name": step, "task": {"type": "behavior", "behaviorType": "command/shell", "behaviorName": name + "-fixture", "methodName": "execute", "inputs": {"run": "printf '%s\\n' \"$PUBLIC\"", "env": {"PUBLIC": json.dumps(value)}}}}]}]}
        if not stdout:
            module = self.repo / "extensions/behaviors/fixtures"
            module.mkdir(parents=True, exist_ok=True)
            (module / (name + ".ts")).write_text('import {z} from "npm:zod@4.4.3";export const behavior={type:"@nckrtl/' + name + '-fixture",version:"2026.11.01.1",resources:{value:{schema:z.record(z.string(),z.unknown()),lifetime:"infinite",garbageCollection:1}},methods:{read:{description:"Public fixture",arguments:z.object({}),async execute(a,ctx){return{dataHandles:[await ctx.writeResource("value","value",' + json.dumps(value) + ')]};}}}};')
            workflow["jobs"][0]["steps"][0]["task"] = {"type": "behavior", "behaviorType": "@nckrtl/" + name + "-fixture", "behaviorName": name + "-fixture", "methodName": "read", "inputs": {}}
        (self.repo / "workflows" / ("workflow-" + name + ".yaml")).write_text(yaml.safe_dump(workflow, sort_keys=False))

    def run_root(self, success=True, *args):
        result = self.cli("workflow", "run", "operation-task-vm-provision", "--input", json.dumps(self.inputs), *args, success=success)
        return json.loads(result.stdout)

    def test_same_root_complete_public_receipt_and209_ssh_order(self):
        self.cli("workflow", "validate", "operation-task-vm-provision")
        run = self.run_root()
        history = json.loads(self.cli("workflow", "history", "get", run["id"]).stdout)
        self.assertEqual(history["inputs"]["node_id"], 9)
        result = json.loads(history["jobs"][0]["steps"][-1]["outputs"]["stdout"])
        self.assertEqual(result["task_vm_id"], 42)
        self.assertTrue(result["ready"])
        self.assertEqual(result["enrollment"]["node"]["wireguard_public_key"], self.peer)
        self.assertEqual(result["enrollment"]["node"]["observer_digest"], "e" * 64)
        calls = self.events.read_text()
        self.assertNotIn("worker connect", calls)
        self.assertNotIn("GatewayOperation", calls)
        self.assertIn("10.44.0.142", calls)
        self.assertIn("openssh-server", calls)
        verify = next(json.loads(line) for line in calls.splitlines() if "def verify(data)" in line)
        self.assertEqual(verify["argv"][-9:-1], ["/usr/bin/sudo", "-n", "-u", "orbit", "--", "/usr/bin/python3", "-B", "-c"])
        self.assertIn("10.44.0.15", calls)
        self.assertLess(calls.index("wg-quick@orbit.service"), calls.index("orbit-agent.service", calls.index("wg-quick@orbit.service")))

    def test_foreign_bootstrap_account_fails_before_network_or_runtime(self):
        self.verify_fault.touch()
        run = self.run_root(success=False)
        self.assertEqual(run["status"], "failed")
        calls = self.events.read_text()
        self.assertIn("def verify(data)", calls)
        self.assertNotIn("def snapshot(data)", calls)

    def test_unavailable_upcloud_refuses_before_provider_or_guest_actions(self):
        self.intent["provider"] = "upcloud"
        run = self.run_root(success=False)
        self.assertEqual(run["status"], "failed")
        self.assertFalse(self.events.exists())

    def test_foreign_runtime_receipt_never_becomes_ready(self):
        self.fixture("task-vm-runtime", "result", {"task_vm_id": 43, "guest_node_id": 142, "process_id": 33, "ready": True}, stdout=True)
        run = self.run_root(success=False)
        self.assertEqual(run["status"], "failed")
        calls = [json.loads(line) for line in self.events.read_text().splitlines()]
        self.assertTrue(any('"restoring":true' in call["body"] or '\\"restoring\\":true' in call["body"] for call in calls))
        self.assertTrue(any("def restore(data)" in " ".join(call["argv"]) for call in calls))
        self.assertFalse(any("def cleanup(data)" in " ".join(call["argv"]) for call in calls))

    def test_native_cancellation_restores_published_network_without_ready_receipt(self):
        path = self.repo / "workflows/workflow-task-vm-runtime.yaml"
        fixture = yaml.safe_load(path.read_text())
        fixture["jobs"][0]["steps"][0]["task"]["inputs"]["run"] = "sleep 30\nprintf '%s\\n' \"$PUBLIC\""
        path.write_text(yaml.safe_dump(fixture, sort_keys=False))
        run = self.run_root(False, "--timeout", "15s")
        self.assertEqual(run["status"], "cancelled")
        calls = [json.loads(line) for line in self.events.read_text().splitlines()]
        self.assertTrue(any("def restore(data)" in " ".join(call["argv"]) for call in calls))
        self.assertTrue(any('\\"restoring\\":true' in call["body"] for call in calls))


class TaskProvisionQueueBudget(unittest.TestCase):
    def test_tasks_provision_default_queue_budget_covers_loaded_dispatch(self):
        workflow = yaml.safe_load((ROOT / "workflows/workflow-operation-task-provision.yaml").read_text())
        self.assertEqual(workflow["queueTimeout"], 30)
