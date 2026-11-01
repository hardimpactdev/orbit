"""Execute the actual start, stop and restart DAG with scratch files and a fake service manager."""

import importlib.util
import json
import os
import shutil
import sys
from pathlib import Path

import yaml

spec = importlib.util.spec_from_file_location(
    "process_fixture", Path(__file__).with_name("native_test.py")
)
assert spec is not None and spec.loader is not None
fixture = importlib.util.module_from_spec(spec)
spec.loader.exec_module(fixture)


class ProcessControl(fixture.NativeProcessFixture):
    def setUp(self):
        super().setUp()
        self.workflow = yaml.safe_load(
            (
                fixture.ROOT / "workflows/workflow-operation-process-control.yaml"
            ).read_text()
        )
        official = self.repo / ".swamp/pulled-extensions/@swamp/ssh"
        official.parent.mkdir(parents=True)
        shutil.copytree(fixture.ROOT / ".swamp/pulled-extensions/@swamp/ssh", official)
        inventory = json.loads(
            (fixture.ROOT / "extensions/models/upstream_extensions.json").read_text()
        )
        path = self.repo / "extensions/models/upstream_extensions.json"
        path.parent.mkdir(parents=True)
        path.write_text(json.dumps({"@swamp/ssh": inventory["@swamp/ssh"]}))
        tools = self.repo / "tools"
        tools.mkdir()
        fake = tools / "ssh"
        fake.write_text(
            "#!"
            + sys.executable
            + "\n"
            + """import shlex,subprocess,sys
args=sys.argv[1:]
if '-M' in args or '-O' in args:raise SystemExit(0)
argv=shlex.split(args[-1])
if argv[:3]==['sudo','-n','--']:argv=argv[3:]
r=subprocess.run(argv,input=sys.stdin.buffer.read(),capture_output=True)
sys.stdout.buffer.write(r.stdout);sys.stderr.buffer.write(r.stderr)
raise SystemExit(r.returncode)
"""
        )
        fake.chmod(0o755)
        scp = tools / "scp"
        scp.write_text(
            "#!"
            + sys.executable
            + "\n"
            + """import shutil,sys
from pathlib import Path
src=Path(sys.argv[-2]);dst=Path(sys.argv[-1].split(':',1)[1])
shutil.copytree(src,dst/src.name,dirs_exist_ok=True)
"""
        )
        scp.chmod(0o755)
        self.native_env["PATH"] = str(tools) + ":" + os.environ["PATH"]
        self.workflow["name"] = "process-command-fixture"
        for job in self.workflow["jobs"]:
            self.assertEqual(job.pop("target"), "orbit-node-209")
            self.assertNotIn("labels", job)
            # The Vite child is its own workflow; these Processes have no Vite plan.
            job["steps"] = [step for step in job["steps"] if step["name"] != "Publish the dev route"]
            for step in job["steps"]:
                step["dependsOn"] = [edge for edge in step.get("dependsOn", []) if edge["step"] != "Publish the dev route"]
                self.assertEqual(step.pop("target"), "orbit-node-209")
                self.assertNotIn("labels", step)
                task = step["task"]
                if task["behaviorType"] == "@swamp/ssh":
                    task["globalArgs"].update(sshBinary=str(fake), scpBinary=str(scp))
        raw = yaml.safe_dump(self.workflow, sort_keys=False).replace(
            "/tmp/orbit-process-control-", str(self.repo / "remote-staging-")
        )
        self.workflow = yaml.safe_load(raw)
        self.inputs = {
            "operation_id": 91,
            "node_id": 9,
            "kind": "process.stop",
            "execution_secrets": {},
            "intent": {
                "control": self.inputs["control"],
                "ssh_execution": {
                    "control_node_id": 209,
                    "identity_file": str(self.repo / "existing-identity"),
                    "public_key": "ssh-ed25519 fixture-public-only",
                    "target": {
                        "name": "target",
                        "host": "198.51.100.9",
                        "user": "orbit",
                        "port": 22,
                        "host_keys": [
                            "ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIJ111111111111111111111111111111111111111111"
                        ],
                    },
                },
            },
        }
        (self.repo / "existing-identity").write_text("fixture identity never consumed")
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
            + "if not failed and cmd in ('enable','disable'):s['enabled']=cmd=='enable'\n"
            + "if not failed and cmd in ('start','stop','restart'):s['active']=cmd!='stop'\n"
            + "if not failed and cmd=='restart':s['restarts']=s.get('restarts',0)+1\n"
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
            step for step in history["jobs"][0]["steps"] if step["name"] == "Verify the state"
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
        self.assertEqual(steps["Control the Process"], "skipped")
        self.assertEqual(steps["Verify the state"], "skipped")

    def test_foreign_unit_refuses_before_boot_policy_or_stop(self):
        self.unit.write_text("[Unit]\nX-Orbit-Process-ID=99\n")

        run = self.run_components(success=False)

        self.assertEqual(run["status"], "failed")
        self.assertEqual(json.loads(self.state.read_text())["calls"], [])

    def use_docker(self):
        self.inputs["intent"]["control"]["runtime"] = "docker"
        self.inputs["intent"]["control"]["name"] = "orbit-process-17-worker"
        docker = self.repo / "docker-fixture"
        docker.write_text(
            "#!/usr/bin/python3\nimport json,sys\nfrom pathlib import Path\n"
            + "p=Path("
            + repr(str(self.state))
            + ")\n"
            + "s=json.loads(p.read_text())\ncmd=sys.argv[3]\n"
            + "s['calls'].append(['docker',cmd])\n"
            + "if cmd in ('start','stop','restart'):s['active']=cmd!='stop'\n"
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


    def test_docker_stop_skips_systemd_boot_policy(self):
        self.use_docker()

        run = self.run_components()

        self.assertEqual(run["status"], "succeeded")
        state = json.loads(self.state.read_text())
        self.assertFalse(state["active"])
        self.assertTrue(state["enabled"])
        self.assertTrue(all(call[0] == "docker" for call in state["calls"]))
        self.assertIn(["docker", "stop"], state["calls"])
        steps = {step["name"]: step["status"] for step in run["jobs"][0]["steps"]}
        self.assertEqual(steps["Set boot policy"], "skipped")

    def test_native_resume_reuses_copied_assets_after_a_failed_stop_policy(self):
        state = json.loads(self.state.read_text())
        state["refuse"] = "disable"
        self.state.write_text(json.dumps(state))
        run = self.run_components(success=False)
        state = json.loads(self.state.read_text())
        state.pop("refuse")
        self.state.write_text(json.dumps(state))

        self.cli(
            "workflow",
            "resume",
            "process-command-fixture",
            "--run",
            run["id"],
            "--from",
            "Set boot policy",
        )

        state = json.loads(self.state.read_text())
        actions = [call[0] for call in state["calls"] if call[0] != "is-active"]
        self.assertEqual(actions, ["disable", "disable", "stop"])
        self.assertFalse(state["active"])
        self.assertFalse(state["enabled"])

    def test_start_enables_then_starts_and_verifies_running(self):
        self.state.write_text(json.dumps({"active": False, "enabled": False, "calls": []}))
        self.inputs["kind"] = "process.start"

        run = self.run_components()

        self.assertEqual(run["status"], "succeeded")
        state = json.loads(self.state.read_text())
        actions = [call[0] for call in state["calls"] if call[0] != "is-active"]
        self.assertEqual(actions, ["enable", "start"])
        self.assertTrue(state["enabled"])
        self.assertTrue(state["active"])

    def test_on_demand_restart_keeps_boot_policy_and_restarts(self):
        self.state.write_text(json.dumps({"active": True, "enabled": False, "calls": []}))
        self.inputs["kind"] = "process.restart"
        self.inputs["intent"]["control"]["on_demand"] = True

        run = self.run_components()

        self.assertEqual(run["status"], "succeeded")
        state = json.loads(self.state.read_text())
        actions = [call[0] for call in state["calls"] if call[0] != "is-active"]
        self.assertEqual(actions, ["restart"])
        self.assertFalse(state["enabled"])
        steps = {step["name"]: step["status"] for step in run["jobs"][0]["steps"]}
        self.assertEqual(steps["Set boot policy"], "skipped")

    def test_docker_start_skips_boot_policy_and_starts_the_container(self):
        self.use_docker()
        state = json.loads(self.state.read_text())
        state["active"] = False
        self.state.write_text(json.dumps(state))
        self.inputs["kind"] = "process.start"

        run = self.run_components()

        self.assertEqual(run["status"], "succeeded")
        state = json.loads(self.state.read_text())
        self.assertTrue(state["active"])
        self.assertIn(["docker", "start"], state["calls"])
        steps = {step["name"]: step["status"] for step in run["jobs"][0]["steps"]}
        self.assertEqual(steps["Set boot policy"], "skipped")

    def test_missing_release_refuses_before_boot_policy_and_start(self):
        self.state.write_text(json.dumps({"active": False, "enabled": False, "calls": []}))
        self.inputs["kind"] = "process.start"
        self.inputs["intent"]["control"]["directory"] = str(self.repo / "missing-release")

        run = self.run_components(success=False)

        self.assertEqual(run["status"], "failed")
        self.assertEqual(json.loads(self.state.read_text())["calls"], [])
        steps = {step["name"]: step["status"] for step in run["jobs"][0]["steps"]}
        self.assertEqual(steps["Check the release"], "failed")
        self.assertEqual(steps["Control the Process"], "skipped")

    def test_vite_start_checks_the_listener_and_marks_awake_but_restart_does_not(self):
        listener = (
            self.repo
            / "extensions/behaviors/operations/process-commands/scripts/lifecycle_listener.py"
        )
        listener.write_text(
            "from process_common import main\n\nif __name__ == '__main__':\n"
            "    main(lambda value: {'ready': True})\n"
        )
        for kind, awake in (("process.restart", False), ("process.start", True)):
            with self.subTest(kind=kind):
                marker = self.repo / "hibernation" / (kind + ".awake")
                self.state.write_text(json.dumps({"active": False, "enabled": False, "calls": []}))
                self.inputs["kind"] = kind
                self.inputs["intent"]["control"]["vite"] = {
                    "path": "/etc/orbit/vite/app-instance-8.env",
                    "instance_id": 8,
                }
                self.inputs["intent"]["vite_plan"] = {
                    "awake": {"marker": str(marker)},
                    "listener": {"port": 4000},
                }

                run = self.run_components()

                self.assertEqual(run["status"], "succeeded")
                steps = {step["name"]: step["status"] for step in run["jobs"][0]["steps"]}
                self.assertEqual(steps["Check the listener"], "succeeded")
                self.assertEqual(steps["Mark it awake"], "succeeded" if awake else "skipped")
                self.assertEqual(marker.exists(), awake)

    def test_failed_listener_keeps_the_run_directory_for_resume(self):
        self.state.write_text(json.dumps({"active": False, "enabled": False, "calls": []}))
        self.inputs["kind"] = "process.start"
        self.inputs["intent"]["control"]["vite"] = {
            "path": "/etc/orbit/vite/app-instance-8.env",
            "instance_id": 8,
        }
        self.inputs["intent"]["vite_plan"] = {
            "awake": {"marker": str(self.repo / "hibernation/never.awake")},
            "listener": {"port": 4000},
        }

        run = self.run_components(success=False)

        self.assertEqual(run["status"], "failed")
        steps = {step["name"]: step["status"] for step in run["jobs"][0]["steps"]}
        self.assertEqual(steps["Check the listener"], "failed")
        self.assertEqual(steps["Clean up"], "skipped")
        self.assertTrue(list(self.repo.glob("remote-staging-*")))
