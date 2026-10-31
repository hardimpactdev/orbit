"""Native systemd script composition and vault environment delivery in a scratch host."""

import hashlib
import importlib.util
import json
import os
import stat
from pathlib import Path

import yaml

spec = importlib.util.spec_from_file_location(
    "process_command_native", Path(__file__).with_name("native_test.py")
)
assert spec is not None and spec.loader is not None
fixture = importlib.util.module_from_spec(spec)
spec.loader.exec_module(fixture)


def reply(raw):
    decoder = json.JSONDecoder()
    for index, char in enumerate(raw):
        if char == "{":
            try:
                return decoder.raw_decode(raw[index:])[0]
            except ValueError:
                pass
    raise AssertionError("Native JSON missing")


class NativeInstallation(fixture.NativeProcessFixture):
    native_timeout = 90

    def setUp(self):
        super().setUp()
        self.host_root = self.repo / "host-orbit"
        self.secret = "native-process-vault-" + os.urandom(16).hex()
        self.secrets = (self.secret,)
        environment = '# Orbit Process 17\nTOKEN="' + self.secret + '"\n'
        self.environment_path = (
            self.host_root
            / "processes/17"
            / (hashlib.sha256(environment.encode()).hexdigest() + ".env")
        )
        content = (
            "[Unit]\nX-Orbit-Process-ID=17\n[Service]\nExecStart=/bin/sleep 300\nEnvironmentFile="
            + str(self.environment_path)
            + "\n"
        )
        self.original = (
            "[Unit]\nX-Orbit-Process-ID=17\n[Service]\nExecStart=/bin/sleep 200\n"
        )
        self.unit.write_text(self.original)
        scripts = self.repo / "extensions/behaviors/operations/process-commands/scripts"
        support = scripts / "unit_files.py"
        analyzer = self.repo / "analyzer"
        analyzer.write_text("#!/usr/bin/python3\nraise SystemExit(0)\n")
        analyzer.chmod(0o700)
        support.write_text(
            support.read_text()
            .replace('Path("/etc/orbit")', "Path(" + repr(str(self.host_root)) + ")")
            .replace('"/usr/bin/systemd-analyze"', repr(str(analyzer)))
        )
        manager = self.repo / "systemctl-fixture"
        manager.write_text(
            manager.read_text().replace(
                'if sys.argv[1]=="stop":s["active"]=False',
                'if sys.argv[1]=="stop":s["active"]=False\nif sys.argv[1] in ("start","restart"):s["active"]=True',
            )
        )
        control = self.inputs["control"]
        installation = {key: value for key, value in control.items() if key != "vite"}
        installation.update(
            desired="running",
            retired=None,
            activation_pending=False,
            content_hash=hashlib.sha256(content.encode()).hexdigest(),
        )
        self.inputs.update(
            operation_id=91001,
            installation=installation,
            execution_secrets={
                "installation": {"vault": "process-fixture", "key": "member-17"}
            },
        )
        self.cli("vault", "create", "local_encryption", "process-fixture")
        self.cli(
            "vault",
            "put",
            "process-fixture",
            "member-17",
            input=json.dumps(
                {
                    "17": {
                        "installation": {
                            "runtime": "systemd",
                            "content": content,
                            "environment": environment,
                        }
                    }
                }
            ),
        )
        self.workflow["name"] = "process-installation-fixture"
        self.workflow["vaults"] = ["process-fixture"]
        self.workflow["inputs"] = {
            "type": "object",
            "properties": {
                "operation_id": {"type": "integer"},
                "control": {"type": "object"},
                "installation": {"type": "object"},
                "execution_secrets": {
                    "type": "object",
                    "properties": {
                        "installation": {
                            "type": "object",
                            "properties": {
                                "vault": {"type": "string"},
                                "key": {"type": "string"},
                            },
                            "additionalProperties": False,
                            "required": ["vault", "key"],
                        }
                    },
                    "additionalProperties": False,
                    "required": ["installation"],
                },
            },
            "required": [
                "operation_id",
                "control",
                "installation",
                "execution_secrets",
            ],
        }
        self.steps = self.workflow["jobs"][0]["steps"] = []
        self.add_step(
            "assets",
            "@nckrtl/process-command-assets",
            "read",
            {
                "control": "${{ inputs.control }}",
                "installation": "${{ inputs.installation }}",
            },
        )
        private = {
            "ORBIT_PROCESS_MATERIAL": "${{ vault.get(inputs.execution_secrets.installation.vault, inputs.execution_secrets.installation.key) }}"
        }
        self.command("environment", "environment_script", private)
        self.command("stage", "stage_unit_script", private)
        self.add_step(
            "staged",
            "@nckrtl/process-command-assets",
            "decode",
            {"stdout": "${{ steps.stage.outputs.stdout }}"},
        )
        staged = {
            "ORBIT_PROCESS_STAGED_HASH": "${{ steps.staged.outputs.content_hash }}"
        }
        self.command("validate", "validate_unit_script", staged)
        self.command("backup", "backup_unit_script")
        self.add_step(
            "previous",
            "@nckrtl/process-command-assets",
            "decode",
            {"stdout": "${{ steps.backup.outputs.stdout }}"},
        )
        publication = {
            **staged,
            "ORBIT_PROCESS_PREVIOUS_HASH": '${{ steps.previous.outputs.previous_hash == null ? "null" : steps.previous.outputs.previous_hash }}',
        }
        self.command("publish", "publish_unit_script", publication)
        self.command("reload", "reload_units_script")
        self.command("enable", "enable_script", {"ORBIT_PROCESS_ENABLE": "enable"})
        self.command("start", "control_script", {"ORBIT_PROCESS_OPERATION": "start"})
        self.command("verify", "verify_script", {"ORBIT_PROCESS_DESIRED": "running"})

    def assert_private_absent(self, *outputs):
        # The one expected durable secret is the installed EnvironmentFile.
        # All history, logs, cache, scripts and other scratch files must stay clean.
        for output in outputs:
            self.assertTrue(
                all(value not in output for value in self.secrets),
                "Private bytes in command output",
            )
        for path in self.repo.rglob("*"):
            if not path.is_file() or path.is_symlink():
                continue
            if hasattr(self, "environment_path") and path == self.environment_path:
                self.assertEqual(stat.S_IMODE(path.stat().st_mode), 0o600)
                continue
            self.assertTrue(
                all(value.encode() not in path.read_bytes() for value in self.secrets),
                "Private bytes outside the protected EnvironmentFile",
            )

    def add_step(self, name, behavior, method, inputs):
        self.steps.append(
            {
                "name": name,
                "writes": True,
                "dependsOn": [
                    {"step": step["name"], "condition": {"type": "succeeded"}}
                    for step in self.steps
                ],
                "task": {
                    "type": "behavior",
                    "behaviorType": behavior,
                    "methodName": method,
                    "behaviorName": "fixture-process-" + name,
                    "inputs": inputs,
                },
            }
        )

    def command(self, name, asset, extra=None):
        self.add_step(
            name,
            "command/shell",
            "execute",
            {
                "run": '/usr/bin/python3 "${{ steps.assets.outputs.' + asset + ' }}"',
                "env": {
                    "ORBIT_PROCESS_CONTROL": "${{ steps.assets.outputs.control_json }}",
                    "ORBIT_PROCESS_INSTALLATION": "${{ steps.assets.outputs.installation_json }}",
                    "ORBIT_OPERATION_ID": "${{ string(inputs.operation_id) }}",
                    **(extra or {}),
                },
            },
        )

    def run_installation(self, success=True, flags=()):
        (self.repo / "workflows/workflow-process-installation-fixture.yaml").write_text(
            yaml.safe_dump(self.workflow)
        )
        self.cli("workflow", "validate", "process-installation-fixture", *flags)
        return reply(
            self.cli(
                "workflow",
                "run",
                "process-installation-fixture",
                "--stdin",
                *flags,
                input=json.dumps(self.inputs),
                success=success,
            ).stdout
        )

    def test_native_vault_environment_installation_and_public_results(self):
        run = self.run_installation()
        self.assertEqual(run["status"], "succeeded")
        self.assertEqual(self.unit.stat().st_mode & 0o777, 0o644)
        self.assertTrue(self.environment_path.exists())
        calls = json.loads(self.state.read_text())["calls"]
        self.assertIn(["daemon-reload"], calls)
        self.assertIn(["enable", self.unit.name], calls)
        self.assertIn(["start", self.unit.name], calls)
        self.assertNotIn(["restart", self.unit.name], calls)
        history = reply(self.cli("workflow", "history", "get", run["id"]).stdout)
        self.assert_private_absent(json.dumps(history))
        self.assertEqual(
            json.loads(history["jobs"][0]["steps"][-1]["outputs"]["stdout"]),
            {"ready": True, "state": "active"},
        )

    def test_native_validation_refusal_keeps_old_running_unit_and_skips_all_effects(
        self,
    ):
        (self.repo / "analyzer").write_text("#!/usr/bin/python3\nraise SystemExit(1)\n")
        run = self.run_installation(success=False)
        self.assertEqual(run["status"], "failed")
        self.assertEqual(self.unit.read_text(), self.original)
        self.assertEqual(json.loads(self.state.read_text())["calls"], [])
        history = reply(self.cli("workflow", "history", "get", run["id"]).stdout)
        statuses = {
            step["name"]: step["status"] for step in history["jobs"][0]["steps"]
        }
        self.assertEqual(statuses["validate"], "failed")
        for name in ["backup", "publish", "reload", "enable", "start", "verify"]:
            self.assertEqual(statuses[name], "skipped")
