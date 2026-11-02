"""Run maintenance through the official SSH extension against a disposable remote fixture."""

import importlib.util
import json
import os
import shutil
import sys
from pathlib import Path

import yaml

ROOT = Path(__file__).resolve().parents[4]
spec = importlib.util.spec_from_file_location(
    "repository_fixture",
    ROOT / "extensions/behaviors/operations/task-repository/native_test.py",
)
fixture = importlib.util.module_from_spec(spec)
spec.loader.exec_module(fixture)


class NativeMaintenance(fixture.NativeRepository):
    test_analysis_native_dag_retains_exact_report = None
    test_private_analysis_is_refused_before_native_history = None
    test_script_native_steps_return_business_failure_with_literal_arguments = None
    test_native_resume_after_result_failure_never_reexecutes_program = None
    native_timeout = 150

    def setUp(self):
        super().setUp()
        for name in ("task-maintenance", "task-vm-workspace"):
            self.copy_behaviors("operations/" + name)
        for family in ("_lib", "js-maintenance"):
            shutil.copytree(
                ROOT / "extensions/behaviors" / family,
                self.repo / "extensions/behaviors" / family,
                dirs_exist_ok=True,
            )
        shutil.copytree(
            ROOT / "extensions/behaviors/operations/_lib",
            self.repo / "extensions/behaviors/operations/_lib",
            dirs_exist_ok=True,
        )
        self.inputs["kind"] = "tasks.maintenance"
        self.inputs["intent"].update(
            task_vm_id=10,
            project_id=20,
            maintenance={
                "mode": "routine",
                "project": {
                    "projectId": 20,
                    "repository": "https://github.com/acme/test.git",
                    "defaultBranch": "main",
                    "check": "composer check",
                    "worker": {"composerPaths": ["."], "jsPaths": [], "policy": {}},
                },
                "targets": [],
            },
        )
        self.inputs["intent"]["ssh_execution"]["public_key"] = "ssh-ed25519 PUBLIC"
        self.inputs["intent"]["ssh_execution"]["control_node_id"] = 209
        self.inputs["intent"]["ssh_execution"]["target"]["name"] = "fixture"
        self.git("config", "user.email", "test@example.test")
        self.git("config", "user.name", "Test")
        (self.workspace / "composer.json").write_text('{"require":{}}\n')
        (self.workspace / "composer.lock").write_text('{"packages":[]}\n')
        self.git("add", ".")
        self.git("commit", "-qm", "fixture")
        self.inputs["intent"]["base"] = self.git("rev-parse", "HEAD")
        composer = self.repo / "tools/composer"
        composer.write_text(
            "#!"
            + sys.executable
            + '\nimport json,sys\nfrom pathlib import Path\nif sys.argv[1]=="audit":print(json.dumps({"advisories":{}}))\nelif sys.argv[1]=="update":\n p=Path(".git/updates");p.write_text(str(int(p.read_text())+1) if p.exists() else "1")\nelse:raise SystemExit(1)\n'
        )
        composer.chmod(0o755)
        # Workspaces query the captured managed account, not a guest Swamp worker.
        tools = self.repo / "tools"
        for name in ("getent", "id"):
            path = tools / name
            path.write_text("#!/bin/sh\nexit 0\n")
            path.chmod(0o755)
        self.native_env["PATH"] = str(tools) + ":" + os.environ["PATH"]
        for name in (
            "task-vm-workspace",
            "task-maintenance-path",
            "operation-task-maintenance",
        ):
            flow = yaml.safe_load(
                (ROOT / f"workflows/workflow-{name}.yaml").read_text()
            )
            for job in flow["jobs"]:
                job.pop("target", None); job.pop("labels", None)
                job.pop("labels", None)
                for step in job["steps"]:
                    self.assertEqual(step.pop("labels"), {"role": "swamp"})
                    step.pop("labels", None)
                    # Keep the production inputs and graph, changing only local placement and temp namespace.
                    task = step["task"]
                    inputs = task.get("inputs", {})
                    for key, value in list(inputs.items()):
                        if isinstance(value, str):
                            inputs[key] = value.replace(
                                "/tmp/orbit-task-repository-",
                                str(self.repo / "remote-assets-"),
                            )
                        elif isinstance(value, list):
                            inputs[key] = [
                                v.replace(
                                    "/tmp/orbit-task-repository-",
                                    str(self.repo / "remote-assets-"),
                                )
                                if isinstance(v, str)
                                else v
                                for v in value
                            ]
            (self.repo / f"workflows/workflow-{name}.yaml").write_text(
                yaml.safe_dump(flow, sort_keys=False)
            )
        self.flow = yaml.safe_load(
            (
                self.repo / "workflows/workflow-operation-task-maintenance.yaml"
            ).read_text()
        )
        self.flow["name"] = "task-repository-fixture"
        self.write_workflow()

    def test_routine_composes_workspace_and_updates_once_over_official_ssh(self):
        result = self.run_workflow()
        self.assertEqual(result["status"], "succeeded")
        self.assertEqual((self.workspace / ".git/updates").read_text(), "1")
        history = json.loads(
            self.cli("workflow", "history", "get", result["id"]).stdout
        )
        output = json.loads(
            next(s for s in history["jobs"][-1]["steps"] if s["name"] == "result")[
                "outputs"
            ]["stdout"]
        )
        self.assertFalse(output["changed"])
        self.assertEqual(output["group_id"], 1)

    def test_committed_and_untracked_repairs_count_against_provision_base(self):
        (self.workspace / "repair.txt").write_text("repair before maintenance")
        self.git("add", ".")
        self.git("commit", "-qm", "repair")
        (self.workspace / "untracked.txt").write_text("untracked repair")
        result = self.run_workflow()
        self.assertEqual(result["status"], "succeeded")
        history = json.loads(
            self.cli("workflow", "history", "get", result["id"]).stdout
        )
        output = json.loads(
            next(s for s in history["jobs"][-1]["steps"] if s["name"] == "result")[
                "outputs"
            ]["stdout"]
        )
        self.assertTrue(output["changed"])

    def fixture_releases(self):
        path = (
            self.repo / "extensions/behaviors/operations/task-maintenance/metadata.ts"
        )
        source = path.read_text()
        old = "const response = await fetch(url, { signal: ctx.signal });"
        assert old in source
        source = source.replace(
            old,
            "const response = new Response(JSON.stringify(args.manager === 'composer' ? {packages:{[args.name]:[{version:'2.0.0',time:'2020-01-01T00:00:00Z'}]}} : {time:{'2.0.0':'2020-01-01T00:00:00Z'}}));",
        )
        path.write_text(source)

    def test_package_exact_composer_solve_preserves_declared_range(self):
        self.fixture_releases()
        (self.workspace / "composer.json").write_text(
            '{"require":{"acme/lib":"^1.0.0"}}\n'
        )
        (self.workspace / "composer.lock").write_text(
            '{"packages":[{"name":"acme/lib","version":"1.0.0"}]}\n'
        )
        self.git("add", ".")
        self.git("commit", "-qm", "package")
        target = {
            "path": ".",
            "manager": "composer",
            "package": "acme/lib",
            "version": "2.0.0",
            "purpose": "major",
            "advisories": [],
            "advisoryUrls": [],
        }
        self.inputs["intent"]["maintenance"].update(mode="package", targets=[target])
        composer = self.repo / "tools/composer"
        composer.write_text(
            "#!"
            + sys.executable
            + '\nimport json,sys\nfrom pathlib import Path\nif sys.argv[1]=="audit":print(json.dumps({"advisories":{}}))\nelif sys.argv[1]=="update":Path("composer.lock").write_text(json.dumps({"packages":[{"name":"acme/lib","version":"2.0.0"}]}))\nelse:raise SystemExit(1)\n'
        )
        composer.chmod(0o755)
        result = self.run_workflow()
        self.assertEqual(result["status"], "succeeded")
        self.assertEqual(
            json.loads((self.workspace / "composer.json").read_text())["require"][
                "acme/lib"
            ],
            "^2.0.0",
        )
        self.assertEqual(
            json.loads((self.workspace / "composer.lock").read_text())["packages"][0][
                "version"
            ],
            "2.0.0",
        )

    def test_package_js_restores_range_and_preserves_the_exact_solved_lock(self):
        self.fixture_releases()
        (self.workspace / "package.json").write_text(
            '{"packageManager":"npm@11","dependencies":{"lib":"^1.0.0"}}\n'
        )
        (self.workspace / "package-lock.json").write_text(
            '{"packages":{"node_modules/lib":{"version":"1.0.0"}}}\n'
        )
        self.git("add", ".")
        self.git("commit", "-qm", "js-package")
        target = {
            "path": ".",
            "manager": "js",
            "package": "lib",
            "version": "2.0.0",
            "purpose": "major",
            "advisories": [],
            "advisoryUrls": [],
        }
        maintenance = self.inputs["intent"]["maintenance"]
        maintenance.update(mode="package", targets=[target])
        maintenance["project"]["worker"].update(composerPaths=[], jsPaths=["."])
        npm = self.repo / "tools/npm"
        npm.write_text(
            "#!"
            + sys.executable
            + '\nimport json,sys\nfrom pathlib import Path\nif sys.argv[1]=="audit":print(json.dumps({"vulnerabilities":{}}))\nelif sys.argv[1]=="install":\n v=json.loads(Path("package.json").read_text())["dependencies"]["lib"].lstrip("^~");Path("package-lock.json").write_text(json.dumps({"packages":{"node_modules/lib":{"version":v}}}))\nelse:raise SystemExit(1)\n'
        )
        npm.chmod(0o755)
        result = self.run_workflow()
        self.assertEqual(result["status"], "succeeded")
        self.assertEqual(
            json.loads((self.workspace / "package.json").read_text())["dependencies"][
                "lib"
            ],
            "^2.0.0",
        )
        self.assertEqual(
            json.loads((self.workspace / "package-lock.json").read_text())["packages"][
                "node_modules/lib"
            ]["version"],
            "2.0.0",
        )


if __name__ == "__main__":
    import unittest

    unittest.main()
