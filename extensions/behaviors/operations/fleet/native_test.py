"""Exercise the native parent/family DAG, then recover in another Swamp process."""
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest

FAMILY = Path(__file__).resolve().parent
ROOT = FAMILY.parents[3]
SWAMP = os.environ.get("SWAMP_BIN", shutil.which("swamp") or "swamp")


class FleetNativeTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix="fleet-operation-native-")
        self.addCleanup(self.temp.cleanup)
        self.repo = Path(self.temp.name)
        shutil.copy(ROOT / ".swamp.yaml", self.repo)
        source = ROOT / "extensions/behaviors"
        destination = self.repo / "extensions/behaviors"
        for name in ("automatic-deploy", "operation-runner"):
            shutil.copytree(source / name, destination / name)
        shutil.copytree(FAMILY, destination / "operations/fleet")
        for name, factory, relative in (
            ("operation-runner", "operationRunner", "../operations/fleet/native_fixture.ts"),
            ("operations/fleet", "fleet", "./native_fixture.ts"),
        ):
            path = destination / name / "mod.ts"
            text = path.read_text().replace(f"...{factory}()", f"...{factory}(() => fixtureHttp)")
            path.write_text(f'import {{ fixtureHttp }} from "{relative}";\n' + text)
        workflows = self.repo / "workflows"
        workflows.mkdir()
        for name in ("operation-runner", "operation-dispatch", "operation-fleet"):
            shutil.copy(ROOT / f"workflows/workflow-{name}.yaml", workflows)
        self.state = {
            "operation": {"id": 9, "node_id": 2, "kind": "fleet.recipe", "status": "queued",
                          "cancel_requested": False, "steps": [], "step_results": {},
                          "input": {"node_id": 2, "daytime": True, "automatic": True,
                                    "has_php": False, "vpn": False, "user": "orbit",
                                    "identity": "sha256:recipe", "agent_sha256": "a" * 64,
                                    "cli_version": "0.1.0", "snapshot": "20261028T000000Z",
                                    "git_packages": [], "artifact_mirror": "https://example.test",
                                    "architecture": "amd64", "tools": {}, "vp_lock": None}},
            "receipts": {}, "calls": [], "mutations": 0, "completions": [],
            "loseRunReply": False, "loseCompletionReply": False,
        }
        self.save()

    def save(self):
        (self.repo / "fleet-native.json").write_text(json.dumps(self.state))

    def read(self):
        return json.loads((self.repo / "fleet-native.json").read_text())

    def swamp(self, *args, success=True):
        result = subprocess.run([SWAMP, *args, "--json"], cwd=self.repo, text=True,
                                capture_output=True, timeout=60,
                                env={**os.environ, "SWAMP_NO_UPDATE_CHECK": "1", "SWAMP_NO_TELEMETRY": "1"})
        self.assertEqual(result.returncode == 0, success, result.stdout + result.stderr)
        return result

    def test_parent_dispatches_and_projects_recipe_steps(self):
        for workflow in ("operation-runner", "operation-dispatch", "operation-fleet"):
            self.swamp("workflow", "validate", workflow)
        self.swamp("workflow", "run", "operation-runner")
        state = self.read()
        self.assertEqual(state["operation"]["status"], "succeeded")
        self.assertEqual(state["mutations"], 1)
        self.assertEqual([s["name"] for s in state["operation"]["steps"]],
                         ["recipe-begin", "recipe-apply", "verify", "recipe-delivery"])

    def test_new_process_resumes_lost_node_reply_with_the_original_key(self):
        self.state["loseRunReply"] = True
        self.save()
        self.swamp("workflow", "run", "operation-runner", success=False)
        self.assertEqual(self.read()["mutations"], 1)
        self.assertEqual(self.read()["completions"], [])
        self.swamp("workflow", "resume", "operation-fleet", "--from", "execute")
        state = self.read()
        self.assertEqual(state["operation"]["status"], "succeeded")
        self.assertEqual(state["mutations"], 1)
        keys = [c["body"]["key"] for c in state["calls"] if c["body"].get("name") == "recipe.apply"]
        self.assertEqual(keys, ["recipe-apply"])
        self.assertTrue(any(c["path"].endswith("/receipts/recipe-apply") for c in state["calls"]))
        self.assertEqual(len(state["completions"]), 1)

    def test_restricted_visit_restart_stops_without_another_node_effect(self):
        self.state["daytime"] = False
        self.state["windowOpen"] = True
        self.state["loseRunReply"] = True
        self.save()
        self.swamp("workflow", "run", "operation-runner", success=False)
        before = self.read()
        self.assertEqual(before["mutations"], 1)
        self.state = before
        self.state["windowOpen"] = False
        self.save()
        self.swamp("workflow", "resume", "operation-fleet", "--from", "execute")
        state = self.read()
        self.assertEqual(state["operation"]["status"], "failed")
        self.assertEqual(state["completions"][0]["error_code"], "fleet.visit_interrupted")
        self.assertEqual(state["mutations"], 1)
        self.assertEqual(len([c for c in state["calls"] if c["path"].endswith("/run")]),
                         len([c for c in before["calls"] if c["path"].endswith("/run")]))
        self.assertFalse(any(s["status"] == "running" for s in state["operation"]["steps"]))

    def test_new_process_replays_saved_completion_without_another_node_effect(self):
        self.state["loseCompletionReply"] = True
        self.save()
        self.swamp("workflow", "run", "operation-runner", success=False)
        before = self.read()
        runs = len([c for c in before["calls"] if c["path"].endswith("/run")])
        self.swamp("workflow", "resume", "operation-fleet", "--from", "execute")
        state = self.read()
        self.assertEqual(len([c for c in state["calls"] if c["path"].endswith("/run")]), runs)
        self.assertEqual(state["mutations"], 1)
        self.assertEqual(len(state["completions"]), 1)

    def start_child_wait(self):
        self.state.update({"daytime": False, "windowOpen": True})
        self.state["operation"]["input"].update({"has_php": True, "vpn": True})
        self.save()
        self.swamp("workflow", "run", "operation-runner")
        self.state = self.read()
        self.assertEqual(self.state["operation"]["status"], "waiting")
        self.assertEqual(self.state["completions"], [])
        self.state["child"]["status"] = "succeeded"

    def test_new_process_continues_a_restricted_durable_child_wait(self):
        self.start_child_wait()
        self.save()
        self.swamp("workflow", "run", "operation-runner")
        state = self.read()
        self.assertEqual(state["operation"]["status"], "succeeded")
        self.assertEqual(state["mutations"], 1)
        self.assertEqual(len([c for c in state["calls"] if c["body"].get("name") == "recipe.apply"]), 1)

    def test_new_process_interrupts_a_settled_child_wait_after_window_closure(self):
        self.start_child_wait()
        self.state["windowOpen"] = False
        before = len([c for c in self.state["calls"] if c["path"].endswith("/run")])
        self.save()
        self.swamp("workflow", "run", "operation-runner")
        state = self.read()
        self.assertEqual(state["completions"][0]["error_code"], "fleet.visit_interrupted")
        self.assertEqual(len([c for c in state["calls"] if c["path"].endswith("/run")]), before)

    def test_new_process_keeps_an_interrupted_running_effect_held(self):
        self.state.update({"daytime": False, "windowOpen": False, "runningReceipts": ["recipe-apply"]})
        self.state["operation"]["steps"] = [{"name": "recipe-apply", "status": "running", "error_code": None}]
        self.save()
        self.swamp("workflow", "run", "operation-runner")
        self.state = self.read()
        self.assertEqual(self.state["completions"], [])
        self.assertEqual(self.state["operation"]["steps"][0]["status"], "running")
        self.state["runningReceipts"] = []
        self.save()
        self.swamp("workflow", "run", "operation-runner")
        state = self.read()
        self.assertEqual(state["completions"][0]["error_code"], "fleet.visit_interrupted")
        self.assertFalse(any(c["path"].endswith("/run") for c in state["calls"]))

    def cli_child(self):
        self.state["operation"]["kind"] = "fleet.cli_converge"
        self.state["operation"]["input"] = {"user": "orbit", "release": {"version": "0.1.0", "url": "https://example.test/cli", "sha256": "a" * 64},
                                             "profile": "profile", "skip_reason": None, "plan_error": None}

    def test_cli_child_resolves_a_lost_install_reply_in_a_new_process(self):
        self.cli_child()
        self.state["loseRunName"] = "cli.install"
        self.state["loseRunReply"] = True
        self.save()
        self.swamp("workflow", "run", "operation-runner", success=False)
        self.assertEqual(self.read()["mutations"], 1)
        self.swamp("workflow", "resume", "operation-fleet", "--from", "execute")
        state = self.read()
        self.assertEqual(state["operation"]["status"], "succeeded")
        self.assertEqual(state["mutations"], 1)
        self.assertEqual([c["body"]["key"] for c in state["calls"] if c["body"].get("name") == "cli.install"], ["cli-install"])
        self.assertTrue(any(c["path"].endswith("/receipts/cli-install") for c in state["calls"]))
        self.assertEqual(state["completions"][0]["result"]["outcome"], "cli_installed")

    def test_cli_child_proves_an_unpublished_release_skip_without_node_effects(self):
        self.cli_child()
        self.state["operation"]["input"].update({"release": None, "skip_reason": "release_missing"})
        self.save()
        self.swamp("workflow", "run", "operation-runner")
        state = self.read()
        self.assertEqual(state["operation"]["status"], "succeeded")
        self.assertEqual(state["mutations"], 0)
        self.assertEqual(state["completions"][0]["result"]["outcome"], "skipped")
        self.assertFalse(any(c["body"].get("name") == "cli.install" for c in state["calls"]))


if __name__ == "__main__":
    unittest.main()
