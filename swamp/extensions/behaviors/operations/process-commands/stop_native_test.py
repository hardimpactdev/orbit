"""Execute the actual explicit-stop DAG with scratch files and a fake service manager."""

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


class ExplicitProcessStop(fixture.NativeProcessFixture):
    def setUp(self):
        super().setUp()
        self.workflow = yaml.safe_load(
            (
                fixture.ROOT / "workflows/workflow-operation-process-stop.yaml"
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
            self.assertEqual(job.pop("labels"), {"node": "209"})
            for step in job["steps"]:
                self.assertEqual(step.pop("target"), "orbit-node-209")
                self.assertEqual(step.pop("labels"), {"node": "209"})
                task = step["task"]
                if task["behaviorType"] == "@swamp/ssh":
                    task["globalArgs"].update(sshBinary=str(fake), scpBinary=str(scp))
        raw = yaml.safe_dump(self.workflow, sort_keys=False).replace(
            "/tmp/orbit-process-stop-", str(self.repo / "remote-staging-")
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
            "disable",
        )

        state = json.loads(self.state.read_text())
        actions = [call[0] for call in state["calls"] if call[0] != "is-active"]
        self.assertEqual(actions, ["disable", "disable", "stop"])
        self.assertFalse(state["active"])
        self.assertFalse(state["enabled"])
