"""Generate private, disposable test values on the workload; print no value."""
import json
import os
import secrets
from pathlib import Path


def prepare(root):
    root = Path(root)
    tab = secrets.token_hex(32) + "\t" + secrets.token_hex(32)
    unquoted = secrets.token_hex(64)
    values = {"tab": tab, "hash": unquoted, "stdout": secrets.token_hex(64)}
    for name in (".env", ".orbit-proof-private-values"):
        path = root / name
        if path.is_symlink():
            raise RuntimeError("proof-private-path-invalid")
    # Deliberately use the accepted raw dotenv forms that #2370 must redact.
    body = 'PROOF_TAB_TOKEN="' + tab.replace("\t", "\\t") + '"\n'
    body += "PROOF_HASH_TOKEN=" + unquoted + "#unspaced-comment\n"
    body += 'PROOF_STDOUT_TOKEN="' + values["stdout"] + '"\n'
    for name, content in ((".env", body), (".orbit-proof-private-values", json.dumps(values))):
        descriptor = os.open(root / name, os.O_WRONLY | os.O_CREAT | os.O_TRUNC | os.O_NOFOLLOW, 0o600)
        with os.fdopen(descriptor, "w") as target:
            target.write(content)
        (root / name).chmod(0o600)


if __name__ == "__main__":
    prepare(Path.cwd())
