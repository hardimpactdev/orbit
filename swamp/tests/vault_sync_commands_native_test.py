"""The actual command/shell DAG, with a disposable Vaultwarden protocol fixture."""

import json
import sys
import unittest
from pathlib import Path

import yaml

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "tests"))
from native_harness import NativeTestCase


class VaultSyncNative(NativeTestCase):
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
        client = self.repo / "extensions/behaviors/operations/vault-sync/client.py"
        source = (
            ROOT / "extensions/behaviors/operations/vault-sync/client.py"
        ).read_text()
        source += """
from pathlib import Path
STATE = Path(STATE_PATH)
class Collection:
    def __init__(self, config): self.config = config
    def open(self):
        state = json.loads(STATE.read_text())
        return {"organizations": [{"id": "org"}], "ciphers": state["ciphers"]}
    def write(self, item, identity=None):
        state = json.loads(STATE.read_text())
        identity = identity or "new-cipher"
        state["writes"].append({"source":item["source"],"id":identity})
        STATE.write_text(json.dumps(state))
        return {"id":identity,"revision":"new-revision"}
    def delete(self, identity):
        state = json.loads(STATE.read_text());state["deletes"].append(identity)
        state["ciphers"] = [item for item in state["ciphers"] if item["id"] != identity]
        STATE.write_text(json.dumps(state))
"""
        client.write_text(source.replace("STATE_PATH", repr(str(self.state))))
        self.intent = {
            "url": "https://vault.example.test",
            "client_id": "client",
            "device_identifier": "device",
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
        )
        private = {
            **self.intent,
            "client_secret": self.secrets[1],
            "master_password": self.secrets[0],
            "wanted": [
                {
                    "source": "database-user:7",
                    "fingerprint": "new",
                    "cipher": {"fields": [{"hidden": True, "value": self.secrets[2]}]},
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


if __name__ == "__main__":
    unittest.main()
