"""Cache failure closure uses the canonical native harness and no live worker."""
import json
import shutil
from pathlib import Path
import sys
import unittest
import uuid
import yaml
ROOT = Path(__file__).resolve().parents[4]
sys.path.insert(0, str(ROOT / "tests"))
from native_harness import NativeTestCase

class CacheNative(NativeTestCase):
    def setUp(self):
        super().setUp()
        token = "cache-private-" + uuid.uuid4().hex
        self.native_env["ORBIT_CACHE_TEST_TOKEN"] = token
        self.secrets = (token,)
        self.copy_behaviors("automatic-deploy", "operation-runner", "operations/task-vms", "operations/_lib")
        module = self.repo / "extensions/behaviors/operations/task-vms/cache_fill_direct.ts"
        source = module.read_text().replace("export const behavior = cacheFillDirect();", """
const fixture = cacheFillDirect(() => fixtureHttp, fixtureAction);
export const behavior = {...fixture,methods:{...fixture.methods,execute:{...fixture.methods.execute,
execute:async (...args:Parameters<typeof fixture.methods.execute.execute>) => {
if (await engineRefused()) throw new Error("Native engine refused before fill");
return await fixture.methods.execute.execute(...args);
}}}};
""")
        module.write_text('import {fixtureHttp,fixtureAction,engineRefused} from "./cache_fill_native_fixture.ts";\n'+source)
        workflow = yaml.safe_load((ROOT / "workflows/workflow-operation-task-vm-cache-fill.yaml").read_text())
        self.assertEqual([j["name"] for j in workflow["jobs"]], ["fill"])
        for job in workflow["jobs"]:
            self.assertEqual(job.pop("target"), '${{ "orbit-node-" + string(inputs.node_id) }}')
            self.assertEqual(job.pop("labels"), {"node": '${{ string(inputs.node_id) }}'})
        (self.repo / "workflows/workflow-operation-task-vm-cache-fill.yaml").write_text(yaml.safe_dump(workflow,sort_keys=False))
        self.state = {"fills":0,"calls":[],"posts":[],"operation":{"id":7,"kind":"task_vm.fill_package_cache","node_id":13,
            "status":"queued","result":None,"cancel_requested":False,"input":{"max_bytes":100000,"conflicts":{},"artifacts":[{
                "manager":"composer","package":"acme/demo","version":"1.0.0","url":"https://api.github.com/repos/acme/demo/zipball/"+"a"*40,
                "key":"fixture","integrity":None}]}}}

    def run_root(self, success):
        (self.repo / "cache-fixture.json").write_text(json.dumps(self.state))
        self.cli("workflow","validate","operation-task-vm-cache-fill")
        self.cli("workflow","run","operation-task-vm-cache-fill","--input",json.dumps({"operation_id":7,"kind":"task_vm.fill_package_cache","node_id":13}),success=success)
        return json.loads((self.repo / "cache-fixture.json").read_text())

    def test_success_is_not_overwritten_by_always_closure(self):
        state = self.run_root(True)
        self.assertEqual(state["operation"]["status"],"succeeded")
        self.assertEqual(state["fills"],1)
        self.assertEqual([p["status"] for p in state["posts"]],["running","running","succeeded"])

    def test_engine_failure_before_fill_closes_existing_queued_root(self):
        self.state["engineRefused"] = True
        state = self.run_root(False)
        self.assertEqual(state["operation"]["status"],"failed")
        self.assertEqual(state["operation"]["result"],{})
        self.assertEqual(state["operation"]["error_code"],"operation.native_failed")
        self.assertEqual(state["fills"],0)

    def test_lost_fill_reply_settles_failed_without_secret_or_archive_claim(self):
        self.state["loseReply"] = True
        state = self.run_root(False)
        self.assertEqual(state["operation"]["status"],"failed")
        self.assertEqual(state["operation"]["result"],{"error_code":"task_vm.package_cache_failed"})
        self.assertEqual(state["fills"],1)
        self.assertEqual([p["status"] for p in state["posts"]],["running","failed"])

if __name__ == "__main__":
    unittest.main()
