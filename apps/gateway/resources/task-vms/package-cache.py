"""Trusted host cache writer. Input is a Gateway-built inventory, never a Project program."""
import fcntl
import hashlib
import json
import os
from pathlib import Path
import re
import shutil
import sys
import tempfile
import urllib.parse
import urllib.request
import uuid

ROOT = Path('/var/lib/orbit/package-cache')
MAX_ARCHIVE = 512 * 1024 * 1024


def allowed_url(url):
    return bool(re.fullmatch(r'https://api\.github\.com/repos/[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+/zipball/[a-f0-9]{40}', url)
                or re.fullmatch(r'https://codeload\.github\.com/[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+/(?:legacy\.zip|zip)/[a-f0-9]{40}', url))


class PublicRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, request, fp, code, msg, headers, newurl):
        if not allowed_url(newurl):
            raise ValueError('Cache download redirect is outside the public archive service')
        return super().redirect_request(request, fp, code, msg, headers, newurl)


def download(url):
    if not allowed_url(url):
        raise ValueError('Cache download URL is outside the public archive service')
    opener = urllib.request.build_opener(urllib.request.ProxyHandler({}), PublicRedirect())
    return opener.open(urllib.request.Request(url, headers={'User-Agent': 'Orbit-package-cache'}), timeout=60)


def atomic_json(path, value):
    temporary = path.with_suffix('.tmp')
    with temporary.open('w') as stream:
        json.dump(value, stream, sort_keys=True)
        stream.flush()
        os.fsync(stream.fileno())
    os.replace(temporary, path)


def initialize(root):
    if root.is_symlink():
        raise ValueError('Cache root must not be a symlink')
    root.mkdir(parents=True, exist_ok=True, mode=0o755)
    if root.stat().st_uid != os.geteuid() or root.stat().st_mode & 0o022:
        raise ValueError('Cache root has unsafe ownership or permissions')
    marker = root / '.orbit-owned'
    if not marker.exists():
        if list(root.iterdir()):
            raise ValueError('Refusing an unowned cache directory')
        marker.write_text('orbit-package-cache-v1\n')
    if marker.read_text() != 'orbit-package-cache-v1\n':
        raise ValueError('Cache ownership marker is invalid')


def fill(root, artifacts, max_bytes, fetch=download):
    initialize(root)
    with (root / '.lock').open('a') as lock:
        fcntl.flock(lock, fcntl.LOCK_EX)
        inventory_file = root / '.inventory.json'
        inventory = json.loads(inventory_file.read_text()) if inventory_file.exists() else {}
        hashes = {}
        used = sum(path.stat().st_size for path in root.rglob('*') if path.is_file())
        for artifact in artifacts:
            key = artifact['key']
            if not re.fullmatch(r'composer/[a-z0-9][a-z0-9_.-]*/[a-z0-9][a-z0-9_.-]*/[a-f0-9]{40}\.zip', key):
                raise ValueError('Invalid cache key')
            if key != 'composer/' + artifact['package'] + '/' + hashlib.sha1(artifact['url'].encode()).hexdigest() + '.zip':
                raise ValueError('Cache key does not match the archive')
            if not allowed_url(artifact['url']):
                raise ValueError('Invalid public archive URL')
            expected = artifact['integrity']
            if expected is not None and not re.fullmatch(r'sha1-[a-f0-9]{40}', expected):
                raise ValueError('Invalid archive integrity')
            target = root / key
            if target.is_file():
                with target.open('rb') as stream:
                    digest = hashlib.file_digest(stream, 'sha256').hexdigest()
                if inventory.get(key) == digest:
                    if expected:
                        with target.open('rb') as stream:
                            if expected != 'sha1-' + hashlib.file_digest(stream, 'sha1').hexdigest():
                                raise ValueError('Package archive integrity mismatch')
                    hashes[key] = 'sha256-' + digest
                    continue
                quarantine = root / 'quarantine'
                quarantine.mkdir(exist_ok=True)
                os.replace(target, quarantine / uuid.uuid4().hex)
            target.parent.mkdir(parents=True, exist_ok=True)
            temporary = None
            try:
                with tempfile.NamedTemporaryFile(dir=root, prefix='.download-', delete=False) as output:
                    temporary = Path(output.name)
                    size = 0
                    sha256 = hashlib.sha256()
                    sha1 = hashlib.sha1()
                    with fetch(artifact['url']) as response:
                        while block := response.read(1024 * 1024):
                            size += len(block)
                            if size > MAX_ARCHIVE or used + size > max_bytes:
                                raise ValueError('Package cache storage limit reached')
                            output.write(block)
                            sha256.update(block)
                            sha1.update(block)
                    output.flush()
                    os.fsync(output.fileno())
                if expected and expected != 'sha1-' + sha1.hexdigest():
                    raise ValueError('Package archive integrity mismatch')
                digest = sha256.hexdigest()
                os.chmod(temporary, 0o444)
                os.replace(temporary, target)
                temporary = None
                used += size
                inventory[key] = digest
                atomic_json(inventory_file, inventory)
                hashes[key] = 'sha256-' + digest
            finally:
                if temporary is not None:
                    temporary.unlink(missing_ok=True)
        return hashes


if __name__ == '__main__':
    try:
        request = json.load(sys.stdin)
        if request['operation'] == 'initialize':
            initialize(ROOT)
            print('{}')
        elif request['operation'] == 'fill':
            print(json.dumps(fill(ROOT, request['artifacts'], request['max_bytes'])))
        else:
            raise ValueError('Unknown cache operation')
    except Exception:
        print('Trusted package cache operation failed; existing verified artifacts are retained.', file=sys.stderr)
        sys.exit(1)
