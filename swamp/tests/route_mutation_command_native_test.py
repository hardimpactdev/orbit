"""Run the real native parent factory; replace child host effects at the workflow boundary."""

import json
import os
import shutil
import sys
from pathlib import Path

import yaml

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from tests.native_harness import NativeTestCase
from tests.route_ssh_fixture import PIN, install_ssh

ROOT = Path(__file__).resolve().parents[1]


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
            "prepare_certificates": [{"node_id": 9, "scope": "route-31-router"}, {"node_id": 10, "scope": "route-31-router"}, {"node_id": 9, "scope": "route-32-router"}],
            "certificates": [{"node_id": 9, "scope": "route-31-router"}],
            "cleanup": [{"node_id": 9}],
            "url_writes": [{"node_id": 9, "secret_field": "route_material_0"}],
            "lan": [{"node_id": 9}],
            "retire_lan": [{"node_id": 9}],
            "ingress": [],
            "retire_ingress": [],
            "publications": [
                {
                    "node_id": node,
                    "user": "orbit",
                    "control_node_id": 209,
                    "connection": {},
                    "host_keys": [PIN],
                    "serves_routes": True,
                    **{
                        side: {"caddy": 0, "php": 0, "dns": index}
                        for index, side in enumerate(("before", "prepared", "withdrawn", "after"))
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
            "intent": {"route_mutations": {"schema_version": 2, "artifacts": {"caddy": [{}], "php": [{}], "dns": [{"digest": side} for side in ("before", "prepared", "withdrawn", "after")]}, "forward": self.plan, "restore": self.plan}},
        }
        script = """import json,os
from pathlib import Path
audit=Path(os.environ['FIXTURE_AUDIT'])
action=os.environ['FIXTURE_ACTION']
with audit.open('a') as output:output.write(json.dumps({'action':action,'kind':os.environ['FIXTURE_KIND'],'operation_id':int(os.environ['FIXTURE_OP']),'node_id':int(os.environ['FIXTURE_NODE']),'scopes':os.environ['FIXTURE_LEAVES'].split(',') if os.environ['FIXTURE_LEAVES'] else []})+'\\n')
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
                        "FIXTURE_LEAVES": '${{ has(inputs.intent.leaves) ? inputs.intent.leaves.map(leaf,leaf.scope).join(",") : "" }}',
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


class RouteEnvironmentNative(NativeTestCase):
    def test_every_environment_ancestor_declares_its_nested_child_vault(self):
        workflows = {value["name"]: value for path in (ROOT / "workflows").glob("*.yaml")
                     if isinstance(value := yaml.safe_load(path.read_text()), dict) and "name" in value}

        def reaches_environment(name, seen):
            if name in seen:
                return False
            if name == "route-environment-write":
                return True
            seen = seen | {name}
            workflow = workflows[name]
            return any(reaches_environment(child, seen)
                       for job in workflow.get("jobs", []) for step in job.get("steps", [])
                       if (task := step.get("task", {})).get("type") == "workflow"
                       and (child := task.get("workflowIdOrName")) in workflows)

        missing = [name for name, workflow in workflows.items()
                   if reaches_environment(name, set())
                   and "orbit-control-inputs" not in workflow.get("vaults", [])]
        self.assertEqual(missing, [], "Every workflow in the nested vault read chain must declare the vault")

    def test_real_vault_environment_write_returns_public_result(self):
        self.environment_write()

    def environment_write(self):
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
            step.pop("labels", None)
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
        name = "route-environment-write"
        reply = json.loads(
            self.cli(
                "workflow",
                "run",
                name,
                "--input",
                json.dumps(value),
            ).stdout
        )
        history = json.loads(self.cli("workflow", "history", "get", reply["id"]).stdout)
        self.assertEqual(history["status"], "succeeded")
        self.assertIn("APP_URL=https://new.test", (path / ".env").read_text())
        self.assertIn("APP_KEY=keep-current", (path / ".env").read_text())
        self.assertEqual(history["jobs"][0]["steps"][-1]["outputs"], {"ready": True, "route_id": 31, "instance_id": 33})
