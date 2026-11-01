"""Populate only the normal fixture's default checkout before the create request."""
import json
import os
import shlex
import subprocess
import sys

SEED = '''import json, os, sqlite3, sys
from pathlib import Path
root = Path(sys.argv[1])
if not root.is_absolute() or root.name != "default" or not root.is_dir() or root.stat().st_uid != os.geteuid():
    raise RuntimeError("proof-default-checkout-invalid")
if any(part.is_symlink() for part in (root, *root.parents)):
    raise RuntimeError("proof-default-checkout-invalid")
directory = root / "database"
if directory.is_symlink():
    raise RuntimeError("proof-database-directory-invalid")
directory.mkdir(exist_ok=True)
path = directory / "database.sqlite"
if path.exists() or path.is_symlink():
    raise RuntimeError("proof-database-already-exists")
with sqlite3.connect(path) as connection:
    connection.execute("CREATE TABLE orbit_create_seed (marker TEXT NOT NULL)")
    connection.execute("INSERT INTO orbit_create_seed VALUES (?)", ("owned-source",))
print(json.dumps({"ready": True}))
'''


def main():
    argv = [*shlex.split(os.environ['PROOF_EXEC']), 'app-dev',
            '--argv=' + json.dumps(['python3', '-c', SEED, sys.argv[1]])]
    result = subprocess.run(argv, text=True, capture_output=True, check=False, timeout=25)
    if result.returncode:
        raise RuntimeError('proof-default-database-seed-failed')
    value = json.loads(result.stdout)
    if value != {'ready': True}:
        raise RuntimeError('proof-default-database-seed-unconfirmed')


if __name__ == '__main__':
    main()
