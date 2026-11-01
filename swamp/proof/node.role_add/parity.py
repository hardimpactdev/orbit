"""Assert the recorded native role facts after the disposable F10 workflow."""
import json
import os
from pathlib import Path

result = json.loads(Path(os.environ["PROOF_OUTPUTS"]).read_text())
assert result["ready"] is True
assert result["target_completed"] is True
assert result["target"]["assignment_id"] > 0
for field in ["role_proven", "runtime_proven", "certificates_proven", "publication_proven"]:
    assert result["target"][field] is True
assert result["target"]["created"] is True
