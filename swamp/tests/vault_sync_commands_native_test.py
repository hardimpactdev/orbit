"""Actual native workflow against the official CLI process boundary fixture."""

import json
import os
import sys
import unittest
from pathlib import Path

import yaml

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "tests"))
from native_harness import NativeTestCase


class VaultSyncFixture(NativeTestCase):
    native_timeout = 180

    def setUp(self):
        super().setUp()
        self.copy_behaviors("operations/vault-sync")
        workflow = yaml.safe_load(
            (ROOT / "workflows/workflow-operation-vault-sync.yaml").read_text()
        )
        for step in [step for job in workflow["jobs"] for step in job["steps"]]:
            self.assertEqual(
                step.pop("target"), '${{ "orbit-node-" + string(inputs.node_id) }}'
            )
            self.assertEqual(
                step.pop("labels"), {"node": "${{ string(inputs.node_id) }}"}
            )
        (self.repo / "workflows/workflow-operation-vault-sync.yaml").write_text(
            yaml.safe_dump(workflow, sort_keys=False)
        )
        self.state = self.repo / "fixture.json"
        self.state.write_text(json.dumps({"ciphers": [], "writes": [], "deletes": []}))
        commands = self.repo / "fixture-bin"
        commands.mkdir()
        npx = commands / "npx"
        npx.write_text(
            "#!/usr/bin/python3\n" + (ROOT / "tests/fixtures/bw_cli.py").read_text()
        )
        npx.chmod(0o700)
        self.native_env["PATH"] = str(commands) + ":" + os.environ["PATH"]
        self.native_env["ORBIT_BW_FIXTURE_STATE"] = str(self.state)
        self.intent = {
            "url": "https://vault.example.test",
            "client_id": "client",
            "organization_id": "org",
            "collection_id": "collection",
            "ca_file": "/public/ca.pem",
            "wanted": [{"source": "database-user:7", "fingerprint": "new"}],
            "ledger": [
                {
                    "source": "database-user:7",
                    "cipher_id": "published",
                    "revision_date": "old",
                    "fingerprint": "old",
                }
            ],
        }
        self.secrets = (
            "vault-private-master-93857",
            "vault-private-client-89216",
            "vault-private-item-35791",
            "vault-private-session-72364",
        )
        self.native_env.update(
            {
                "FIXTURE_MASTER_PASSWORD": self.secrets[0],
                "FIXTURE_CLIENT_SECRET": self.secrets[1],
                "FIXTURE_ITEM_SECRET": self.secrets[2],
                "FIXTURE_SESSION": self.secrets[3],
            }
        )
        private = {
            **self.intent,
            "client_secret": self.secrets[1],
            "master_password": self.secrets[0],
            "wanted": [
                {
                    "source": "database-user:7",
                    "fingerprint": "new",
                    "cipher": {
                        "name": "Database",
                        "notes": "Owned credential",
                        "fields": [
                            {
                                "name": "orbit.source",
                                "hidden": False,
                                "value": "database-user:7",
                            },
                            {
                                "name": "password",
                                "hidden": True,
                                "value": self.secrets[2],
                            },
                        ],
                    },
                }
            ],
        }
        self.cli("vault", "create", "local_encryption", "orbit-control-inputs")
        self.cli(
            "vault",
            "put",
            "orbit-control-inputs",
            "vault-sync-fixture",
            input=json.dumps(private),
        )
        self.inputs = {
            "operation_id": 7,
            "node_id": 9,
            "kind": "vault.sync",
            "intent": self.intent,
            "execution_secrets": {
                "configuration": {
                    "vault": "orbit-control-inputs",
                    "key": "vault-sync-fixture",
                }
            },
        }
        self.private = private
        self.input_file = self.repo / "public-input.json"
        self.input_file.write_text(json.dumps(self.inputs))

    def run_sync(self, success=True):
        result = self.cli(
            "workflow",
            "run",
            "operation-vault-sync",
            "--input-file",
            str(self.input_file),
            success=success,
        )
        run = json.JSONDecoder().raw_decode(result.stdout.lstrip())[0]
        history = self.cli("workflow", "history", "get", run["id"])
        run = json.JSONDecoder().raw_decode(history.stdout.lstrip())[0]
        workspace = next(
            step
            for job in run["jobs"]
            for step in job["steps"]
            if step["name"] == "workspace"
        )
        if "stdout" in workspace.get("outputs", {}):
            self.assertFalse(Path(workspace["outputs"]["stdout"].strip()).exists())
        if success:
            self.assertEqual(run["status"], "succeeded")
            terminal = next(
                step
                for job in run["jobs"]
                for step in job["steps"]
                if step["name"] == "result"
            )
            self.assertEqual(
                json.loads(terminal["outputs"]["stdout"])["outcome"], "succeeded"
            )
        return run


class VaultSyncNative(VaultSyncFixture):
    def test_missing_recorded_cipher_creates_one_without_adopting_copy(self):
        state = json.loads(self.state.read_text())
        state["ciphers"] = [
            {
                "id": "copy",
                "source": "database-user:7",
                "organization_id": "org",
                "collection_ids": ["collection"],
                "revision": "copy-revision",
                "deleted": False,
            }
        ]
        self.state.write_text(json.dumps(state))
        self.cli("workflow", "validate", "operation-vault-sync")
        self.run_sync()
        state = json.loads(self.state.read_text())
        self.assertEqual(
            state["writes"], [{"source": "database-user:7", "id": "new-cipher"}]
        )
        self.assertEqual(state["deletes"], [])

    def test_shared_trashed_recorded_cipher_refuses_before_mutation(self):
        state = json.loads(self.state.read_text())
        state["ciphers"] = [
            {
                "id": "published",
                "source": "database-user:7",
                "organization_id": "org",
                "collection_ids": ["collection", "other"],
                "revision": "old",
                "deleted": True,
            }
        ]
        self.state.write_text(json.dumps(state))
        run = self.run_sync(success=False)
        plan = next(
            step
            for job in run["jobs"]
            for step in job["steps"]
            if step["name"] == "plan"
        )
        self.assertEqual(plan["status"], "failed")
        state = json.loads(self.state.read_text())
        self.assertEqual(state["writes"], [])
        self.assertEqual(state["deletes"], [])

    def test_unchanged_cipher_completes_with_empty_fanouts(self):
        self.intent["wanted"][0]["fingerprint"] = "old"
        self.input_file.write_text(json.dumps(self.inputs))
        self.state.write_text(
            json.dumps(
                {
                    "ciphers": [
                        {
                            "id": "published",
                            "source": "database-user:7",
                            "organization_id": "org",
                            "collection_ids": ["collection"],
                            "revision": "old",
                            "deleted": False,
                        }
                    ],
                    "writes": [],
                    "deletes": [],
                }
            )
        )
        self.run_sync()
        state = json.loads(self.state.read_text())
        self.assertEqual(state["writes"], [])
        self.assertEqual(state["deletes"], [])

    def test_recorded_orphan_is_deleted_and_unrecorded_copy_is_preserved(self):
        self.intent["wanted"] = []
        self.input_file.write_text(json.dumps(self.inputs))
        cipher = {
            "source": "database-user:7",
            "organization_id": "org",
            "collection_ids": ["collection"],
            "revision": "old",
            "deleted": False,
        }
        self.state.write_text(
            json.dumps(
                {
                    "ciphers": [
                        {**cipher, "id": "published"},
                        {**cipher, "id": "copy"},
                    ],
                    "writes": [],
                    "deletes": [],
                }
            )
        )
        self.run_sync()
        state = json.loads(self.state.read_text())
        self.assertEqual(state["writes"], [])
        self.assertEqual(state["deletes"], ["published"])
        self.assertEqual([row["id"] for row in state["ciphers"]], ["copy"])

    def test_early_failures_clean_cache_and_emit_failure(self):
        for command in ("config", "login", "unlock"):
            with self.subTest(command=command):
                state = json.loads(self.state.read_text())
                state["fail_command"] = command
                self.state.write_text(json.dumps(state))
                run = self.run_sync(success=False)
                result = next(
                    step
                    for job in run["jobs"]
                    for step in job["steps"]
                    if step["name"] == "result"
                )
                self.assertEqual(
                    json.loads(result["outputs"]["stdout"]),
                    {"error_code": "vault.sync_failed"},
                )

    def test_failed_logout_still_removes_cache_and_reports_failure(self):
        state = json.loads(self.state.read_text())
        state["fail_command"] = "logout"
        self.state.write_text(json.dumps(state))
        run = self.run_sync(success=False)
        result = next(
            step
            for job in run["jobs"]
            for step in job["steps"]
            if step["name"] == "result"
        )
        self.assertEqual(
            json.loads(result["outputs"]["stdout"]), {"error_code": "vault.sync_failed"}
        )

    def test_native_resume_rebuilds_cleaned_workspace(self):
        state = json.loads(self.state.read_text())
        state["fail_write"] = True
        self.state.write_text(json.dumps(state))
        first = self.run_sync(success=False)
        state = json.loads(self.state.read_text())
        state["fail_write"] = False
        self.state.write_text(json.dumps(state))
        resumed = self.cli(
            "workflow",
            "resume",
            "operation-vault-sync",
            "--run",
            first["id"],
            "--from",
            "workspace",
        )
        run = json.JSONDecoder().raw_decode(resumed.stdout.lstrip())[0]
        self.assertEqual(run["status"], "succeeded")
        history = self.cli("workflow", "history", "get", run["id"])
        run = json.JSONDecoder().raw_decode(history.stdout.lstrip())[0]
        workspace = next(
            step
            for job in run["jobs"]
            for step in job["steps"]
            if step["name"] == "workspace"
        )
        self.assertFalse(Path(workspace["outputs"]["stdout"].strip()).exists())

    def test_failed_cli_mutation_cleans_session_and_never_reports_success(self):
        state = json.loads(self.state.read_text())
        state["fail_write"] = True
        self.state.write_text(json.dumps(state))
        run = self.run_sync(success=False)
        self.assertEqual(run["status"], "failed")
        result = next(
            step
            for job in run["jobs"]
            for step in job["steps"]
            if step["name"] == "result"
        )
        self.assertEqual(result["status"], "succeeded")
        self.assertEqual(
            json.loads(result["outputs"]["stdout"]), {"error_code": "vault.sync_failed"}
        )
        commands = json.loads(self.state.read_text())["commands"]
        self.assertIn(["logout"], commands)
        self.assertNotIn(self.secrets[2], json.dumps(commands))


if __name__ == "__main__":
    unittest.main()
