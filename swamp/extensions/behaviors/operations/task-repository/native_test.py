"""Run both DAGs with official SSH exec argv and a disposable local remote-host fixture."""

import hashlib
import importlib.util
import json
import os
import shutil
import sys
from pathlib import Path

import yaml

ROOT = Path(__file__).resolve().parents[4]
spec = importlib.util.spec_from_file_location(
    "native_harness", ROOT / "tests/native_harness.py"
)
harness = importlib.util.module_from_spec(spec)
spec.loader.exec_module(harness)


class NativeRepository(harness.NativeTestCase):
    native_timeout = 90

    def setUp(self):
        super().setUp()
        self.copy_behaviors("operations/task-repository")
        tasks = self.repo / "extensions/behaviors/operations/tasks"
        tasks.mkdir(parents=True, exist_ok=True)
        for name in ("publish.ts", "check.ts"):
            shutil.copy(ROOT / "extensions/behaviors/operations/tasks" / name, tasks)
        library = self.repo / "extensions/behaviors/_lib"
        library.mkdir(parents=True, exist_ok=True)
        shutil.copy(ROOT / "extensions/behaviors/_lib/repository_analysis.ts", library)
        # Setup and teardown argv expand @orbit/ci-caches with the shared program.
        runtime = self.repo / "extensions/behaviors/operations/instance-deploy"
        runtime.mkdir(parents=True, exist_ok=True)
        shutil.copy(ROOT / "extensions/behaviors/operations/instance-deploy/app_step_runtime.ts", runtime)

        official = self.repo / ".swamp/pulled-extensions/@swamp/ssh"
        official.parent.mkdir(parents=True)
        shutil.copytree(ROOT / ".swamp/pulled-extensions/@swamp/ssh", official)
        inventory = json.loads(
            (ROOT / "extensions/models/upstream_extensions.json").read_text()
        )
        path = self.repo / "extensions/models/upstream_extensions.json"
        path.parent.mkdir(parents=True)
        path.write_text(json.dumps({"@swamp/ssh": inventory["@swamp/ssh"]}))
        self.workspace = self.repo / "workspace"
        self.workspace.mkdir()
        self.git("init", "-qb", "task-1")
        self.git("config", "user.email", "test@example.test")
        self.git("config", "user.name", "Test")
        self.git("remote", "add", "origin", "https://github.com/acme/test.git")
        tools = self.repo / "tools"
        tools.mkdir()
        ssh = tools / "ssh"
        ssh.write_text(
            "#!"
            + sys.executable
            + '\nimport shlex,subprocess,sys\nargs=sys.argv[1:]\nif "-M" in args or "-O" in args:raise SystemExit(0)\nargv=shlex.split(args[-1])\nr=subprocess.run(argv,input=sys.stdin.buffer.read(),capture_output=True)\nsys.stdout.buffer.write(r.stdout);sys.stderr.buffer.write(r.stderr)\nraise SystemExit(r.returncode)\n'
        )
        ssh.chmod(0o755)
        # The check DAG in check_native_test.py still copies its scripts.
        scp = tools / "scp"
        scp.write_text(
            "#!"
            + sys.executable
            + '\nimport shutil,sys\nfrom pathlib import Path\nsrc=Path(sys.argv[-2]);dst=Path(sys.argv[-1].split(":",1)[1])\nshutil.copytree(src,dst/src.name,dirs_exist_ok=True)\n'
        )
        scp.chmod(0o755)
        self.native_env["PATH"] = str(tools) + ":" + os.environ["PATH"]
        self.inputs = {
            "operation_id": 91001,
            "node_id": 3,
            "execution_secrets": {},
            "intent": {
                "group_id": 1,
                "instance_id": 2,
                "node_id": 3,
                "subtask": 4,
                "checkout_path": str(self.workspace),
                "ssr_port": None,
                "ssh_execution": {
                    "target": {
                        "user": "orbit",
                        "port": 22,
                        "host": "192.0.2.3",
                        "host_keys": [
                            "ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAINqVuK+N4gL/sDvGNKnGrYhEtEFnZx9hvH+134PsQgND"
                        ],
                    },
                    "identity_file": "/dev/null",
                },
            },
        }

    def git(self, *args):
        import subprocess

        return (
            subprocess.check_output(
                ["git", "-C", str(self.workspace), *args], stderr=subprocess.DEVNULL
            )
            .decode()
            .strip()
        )

    def workflow(self, kind):
        self.inputs["kind"] = "tasks." + kind
        self.flow = yaml.safe_load(
            (ROOT / f"workflows/workflow-operation-task-{kind}.yaml").read_text()
        )
        self.flow["name"] = "task-repository-fixture"
        for job in self.flow["jobs"]:
            # A local native run has no remote worker; strip the role placement.
            self.assertEqual(job.pop("labels"), {"role": "swamp"})
            for step in job["steps"]:
                self.assertEqual(step.pop("labels"), {"role": "swamp"})
        self.write_workflow()

    def write_workflow(self):
        (self.repo / "workflows/workflow-task-repository-fixture.yaml").write_text(
            yaml.safe_dump(self.flow, sort_keys=False)
        )

    def decode(self, reply):
        decoder = json.JSONDecoder()
        for index, char in enumerate(reply.stdout):
            if char == "{":
                try:
                    return decoder.raw_decode(reply.stdout[index:])[0]
                except ValueError:
                    pass
        self.fail("Native JSON missing")

    def run_workflow(self, success=True):
        self.cli("workflow", "validate", "task-repository-fixture")
        return self.decode(
            self.cli(
                "workflow",
                "run",
                "task-repository-fixture",
                "--stdin",
                input=json.dumps(self.inputs),
                success=success,
            )
        )

    def install(self, source, path="scripts/run"):
        target = self.workspace / path
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_text(source)
        target.chmod(0o755)
        self.git("add", ".")
        self.git("commit", "-qm", "fixture")

    def steps(self, run):
        history = json.loads(self.cli("workflow", "history", "get", run["id"]).stdout)
        return {step["name"]: step for step in history["jobs"][0]["steps"]}

    def result(self, run):
        return json.loads(self.steps(run)["result"]["outputs"]["stdout"])

    def analysis_intent(self):
        head = self.git("rev-parse", "HEAD")
        self.inputs["intent"].update(
            plan={"id": "test", "revision": 1},
            plan_sha256="b" * 64,
            base=head,
            head=head,
            tree=self.git("rev-parse", "HEAD^{tree}"),
            branch="task-1",
            repository="https://github.com/acme/test.git",
        )

    def test_script_native_steps_return_business_failure_with_literal_arguments(self):
        self.install(
            '#!/usr/bin/python3\nimport json,pathlib,sys\npathlib.Path("args").write_text(json.dumps(sys.argv[1:]))\nprint("done")\nsys.exit(23)\n'
        )
        self.inputs["intent"].update(
            script="scripts/run", args=["$(touch never)", " spaces ", ""]
        )
        index = (self.workspace / ".git/index").read_bytes()
        self.workflow("script")
        run = self.run_workflow()
        self.assertEqual(run["status"], "succeeded")
        self.assertEqual(
            json.loads((self.workspace / "args").read_text()),
            self.inputs["intent"]["args"],
        )
        self.assertFalse((self.workspace / "never").exists())
        # The snapshots use a temporary index; the real index is untouched and the temporary one removed.
        self.assertEqual((self.workspace / ".git/index").read_bytes(), index)
        self.assertEqual(self.steps(run)["cleanup-index"]["status"], "succeeded")
        history = json.loads(self.cli("workflow", "history", "get", run["id"]).stdout)
        result = json.loads(
            next(
                step for step in history["jobs"][0]["steps"] if step["name"] == "result"
            )["outputs"]["stdout"]
        )
        self.assertEqual(result["outcome"], "failed")
        self.assertEqual(result["exit_code"], 23)
        self.assertEqual(result["output"], "done\n")
        self.assertFalse(result["output_truncated"])
        self.assertTrue(result["changed"])

    def test_untracked_or_non_executable_script_refuses_without_running(self):
        self.install("#!/bin/sh\ntouch invoked\n")
        self.git("update-index", "--chmod=-x", "scripts/run")
        self.git("commit", "-qm", "not executable")
        self.inputs["intent"].update(script="scripts/run", args=[])
        self.workflow("script")
        run = self.run_workflow(success=False)
        steps = self.steps(run)
        self.assertEqual(
            json.loads(steps["result"]["outputs"]["stdout"]),
            {"error_code": "tasks.script_unavailable"},
        )
        self.assertEqual(steps["execute"]["status"], "skipped")
        self.assertFalse((self.workspace / "invoked").exists())

    def test_native_resume_after_result_failure_never_reexecutes_program(self):
        self.install(
            '#!/usr/bin/python3\nfrom pathlib import Path\np=Path(".git/count")\np.write_text(str(int(p.read_text())+1) if p.exists() else "1")\n'
        )
        self.inputs["intent"].update(script="scripts/run", args=[])
        self.workflow("script")
        result = next(
            step for step in self.flow["jobs"][0]["steps"] if step["name"] == "result"
        )
        result["task"]["inputs"]["run"] = (
            'test -f "$ORBIT_TEST_READY"\n' + result["task"]["inputs"]["run"]
        )
        marker = self.repo / "result-ready"
        result["task"]["inputs"]["env"]["ORBIT_TEST_READY"] = str(marker)
        # Native shell uses explicit shell failure rather than a hidden application retry.
        result["task"]["inputs"]["run"] = "set -e\n" + result["task"]["inputs"]["run"]
        self.write_workflow()
        run = self.run_workflow(success=False)
        marker.touch()
        resumed = self.decode(
            self.cli(
                "workflow", "resume", "task-repository-fixture", "--run", run["id"]
            )
        )
        self.assertEqual(resumed["status"], "succeeded")
        self.assertEqual((self.workspace / ".git/count").read_text(), "1")

    def test_analysis_native_dag_retains_exact_report(self):
        self.install(
            '#!/usr/bin/python3\nimport json,sys\nv=json.load(sys.stdin)\nr={k:v[k] for k in ("execution_id","plan_sha256","base","head","tree")}\nr.update(schema=1,status="ready",diagnostics=[],report={"schema":"test.v1","data":{}})\nprint(json.dumps(r))\n',
            "bin/task-analyze",
        )
        # A tracked file that .gitignore also matches stays in the snapshot tree.
        (self.workspace / ".gitignore").write_text("generated.txt\n")
        (self.workspace / "generated.txt").write_text("tracked\n")
        self.git("add", "-f", ".gitignore", "generated.txt")
        self.git("commit", "-qm", "tracked ignored file")
        self.analysis_intent()
        self.workflow("analyze")
        run = self.run_workflow()
        self.assertEqual(run["status"], "succeeded")
        result = self.result(run)
        self.assertEqual(result["outcome"], "ready")
        provenance = result["provenance"]
        self.assertEqual(
            set(provenance),
            {
                "operation_id",
                "group",
                "subtask",
                "repository",
                "branch",
                "instance_id",
                "node_id",
                "plan_id",
                "plan_revision",
                "plan_sha256",
                "base",
                "head",
                "tree",
                "before",
                "after",
                "execution_id",
                "entrypoint_sha256",
                "started_at",
                "finished_at",
                "duration_ms",
                "exit_code",
                "mechanical_llm_calls",
            },
        )
        self.assertEqual(provenance["node_id"], 3)
        self.assertEqual(provenance["operation_id"], 91001)
        self.assertEqual(
            provenance["before"],
            {
                "head": self.inputs["intent"]["head"],
                "tree": self.inputs["intent"]["tree"],
            },
        )
        self.assertEqual(provenance["after"], provenance["before"])
        self.assertEqual(
            provenance["entrypoint_sha256"],
            hashlib.sha256(
                (self.workspace / "bin/task-analyze").read_bytes()
            ).hexdigest(),
        )
        self.assertEqual(
            provenance["execution_id"],
            hashlib.sha256(b"operation-91001").hexdigest()[:32],
        )
        self.assertRegex(provenance["started_at"], r"^\d{4}-\d\d-\d\dT.*Z$")
        self.assertIsInstance(provenance["duration_ms"], int)
        raw = result["artifact"]["content"].encode()
        self.assertEqual(result["artifact"]["sha256"], hashlib.sha256(raw).hexdigest())
        self.assertEqual(result["artifact"]["bytes"], len(raw))

    def test_moved_candidate_refuses_as_stale_without_running_the_analyzer(self):
        self.install("#!/bin/sh\ntouch invoked\n", "bin/task-analyze")
        self.analysis_intent()
        self.inputs["intent"]["tree"] = "f" * 40
        self.workflow("analyze")
        run = self.run_workflow(success=False)
        steps = self.steps(run)
        self.assertEqual(
            json.loads(steps["result"]["outputs"]["stdout"]),
            {"error_code": "tasks.analysis_stale"},
        )
        self.assertEqual(steps["execute"]["status"], "skipped")
        self.assertFalse((self.workspace / "invoked").exists())

    def test_private_analysis_is_refused_in_the_terminal_result(self):
        self.install(
            '#!/usr/bin/python3\nimport json\nprint(json.dumps({"API_KEY":"synthetic-private-report-value"}))\n',
            "bin/task-analyze",
        )
        self.analysis_intent()
        self.workflow("analyze")
        run = self.run_workflow(success=False)
        self.assertEqual(run["status"], "failed")
        history = json.loads(self.cli("workflow", "history", "get", run["id"]).stdout)
        steps = {step["name"]: step for step in history["jobs"][0]["steps"]}
        # The Gateway reads only the result step; the analyzer stdout stays on Node 209.
        result = steps["result"]
        self.assertNotIn("synthetic-private-report-value", json.dumps(result))
        self.assertEqual(steps["outcome"]["status"], "failed")
        self.assertEqual(
            json.loads(result["outputs"]["stdout"]),
            {"error_code": "tasks.analysis_report_invalid"},
        )


if __name__ == "__main__":
    import unittest

    unittest.main()
