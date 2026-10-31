"""Official native section composition with shipped scripts and child publication."""

import hashlib
import json
import re
import shutil
import sys
from pathlib import Path

import yaml

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from tests.cfgmgmt_fixture import install_cfgmgmt
from tests.native_harness import NativeTestCase

ROOT = Path(__file__).resolve().parents[1]
HEADER = "# Managed by Orbit: Node Caddy build\n{ admin off }\n"


def site(scope, domain, upstream):
    return f"# orbit: routes {scope}\nhttps://{domain} {{\n    reverse_proxy {upstream}\n}}\n"


def config(*blocks):
    return HEADER + ("\n" + "\n".join(blocks) if blocks else "")


def descriptor(content):
    return {
        "content": content,
        "version": hashlib.sha256(content.encode()).hexdigest()[:32],
        "listen_addresses": ["10.44.0.9"],
        "unserved": "# orbit:" not in content,
    }


class CaddyDeltaNative(NativeTestCase):
    native_timeout = 100

    def setUp(self):
        super().setUp()
        connection = install_cfgmgmt(self)
        self.host = self.repo / "host"
        self.host.mkdir()
        self.audit = self.repo / "commands.log"
        commands = self.repo / "commands"
        commands.mkdir()
        program = """#!/usr/bin/env python3
import json, sys
from pathlib import Path
name = Path(sys.argv[0]).name
with Path(AUDIT).open("a") as output: output.write(json.dumps([name, *sys.argv[1:]])+"\\n")
if name == "caddy": print("v2.10.2 fixture")
if name == "systemctl" and sys.argv[1] == "show": print("a" * 32)
if name == "ip": print("1: wg0 inet 10.44.0.9/24 scope global")
""".replace("AUDIT", repr(str(self.audit)))
        for name in ("caddy", "systemctl", "runuser", "ip"):
            path = commands / name
            path.write_text(program)
            path.chmod(0o755)
        for name in ("operation-route-caddy", "operation-route-caddy-delta"):
            workflow = yaml.safe_load(
                (ROOT / f"workflows/workflow-{name}.yaml").read_text()
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
                    if task.get("behaviorType", "").startswith("@swamp/cfgmgmt/"):
                        task["globalArgs"]["become"] = False
                    if task.get("behaviorType") != "command/shell":
                        continue
                    script = task["inputs"]["run"]
                    script = re.sub(r"sudo --preserve-env=[A-Z_,]+ ", "", script)
                    script = script.replace(
                        'Path("/etc/caddy")', "Path(" + repr(str(self.host)) + ")"
                    )
                    script = script.replace(".st_uid != 0", ".st_uid != os.getuid()")
                    script = script.replace(
                        'grp.getgrnam("caddy").gr_gid', "os.getgid()"
                    )
                    script = script.replace(
                        "os.fchown(output.fileno(), 0, gid)",
                        "os.fchown(output.fileno(), os.getuid(), gid)",
                    )
                    script = re.sub(
                        r"os.chown\(([^,\n]+), 0, gid\)",
                        r"os.chown(\1, os.getuid(), gid)",
                        script,
                    )
                    for binary, replacement in (
                        ("/usr/bin/caddy", "caddy"),
                        ("/usr/bin/systemctl", "systemctl"),
                        ("/usr/sbin/runuser", "runuser"),
                        ("/usr/sbin/ip", "ip"),
                    ):
                        script = script.replace(binary, str(commands / replacement))
                    task["inputs"]["run"] = script
            (self.repo / f"workflows/workflow-{name}.yaml").write_text(
                yaml.safe_dump(workflow, sort_keys=False)
            )
        destination = self.repo / "extensions/behaviors/operations/routes"
        destination.mkdir(parents=True)
        shutil.copy(
            ROOT / "extensions/behaviors/operations/routes/caddy-input.ts", destination
        )
        shutil.copytree(
            ROOT / "extensions/behaviors/operations/routes/publication-command",
            destination / "publication-command",
        )
        for path in (destination / "publication-command/scripts").glob("*.py"):
            text = path.read_text().replace(
                'Path("/etc/caddy")', "Path(" + repr(str(self.host)) + ")"
            )
            text = text.replace(".st_uid != 0", ".st_uid != os.getuid()")
            path.write_text(text)
        assets = destination / "publication-command/assets.ts"
        assets.write_text(
            assets.read_text().replace('"/etc/caddy"', repr(str(self.host)))
        )
        self.a = site("moving", "app.test", "10.44.0.11")
        self.b = site("moving", "app.test", "10.44.0.12")
        self.survivor = site("survivor", "other.test", "10.44.0.13")
        self.revised = site("survivor", "other.test", "10.44.0.14")
        self.addition = site("addition", "added.test", "10.44.0.15")
        self.inputs = {
            "operation_id": 17,
            "node_id": 209,
            "kind": "instance.transfer",
            "execution_secrets": {},
            "intent": {
                "node_id": 9,
                "connection": connection,
                "user": "orbit",
                "before": descriptor(config(self.a, self.survivor)),
                "after": descriptor(config(self.b, self.survivor)),
                "direction": "forward",
            },
        }

    def run_delta(self, success=True):
        return self.cli(
            "workflow",
            "run",
            "operation-route-caddy-delta",
            "--input",
            json.dumps(self.inputs),
            success=success,
        )

    def test_forward_and_restoration_preserve_unrelated_current_revisions(self):
        live = self.host / "Caddyfile"
        live.write_text(config(self.a, self.revised, self.addition))
        self.run_delta()
        self.assertEqual(live.read_text(), config(self.b, self.revised, self.addition))
        self.assertTrue((live.resolve().parent / "loaded.json").is_file())
        self.inputs["intent"]["direction"] = "restore"
        self.run_delta()
        self.assertEqual(live.read_text(), config(self.a, self.revised, self.addition))

    def test_owned_conflict_refuses_before_publication_or_service_effects(self):
        live = self.host / "Caddyfile"
        conflict = config(site("moving", "app.test", "10.44.0.99"), self.revised)
        live.write_text(conflict)
        failure = self.run_delta(success=False)
        run_id = json.loads(failure.stdout)["id"]
        detail = self.cli(
            "data",
            "query",
            f'workflowRunId == "{run_id}" && stepName == "compose" && name == "result"',
            "--select",
            "content",
        )
        self.assertIn(
            "owned_revision_conflict", json.loads(detail.stdout)["results"][0]["stderr"]
        )
        self.assertEqual(live.read_text(), conflict)
        self.assertFalse(self.audit.exists())

    def test_empty_destination_can_publish_without_adopting_a_foreign_file(self):
        self.inputs["intent"]["before"] = descriptor(config())
        self.inputs["intent"]["after"] = descriptor(config(self.b))
        self.run_delta()
        self.assertEqual((self.host / "Caddyfile").read_text(), config(self.b))

    def test_intervening_revision_refuses_then_native_resume_recomposes(self):
        live = self.host / "Caddyfile"
        live.write_text(config(self.a, self.revised))
        extra = site("late", "late.test", "10.44.0.16")
        script = (
            "import pathlib\np=pathlib.Path("
            + repr(str(live))
            + ")\nmarker=pathlib.Path("
            + repr(str(self.host / "interleaved"))
            + ")\nif not marker.exists():\n p.write_text(p.read_text()+"
            + repr("\n" + extra)
            + ")\n marker.touch()\nprint('interleaved')\n"
        )
        workflow_path = (
            self.repo / "workflows/workflow-operation-route-caddy-delta.yaml"
        )
        workflow = yaml.safe_load(workflow_path.read_text())
        publication = next(
            job for job in workflow["jobs"] if job["name"] == "publication"
        )
        publication["steps"][0]["dependsOn"] = [
            {"step": "fixture-interleave", "condition": {"type": "succeeded"}}
        ]
        publication["steps"].insert(
            0,
            {
                "name": "fixture-interleave",
                "task": {
                    "type": "behavior",
                    "behaviorType": "command/shell",
                    "behaviorName": "fixture-delta-interleave",
                    "methodName": "execute",
                    "inputs": {
                        "run": "/usr/bin/python3 - <<'PYTHON'\n" + script + "PYTHON\n"
                    },
                },
            },
        )
        workflow_path.write_text(yaml.safe_dump(workflow, sort_keys=False))
        failure = self.run_delta(success=False)
        self.assertFalse(self.audit.exists())
        self.assertEqual(live.read_text(), config(self.a, self.revised, extra))
        run_id = json.loads(failure.stdout)["id"]
        self.cli(
            "workflow",
            "resume",
            "operation-route-caddy-delta",
            "--run",
            run_id,
            "--from",
            "compose",
        )
        self.assertEqual(live.read_text(), config(self.b, self.revised, extra))
