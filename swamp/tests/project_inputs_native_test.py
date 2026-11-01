"""Execute shipped Project workflows with native metadata and disposable commands."""
import base64
import json
import os
import shutil
import sys

import yaml
from native_harness import ROOT, NativeTestCase


class ProjectInputsNativeTest(NativeTestCase):
    native_timeout = 120

    def setUp(self):
        super().setUp()
        shutil.copytree(ROOT / "extensions", self.repo / "extensions", dirs_exist_ok=True,
                        ignore=shutil.ignore_patterns("*test*", "__pycache__"))
        model = self.repo / "models/orbit-gateway.yaml"
        model.write_text(yaml.safe_dump({"type": "@nckrtl/orbit-gateway", "typeVersion": "2026.10.30.1",
                                        "id": "f5fa45d6-6307-48b6-a094-9aae8f79febc", "name": "orbit-gateway", "version": 1, "globalArguments": {}, "methods": {}}))
        self.tools = self.repo / "tools"
        self.tools.mkdir()
        curl = self.tools / "curl"
        curl.write_text("#!/bin/sh\nprintf forbidden-gateway-call >&2\nexit 93\n")
        curl.chmod(0o755)
        self.native_env["PATH"] = str(self.tools) + ":" + os.environ["PATH"]
        self.inputs = {"operation_id": 901, "node_id": 209, "kind": "project.routine", "execution_secrets": {},
                       "intent": {"project": {"id": 32, "repository": "https://github.com/nckrtl/launch.git", "default_branch": "main", "check": "composer check"},
                                  "groups": [], "admitted_at": "2026-11-01T04:00:00Z"}}

    def workflow(self, mode):
        if mode in ("detect", "observe"):
            child = yaml.safe_load((ROOT / "workflows/workflow-project-dependency-evidence.yaml").read_text())
            for job in child["jobs"]:
                job.pop("target")
                for step in job["steps"]:
                    step.pop("target")
            (self.repo / "workflows/workflow-project-dependency-evidence.yaml").write_text(yaml.safe_dump(child))
        flow = yaml.safe_load((ROOT / f"workflows/workflow-project-{mode}.yaml").read_text())
        for job in flow["jobs"]:
            self.assertEqual(job.pop("target"), "orbit-node-209")
            self.assertNotIn("labels", job)
            for step in job["steps"]:
                self.assertEqual(step.pop("target"), "orbit-node-209")
                self.assertNotIn("labels", step)
                encoded = json.dumps(step).replace("/home/orbit/.cache/orbit-dependency-scans/", str(self.repo / "scans") + "/")
                step.clear()
                step.update(json.loads(encoded))
        (self.repo / f"workflows/workflow-project-{mode}.yaml").write_text(yaml.safe_dump(flow, sort_keys=False))
        self.inputs["kind"] = "project." + mode

    def run_workflow(self, mode, success=True):
        source = self.repo / "input.json"
        source.write_text(json.dumps(self.inputs))
        self.cli("workflow", "validate", "project-" + mode)
        raw = self.cli("workflow", "run", "project-" + mode, "--input-file", str(source), success=success)
        run = json.loads(raw.stdout)
        history = json.loads(self.cli("workflow", "history", "get", run["id"]).stdout)
        return history, {step["name"]: step for step in history["jobs"][0]["steps"]}

    def test_routine_returns_request_without_gateway_calls_or_group_execution(self):
        self.workflow("routine")
        history, steps = self.run_workflow("routine")
        self.assertEqual(history["status"], "succeeded")
        result = json.loads(steps["result"]["outputs"]["stdout"])
        self.assertEqual(result["project_id"], 32)
        self.assertEqual(len(result["requests"]), 1)
        self.assertEqual(result["requests"][0]["action"], "create_group")
        self.assertEqual(result["requests"][0]["input"]["project_id"], 32)
        self.assertEqual(result["requests"][0]["input"]["status"], "backlog")
        self.assertIn("32-routine-2026-10-26", result["key"])

    def test_project_mismatch_fails_before_any_downstream_request(self):
        self.workflow("routine")
        self.inputs["intent"]["project"]["check"] = "a different check"
        history, steps = self.run_workflow("routine", success=False)
        self.assertEqual(history["status"], "failed")
        self.assertEqual(steps["input"]["status"], "failed")
        self.assertEqual(steps["select"]["status"], "skipped")
        self.assertEqual(steps["result"]["status"], "skipped")

    def test_observe_returns_public_evidence_without_gateway_calls(self):
        self.workflow("observe")
        self.inputs["execution_secrets"] = {
            "github_token": {"vault": "orbit-control-inputs", "key": "project-dependency-read-901"},
            "git_authorization": {"vault": "orbit-control-inputs", "key": "project-dependency-auth-901"},
        }
        history, steps = self.run_workflow("observe")
        self.assertEqual(history["status"], "succeeded")
        result = json.loads(steps["result"]["outputs"]["stdout"])
        self.assertEqual(result, {"project_id": 32, "observations": [], "resume_group_ids": [], "requests": []})

    def test_observe_correlates_one_bounded_read_with_the_captured_group(self):
        self.workflow("observe")
        url = "https://github.com/nckrtl/launch/pull/17"
        self.inputs["intent"]["groups"] = [{"id": 55, "project_id": 32, "status": "running", "pr_url": url}]
        observation = {"number": 17, "url": url, "state": "MERGED", "headRefOid": "a" * 40}
        gh = self.tools / "gh"
        gh.write_text("#!" + sys.executable + "\nimport json\nprint(json.dumps(" + repr(observation) + "))\n")
        gh.chmod(0o755)
        self.cli("vault", "create", "local_encryption", "orbit-control-inputs")
        self.cli("vault", "put", "orbit-control-inputs", "project-dependency-read-901", input="fixture-observe-token")
        self.inputs["execution_secrets"] = {
            "github_token": {"vault": "orbit-control-inputs", "key": "project-dependency-read-901"},
            "git_authorization": {"vault": "orbit-control-inputs", "key": "project-dependency-auth-901"},
        }
        history, steps = self.run_workflow("observe")
        self.assertEqual(history["status"], "succeeded")
        result = json.loads(steps["result"]["outputs"]["stdout"])
        self.assertEqual(result["observations"], [{"group_id": 55, "url": url, "state": "merged", "head": "a" * 40}])
        self.assertEqual(result["requests"], [])

    def test_scan_acquisition_failure_preserves_failure_and_cleans_local_data(self):
        self.workflow("detect")
        git = self.tools / "git"
        git.write_text("#!" + sys.executable + "\nimport sys\nsys.stderr.write('fixture clone unavailable\\n')\nraise SystemExit(41)\n")
        git.chmod(0o755)
        token = "fixture_project_read_token_7b9e"
        authorization = "AUTHORIZATION: basic " + base64.b64encode(("x-access-token:" + token).encode()).decode()
        self.secrets = (token, authorization)
        self.cli("vault", "create", "local_encryption", "orbit-control-inputs")
        self.cli("vault", "put", "orbit-control-inputs", "project-dependency-read-901", input=token)
        self.cli("vault", "put", "orbit-control-inputs", "project-dependency-auth-901", input=authorization)
        self.inputs["execution_secrets"] = {"github_token": {"vault": "orbit-control-inputs", "key": "project-dependency-read-901"}, "git_authorization": {"vault": "orbit-control-inputs", "key": "project-dependency-auth-901"}}
        history, steps = self.run_workflow("detect", success=False)
        self.assertEqual(history["status"], "failed")
        self.assertEqual(steps["checkout"]["status"], "failed", json.dumps(history, indent=2))
        self.assertEqual(steps["head"]["status"], "skipped")
        self.assertEqual(steps["scan"]["status"], "skipped")
        self.assertEqual(steps["result"]["status"], "succeeded")
        result = json.loads(steps["result"]["outputs"]["stdout"])
        self.assertEqual(result["status"], "failed")
        self.assertEqual(result["requests"], [])
        self.assertEqual(result["error_code"], "source_acquisition_failed")
        observed = json.loads(self.cli("data", "query", 'modelName == "orbit-gateway" && name.startsWith("dependencies-v1-project-")', "--select", "attributes").stdout)["results"]
        self.assertEqual(len(observed), 1)
        self.assertEqual(observed[0]["last_attempt"]["status"], "failed")
        self.assertEqual(observed[0]["source_commit"], None)
        self.assertEqual(steps["cleanup"]["status"], "succeeded")
        self.assertNotIn(token, json.dumps(history))
