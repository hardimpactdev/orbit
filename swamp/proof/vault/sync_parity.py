"""Check the real CLI reconciliation's public ledger and cache cleanup."""

import json
import os
from pathlib import Path

operation = json.loads(os.environ["PROOF_OPERATION"])
assert operation["kind"] == "vault.sync" and operation["status"] == "succeeded"
result = operation["result"]
assert result["outcome"] == "succeeded"
data = result["data"]
assert data["backend"] == "synced"
assert len(data["sources"]) == 1 and data["sources"][0].startswith("database:")
assert len(data["cipher_ids"]) == 1 and data["cipher_ids"][0]
assert len(data["revisions"]) == 1 and data["revisions"][0]
assert len(data["fingerprints"]) == 1 and data["fingerprints"][0]
assert not list(Path("/tmp").glob("orbit-vault-sync.*"))
assert not any(key in json.dumps(operation["input"]) for key in ("client_secret", "master_password", "cipher"))
print("Real Bitwarden CLI reconciled one encrypted item; only its public ledger was projected; CLI cache removed.")
