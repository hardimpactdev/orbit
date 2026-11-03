"""Execute shipped Project workflows with native metadata and disposable commands."""
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
        if mode == "observe":
            child = yaml.safe_load((ROOT / "workflows/workflow-project-dependency-evidence.yaml").read_text())
            for job in child["jobs"]:
                job.pop("labels")
                for step in job["steps"]:
                    step.pop("labels")
            (self.repo / "workflows/workflow-project-dependency-evidence.yaml").write_text(yaml.safe_dump(child))
        flow = yaml.safe_load((ROOT / f"workflows/workflow-project-{mode}.yaml").read_text())
        for job in flow["jobs"]:
            self.assertEqual(job.pop("labels"), {"role": "swamp"})
            self.assertNotIn("labels", job)
            for step in job["steps"]:
                self.assertEqual(step.pop("labels"), {"role": "swamp"})
                self.assertNotIn("labels", step)
                encoded = json.dumps(step).replace("/home/orbit/.cache/orbit-dependency-scans/", str(self.repo / "scans") + "/")
                step.clear()
                step.update(json.loads(encoded))
        (self.repo / f"workflows/workflow-project-{mode}.yaml").write_text(yaml.safe_dump(flow, sort_keys=False))
        self.inputs["kind"] = "project." + mode

    def run_workflow(self, mode, success=True):
        if mode == "observe":
            self.inputs["intent"]["pr_urls"] = list(dict.fromkeys(
                url for group in self.inputs["intent"]["groups"]
                if group.get("status") not in ("completed", "cancelled", "failed")
                for url in (group.get("pr_url"), group.get("watched_pr_url")) if url))
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
        result = steps["Select this week's routine"]["outputs"]["result"]
        self.assertEqual(result["project_id"], 32)
        self.assertEqual(len(result["requests"]), 1)
        self.assertEqual(result["requests"][0]["action"], "create_group")
        self.assertEqual(result["requests"][0]["input"]["project_id"], 32)
        self.assertEqual(result["requests"][0]["input"]["status"], "backlog")
        self.assertIn("32-routine-2026-10-26", result["key"])

    def test_a_project_without_maintenance_policy_is_refused_by_name(self):
        self.workflow("routine")
        self.inputs["intent"]["project"] = {"id": 999, "repository": "https://github.com/example/unknown.git", "default_branch": "main", "check": "composer check"}
        _history, steps = self.run_workflow("routine", success=False)
        self.assertIn("project.maintenance_not_configured", steps["Select this week's routine"]["error"])

    def test_orbit_routine_reads_its_policy(self):
        self.workflow("routine")
        self.inputs["intent"]["project"] = {"id": 46, "repository": "https://github.com/hardimpactdev/orbit.git", "default_branch": "main", "check": "composer check"}
        # Ended groups keep PR URLs from the repository's old name; only open groups are checked.
        self.inputs["intent"]["groups"] = [{"id": 1, "project_id": 46, "status": "completed", "pr_url": "https://github.com/nckrtl/orbit/pull/1025", "watched_pr_url": None},
                                           {"id": 2, "project_id": 46, "status": "backlog", "pr_url": "https://github.com/hardimpactdev/orbit/pull/2450", "watched_pr_url": None}]
        _history, steps = self.run_workflow("routine")
        self.assertEqual(steps["Select this week's routine"]["status"], "succeeded")
        self.assertEqual(steps["Select this week's routine"]["outputs"]["result"]["project_id"], 46)

    def test_routine_keeps_an_unfinished_group_blocked_without_reading_its_pr(self):
        self.workflow("routine")
        self.inputs["intent"]["groups"] = [{"id": 1, "project_id": 32, "status": "running", "pr_url": "https://github.com/someone/else/pull/1", "watched_pr_url": None}]
        _, steps = self.run_workflow("routine")
        self.assertEqual(steps["Select this week's routine"]["outputs"]["result"]["requests"], [])

    def test_project_mismatch_fails_before_any_downstream_request(self):
        self.workflow("routine")
        self.inputs["intent"]["project"]["check"] = "a different check"
        history, steps = self.run_workflow("routine", success=False)
        self.assertEqual(history["status"], "failed")
        self.assertEqual(steps["Select this week's routine"]["status"], "failed")

    def test_observe_returns_public_evidence_without_gateway_calls(self):
        self.workflow("observe")
        self.inputs["execution_secrets"] = {}
        history, steps = self.run_workflow("observe")
        self.assertEqual(history["status"], "succeeded")
        result = steps["Refresh the findings index"]["outputs"]["result"]
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
        }
        history, steps = self.run_workflow("observe")
        self.assertEqual(history["status"], "succeeded")
        result = steps["Refresh the findings index"]["outputs"]["result"]
        self.assertEqual(result["observations"], [{"group_id": 55, "url": url, "state": "merged", "head": "a" * 40}])
        self.assertEqual(result["requests"], [])

    def test_observe_fails_a_github_read_without_projecting_partial_evidence(self):
        self.workflow("observe")
        self.inputs["intent"]["groups"] = [{"id": 55, "project_id": 32, "status": "running", "pr_url": "https://github.com/nckrtl/launch/pull/17"}]
        gh = self.tools / "gh"
        gh.write_text("#!/bin/sh\nexit 23\n")
        gh.chmod(0o755)
        self.cli("vault", "create", "local_encryption", "orbit-control-inputs")
        self.cli("vault", "put", "orbit-control-inputs", "project-dependency-read-901", input="fixture-observe-token")
        self.inputs["execution_secrets"] = {"github_token": {"vault": "orbit-control-inputs", "key": "project-dependency-read-901"}}
        history, steps = self.run_workflow("observe", success=False)
        self.assertEqual(history["status"], "failed")
        self.assertEqual(steps["Read the watched PRs"]["status"], "failed")
        self.assertEqual(steps["Refresh the findings index"]["status"], "skipped")
