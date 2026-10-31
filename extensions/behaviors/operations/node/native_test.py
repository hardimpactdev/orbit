"""Exercise the pinned native runner, node workflow and fresh-process recovery."""
import json
from pathlib import Path
import sys
import unittest

ROOT = Path(__file__).resolve().parents[4]
sys.path.insert(0, str(ROOT / 'tests'))
from native_harness import NativeTestCase


class NodeWorkflowTest(NativeTestCase):
    native_timeout = 45

    def setUp(self):
        super().setUp()
        self.copy_behaviors('automatic-deploy', 'operation-runner', 'operations/node', 'operations/_lib', 'operations/fleet')
        self.native_env = {'SWAMP_NO_INBOX_NOTICE': '1'}
        for name, factory, relative in (
            ("operation-runner", "operationDiscovery", "../operations/node/native_fixture.ts"),
            ("operations/node", "node", "./native_fixture.ts"),
        ):
            path = self.repo / "extensions/behaviors" / name / "mod.ts"
            source = path.read_text().replace(f"{factory}()", f"{factory}(() => fixtureHttp)")
            path.write_text(f'import {{ fixtureHttp }} from "{relative}";\n' + source)
        for name in ("operation-node",):
            source = (ROOT / "workflows" / f"workflow-{name}.yaml").read_text()
            # Process recovery is local; the shipped workflow keeps worker placement.
            source = "\n".join(line for line in source.splitlines()
                               if not line.startswith(("labels:", "queueTimeout:"))) + "\n"
            (self.repo / "workflows" / f"workflow-{name}.yaml").write_text(source)
        self.state = {"operation": {
            "id": 7, "kind": "node.prepare_php_packages", "node_id": 2,
            "status": "queued", "cancel_requested": False, "step_results": {}, "steps": [],
            "input": {"versions": ["8.5"], "profile": "app-prod",
                "_php_packages_revision": "a" * 64, "_php_packages_deadline": 1793232120,
                "_php_packages": {"versions": {}, "mirror": "https://mirror.example.test",
                    "pcov": False, "opentelemetry": False, "extras": "keep", "fpm": "none",
                    "repack": {}, "release": {}}},
        }, "runs": 0, "keys": [], "completions": [], "receipt": None,
            "loseRunReply": False, "loseCompletionReply": False, "calls": [], "loseBeforeCommit": False}
        self.save()

    def save(self):
        (self.repo / "native-node.json").write_text(json.dumps(self.state))

    def read(self):
        return json.loads((self.repo / "native-node.json").read_text())

    def run_workflow(self, success=True):
        result = self.cli('workflow', 'run', 'operation-node', success=success)
        return json.JSONDecoder().raw_decode(result.stdout.lstrip())[0]['id']

    def test_native_dispatch_runs_remaining_php_package_work(self):
        self.cli("doctor", "extensions")
        for name in ("operation-node",):
            self.cli("workflow", "validate", name)
        self.run_workflow()
        state = self.read()
        self.assertEqual(state["operation"]["status"], "succeeded")
        self.assertEqual(state["runs"], 1)
        self.assertEqual(state["operation"]["steps"], [{"name": "php-packages", "status": "succeeded"}])

    def test_fresh_process_recovers_a_lost_node_reply(self):
        self.state["loseRunReply"] = True
        self.save()
        run = self.run_workflow(success=False)
        self.assertEqual(self.read()["runs"], 1)
        self.assertEqual(self.read()["completions"], [])
        self.cli("workflow", "resume", "operation-node", "--run", run)
        state = self.read()
        self.assertEqual(state["runs"], 1)
        self.assertEqual(state["calls"].count("/api/v1/operation-executor/7/run"), 1)
        self.assertEqual(state["operation"]["status"], "succeeded")
        self.assertEqual(len(state["completions"]), 1)

    def test_fresh_process_replays_lost_completion_without_node_work(self):
        self.state["loseCompletionReply"] = True
        self.save()
        run = self.run_workflow(success=False)
        self.cli("workflow", "resume", "operation-node", "--run", run)
        state = self.read()
        self.assertEqual(state["runs"], 1)
        self.assertEqual(state["calls"].count("/api/v1/operation-executor/7/run"), 1)
        self.assertEqual(state["operation"]["status"], "succeeded")
        self.assertEqual(len(state["completions"]), 1)

    def test_fresh_process_replays_a_completion_that_did_not_commit(self):
        self.state["loseBeforeCommit"] = True
        self.save()
        run = self.run_workflow(success=False)
        self.assertEqual(self.read()["completions"], [])
        self.cli("workflow", "resume", "operation-node", "--run", run)
        state = self.read()
        self.assertEqual(state["runs"], 1)
        self.assertEqual(len(state["completions"]), 1)
        self.assertEqual(state["operation"]["status"], "succeeded")

    def test_parent_recovers_lost_admission_and_waits_before_completion(self):
        self.state["operation"]["kind"] = "node.add"
        self.state["operation"]["input"] = {"_node_plan": [{
            "type": "child", "key": "add-bootstrap-probe", "kind": "node.bootstrap_probe",
            "node_id": 2, "input": {}, "optional": False,
        }]}
        self.state["loseChildReply"] = True
        self.save()
        run = self.run_workflow(success=False)
        state = self.read()
        self.assertEqual(state["child"]["id"], 20)
        self.assertEqual(state["runs"], 0)
        self.cli("workflow", "resume", "operation-node", "--run", run)
        state = self.read()
        self.assertEqual(state["operation"]["status"], "waiting")
        self.assertEqual(state["completions"], [])
        self.assertEqual(state["calls"].count("/api/v1/operation-executor/7/children"), 3)
        state["child"]["status"] = "succeeded"
        state["operation"]["status"] = "queued"
        state["loseBeforeCommit"] = True
        self.state = state
        self.save()
        run = self.run_workflow(success=False)
        self.assertEqual(self.read()["runs"], 1)
        self.cli("workflow", "resume", "operation-node", "--run", run)
        state = self.read()
        self.assertEqual(state["runs"], 1)
        self.assertEqual(state["operation"]["status"], "succeeded")
        self.assertEqual(len(state["completions"]), 1)
        self.assertEqual(state["child"]["id"], 20)

if __name__ == "__main__":
    unittest.main()
