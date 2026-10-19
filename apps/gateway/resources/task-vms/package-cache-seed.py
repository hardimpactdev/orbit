"""Read shared artifacts; copy missing native cache files into this workspace's private state."""
import base64
import hashlib
import json
import os
from pathlib import Path
import shutil
import stat
import sys
import tarfile
import tempfile
import time


def seed(shared, home, artifacts=None):
    inventory_file = shared / '.inventory.json'
    if not inventory_file.exists():
        return
    inventory = json.loads(inventory_file.read_text())
    selected = None if artifacts is None else {artifact['key']: artifact for artifact in artifacts}
    for key, digest in inventory.items():
        if selected is not None and key not in selected:
            continue
        source = shared / key
        if key.startswith('npm/') and selected is not None:
            seed_npm(source, home, selected[key], digest)
            continue
        if not key.startswith('composer/'):
            continue
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


def seed_npm(source, home, artifact, digest):
    if not source.is_file():
        return
    with source.open('rb') as stream:
        if hashlib.file_digest(stream, 'sha256').hexdigest() != digest:
            return
    algorithm, encoded = artifact['integrity'].split('-', 1)
    content_hash = base64.b64decode(encoded, validate=True).hex()
    target = home / '.npm/_cacache/content-v2' / algorithm / content_hash[:2] / content_hash[2:4] / content_hash[4:]
    target.parent.mkdir(parents=True, exist_ok=True)
    if not target.exists():
        with source.open('rb') as stream, tempfile.NamedTemporaryFile(dir=target.parent, delete=False) as output:
            temporary = Path(output.name)
            try:
                shutil.copyfileobj(stream, output)
                output.flush()
                os.chmod(temporary, 0o644)
                os.replace(temporary, target)
            finally:
                temporary.unlink(missing_ok=True)
    bun = home / '.bun/install/cache' / (artifact['package'] + '@' + artifact['version'] + '@@@1')
    if bun.exists():
        return
    bun.parent.mkdir(parents=True, exist_ok=True)
    with tempfile.TemporaryDirectory(dir=bun.parent, prefix='.orbit-extract-') as work:
        with tarfile.open(source, 'r:gz') as archive:
            members = archive.getmembers()
            roots = {member.name.split('/', 1)[0] for member in members}
            if len(roots) != 1 or next(iter(roots)) in ('', '.', '..'):
                raise ValueError('Registry archive has an unexpected root')
            extracted = Path(work) / roots.pop()
            archive.extractall(work, filter='data')
        if not extracted.is_dir() or extracted.is_symlink():
            raise ValueError('Registry archive has no package directory')
        os.replace(extracted, bun)


if __name__ == '__main__':
    shared = Path(sys.argv[1])
    marker = shared / '.orbit-owned'
    for _attempt in range(100):
        try:
            ready = stat.S_ISREG(marker.stat().st_mode)
        except FileNotFoundError:
            ready = False
        if ready:
            break
        time.sleep(0.1)
    else:
        raise RuntimeError(f'Read-only package cache marker [{marker}] did not arrive')
    seed(shared, Path.home(), json.load(sys.stdin))
