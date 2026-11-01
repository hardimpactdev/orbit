"""Same-root native resume with shared harness; all GitHub effects are disposable fixtures."""
import json
from pathlib import Path
import secrets
import sys
import unittest

ROOT = Path(__file__).resolve().parents[4]
sys.path.insert(0, str(ROOT / "tests"))
from native_harness import NativeTestCase


class DependencyMergeNativeTest(NativeTestCase):
    def prepare(self, boundary):
        self.copy_behaviors("automatic-deploy", "operations/_lib", "operations/dependency-merge", "_lib")
        (self.repo / "extensions/behaviors/native_host_method.ts").write_text((ROOT / "extensions/behaviors/native_host_method.ts").read_text())
        self.secrets = (secrets.token_urlsafe(32),)
        self.native_env["DEPENDENCY_MERGE_TEST_TOKEN"] = self.secrets[0]
        family = self.repo / "extensions/behaviors/operations/dependency-merge"
        source = family / "mod.ts"
        text = source.read_text().replace('import { callWorkerModel } from "../../_lib/worker_model.ts";',
            'import { fixtureHttp, fixtureGithub, fixtureQuery as queryWorkerData, fixtureConfiguration as callWorkerModel } from "../../_test/merge.ts";')
        source.write_text(text.replace('import { queryWorkerData } from "../../_lib/worker_query.ts";', "")
                          .replace("...dependencyMerge()", "...dependencyMerge(() => fixtureHttp, fixtureGithub)"))
        fixture = self.repo / "extensions/behaviors/_test/merge.ts"
        fixture.parent.mkdir()
        fixture.write_text((ROOT / "extensions/behaviors/operations/dependency-merge/native_fixture.ts").read_text()
                           .replace('"../../automatic-deploy/gateway.ts"', '"../automatic-deploy/gateway.ts"')
                           .replace('"./policy_fixture.ts"', '"../operations/dependency-merge/policy_fixture.ts"'))
        workflow = ROOT / "workflows/workflow-operation-dependency-merge.yaml"
        # Scratch dispatch is local; production retains exact target AND Node label.
        (self.repo / "workflows" / workflow.name).write_text("\n".join(
            line for line in workflow.read_text().splitlines() if not line.strip().startswith(("target:", "labels:"))) + "\n")
        head = "a" * 40
        self.state = self.repo / "native-merge.json"
        self.state.write_text(json.dumps({"operation": {"id": 7, "kind": "tasks.dependency_merge", "node_id": 209,
            "status": "queued", "result": None, "cancel_requested": False,
            "input": {"node_id": 209, "project_id": 20, "group_id": 91, "publish_operation_id": 8,
            "repository": "acme/widgets", "default_branch": "main", "smoke_workflow": ".github/workflows/production-smoke.yml", "pr_number": 3, "head_sha": head}},
            "pr": {"number": 3, "state": "open", "merged": False, "merge_commit_sha": None,
            "head": {"sha": head, "ref": "task-91", "repo": {"full_name": "acme/widgets"}},
            "base": {"ref": "main", "repo": {"full_name": "acme/widgets"}}},
            "calls": [], "writes": 0, boundary: True}))

    def recover(self, boundary):
        self.prepare(boundary)
        self.cli("workflow", "validate", "operation-dependency-merge")
        first = self.cli("workflow", "run", "operation-dependency-merge", "--input",
                         json.dumps({"operation_id": 7, "node_id": 209, "kind": "tasks.dependency_merge"}), success=False)
        run_id = json.JSONDecoder().raw_decode(first.stdout.lstrip())[0]["id"]
        self.assertEqual(json.loads(self.state.read_text())["writes"], 1, first.stdout + first.stderr)
        state = json.loads(self.state.read_text())
        state["disabled"] = True
        self.state.write_text(json.dumps(state))
        self.cli("workflow", "resume", "operation-dependency-merge", "--run", run_id)
        final = json.loads(self.state.read_text())
        self.assertEqual(final["writes"], 1)
        self.assertEqual(final["refreshes"], 2)
        self.assertEqual(final["operation"]["status"], "succeeded")
        self.assertFalse(any("operation-executor" in call for call in final["calls"]))

    def test_resume_refreshes_disabled_switch_before_an_unproven_put(self):
        self.prepare("loseBeforeMerge")
        first = self.cli("workflow", "run", "operation-dependency-merge", "--input",
                         json.dumps({"operation_id": 7, "node_id": 209, "kind": "tasks.dependency_merge"}), success=False)
        run_id = json.JSONDecoder().raw_decode(first.stdout.lstrip())[0]["id"]
        state = json.loads(self.state.read_text())
        self.assertEqual(state["writes"], 0)
        state["disabled"] = True
        self.state.write_text(json.dumps(state))
        self.cli("workflow", "resume", "operation-dependency-merge", "--run", run_id, success=False)
        final = json.loads(self.state.read_text())
        self.assertEqual(final["writes"], 0)
        self.assertEqual(final["refreshes"], 2)
        self.assertEqual(final["operation"]["result"], {"error_code": "dependency_merge.disabled"})

    def test_failed_refresh_prevents_credentials_and_effect(self):
        self.prepare("refreshFail")
        self.cli("workflow", "run", "operation-dependency-merge", "--input",
                 json.dumps({"operation_id": 7, "node_id": 209, "kind": "tasks.dependency_merge"}), success=False)
        final = json.loads(self.state.read_text())
        self.assertEqual(final["writes"], 0)
        self.assertFalse(any("private-material" in call for call in final["calls"]))
        self.assertEqual(final["operation"]["status"], "running")

    def test_lost_merge_reply(self):
        self.recover("loseMerge")

    def test_lost_projection_reply(self):
        self.recover("loseProjection")


if __name__ == "__main__":
    unittest.main()
