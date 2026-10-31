"""Real native parent DAG tests using the one shared scratch harness."""
import json
from pathlib import Path
import sys
import unittest
import yaml

ROOT = Path(__file__).resolve().parents[4]
sys.path.insert(0, str(ROOT / "tests"))
from native_harness import NativeTestCase


class ProvisionNative(NativeTestCase):
    def setUp(self):
        super().setUp()
        self.copy_behaviors("automatic-deploy", "operation-runner", "operations", "node-worker")
        module=self.repo / "extensions/behaviors/operations/task-vms/provision_direct.ts"
        module.write_text('import {fixtureHttp,fixtureActions} from "./provision_native_fixture.ts";\n'+module.read_text().replace('export const behavior = nativeProvision(undefined, provisionNativeActions());','export const behavior = nativeProvision(() => fixtureHttp,fixtureActions);'))
        (self.repo/"extensions/behaviors/operations/task-vms/model_keys_native.ts").write_text('import {fixtureModelKeys} from "./provision_native_fixture.ts"; export const behavior=fixtureModelKeys;\n')
        failure=self.repo/"extensions/behaviors/operations/failure.ts"
        failure.write_text('import {fixtureHttp} from "./task-vms/provision_native_fixture.ts";\n'+failure.read_text().replace('export const behavior = nativeFailure();','export const behavior = nativeFailure(() => fixtureHttp);'))
        (self.repo/"extensions/behaviors/operations/node/wireguard_direct.ts").write_text('import {fixtureHub} from "../task-vms/provision_native_fixture.ts"; export const behavior=fixtureHub;\n')
        source=yaml.safe_load((ROOT/"workflows/workflow-operation-task-vm-provision.yaml").read_text())
        self.assertEqual([job["name"] for job in source["jobs"]],["admission","provider","bootstrap","prepare-network","publish-hub","publish-network","issue-worker","configure-worker","worker-ready","issue-model","prepare-runtime","confirm-runtime","projection","failure"])
        for job in source["jobs"]:
            stage=job["name"]
            node='steps.admit.outputs.input.guest_node_id' if stage in ['worker-ready','prepare-runtime','confirm-runtime'] else 'steps.admit.outputs.input.gateway_node_id' if stage in ['publish-hub','issue-model'] else 'steps.admit.outputs.input.issuer_node_id' if stage=='issue-worker' else 'inputs.node_id' 
            self.assertEqual(job.pop("target"),'${{ "orbit-node-" + string('+node+') }}')
            self.assertEqual(job.pop("labels"),{"node":'${{ string('+node+') }}'})
        (self.repo/"workflows/workflow-operation-task-vm-provision.yaml").write_text(yaml.safe_dump(source,sort_keys=False))
        self.state={"calls":[],"stages":[],"operation":{"id":7,"kind":"task_vm.provision","node_id":13,"status":"queued","cancel_requested":False,"input":{
            "eligible":True,"upcloud":None,"task_vm_id":5,"node_id":13,"host_node_id":13,"gateway_node_id":14,"guest_node_id":208,"issuer_node_id":209,"participant_node_ids":[13,14,209,208,1],"private_bindings":{},"enrollment":{"task_vm_id":5,"guest_node_id":208,"execution_node_id":13,"provider":"incus","name":"tvm-5","agent_sha256":"c"*64,"node":{"node_id":208,"name":"tvm-5","user":"orbit","wireguard_ip":"10.44.0.208"},"wireguard_public_key":None},"name":"tvm-5","provider":"incus","provider_id":None,
            "wireguard_ip":"10.44.0.208","image_fingerprint":"a"*64,"recipe_identity":"sha256:"+"b"*64,"agent_sha256":"c"*64,"deadline_at":"2286-11-20T17:46:39+00:00","gateway_public_key":"ssh-ed25519 fixture",
            "vm":{"project":"orbit-tasks","name":"tvm-5","cidr":"10.251.77.0/24"},
            "create":{"kind":"create","image":"a"*64,"expected_fingerprint":"a"*64,"base_alias":"orbit-task-base","gateway_public_key":"ssh-ed25519 fixture","cpus":2,"memory":"4GiB","disk":"20GiB","network":"orbittask0","pool":"orbit-tasks","topology":False}}}}
        self.state["operation"]["input"]["enrollment"]={'task_vm_id': 5, 'guest_node_id': 208, 'execution_node_id': 13, 'gateway_node_id': 14, 'issuer_node_id': 209, 'provider': 'incus', 'name': 'tvm-5', 'user': 'orbit', 'agent_sha256': 'cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc', 'agent_artifact_url': 'https://artifacts.example/observer', 'vm': {'project': 'orbit-tasks', 'name': 'tvm-5'}, 'agent': {'configuration': 'gateway_url="https://gateway.orbit"\n', 'certificate': 'public-ca', 'unit': 'public-unit', 'checkouts': []}, 'bootstrap': {}, 'node': {'node_id': 208, 'name': 'tvm-5', 'user': 'orbit', 'wireguard_ip': '10.44.0.208'}, 'network': {'server_public_key': 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb=', 'endpoint': 'vpn.example:51820', 'address': '10.44.0.208/24', 'subnet': '10.44.0.0/24', 'dns_server': '10.44.0.1', 'domains': ['orbit']}, 'wireguard_public_key': None, 'pi_url': 'http://10.44.0.208:3774', 'runtime': {'guest_node_id': 208, 'user': 'orbit', 'agent_sha256': 'cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc', 'pi_sha256': 'eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee', 'origin': 'http://10.44.0.3:8317', 'models': ['fixture'], 'process': {'process_id': 7, 'name': 'orbit-process-7-pi-server.service', 'runtime': 'systemd', 'agent_sha256': 'cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc', 'content_hash': 'ffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffff', 'desired': 'running', 'on_demand': False, 'activation_pending': False, 'retired': None}}}
        self.state["operation"]["input"]["enrollment"].update(model_origin="http://10.44.0.3:8317", model_router_node_id=17)
        self.state["operation"]["input"].update(model_origin="http://10.44.0.3:8317", model_router_node_id=17)
        hub={"schema_version":1,"executor_node_id":14,"hub_id":1,"context_node_id":208,"excluded_node_id":None,"agent_sha256":"c"*64}
        self.state["operation"]["input"]["_wireguard"]=hub
        self.state["operation"]["input"]["enrollment"]["_wireguard"]=hub
        self.save()

    def save(self):
        (self.repo/"provision-fixture.json").write_text(json.dumps(self.state))

    def read(self):
        return json.loads((self.repo/"provision-fixture.json").read_text())

    def run_root(self,success=True):
        self.cli("workflow","validate","operation-task-vm-provision")
        result=self.cli("workflow","run","operation-task-vm-provision","--input",json.dumps({"operation_id":7,"node_id":13,"kind":"task_vm.provision"}),success=success)
        return json.JSONDecoder().raw_decode(result.stdout.lstrip())[0]["id"]

    def test_provider_then_bootstrap_then_exact_guest_then_runtime(self):
        self.run_root()
        saved=self.read()
        expected=[("provider",13),("bootstrap",13),("prepare-network",13),("publish-hub",14),("publish-network",13),("issue-worker",209),("configure-worker",13),("worker-ready",208),("issue-model",14),("prepare-runtime",208),("confirm-runtime",208)]
        self.assertEqual(saved["stages"],[{"stage":stage,"node_id":node} for stage,node in expected])
        self.assertTrue(saved["hubSawRootKey"])
        self.assertTrue(saved["networkCheckpoint"])
        network_posts=[call for call in saved["calls"] if call["method"]=="POST" and (call.get("result") or {}).get("node",{}).get("network_enrolled")]
        self.assertEqual(len(network_posts),1)
        self.assertEqual(saved["operation"]["status"],"succeeded")
        self.assertEqual(saved["operation"]["result"]["guest_node_id"],208)
        self.assertEqual(saved["operation"]["result"]["enrollment"],{"ready":True,"node":{"address":"10.251.77.2","wireguard_public_key":"a"*43+"="},"process":{"status":"active"}})
        key_posts=[call for call in saved["calls"] if call["method"]=="POST" and (call.get("result") or {}).get("node",{}).get("wireguard_public_key")]
        self.assertEqual(len(key_posts),1)
        self.assertTrue(all('/direct-operations/7' in call['path'] for call in saved['calls']))

    def test_bootstrap_failure_skips_guest_and_runtime(self):
        self.state["failStage"]="bootstrap"; self.save()
        self.run_root(success=False)
        saved=self.read()
        self.assertEqual([item["stage"] for item in saved["stages"]],["provider","bootstrap"])
        self.assertEqual(saved["operation"]["status"],"failed")

    def test_hub_failure_stops_peer_and_worker_steps(self):
        self.state["failStage"]="publish-hub"; self.save()
        self.run_root(success=False)
        saved=self.read()
        self.assertNotIn("publish-network",[item["stage"] for item in saved["stages"]])
        self.assertNotIn("issue-worker",[item["stage"] for item in saved["stages"]])
        self.assertEqual(saved["operation"]["status"],"failed")

    def test_network_failure_skips_issuer_and_guest(self):
        self.state["failStage"]="publish-network"; self.save()
        self.run_root(success=False)
        saved=self.read()
        self.assertFalse(saved.get("networkCheckpoint",False))
        self.assertNotIn("issue-worker",[item["stage"] for item in saved["stages"]])
        self.assertEqual(saved["operation"]["status"],"failed")

    def test_prestart_cancel_never_allocates_provider(self):
        self.state["operation"]["cancel_requested"]=True; self.save()
        self.run_root()
        self.assertEqual(self.read()["stages"],[])
        self.assertEqual(self.read()["operation"]["status"],"cancelled")

    def test_lost_final_reply_recovers_same_root_without_allocating_again(self):
        self.state["loseFinal"]=True; self.save()
        run=self.run_root(success=False)
        stages=self.read()["stages"]
        self.cli("workflow","resume","operation-task-vm-provision","--run",run)
        self.assertEqual(self.read()["stages"],stages)
        self.assertEqual(self.read()["operation"]["status"],"succeeded")


if __name__=="__main__":
    unittest.main()
