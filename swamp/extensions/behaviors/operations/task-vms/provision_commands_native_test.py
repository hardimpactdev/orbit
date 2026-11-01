"""Official SSH provider DAG from209 with disposable Incus command fixtures."""

import json
import os
from pathlib import Path
import shutil
import sys
import unittest

import yaml

ROOT = Path(__file__).resolve().parents[4]
sys.path.insert(0, str(ROOT / "tests"))
from native_harness import NativeTestCase


FAKE_INCUS = r'''
import json
from pathlib import Path
import sys

path = Path("incus-state.json")
state = json.loads(path.read_text())
args = sys.argv[1:]
state["calls"].append(args)
args = args[2:]
reply = ""
failure = False
if args[0] == "list":
    rows = []
    if state["exists"]:
        rows = [{"name": "tvm-12", "type": "virtual-machine",
                 "status": "Running" if state["running"] else "Stopped",
                 "config": {"volatile.base_image": state["fingerprint"]},
                 "state": {"network": {"eth0": {"addresses": [] if state.get("no_address") else
                    [{"family": "inet", "address": "10.240.9.12"}]}}}}]
    reply = json.dumps(rows)
elif args[:3] == ["storage", "volume", "list"]:
    reply = json.dumps([{"type": "custom", "name": name} for name in state["volumes"]])
elif args[:3] == ["storage", "volume", "copy"]:
    if state.get("clone_failure"):
        failure = True
    else:
        state["volumes"].append("tvm-12-topology-seed")
elif args[0] == "init":
    state["exists"] = True
    state["running"] = False
    state["initial_config"] = json.loads(sys.stdin.read())
elif args[:3] == ["config", "metadata", "show"]:
    reply = "templates:\n  /var/lib/cloud/seed/nocloud-net/user-data: {template: cloud-init.tpl}\n  /etc/hostname: {template: hostname.tpl}\n"
elif args[:3] == ["config", "metadata", "edit"]:
    state["metadata"] = sys.stdin.read()
elif args[0] == "start":
    state["running"] = True
    if state.get("lose_start_reply"):
        state["lose_start_reply"] = False
        failure = True
else:
    raise RuntimeError("Unexpected Incus command: " + repr(args))
path.write_text(json.dumps(state))
print(reply)
sys.exit(1 if failure else 0)
'''


class ProvisionCommands(NativeTestCase):
    def setUp(self):
        super().setUp()
        self.cli("extension", "pull", "@swamp/ssh@2026.10.23.1")
        self.copy_behaviors("operations/task-vm-lifecycle")
        module = self.repo / "extensions/behaviors/operations/task-vms"
        module.mkdir(parents=True)
        shutil.copy(ROOT / "extensions/behaviors/operations/task-vms/provision_inputs.ts", module)
        (self.repo / "fake_incus.py").write_text(FAKE_INCUS)
        fake = self.repo / "ssh"
        fake.write_text("#!" + sys.executable + "\n" + """import shlex,subprocess,sys
args=shlex.split(sys.argv[-1]);body=sys.stdin.buffer.read()
args[-1]=args[-1].replace('/usr/bin/sudo -n -- /usr/bin/incus', REPLACEMENT)
result=subprocess.run(args,input=body,capture_output=True)
sys.stdout.buffer.write(result.stdout);sys.stderr.buffer.write(result.stderr)
raise SystemExit(result.returncode)
""".replace("REPLACEMENT",repr(sys.executable+' '+str(self.repo/'fake_incus.py'))))
        fake.chmod(0o755)
        self.source = yaml.safe_load((ROOT / "workflows/workflow-task-vm-provider-incus.yaml").read_text())
        self.assertEqual(self.source["jobs"][0]["name"], "provider")
        for job in self.source["jobs"]:
            self.assertEqual(job.pop("target"), "orbit-node-209")
            self.assertNotIn("labels", job)
            for step in job["steps"]:
                self.assertEqual(step.pop("target"), "orbit-node-209")
                self.assertNotIn("labels", step)
                task = step["task"]
                if task.get("behaviorType") == "@swamp/ssh":
                    task["globalArgs"]["sshBinary"] = str(fake)
        (self.repo / "workflows/workflow-task-vm-provider-incus.yaml").write_text(yaml.safe_dump(self.source, sort_keys=False))
        self.intent = {
            "host_ssh_execution": {"control_node_id":209,"identity_file":"/run/credentials/orbit-node-worker.service/proxy-ssh-identity","public_key":"ssh-ed25519 PUBLIC","target":{"name":"target","host":"192.0.2.9","user":"orbit","port":22,"host_keys":["ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIGaNG9xwGkyymXqkaSEKl6+u5VLBHPbi0ga0ya7sdCtG"]}},
            "task_vm_id": 12, "node_id": 9, "host_node_id": 9, "guest_node_id": 210,
            "gateway_node_id": 14, "issuer_node_id": 209, "model_router_node_id": 6,
            "provider": "incus", "name": "tvm-12", "image_fingerprint": "a" * 64,
            "recipe_identity": "sha256:" + "b" * 64,
            "vm": {"project": "orbit-task-vms", "name": "tvm-12", "cidr": "10.240.9.0/24"},
            "create": {"image": "a" * 64, "expected_fingerprint": "a" * 64,
                       "gateway_public_key": "ssh-ed25519 " + "a" * 43 + "=", "cpus": 2,
                       "memory": "2GiB", "disk": "20GiB", "network": "orbit-task", "pool": "default", "topology": False},
        }
        self.state = {"calls": [], "exists": False, "running": False,
                      "fingerprint": "a" * 64, "volumes": ["orbit-topology-seed-" + "a" * 12]}
        self.save()

    def save(self):
        (self.repo / "incus-state.json").write_text(json.dumps(self.state))

    def read(self):
        return json.loads((self.repo / "incus-state.json").read_text())

    def run_provider(self, success=True):
        self.cli("workflow", "validate", "task-vm-provider-incus")
        return self.cli("workflow", "run", "task-vm-provider-incus", "--input", json.dumps({
            "operation_id": 7, "kind": "task_vm.provision", "node_id": 9,
            "intent": self.intent}), success=success)

    def test_fresh_provider_creates_and_starts_without_gateway_calls(self):
        self.run_provider()
        state = self.read()
        self.assertTrue(state["exists"])
        self.assertTrue(state["running"])
        self.assertNotIn("cloud-init.tpl", state["metadata"])
        actions = [call[2] for call in state["calls"]]
        self.assertEqual(actions.count("init"), 1)
        self.assertEqual(actions.count("start"), 1)

    def test_provider_fragment_returns_typed_public_state_to_its_parent(self):
        parent = {
            "id": "4c17391e-f057-468c-9a13-cbd977510272",
            "name": "provider-parent",
            "inputs": self.source["inputs"],
            "jobs": [{"name": "create", "steps": [
                {"name": "create", "task": {
                    "type": "workflow", "workflowIdOrName": "task-vm-provider-incus",
                    "inputs": {key: "${{ inputs." + key + " }}"
                               for key in ("operation_id", "kind", "node_id", "intent")}}},
                {"name": "proof", "dependsOn": [{"step": "create", "condition": {"type": "succeeded"}}],
                 "task": {"type": "assert", "expr":
                          'steps.create.outputs.provider.address == "10.240.9.12" && '
                          'steps.create.outputs.provider.settled && '
                          'steps.create.outputs.provider.image_fingerprint == inputs.intent.image_fingerprint',
                          "message": "Provider fragment did not expose settled state", "severity": "high"}}
            ]}]
        }
        (self.repo / "workflows/workflow-provider-parent.yaml").write_text(yaml.safe_dump(parent, sort_keys=False))
        self.cli("workflow", "run", "provider-parent", "--input", json.dumps({
            "operation_id": 7, "kind": "tasks.provision", "node_id": 9, "intent": self.intent}))

    def test_existing_running_vm_skips_mutations(self):
        self.state.update(exists=True, running=True)
        self.save()
        self.run_provider()
        actions = [call[2] for call in self.read()["calls"]]
        self.assertNotIn("init", actions)
        self.assertNotIn("start", actions)
        self.assertFalse(any(call[2:5] == ["config", "metadata", "edit"] for call in self.read()["calls"]))

    def test_foreign_image_fails_before_mutation(self):
        self.state.update(exists=True, fingerprint="c" * 64)
        self.save()
        self.run_provider(success=False)
        self.assertEqual([call[2] for call in self.read()["calls"]], ["list"])

    def test_topology_clone_failure_prevents_vm_initialization(self):
        self.intent["create"]["topology"] = True
        self.state["clone_failure"] = True
        self.save()
        self.run_provider(success=False)
        self.assertFalse(self.read()["exists"])
        self.assertNotIn("init", [call[2] for call in self.read()["calls"]])

    def test_topology_clone_is_attached_to_the_exact_guest(self):
        self.intent["create"]["topology"] = True
        self.run_provider()
        state = self.read()
        self.assertEqual(state["initial_config"]["devices"]["topology-seed"]["source"], "tvm-12-topology-seed")

    def test_pending_address_fails_after_one_final_observation(self):
        self.state["no_address"] = True
        self.save()
        self.run_provider(success=False)
        self.assertLessEqual([call[2] for call in self.read()["calls"]].count("list"), 4)

    def test_native_resume_from_final_observation_reads_a_new_address_without_recreating_vm(self):
        self.state["no_address"] = True
        self.save()
        result = self.run_provider(success=False)
        run_id = json.JSONDecoder().raw_decode(result.stdout.lstrip())[0]["id"]
        self.state = self.read()
        reads = [call[2] for call in self.state["calls"]].count("list")
        self.state["no_address"] = False
        self.save()

        self.cli("workflow", "resume", "task-vm-provider-incus", "--run", run_id,
                 "--from", "inspect-created-vm", "--job", "provider")

        actions = [call[2] for call in self.read()["calls"]]
        self.assertEqual(actions.count("list"), reads + 1)
        self.assertEqual(actions.count("init"), 1)
        self.assertEqual(actions.count("start"), 1)

    def test_native_resume_from_seed_observation_reads_a_repaired_seed_before_initialization(self):
        self.intent["create"]["topology"] = True
        self.state["volumes"] = []
        self.save()
        result = self.run_provider(success=False)
        run_id = json.JSONDecoder().raw_decode(result.stdout.lstrip())[0]["id"]
        self.state = self.read()
        self.assertFalse(self.state["exists"])
        self.state["volumes"] = ["orbit-topology-seed-" + "a" * 12]
        self.save()

        self.cli("workflow", "resume", "task-vm-provider-incus", "--run", run_id,
                 "--from", "observe-seed", "--job", "provider")

        state = self.read()
        self.assertTrue(state["running"])
        self.assertEqual(state["initial_config"]["devices"]["topology-seed"]["source"], "tvm-12-topology-seed")

    def test_native_resume_observes_an_uncertain_start_without_repeating_the_mutation(self):
        self.state["lose_start_reply"] = True
        self.save()
        result = self.run_provider(success=False)
        run_id = json.JSONDecoder().raw_decode(result.stdout.lstrip())[0]["id"]
        self.assertTrue(self.read()["running"])

        self.cli("workflow", "resume", "task-vm-provider-incus", "--run", run_id)

        self.assertEqual([call[2] for call in self.read()["calls"]].count("start"), 1)


if __name__ == "__main__":
    unittest.main()
