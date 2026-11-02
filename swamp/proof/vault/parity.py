"""Check public setup projection and private-cache cleanup on the proof Gateway."""

import json
import os
from pathlib import Path

operation = json.loads(os.environ["PROOF_OPERATION"])
result = operation["result"]
assert operation["kind"] == "vault.setup" and operation["status"] == "succeeded"
assert result["outcome"] == "succeeded"
assert set(result["data"]) == {
    "backend",
    "email",
    "organization_id",
    "organization_name",
    "collection_id",
    "collection_name",
}
assert result["data"]["backend"] == "configured"
assert result["data"]["collection_name"] == "Disposable"
assert not list(Path("/tmp").glob("orbit-vault-setup.*"))
assert not any(
    key in json.dumps(operation["input"])
    for key in ("client_secret", "master_password")
)
print(
    "Setup verified the disposable collection; only public metadata projected; private session removed."
)
