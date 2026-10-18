"""Trusted host cache writer. Input is a Gateway-built inventory, never a Project program."""
import base64
import fcntl
import hashlib
import json
import os
from pathlib import Path
import re
import sys
import tempfile
import urllib.error
import urllib.parse
import urllib.request
import uuid

ROOT = Path('/var/lib/orbit/package-cache')
MAX_ARCHIVE = 512 * 1024 * 1024


def allowed_url(url):
    return bool(re.fullmatch(r'https://registry\.npmjs\.org/(?:@[a-z0-9][a-z0-9_.-]*/)?[a-z0-9][a-z0-9_.-]*/-/[a-z0-9][a-z0-9_.-]*-[0-9]+\.[0-9]+\.[0-9]+(?:[-+][a-zA-Z0-9_.-]+)?\.tgz', url)
                or re.fullmatch(r'https://api\.github\.com/repos/[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+/zipball/[a-f0-9]{40}', url)
                or re.fullmatch(r'https://codeload\.github\.com/[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+/(?:legacy\.zip|zip)/[a-f0-9]{40}', url))


class ArchiveError(ValueError):
    """A fixed archive failure reason safe to return to the Gateway."""


class PublicRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, request, fp, code, msg, headers, newurl):
        repository_redirect = bool(re.fullmatch(r'https://api\.github\.com/repositories/[1-9][0-9]*/zipball/[a-f0-9]{40}', newurl))
        if (not (allowed_url(newurl) or repository_redirect) or github_url(request.full_url) != github_url(newurl)
                or (github_url(newurl) and (request.full_url.rsplit('/', 1)[-1] != newurl.rsplit('/', 1)[-1]
                    or (urllib.parse.urlparse(newurl).hostname == 'api.github.com'
                        and not newurl.startswith('https://api.github.com/repositories/'))))):
            raise ArchiveError('Rejected archive redirect')
        return super().redirect_request(request, fp, code, msg, headers, newurl)


def github_url(url):
    return urllib.parse.urlparse(url).hostname in ('api.github.com', 'codeload.github.com')


class GitHubAuthenticationUnavailable(ArchiveError):
    pass


def download(url, github_token=None):
    if not allowed_url(url):
        raise ArchiveError('Rejected archive URL')
    headers = {'User-Agent': 'Orbit-package-cache'}
    if github_url(url):
        if not github_token:
            raise GitHubAuthenticationUnavailable('GitHub installation token unavailable')
        headers['Authorization'] = 'Bearer ' + github_token
    opener = urllib.request.build_opener(urllib.request.ProxyHandler({}), PublicRedirect())
    return opener.open(urllib.request.Request(url, headers=headers), timeout=60)


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
        failures = {}
        used = sum(path.stat().st_size for path in root.rglob('*') if path.is_file())
        for artifact in artifacts:
            key = artifact['key']
            try:
                expected = artifact['integrity']
                if artifact['manager'] == 'composer':
                    if not re.fullmatch(r'composer/[a-z0-9][a-z0-9_.-]*/[a-z0-9][a-z0-9_.-]*/[a-f0-9]{40}\.zip', key):
                        raise ArchiveError('Invalid Composer cache key')
                    if key != 'composer/' + artifact['package'] + '/' + hashlib.sha1(artifact['url'].encode()).hexdigest() + '.zip':
                        raise ArchiveError('Composer key does not match the archive')
                    if expected is not None and not re.fullmatch(r'sha1-[a-f0-9]{40}', expected):
                        raise ArchiveError('Invalid Composer integrity')
                    algorithm, wanted = ('sha1', bytes.fromhex(expected[5:])) if expected else (None, None)
                elif artifact['manager'] == 'npm':
                    name, version = artifact['package'], artifact['version']
                    if not re.fullmatch(r'(?:@[a-z0-9][a-z0-9_.-]*/)?[a-z0-9][a-z0-9_.-]*', name) or not re.fullmatch(r'[0-9]+\.[0-9]+\.[0-9]+(?:[-+][a-zA-Z0-9_.-]+)?', version):
                        raise ArchiveError('Invalid npm identity')
                    url = 'https://registry.npmjs.org/' + name + '/-/' + name.split('/')[-1] + '-' + version + '.tgz'
                    if artifact['url'] != url:
                        raise ArchiveError('Invalid npm archive URL')
                    algorithm, encoded = expected.split('-', 1)
                    wanted = base64.b64decode(encoded, validate=True)
                    if key != 'npm/' + hashlib.sha256((url + ' ' + expected).encode()).hexdigest() + '.tgz':
                        raise ArchiveError('npm key does not match the archive')
                else:
                    raise ArchiveError('Unknown package cache manager')
                if not allowed_url(artifact['url']):
                    raise ArchiveError('Invalid public archive URL')
                target = root / key
                if target.is_file():
                    with target.open('rb') as stream:
                        digest = hashlib.file_digest(stream, 'sha256').hexdigest()
                    if inventory.get(key) == digest:
                        if algorithm:
                            with target.open('rb') as stream:
                                if wanted != hashlib.file_digest(stream, algorithm).digest():
                                    raise ArchiveError('Archive checksum mismatch')
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
                        checksum = hashlib.new(algorithm) if algorithm else None
                        with fetch(artifact['url']) as response:
                            while block := response.read(1024 * 1024):
                                size += len(block)
                                if size > MAX_ARCHIVE:
                                    raise ArchiveError('Archive exceeds size limit')
                                if used + size > max_bytes:
                                    raise ArchiveError('Cache full')
                                output.write(block)
                                sha256.update(block)
                                if checksum:
                                    checksum.update(block)
                        output.flush()
                        os.fsync(output.fileno())
                    if checksum and wanted != checksum.digest():
                        raise ArchiveError('Archive checksum mismatch')
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
            except ArchiveError as error:
                failures[key] = str(error)
            except urllib.error.HTTPError as error:
                failures[key] = 'HTTP ' + str(error.code)
            except Exception as error:
                failures[key] = type(error).__name__
        return {'hashes': hashes, 'failures': failures}


if __name__ == '__main__':
    try:
        request = json.load(sys.stdin)
        if request['operation'] == 'initialize':
            initialize(ROOT)
            print('{}')
        elif request['operation'] == 'fill':
            print(json.dumps(fill(ROOT, request['artifacts'], request['max_bytes'], lambda url: download(url, request.get('github_token')))))
        else:
            raise ValueError('Unknown cache operation')
    except Exception:
        print('Trusted package cache operation failed; existing verified artifacts are retained.', file=sys.stderr)
        sys.exit(1)
