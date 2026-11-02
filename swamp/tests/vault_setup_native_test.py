"""Setup parity at the official CLI boundary, through the pinned native DAG."""

import json
import os
import sys
import unittest
from pathlib import Path

import yaml

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "tests"))
from native_harness import NativeTestCase


class VaultSetupNative(NativeTestCase):
    native_timeout = 120

    def setUp(self):
        super().setUp()
        self.copy_behaviors("operations/vault-sync")
        workflow = yaml.safe_load(
            (ROOT / "workflows/workflow-operation-vault-setup.yaml").read_text()
        )
        for job in workflow["jobs"]:
            for step in job["steps"]:
                self.assertEqual(step.pop("labels"), {"role": "swamp"})
        (self.repo / "workflows/workflow-operation-vault-setup.yaml").write_text(
            yaml.safe_dump(workflow, sort_keys=False)
        )
        self.state = self.repo / "cli-state.json"
        self.state.write_text(
            json.dumps(
                {
                    "commands": [],
                    "organizations": [{"id": "org", "name": "Orbit"}],
                    "collections": [
                        {
                            "id": "collection",
                            "organizationId": "org",
                            "name": "Disposable",
                        }
                    ],
                }
            )
        )
        commands = self.repo / "fixture-bin"
        commands.mkdir()
        executable = commands / "npx"
        executable.write_text(
            "#!/usr/bin/python3\n"
            + (ROOT / "tests/fixtures/bw_setup_cli.py").read_text()
        )
        executable.chmod(0o700)
        self.secrets = (
            "setup-private-client-49381",
            "setup-private-password-65921",
            "setup-private-session-74891",
        )
        self.native_env.update(
            {
                "PATH": str(commands) + ":" + os.environ["PATH"],
                "ORBIT_BW_FIXTURE_STATE": str(self.state),
                "FIXTURE_CLIENT_SECRET": self.secrets[0],
                "FIXTURE_MASTER_PASSWORD": self.secrets[1],
                "FIXTURE_SESSION": self.secrets[2],
            }
        )
        self.intent = {
            "url": "https://vault.orbit",
            "client_id": "client",
            "organization": "Orbit",
            "collection": "Disposable",
            "published": False,
            "ca_file": "/public/ca.pem",
            "refusal_exit_codes": {
                "vault.login_failed": 10,
                "vault.organization_missing": 11,
                "vault.collection_missing": 12,
                "vault.collection_read_only": 13,
                "vault.items_published": 14,
                "vault.result_invalid": 15,
                "vault.kdf_unsupported": 16,
                "vault.unreachable": 17,
            },
        }
        self.inputs = {
            "operation_id": 7,
            "node_id": 9,
            "kind": "vault.setup",
            "intent": self.intent,
            "execution_secrets": {
                "configuration": {
                    "vault": "orbit-control-inputs",
                    "key": "vault-setup-00000000-0000-4000-8000-000000000007",
                }
            },
        }
        self.cli("vault", "create", "local_encryption", "orbit-control-inputs")

    def run_setup(self, success=True):
        self.cli(
            "vault",
            "put",
            "orbit-control-inputs",
            self.inputs["execution_secrets"]["configuration"]["key"],
            "--force",
            input=json.dumps(
                {
                    **self.intent,
                    "client_secret": self.secrets[0],
                    "master_password": self.secrets[1],
                }
            ),
        )
        input_file = self.repo / "input.json"
        input_file.write_text(json.dumps(self.inputs))
        result = self.cli(
            "workflow",
            "run",
            "operation-vault-setup",
            "--input-file",
            str(input_file),
            success=success,
        )
        run = json.JSONDecoder().raw_decode(result.stdout.lstrip())[0]
        history = self.cli("workflow", "history", "get", run["id"])
        run = json.JSONDecoder().raw_decode(history.stdout.lstrip())[0]
        steps = {step["name"]: step for job in run["jobs"] for step in job["steps"]}
        self.assertEqual(
            list(steps),
            [
                "Prepare public data",
                "Verify the account",
                "Clear the session",
                "Remove CLI session",
                "Confirm the setup",
            ],
        )
        self.assertEqual(run["status"], "succeeded" if success else "failed")
        self.assertEqual(steps["Clear the session"]["status"], "succeeded")
        self.assertFalse(Path("/tmp/orbit-vault-setup-" + run["id"]).exists())
        self.assert_private_absent(result.stdout, result.stderr, history.stdout)
        verification = steps["Verify the account"]
        if not success:
            self.assertEqual(verification["status"], "failed")
            self.assertNotIn("outputs", verification)
            self.assertEqual(steps["Confirm the setup"]["status"], "skipped")
            return {
                "exit_code": int(
                    verification["error"].removeprefix("Command exited with code ")
                )
            }
        return json.loads(verification["outputs"]["stdout"])

    def test_setup_proves_write_access_and_cleans_its_session(self):
        self.assertEqual(
            self.run_setup()["data"],
            {
                "backend": "configured",
                "email": "setup@example.test",
                "organization_id": "org",
                "organization_name": "Orbit",
                "collection_id": "collection",
                "collection_name": "Disposable",
            },
        )
        commands = json.loads(self.state.read_text())["commands"]
        self.assertEqual(
            [row[0] for row in commands],
            [
                "config",
                "login",
                "unlock",
                "status",
                "list",
                "list",
                "create",
                "delete",
                "logout",
            ],
        )

    def test_legacy_refusals_preserve_public_codes_and_always_clean_up(self):
        for change, code in [
            ({"organizations": []}, "vault.organization_missing"),
            (
                {
                    "organizations": [
                        {"id": "one", "name": "Orbit"},
                        {"id": "two", "name": "Orbit"},
                    ]
                },
                "vault.organization_missing",
            ),
            ({"collections": []}, "vault.collection_missing"),
            ({"readonly": True}, "vault.collection_read_only"),
            ({"fail": "login"}, "vault.login_failed"),
            ({"fail": "unlock"}, "vault.login_failed"),
            ({"kdf": 1}, "vault.kdf_unsupported"),
        ]:
            with self.subTest(code=code, change=change):
                original = self.state.read_text()
                self.state.write_text(json.dumps({**json.loads(original), **change}))
                self.assertEqual(
                    self.run_setup(False),
                    {"exit_code": self.intent["refusal_exit_codes"][code]},
                )
                self.state.write_text(original)

    def test_collection_change_refuses_before_the_test_write(self):
        self.intent.update(
            previous={"organization_id": "old-org", "collection_id": "old-collection"},
            published=True,
        )
        self.assertEqual(self.run_setup(False), {"exit_code": 14})
        self.assertFalse(
            any(
                row[0] == "create"
                for row in json.loads(self.state.read_text())["commands"]
            )
        )


if __name__ == "__main__":
    unittest.main()
