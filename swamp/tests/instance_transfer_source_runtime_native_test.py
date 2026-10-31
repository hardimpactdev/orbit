"""Run the transfer's real Process fan-out using canonical scripts in a scratch host."""

import importlib.util
import json
from pathlib import Path

import yaml

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location(
    "transfer_process_fixture",
    ROOT / "extensions/behaviors/operations/process-commands/native_test.py",
)
assert spec is not None and spec.loader is not None
fixture = importlib.util.module_from_spec(spec)
spec.loader.exec_module(fixture)


class TransferSourceRuntime(fixture.NativeProcessFixture):
    def setUp(self):
        super().setUp()
        manager = self.repo / "systemctl-fixture"
        manager.write_text(
            manager.read_text().replace(
                'if sys.argv[1]=="stop":s["active"]=False',
                'if sys.argv[1]=="stop":s["active"]=False\n'
                'if sys.argv[1]=="start":s["active"]=True',
            )
        )
        self.workflow = yaml.safe_load(
            (ROOT / "workflows/workflow-instance-transfer-source-runtime.yaml").read_text()
        )
        for job in self.workflow["jobs"]:
            for step in job["steps"]:
                # Local disposable host; production placement is checked separately.
                step.pop("target")
                step.pop("labels")
                inputs = step["task"]["inputs"]
                if "run" in inputs:
                    inputs["run"] = inputs["run"][inputs["run"].index("/usr/bin/python3"):]
        (self.repo / "workflows/workflow-instance-transfer-source-runtime.yaml").write_text(
            yaml.safe_dump(self.workflow, sort_keys=False)
        )
        control = self.inputs["control"]
        installation = {key: value for key, value in control.items() if key != "vite"}
        installation.update(
            desired="running", retired=None, activation_pending=False, content_hash="a" * 64
        )
        self.member = {"id": 17, "input": {"control": control, "installation": installation}}
        self.inputs = {
            "operation_id": 91001,
            "kind": "instance.transfer",
            "node_id": 9,
            "phase": "pause",
            "intent": {"process_runtime": {"source": {"node_id": 9, "members": [self.member]}}},
        }

    def run_source_runtime(self, success=True):
        self.cli("workflow", "validate", "instance-transfer-source-runtime")
        reply = self.cli(
            "workflow", "run", "instance-transfer-source-runtime", "--stdin",
            input=json.dumps(self.inputs), success=success,
        )
        run = json.loads(reply.stdout)
        history = json.loads(self.cli("workflow", "history", "get", run["id"]).stdout)
        return {step["name"]: step for job in history["jobs"] for step in job["steps"]}

    def docker_member(self, actual_spec):
        control = self.member["input"]["control"]
        control.update(runtime="docker", name="orbit-process-17-worker")
        self.member["input"]["installation"] = {
            key: value for key, value in control.items() if key != "vite"
        } | {"desired": "running", "retired": None, "spec": "a" * 64}
        self.state.write_text(json.dumps({"active": True, "calls": [], "spec": actual_spec}))
        docker = self.repo / "docker-fixture"
        docker.write_text(
            "#!/usr/bin/python3\nimport json,sys\nfrom pathlib import Path\np=Path("
            + repr(str(self.state))
            + ')\ns=json.loads(p.read_text())\ns["calls"].append(sys.argv[1:])\n'
            'if sys.argv[3]=="ls": print("orbit-process-17-worker")\n'
            'if sys.argv[3]=="inspect": print("true\\nprocess\\n17\\n"+s["spec"]+"\\n"+("running" if s["active"] else "exited"))\n'
            'if sys.argv[3]=="stop": s["active"]=False\n'
            'if sys.argv[3]=="start": s["active"]=True\n'
            "p.write_text(json.dumps(s))\n"
        )
        docker.chmod(0o755)
        common = self.repo / "extensions/behaviors/operations/process-commands/scripts/process_common.py"
        common.write_text(common.read_text().replace('"/usr/bin/docker"', repr(str(docker))))

    def test_pause_stops_and_verifies_without_changing_boot_policy(self):
        steps = self.run_source_runtime()

        state = json.loads(self.state.read_text())
        self.assertFalse(state["active"])
        self.assertEqual(["stop", self.unit.name], state["calls"][1])
        self.assertFalse(any(call[0] in ("enable", "disable") for call in state["calls"]))
        self.assertEqual(steps["verify-0"]["status"], "succeeded")
        self.assertEqual(json.loads(steps["verify-0"]["outputs"]["stdout"])["state"], "inactive")
        self.assertEqual(self.member["input"]["installation"]["desired"], "running")

    def test_restoration_starts_only_the_captured_desired_running_member(self):
        self.inputs["phase"] = "restore"
        self.state.write_text(json.dumps({"active": False, "calls": []}))

        steps = self.run_source_runtime()

        state = json.loads(self.state.read_text())
        self.assertTrue(state["active"])
        self.assertIn(["start", self.unit.name], state["calls"])
        self.assertFalse(any(call[0] in ("enable", "disable", "restart") for call in state["calls"]))
        self.assertEqual(steps["verify-0"]["status"], "succeeded")

    def test_restoration_keeps_a_desired_stopped_member_stopped(self):
        self.inputs["phase"] = "restore"
        self.member["input"]["installation"]["desired"] = "stopped"

        self.run_source_runtime()

        state = json.loads(self.state.read_text())
        self.assertFalse(state["active"])
        self.assertNotIn(["start", self.unit.name], state["calls"])

    def test_foreign_artifact_refuses_control_and_skips_verification(self):
        self.unit.write_text("X-Orbit-Process-ID=18\n")

        steps = self.run_source_runtime(success=False)

        self.assertEqual(steps["control-0"]["status"], "failed")
        self.assertEqual(steps["verify-0"]["status"], "skipped")
        self.assertEqual(json.loads(self.state.read_text())["calls"], [])

    def test_one_invalid_member_blocks_every_process_effect(self):
        invalid = json.loads(json.dumps(self.member))
        invalid["id"] = 18
        invalid["input"]["installation"]["process_id"] = 18
        self.inputs["intent"]["process_runtime"]["source"]["members"].append(invalid)

        steps = self.run_source_runtime(success=False)

        self.assertEqual(steps["assets-1"]["status"], "failed")
        self.assertTrue(all(step["status"] == "skipped" for name, step in steps.items()
                            if name.startswith(("control-", "verify-"))))
        self.assertEqual(json.loads(self.state.read_text())["calls"], [])

    def test_empty_process_batch_performs_no_effect(self):
        self.inputs["intent"]["process_runtime"]["source"]["members"] = []

        self.run_source_runtime()

        self.assertEqual(json.loads(self.state.read_text())["calls"], [])

    def test_docker_pause_uses_the_captured_spec_and_fixed_local_socket(self):
        self.docker_member("a" * 64)

        steps = self.run_source_runtime()

        state = json.loads(self.state.read_text())
        self.assertFalse(state["active"])
        self.assertIn(["--host=unix:///var/run/docker.sock", "container", "stop", "orbit-process-17-worker"],
                      state["calls"])
        self.assertEqual(steps["verify-0"]["status"], "succeeded")

    def test_docker_changed_spec_refuses_before_stop_and_verification(self):
        self.docker_member("b" * 64)

        steps = self.run_source_runtime(success=False)

        state = json.loads(self.state.read_text())
        self.assertTrue(state["active"])
        self.assertFalse(any(call[2] in ("stop", "start") for call in state["calls"]))
        self.assertEqual(steps["control-0"]["status"], "failed")
        self.assertEqual(steps["verify-0"]["status"], "skipped")
