"""Read shared artifacts; copy missing native cache files into this workspace's private state."""
import hashlib
import json
import os
from pathlib import Path
import shutil
import sys
import tempfile
import time


def seed(shared, home, artifacts=None):
    inventory_file = shared / '.inventory.json'
    if not inventory_file.exists():
        return
    inventory = json.loads(inventory_file.read_text())
    selected = None if artifacts is None else {artifact['key'] for artifact in artifacts}
    for key, digest in inventory.items():
        if selected is not None and key not in selected:
            continue
        if not key.startswith('composer/'):
            continue
        source = shared / key
        destination = home / '.cache/composer/files' / key.removeprefix('composer/')
        if not source.is_file() or destination.exists():
            continue
        with source.open('rb') as stream:
            actual = hashlib.file_digest(stream, 'sha256').hexdigest()
        if actual != digest:
            continue
        destination.parent.mkdir(parents=True, exist_ok=True)
        with tempfile.NamedTemporaryFile(dir=destination.parent, delete=False) as output:
            temporary = Path(output.name)
            try:
                with source.open('rb') as stream:
                    shutil.copyfileobj(stream, output)
                output.flush()
                os.chmod(temporary, 0o644)
                os.replace(temporary, destination)
            finally:
                temporary.unlink(missing_ok=True)


if __name__ == '__main__':
    shared = Path(sys.argv[1])
    for attempt in range(100):
        if (shared / '.orbit-owned').is_file():
            break
        time.sleep(0.1)
    else:
        raise RuntimeError('Read-only package cache mount did not arrive')
    seed(shared, Path.home(), json.load(sys.stdin))
