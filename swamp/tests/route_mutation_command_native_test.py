"""Run the real native parent factory; replace child host effects at the workflow boundary."""

import json
import os
import shutil
import subprocess
import sys
from pathlib import Path

import yaml

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from tests.native_harness import NativeTestCase
from tests.route_ssh_fixture import PIN, install_ssh

ROOT = Path(__file__).resolve().parents[1]
DENO = os.environ.get("DENO_BIN", "/fast/worktrees/swamp-lane8/_scratch/tools/deno")


class RouteMutationFixture(NativeTestCase):
    def setUp(self):
        super().setUp()
        self.copy_behaviors("operations/routes/mutation-command")
        dependency = (
            self.repo
            / "extensions/behaviors/operations/routes/publication-command/sections.ts"
        )
        dependency.parent.mkdir(parents=True, exist_ok=True)
        shutil.copy(
            ROOT
            / "extensions/behaviors/operations/routes/publication-command/sections.ts",
            dependency,
        )
        self.audit = self.repo / "audit"
        self.native_env["FIXTURE_AUDIT"] = str(self.audit)
        self.native_env["FIXTURE_FAIL"] = ""
        self.plan = {
            "routes": [
                {
                    "route_id": 31,
                    "domain": "app.test",
                    "node_ids": [9, 10],
                    "certificate_scopes": [{"node_id": 9, "scope": "route-31-router"}],
                }
            ],
            "prepare_certificates": [{"node_id": 9}, {"node_id": 10}],
            "certificates": [{"node_id": 9}],
            "cleanup": [{"node_id": 9}],
            "url_writes": [{"node_id": 9, "secret_field": "route_material_0"}],
            "lan": [{"node_id": 9}],
            "retire_lan": [{"node_id": 9}],
            "ingress": [], "retire_ingress": [],
            "publications": [
                {
                    "node_id": node,
                    "user": "orbit",
                    "control_node_id": 209,
                    "connection": {},
                    "host_keys": [PIN],
                    "serves_routes": True,
                    **{
                        side: {"caddy": {}, "php": {}, "dns": {"digest": side}}
                        for side in ("before", "prepared", "withdrawn", "after")
                    },
                }
                for node in (9, 10)
            ],
        }
        self.input = {
            "operation_id": 7,
            "node_id": 14,
            "kind": "node.add",
            "execution_secrets": {
                "route_material_0": {"vault": "orbit-control-inputs", "key": "fixture"}
            },
            "intent": {"route_mutations": {"forward": self.plan, "restore": self.plan}},
        }
        script = """import json,os
from pathlib import Path
audit=Path(os.environ['FIXTURE_AUDIT'])
action=os.environ['FIXTURE_ACTION']
with audit.open('a') as output:output.write(json.dumps({'action':action,'kind':os.environ['FIXTURE_KIND'],'operation_id':int(os.environ['FIXTURE_OP']),'node_id':int(os.environ['FIXTURE_NODE'])})+'\\n')
marker=audit.with_suffix('.failed')
if os.environ.get('FIXTURE_FAIL')==action and not marker.exists():marker.touch();raise SystemExit(1)
print('{"ready":true}')
"""
        for index, name in enumerate(
            (
                "route-certificate-publish",
                "route-certificate-remove",
                "route-environment-write",
                "route-lan-apply",
                "route-dns-publish",
                "route-dns-grace",
                "route-ingress-apply",
                "operation-route-caddy-delta",
                "operation-route-php-delta",
            )
        ):
            task = {
                "type": "behavior",
                "behaviorType": "command/shell",
                "behaviorName": "fixture-" + name,
                "methodName": "execute",
                "inputs": {
                    "run": "python3 - <<'PYTHON'\n" + script + "PYTHON",
                    "env": {
                        "FIXTURE_ACTION": name,
                        "FIXTURE_KIND": "${{ inputs.kind }}",
                        "FIXTURE_OP": "${{ string(inputs.operation_id) }}",
                        "FIXTURE_NODE": "${{ string(inputs.node_id) }}",
                    },
                },
            }
            workflow = {
                "id": f"3ec8473a-4c45-4a54-a2c6-{index:012d}",
                "name": name,
                "inputs": {"type": "object"},
                "jobs": [
                    {"name": "effect", "steps": [{"name": "apply", "task": task}]}
                ],
            }
            (self.repo / f"workflows/workflow-{name}.yaml").write_text(
                yaml.safe_dump(workflow, sort_keys=False)
            )

    def parent(self, compensate=False, guard=None):
        module = (
            ROOT / "extensions/behaviors/operations/routes/mutation-command/steps.ts"
        )
        code = f'import {{routeMutationSteps}} from {json.dumps(str(module))}; console.log(JSON.stringify([routeMutationSteps("forward","inputs.intent.route_mutations","forward",{json.dumps(guard)}),routeMutationSteps("restore","inputs.intent.route_mutations","restore")]));'
        generated = json.loads(subprocess.check_output([DENO, "eval", code], text=True))
        jobs = [{"name": "forward", "steps": generated[0]}]
        if compensate:
            jobs.append(
                {
                    "name": "compensation",
                    "dependsOn": [{"job": "forward", "condition": {"type": "failed"}}],
                    "steps": generated[1],
                }
            )
        for job in jobs:
            for step in job["steps"]:
                step.pop("target")
                step.pop("labels")
        workflow = {
            "id": "3efabfbc-eb73-42e1-8426-a4e00f0fe035",
            "name": "fixture-route-mutation",
            "vaults": [],
            "inputs": {"type": "object"},
            "jobs": jobs,
        }
        (self.repo / "workflows/workflow-fixture-route-mutation.yaml").write_text(
            yaml.safe_dump(workflow, sort_keys=False)
        )

    def run_parent(self, success=True):
        reply = json.loads(
            self.cli(
                "workflow",
                "run",
                "fixture-route-mutation",
                "--input",
                json.dumps(self.input),
                success=success,
            ).stdout
        )
        return json.loads(self.cli("workflow", "history", "get", reply["id"]).stdout)



class RouteMutationNative(RouteMutationFixture):
    def test_multi_item_parent_requires_every_public_contribution(self):
        self.parent()
        history = self.run_parent()
        terminal = next(
            step for step in history["jobs"][0]["steps"] if step["name"] == "forward-0"
        )
        self.assertTrue(terminal["outputs"]["ready"])
        self.assertEqual(terminal["outputs"]["routes"][0]["route_id"], 31)
        calls = [json.loads(line) for line in self.audit.read_text().splitlines()]
        self.assertEqual(len(calls), 20)
        self.assertTrue(
            all(
                call["operation_id"] == 7
                and call["node_id"] == 14
                and call["kind"] == "node.add"
                for call in calls
            )
        )

    def test_empty_optional_fanouts_do_not_skip_required_publication(self):
        self.plan['lan'] = []
        self.plan['url_writes'] = []
        self.plan['cleanup'] = []
        self.plan['retire_lan'] = []
        self.parent()
        history = self.run_parent()
        result = next(step for step in history['jobs'][0]['steps'] if step['name'] == 'forward-0')
        self.assertTrue(result['outputs']['ready'])
        self.assertEqual(result['outputs']['contributions']['forward_lan'], 0)

    def test_false_guard_creates_no_effects_and_no_completion_claim(self):
        self.parent(guard="false")
        history = self.run_parent()
        self.assertFalse(self.audit.exists())
        self.assertFalse(
            any(
                step.get("outputs", {}).get("ready")
                for step in history["jobs"][0]["steps"]
            )
        )

    def test_failed_forward_blocks_later_actions_and_complete_restore_runs(self):
        self.parent(compensate=True)
        self.native_env["FIXTURE_FAIL"] = "route-dns-publish"
        history = self.run_parent(success=False)
        restore = next(
            step for step in history["jobs"][1]["steps"] if step["name"] == "restore-0"
        )
        self.assertTrue(restore["outputs"]["restored"])
        forward = history["jobs"][0]["steps"]
        self.assertTrue(
            all(
                step["status"] == "skipped"
                for step in forward
                if step["name"].startswith("forward_live_certificates")
            )
        )


class RouteEnvironmentNative(NativeTestCase):
    def test_real_vault_environment_write_returns_public_result(self):
        import pwd
        import tempfile

        self.copy_behaviors("operations/routes/mutation-command")
        dependency = (
            self.repo
            / "extensions/behaviors/operations/routes/publication-command/sections.ts"
        )
        dependency.parent.mkdir(parents=True, exist_ok=True)
        shutil.copy(
            ROOT
            / "extensions/behaviors/operations/routes/publication-command/sections.ts",
            dependency,
        )
        target = tempfile.TemporaryDirectory(dir=os.environ.get("TMPDIR"))
        self.addCleanup(target.cleanup)
        path = Path(target.name)
        (path / "artisan").touch()
        (path / ".env").write_text("APP_KEY=keep-current\nAPP_URL=https://old.test\n")
        secret = "synthetic-route-private-key-63a"
        self.secrets = (secret,)
        self.cli("vault", "create", "local_encryption", "orbit-control-inputs")
        self.cli(
            "vault",
            "put",
            "orbit-control-inputs",
            "material",
            input=json.dumps(
                {"app_key": "APP_KEY=" + secret, "app_name": "APP_NAME=Example"}
            ),
        )
        connection = install_ssh(self)
        workflow = yaml.safe_load(
            (ROOT / "workflows/workflow-route-environment-write.yaml").read_text()
        )

        def remote_path(value):
            if isinstance(value, str):
                return value.replace("/tmp/orbit-route-", str(self.repo / "remote-"))
            if isinstance(value, dict):
                return {key: remote_path(item) for key, item in value.items()}
            if isinstance(value, list):
                return [remote_path(item) for item in value]
            return value

        workflow = remote_path(workflow)
        for step in workflow["jobs"][0]["steps"]:
            step.pop("target")
            step.pop("labels")
        (self.repo / "workflows/workflow-route-environment-write.yaml").write_text(
            yaml.safe_dump(workflow, sort_keys=False)
        )
        value = {
            "operation_id": 8,
            "node_id": 14,
            "kind": "node.add",
            "execution_secrets": {
                "material": {"vault": "orbit-control-inputs", "key": "material"}
            },
            "intent": {
                "index": 0,
                "route_id": 31,
                "node_id": 9,
                "instance_id": 33,
                "name": "instance.write-development-url",
                "secret_field": "material",
                "input": {
                    "user": pwd.getpwuid(os.getuid()).pw_name,
                    "directory": str(path),
                    "require_artisan": True,
                    "url": "https://new.test",
                    "app_key_stored": True,
                    "app_name_stored": True,
                },
            },
        }
        value["intent"] = {
            "write": value["intent"],
            "control_node_id": 209,
            "connection": connection,
            "host_keys": [PIN],
        }
        reply = json.loads(
            self.cli(
                "workflow",
                "run",
                "route-environment-write",
                "--input",
                json.dumps(value),
            ).stdout
        )
        history = json.loads(self.cli("workflow", "history", "get", reply["id"]).stdout)
        self.assertEqual(history["status"], "succeeded")
        self.assertIn("APP_URL=https://new.test", (path / ".env").read_text())
        self.assertIn("APP_KEY=keep-current", (path / ".env").read_text())
        result = history["jobs"][0]["steps"][-1]["outputs"]
        self.assertEqual(result, {"ready": True, "route_id": 31, "instance_id": 33})
