"""Execute the check DAG with official SSH exec argv and harmless local host tools."""

import json
import shutil
import sys
import unittest

import yaml
from native_test import ROOT, NativeRepository


class NativeCheck(NativeRepository):
    def setUp(self):
        super().setUp()
        tasks = self.repo / "extensions/behaviors/operations/tasks"
        tasks.mkdir(parents=True, exist_ok=True)
        for name in ("publish.ts", "check.ts"):
            shutil.copy(ROOT / "extensions/behaviors/operations/tasks" / name, tasks)
        self.inputs["kind"] = "tasks.check"
        self.inputs["intent"].update(
            check_id=5,
            phase="baseline",
            command="test -f code",
            setup=[],
            deliverables=[],
            base="",
            message="Check",
            repository=None,
            check_name=None,
            branch="task-1-handoff",
            admitted_at="2026-10-31T22:00:00Z",
        )
        self.workspace.joinpath("code").write_text("old\n")
        self.git("add", ".")
        self.git("commit", "-qm", "Base")
        self.inputs["intent"]["base"] = self.git("rev-parse", "HEAD")

    def execute_check(self, success=True, has_result=True):
        intent = self.inputs["intent"]
        intent["setup_steps"] = [
            {**x, "input_json": json.dumps(x)} for x in intent["setup"]
        ]
        intent["command_deliverables"] = [
            {**x, "input_json": json.dumps(x)}
            for x in intent["deliverables"]
            if x["type"] == "command"
        ]
        intent["execution_references_json"] = json.dumps(
            self.inputs["execution_secrets"]
        )
        intent["project_command_json"] = json.dumps({"command": intent["command"]})
        flow = yaml.safe_load(
            (ROOT / "workflows/workflow-operation-task-check.yaml").read_text()
        )
        flow["name"] = "task-repository-fixture"

        def paths(value):
            if isinstance(value, str):
                return value.replace(
                    "/tmp/orbit-task-check-", str(self.repo / "check-")
                )
            if isinstance(value, list):
                return [paths(x) for x in value]
            if isinstance(value, dict):
                return {k: paths(v) for k, v in value.items()}
            return value

        flow = paths(flow)
        for job in flow["jobs"]:
            job.pop("target", None); job.pop("labels", None)
            job.pop("labels", None)
            for step in job["steps"]:
                step.pop("target", None); step.pop("labels", None)
                step.pop("labels", None)
        (self.repo / "workflows/workflow-check.yaml").write_text(
            yaml.safe_dump(flow, sort_keys=False)
        )
        result = self.cli(
            "workflow",
            "run",
            "task-repository-fixture",
            "--stdin",
            input=json.dumps(self.inputs),
            success=success,
        )
        run = self.decode(result)
        history = json.loads(self.cli("workflow", "history", "get", run["id"]).stdout)
        selected = next(x for x in history["jobs"][0]["steps"] if x["name"] == "result")
        return history, json.loads(
            selected["outputs"]["stdout"]
        ) if has_result else None

    def assert_preparation_failure(self, failed_step):
        self.inputs["intent"].update(
            phase="handoff",
            command="touch should-not-run",
            deliverables=[
                {
                    "id": "base-command",
                    "type": "command",
                    "command": "touch should-not-run",
                    "directory": ".",
                    "fails_on_base": True,
                    "paths": [],
                },
                {
                    "id": "current-command",
                    "type": "command",
                    "command": "touch should-not-run",
                    "directory": ".",
                    "fails_on_base": False,
                    "paths": [],
                },
            ],
        )
        history, _ = self.execute_check(success=False, has_result=False)
        self.assertEqual(history["status"], "failed")
        steps = {step["name"]: step for step in history["jobs"][0]["steps"]}
        self.assertEqual(steps[failed_step]["status"], "failed")
        for name in (
            "original",
            "setup_result",
            "snapshot-before-index",
            "project",
            "base_archive-0",
            "base_archive-1",
            "base-0",
            "base-1",
            "deliverable-0",
            "deliverable-1",
            "snapshot-after-index",
            "local",
            "mint_github",
            "push",
            "result",
        ):
            self.assertEqual(steps[name]["status"], "skipped", name)
        self.assertFalse((self.workspace / "should-not-run").exists())
        self.assertNotIn("Invalid expression", json.dumps(history))
        self.assertNotIn("InvalidExpressionError", json.dumps(history))
        self.assertEqual(steps["cleanup_local"]["status"], "succeeded")

    def test_ssh_failure_skips_checks_without_reading_missing_outputs(self):
        (self.repo / "tools/ssh").write_text(
            "#!"
            + sys.executable
            + '\nimport sys\nif "-M" in sys.argv or "-O" in sys.argv:raise SystemExit(0)'
            + '\nsys.stderr.write("fixture SSH unavailable\\n")\nraise SystemExit(255)\n'
        )
        self.assert_preparation_failure("prepare_remote")

    def test_baseline_setup_is_ordered_before_the_checked_snapshot(self):
        self.inputs["intent"]["setup"] = [
            {"name": "first", "command": "printf new > code", "timeout_seconds": 30},
            {
                "name": "second",
                "command": "test $(cat code) = new",
                "timeout_seconds": 30,
            },
        ]
        index = (self.workspace / ".git/index").read_bytes()
        history, result = self.execute_check()
        self.assertEqual(result["outcome"], "passed")
        self.assertIsNone(result["commit"])
        self.assertEqual(history["status"], "succeeded")
        # Setup ran before the checked snapshot, which used its own index file.
        self.assertEqual(result["head_before"], self.git("rev-parse", "HEAD"))
        self.assertEqual(result["subtask_start_commit"], self.git("rev-parse", "HEAD"))
        self.assertEqual(result["changed_paths"], [])
        self.assertEqual((self.workspace / ".git/index").read_bytes(), index)
        self.assertEqual(result["tree_before"], result["tree"])
        # The run directory with the snapshot index is removed.
        self.assertFalse(list(self.repo.glob("check-*")))

    def test_setup_failure_skips_later_setup_and_project(self):
        self.inputs["intent"]["setup"] = [
            {"name": "failure", "command": "exit 23", "timeout_seconds": 30},
            {
                "name": "should-skip",
                "command": "touch should-not-run",
                "timeout_seconds": 30,
            },
        ]
        _history, result = self.execute_check()
        self.assertEqual(result["outcome"], "failed")
        self.assertEqual(result["failed_step"], "failure")
        self.assertFalse((self.workspace / "should-not-run").exists())

    def test_invalid_overlay_never_runs_a_project_command(self):
        self.inputs["intent"].update(
            command="touch should-not-run",
            deliverables=[
                {
                    "id": "bad",
                    "type": "command",
                    "command": "true",
                    "directory": ".",
                    "paths": ["../code"],
                    "fails_on_base": True,
                }
            ],
        )
        _, result = self.execute_check(success=False)
        self.assertEqual(result["error_code"], "tasks.deliverable_invalid")
        self.assertFalse((self.workspace / "should-not-run").exists())

    def test_missing_overlay_or_base_directory_refuses_without_a_base_run(self):
        for paths, directory in ((["tests/missing.sh"], "."), ([], "new")):
            with self.subTest(paths=paths, directory=directory):
                (self.workspace / "new").mkdir(exist_ok=True)
                (self.workspace / "new/keep").write_text("")
                self.inputs["intent"].update(
                    phase="handoff",
                    deliverables=[
                        {
                            "id": "bad",
                            "type": "command",
                            "command": "touch base-ran",
                            "directory": directory,
                            "paths": paths,
                            "fails_on_base": True,
                        }
                    ],
                )
                before = self.git("rev-parse", "HEAD")
                history, result = self.execute_check(success=False)
                steps = {step["name"]: step for step in history["jobs"][0]["steps"]}
                self.assertEqual(result["error_code"], "tasks.deliverable_invalid")
                self.assertEqual(steps["base-0"]["status"], "skipped")
                self.assertEqual(self.git("rev-parse", "HEAD"), before)

    def test_missing_base_cannot_count_as_a_reproduced_failure(self):
        self.inputs["intent"].update(
            phase="handoff",
            base="f" * 40,
            deliverables=[
                {
                    "id": "bad",
                    "type": "command",
                    "command": "true",
                    "directory": ".",
                    "paths": [],
                    "fails_on_base": True,
                }
            ],
        )
        before = self.git("rev-parse", "HEAD")
        _, result = self.execute_check(success=False)
        self.assertIn("error_code", result)
        self.assertEqual(self.git("rev-parse", "HEAD"), before)

    def test_project_failure_with_skipped_command_deliverable_returns_failed_check(
        self,
    ):
        self.inputs["intent"].update(
            phase="handoff",
            command="exit 23",
            deliverables=[
                {
                    "id": "project-check",
                    "type": "command",
                    "command": "touch should-not-run",
                    "directory": ".",
                    "fails_on_base": False,
                    "paths": [],
                }
            ],
        )
        history, result = self.execute_check()
        self.assertEqual(history["status"], "succeeded")
        self.assertEqual(result["outcome"], "failed")
        self.assertEqual(result["exit_code"], 23)
        self.assertEqual(result["evidence"]["commands"], {})
        self.assertEqual(result["deliverables"][0]["outcome"], "failed")
        self.assertIsNone(result["commit"])
        self.assertFalse((self.workspace / "should-not-run").exists())

    def test_mutating_check_cannot_create_a_candidate(self):
        self.inputs["intent"].update(phase="handoff", command="printf moved > code")
        _, result = self.execute_check()
        self.assertEqual(result["outcome"], "changed")
        self.assertIsNone(result["commit"])

    def test_handoff_file_and_base_command_evidence_precede_commit(self):
        self.workspace.joinpath("code").write_text("new\n")
        # An installed, ignored dependency directory reaches the start-commit tree.
        self.workspace.joinpath(".gitignore").write_text("vendor/\n")
        self.workspace.joinpath("vendor").mkdir()
        self.workspace.joinpath("vendor/expect.sh").write_text("expected=new\n")
        self.workspace.joinpath("test.sh").write_text(
            '. vendor/expect.sh\ntest "$(cat code)" = "$expected"\n'
        )
        self.inputs["intent"].update(
            phase="handoff",
            deliverables=[
                {
                    "id": "file",
                    "type": "file",
                    "path": "code",
                    "change": "modified",
                    "command": "",
                    "directory": ".",
                    "fails_on_base": False,
                    "paths": [],
                },
                {
                    "id": "test",
                    "type": "command",
                    "path": "",
                    "change": "any",
                    "command": "bash test.sh",
                    "directory": ".",
                    "fails_on_base": True,
                    "paths": ["test.sh"],
                },
            ],
        )
        start = self.git("rev-parse", "HEAD")
        _, result = self.execute_check()
        self.assertEqual(result["outcome"], "passed")
        self.assertEqual(result["commit"], self.git("rev-parse", "HEAD"))
        self.assertEqual(result["evidence"]["commands"]["test"]["base_exit_code"], 1)
        self.assertEqual(
            sorted(result["evidence"]["diff"], key=lambda row: row["path"]),
            [
                {"status": "A", "path": ".gitignore"},
                {"status": "M", "path": "code"},
                {"status": "A", "path": "test.sh"},
            ],
        )
        # The candidate commits the checked tree on the checked head, as Orbit,
        # and the check operation's ref names it.
        self.assertEqual(self.git("rev-parse", "HEAD^"), start)
        self.assertEqual(self.git("rev-parse", "HEAD^{tree}"), result["tree"])
        self.assertEqual(
            self.git("log", "-1", "--format=%an <%ae>|%cn <%ce>|%B"),
            "Orbit <orbit@users.noreply.github.com>|"
            "Orbit <orbit@users.noreply.github.com>|Check",
        )
        self.assertEqual(
            self.git("rev-parse", "refs/orbit/check-operations/91001"),
            result["commit"],
        )
        self.assertEqual(self.git("status", "--porcelain"), "")
        # The start-commit tree held only the start commit plus the overlay.
        self.assertFalse(list(self.repo.glob("check-*")))


if __name__ == "__main__":
    unittest.main()
