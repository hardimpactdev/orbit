"""Native workflow recovery uses one keyed fake Gateway merge, without credentials."""
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[4]


class DependencyMergeNativeTest(unittest.TestCase):
    def test_lost_effect_and_completion_replies_resume_without_another_merge(self):
        for boundary in ("loseRun", "loseComplete"):
            with self.subTest(boundary=boundary), tempfile.TemporaryDirectory(prefix="dependency-merge-native-") as tmp:
                repo = Path(tmp)
                shutil.copy(ROOT / ".swamp.yaml", repo)
                behavior = repo / "extensions/behaviors"
                for name in ("automatic-deploy", "operation-runner", "_lib"):
                    shutil.copytree(ROOT / "extensions/behaviors" / name, behavior / name)
                family = behavior / "operations/dependency-merge"
                family.mkdir(parents=True)
                source = (ROOT / "extensions/behaviors/operations/dependency-merge/mod.ts").read_text()
                source = 'import { fixtureHttp, fixtureConfiguration } from "../../_test/merge.ts";\n' + source.replace("behavior = dependencyMerge()", "behavior = dependencyMerge(() => fixtureHttp)").replace("const refreshed = await ctx.runModel(", "const refreshed = await fixtureConfiguration.runModel(").replace("const rows = await ctx.queryData(", "const rows = await fixtureConfiguration.queryData(")
                (family / "mod.ts").write_text(source)
                shutil.copy(ROOT / "extensions/behaviors/operations/dependency-merge/configuration.ts", family)
                fixture = behavior / "_test/merge.ts"
                fixture.parent.mkdir()
                fixture.write_text((ROOT / "tests/fixtures/dependency-merge/executor.ts").read_text().replace('"../../../extensions/behaviors/automatic-deploy/gateway.ts"', '"../automatic-deploy/gateway.ts"'))
                workflows = repo / "workflows"
                workflows.mkdir()
                shutil.copy(ROOT / "workflows/workflow-operation-dependency-merge.yaml", workflows)
                state = {"operation": {"id": 7, "kind": "tasks.dependency_merge", "target_type": "task_group", "target_id": 91, "node_id": None, "cancel_requested": False,
                         "input": {"project_id": 20, "repository": "acme/widgets", "default_branch": "main", "smoke_workflow": ".github/workflows/production-smoke.yml", "group_id": 91, "head_sha": "a" * 40, "token": {"$secret": "github.dependency_merge", "id": 20}}, "step_results": {}},
                         "writes": 0, "runs": 0, "receipt": None, "completions": [], boundary: True}
                state_file = repo / "native-merge.json"
                state_file.write_text(json.dumps(state))
                env = {key: value for key, value in os.environ.items() if not key.startswith("SWAMP_SERVER_") and key not in ("SWAMP_SERVE_URL", "SWAMP_SERVER_URL")}
                env.update(SWAMP_NO_UPDATE_CHECK="1", SWAMP_NO_TELEMETRY="1")
                def run(*args):
                    return subprocess.run(["swamp", "workflow", *args, "--json"], cwd=repo, env=env, text=True, capture_output=True, timeout=50)
                first = run("run", "operation-dependency-merge", "--input", "operationId=7", "--input", "worker=proof")
                self.assertNotEqual(first.returncode, 0, first.stdout + first.stderr)
                self.assertEqual(json.loads(state_file.read_text())["writes"], 1, first.stdout + first.stderr)
                resumed = run("resume", "operation-dependency-merge", "--from", "execute")
                self.assertEqual(resumed.returncode, 0, resumed.stdout + resumed.stderr)
                final = json.loads(state_file.read_text())
                self.assertEqual(final["writes"], 1)
                self.assertEqual(final["operation"]["status"], "succeeded")
                self.assertEqual(len(final["completions"]), 1)
                self.assertEqual(final["runs"], 2 if boundary == "loseRun" else 1)


if __name__ == "__main__":
    unittest.main()
