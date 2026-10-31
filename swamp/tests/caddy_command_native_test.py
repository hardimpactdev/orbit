"""Official cfgmgmt file application followed by actual native shell dependencies."""

import grp
import hashlib
import json
import os
import pwd
import sys
from pathlib import Path

import yaml

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from tests.cfgmgmt_fixture import install_cfgmgmt
from tests.native_harness import NativeTestCase

ROOT = Path(__file__).resolve().parents[1]


class CaddyCommandNative(NativeTestCase):
    def setUp(self):
        super().setUp()
        self.connection = dict(
            install_cfgmgmt(self), nodeIdentityFile="/unused/fixture-key", become=True
        )
        self.host = self.repo / "host"
        self.host.mkdir()
        self.audit = self.repo / "audit"
        commands = self.repo / "commands"
        commands.mkdir()
        script = r"""#!/usr/bin/env python3
import json, os, sys
from pathlib import Path
name=Path(sys.argv[0]).name
with open(os.environ['FIXTURE_AUDIT'],'a') as out: out.write(json.dumps([name,*sys.argv[1:]])+'\n')
if name=='caddy' and os.environ.get('FIXTURE_FAIL')=='validate': sys.exit(1)
if name=='systemctl' and os.environ.get('FIXTURE_FAIL')=='reload': sys.exit(1)
"""
        for name in ("caddy", "systemctl"):
            path = commands / name
            path.write_text(script)
            path.chmod(0o700)
        self.native_env.update(FIXTURE_AUDIT=str(self.audit))
        workflow = yaml.safe_load(
            (ROOT / "workflows/workflow-operation-route-caddy.yaml").read_text()
        )
        for job in workflow["jobs"]:
            job.pop("target")
            job.pop("labels")
            for step in job["steps"]:
                self.assertIn("target", step)
                self.assertIn("labels", step)
                step.pop("target")
                step.pop("labels")
                task = step["task"]
                if task["behaviorType"] == "@swamp/cfgmgmt/file":
                    task["globalArgs"].update(
                        path=str(self.host / "Caddyfile"),
                        owner=pwd.getpwuid(os.getuid()).pw_name,
                        group=grp.getgrgid(os.getgid()).gr_name,
                        become=False,
                    )
                if task["behaviorType"] == "command/shell":
                    task["inputs"]["run"] = (
                        task["inputs"]["run"]
                        .replace("sudo -u caddy ", "")
                        .replace("sudo ", "")
                        .replace("/usr/bin/caddy", str(commands / "caddy"))
                        .replace("/usr/bin/systemctl", str(commands / "systemctl"))
                        .replace("/etc/caddy/Caddyfile", str(self.host / "Caddyfile"))
                    )
        folder = self.repo / "extensions/behaviors/operations/routes"
        folder.mkdir(parents=True)
        import shutil

        shutil.copy(
            ROOT / "extensions/behaviors/operations/routes/caddy-input.ts", folder
        )
        (self.repo / "workflows/workflow-operation-route-caddy.yaml").write_text(
            yaml.safe_dump(workflow, sort_keys=False)
        )
        self.content = "# Managed by Orbit: Node Caddy build\n{ admin off }\n"
        self.inputs = {
            "operation_id": 17,
            "node_id": 209,
            "kind": "route.caddy_converge",
            "execution_secrets": {},
            "intent": {
                "caddy_publication": {
                    "node_id": 9,
                    "user": "orbit",
                    "control_node_id": 14,
                    "connection": self.connection,
                    "caddy": {
                        "content": self.content,
                        "version": hashlib.sha256(self.content.encode()).hexdigest()[
                            :32
                        ],
                        "listen_addresses": ["10.44.0.9"],
                        "unserved": False,
                    },
                }
            },
        }

    def run_caddy(self, fail="", success=True):
        self.native_env["FIXTURE_FAIL"] = fail
        result = self.cli(
            "workflow",
            "run",
            "operation-route-caddy",
            "--input",
            json.dumps(self.inputs),
            success=success,
        )
        calls = (
            [json.loads(line) for line in self.audit.read_text().splitlines()]
            if self.audit.exists()
            else []
        )
        return json.loads(result.stdout), calls

    def test_official_file_replaces_previous_symlink_and_rerun_converges(self):
        old = self.host / "old-config"
        old.write_text("previous")
        (self.host / "Caddyfile").symlink_to(old)
        result, calls = self.run_caddy()
        self.assertEqual(result["status"], "succeeded")
        history = json.loads(
            self.cli("workflow", "history", "get", result["id"]).stdout
        )
        completion = next(job for job in history["jobs"] if job["name"] == "completion")
        terminal = next(
            step for step in completion["steps"] if step["name"] == "result"
        )
        self.assertEqual(
            json.loads(terminal["outputs"]["stdout"]),
            {
                "ready": True,
                "result": "published",
                "version": self.inputs["intent"]["caddy_publication"]["caddy"][
                    "version"
                ],
            },
        )
        self.assertEqual((self.host / "Caddyfile").read_text(), self.content)
        self.assertFalse((self.host / "Caddyfile").is_symlink())
        self.assertEqual([row[0] for row in calls], ["caddy", "systemctl"])
        self.assertEqual(calls[-1], ["systemctl", "reload", "caddy"])
        self.audit.unlink()
        self.run_caddy()
        self.assertEqual((self.host / "Caddyfile").read_text(), self.content)

    def test_validation_failure_skips_reload(self):
        result, calls = self.run_caddy("validate", success=False)
        self.assertEqual(result["status"], "failed")
        self.assertEqual([row[0] for row in calls], ["caddy"])
        self.assertEqual((self.host / "Caddyfile").read_text(), self.content)

    def test_reload_failure_fails_without_a_journal_and_rerun_succeeds(self):
        result, _ = self.run_caddy("reload", success=False)
        self.assertEqual(result["status"], "failed")
        self.assertEqual(sorted(p.name for p in self.host.iterdir()), ["Caddyfile"])
        result, _ = self.run_caddy()
        self.assertEqual(result["status"], "succeeded")
