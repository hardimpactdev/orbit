"""Real official CLI protocol test against disposable Vaultwarden 1.37.

Set ORBIT_TEST_VAULTWARDEN_URL and ORBIT_TEST_VAULTWARDEN_CA to its TLS origin
and public CA file. Creates an account and organization; never use production.
"""

import json
import os
import unittest

from swamp.tests.vault_sync_commands_native_test import VaultSyncFixture


class VaultSyncBwIntegration(VaultSyncFixture):
    def test_real_official_cli_creates_updates_and_deletes_a_secure_note(self):
        from fixtures.vaultwarden_account import provision

        configuration = provision(
            os.environ["ORBIT_TEST_VAULTWARDEN_URL"],
            self.secrets[0],
            os.environ["ORBIT_TEST_VAULTWARDEN_CA"],
        )
        self.secrets = (*self.secrets, configuration["client_secret"])
        (self.repo / "fixture-bin/npx").unlink()
        self.intent["ca_file"] = os.environ["ORBIT_TEST_VAULTWARDEN_CA"]
        self.intent.update(
            {
                key: configuration[key]
                for key in ("url", "client_id", "organization_id", "collection_id")
            }
        )
        self.intent["ledger"] = []
        self.private.update(configuration)
        self.private["ledger"] = []
        self.cli(
            "vault",
            "put",
            "orbit-control-inputs",
            "vault-sync-fixture",
            "--force",
            input=json.dumps(self.private),
        )
        self.input_file.write_text(json.dumps(self.inputs))
        first = self.run_sync()
        terminal = next(
            step
            for job in first["jobs"]
            for step in job["steps"]
            if step["name"] == "result"
        )
        ledger = json.loads(terminal["outputs"]["stdout"])["data"]
        self.assertEqual(ledger["sources"], ["database-user:7"])
        self.intent["ledger"] = [
            {
                "source": ledger["sources"][0],
                "cipher_id": ledger["cipher_ids"][0],
                "revision_date": ledger["revisions"][0],
                "fingerprint": "new",
            }
        ]
        self.intent["wanted"][0]["fingerprint"] = "updated"
        self.private["wanted"][0]["fingerprint"] = "updated"
        self.cli(
            "vault",
            "put",
            "orbit-control-inputs",
            "vault-sync-fixture",
            "--force",
            input=json.dumps(self.private),
        )
        self.input_file.write_text(json.dumps(self.inputs))
        updated = self.run_sync()
        terminal = next(
            step
            for job in updated["jobs"]
            for step in job["steps"]
            if step["name"] == "result"
        )
        self.assertEqual(
            json.loads(terminal["outputs"]["stdout"])["data"]["cipher_ids"],
            ledger["cipher_ids"],
        )
        self.intent["wanted"] = []
        self.input_file.write_text(json.dumps(self.inputs))
        removed = self.run_sync()
        terminal = next(
            step
            for job in removed["jobs"]
            for step in job["steps"]
            if step["name"] == "result"
        )
        self.assertEqual(
            json.loads(terminal["outputs"]["stdout"])["data"]["sources"], []
        )


if __name__ == "__main__":
    unittest.main()
