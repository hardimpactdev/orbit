"""Execute the actual explicit-stop DAG with scratch files and a fake service manager."""

import importlib.util
import json
from pathlib import Path

import yaml

spec = importlib.util.spec_from_file_location(
    "process_fixture", Path(__file__).with_name("native_test.py")
)
assert spec is not None and spec.loader is not None
fixture = importlib.util.module_from_spec(spec)
spec.loader.exec_module(fixture)


class ExplicitProcessStop(fixture.NativeProcessFixture):
    def setUp(self):
        super().setUp()
        self.workflow = yaml.safe_load(
            (
                fixture.ROOT / "workflows/workflow-operation-process-stop.yaml"
            ).read_text()
        )
        self.workflow["name"] = "process-command-fixture"
        for job in self.workflow["jobs"]:
            job.pop("target", None)
            job.pop("labels", None)
            for step in job["steps"]:
                step.pop("target", None)
                step.pop("labels", None)
                inputs = step["task"]["inputs"]
                if "run" in inputs:
                    inputs["run"] = (
                        "/usr/bin/python3"
                        + inputs["run"].split("/usr/bin/python3", 1)[1]
                    )
        self.inputs = {
            "operation_id": 91,
            "node_id": 9,
            "kind": "process.stop",
            "execution_secrets": {},
            "intent": self.inputs["control"],
        }
        self.state.write_text(
            json.dumps({"active": True, "enabled": True, "calls": []})
        )
        (self.repo / "systemctl-fixture").write_text(
            "#!/usr/bin/python3\n"
            "import json,sys\nfrom pathlib import Path\n"
            + "p=Path("
            + repr(str(self.state))
            + ")\n"
            + "s=json.loads(p.read_text())\n"
            + "cmd=sys.argv[1]\ns['calls'].append(sys.argv[1:])\n"
            + "failed=cmd==s.get('refuse')\n"
            + "if not failed and cmd=='disable':s['enabled']=False\n"
            + "if not failed and cmd=='stop':s['active']=False\n"
            + "p.write_text(json.dumps(s))\n"
            + "if cmd=='is-active':\n"
            + " print('active' if s['active'] else 'inactive')\n"
            + " sys.exit(0 if s['active'] else 3)\n"
            + "sys.exit(1 if failed else 0)\n"
        )

    def test_explicit_stop_disables_then_stops_and_projects_observed_state(self):
        run = self.run_components()

        self.assertEqual(run["status"], "succeeded")
        state = json.loads(self.state.read_text())
        actions = [call[0] for call in state["calls"] if call[0] != "is-active"]
        self.assertEqual(actions, ["disable", "stop"])
        self.assertFalse(state["enabled"])
        self.assertFalse(state["active"])
        history = json.loads(self.cli("workflow", "history", "get", run["id"]).stdout)
        result = next(
            step for step in history["jobs"][0]["steps"] if step["name"] == "verify"
        )
        self.assertEqual(
            json.loads(result["outputs"]["stdout"]),
            {"ready": True, "state": "inactive"},
        )

    def test_boot_policy_failure_skips_stop_and_verification(self):
        state = json.loads(self.state.read_text())
        state["refuse"] = "disable"
        self.state.write_text(json.dumps(state))

        run = self.run_components(success=False)

        self.assertEqual(run["status"], "failed")
        state = json.loads(self.state.read_text())
        self.assertTrue(state["active"])
        self.assertTrue(state["enabled"])
        self.assertNotIn(["stop", self.unit.name], state["calls"])
        steps = {step["name"]: step["status"] for step in run["jobs"][0]["steps"]}
        self.assertEqual(steps["stop"], "skipped")
        self.assertEqual(steps["verify"], "skipped")

    def test_foreign_unit_refuses_before_boot_policy_or_stop(self):
        self.unit.write_text("[Unit]\nX-Orbit-Process-ID=99\n")

        run = self.run_components(success=False)

        self.assertEqual(run["status"], "failed")
        self.assertEqual(json.loads(self.state.read_text())["calls"], [])

    def test_docker_stop_skips_systemd_boot_policy(self):
        self.inputs["intent"]["runtime"] = "docker"
        self.inputs["intent"]["name"] = "orbit-process-17-worker"
        docker = self.repo / "docker-fixture"
        docker.write_text(
            "#!/usr/bin/python3\nimport json,sys\nfrom pathlib import Path\n"
            + "p=Path("
            + repr(str(self.state))
            + ")\n"
            + "s=json.loads(p.read_text())\ncmd=sys.argv[3]\n"
            + "s['calls'].append(['docker',cmd])\n"
            + "if cmd=='stop':s['active']=False\n"
            + "p.write_text(json.dumps(s))\n"
            + "if cmd=='ls':print('orbit-process-17-worker')\n"
            + "if cmd=='inspect':print('true\\nprocess\\n17\\n'+'a'*64+'\\n'+('running' if s['active'] else 'exited'))\n"
        )
        docker.chmod(0o755)
        common = (
            self.repo
            / "extensions/behaviors/operations/process-commands/scripts/process_common.py"
        )
        common.write_text(
            common.read_text().replace('"/usr/bin/docker"', repr(str(docker)))
        )

        run = self.run_components()

        self.assertEqual(run["status"], "succeeded")
        state = json.loads(self.state.read_text())
        self.assertFalse(state["active"])
        self.assertTrue(state["enabled"])
        self.assertTrue(all(call[0] == "docker" for call in state["calls"]))
        self.assertIn(["docker", "stop"], state["calls"])
        steps = {step["name"]: step["status"] for step in run["jobs"][0]["steps"]}
        self.assertEqual(steps["disable"], "skipped")
