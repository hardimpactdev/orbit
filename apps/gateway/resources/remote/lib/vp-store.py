# Shared owner-only vp store operations. No Project code or package lifecycle hooks run here.
import base64
from contextlib import contextmanager
import fcntl
import hashlib
import json
import os
import pathlib
import platform
import re
import shutil
import stat
import tarfile
import tempfile
import time
import urllib.parse
import urllib.request


RECIPE_NODE_REFERENCE = '.orbit-recipe-node.json'

VERSION = re.compile(r'\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?\Z')


def store_lock(home):
    descriptor = os.open(home / '.orbit-store.lock', os.O_CREAT | os.O_RDWR | os.O_NOFOLLOW, 0o644)
    try:
        info = os.fstat(descriptor)
        if not stat.S_ISREG(info.st_mode) or info.st_uid != os.getuid():
            raise ValueError('The vp store lock must belong to the store owner')
        os.fchmod(descriptor, 0o644)
        return os.fdopen(descriptor, 'a')
    except BaseException:
        os.close(descriptor)
        raise


@contextmanager
def locked_store(home, timeout=120):
    with store_lock(home) as lock:
        deadline = time.monotonic() + min(max(float(timeout), 0), 120)
        while True:
            try:
                fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
                break
            except BlockingIOError:
                if time.monotonic() >= deadline:
                    raise TimeoutError('Timed out waiting for the vp store lock') from None
                time.sleep(0.1)
        yield


def store_home(home):
    if not home.is_absolute() or home.is_symlink() or not home.is_dir() or home.stat().st_uid != os.getuid() or home.stat().st_mode & 0o022:
        raise ValueError('The vp store must belong to the enrolled account')
    return home


def check_store_path(home, target):
    for part in (target, *target.parents):
        if part == home:
            break
        if part.is_symlink() or (part.exists() and (part.stat().st_uid != os.getuid() or part.stat().st_mode & 0o022)):
            raise ValueError('Runtime paths must belong to the store owner')
    return target


def node_runtime_path(home, version):
    store_home(home)
    if not isinstance(version, str) or not VERSION.fullmatch(version):
        raise ValueError('Exact supported runtime versions required')
    return check_store_path(home, home / 'js_runtime' / 'node' / version)


def store_paths(payload):
    home = pathlib.Path(payload['store'])
    node, manager, version = (payload[key] for key in ('node_version', 'package_manager', 'package_manager_version'))
    node_path = node_runtime_path(home, node)
    if not VERSION.fullmatch(version) or manager not in ('npm', 'pnpm'):
        raise ValueError('Exact supported runtime versions required')
    pm_path = check_store_path(home, home / 'package_manager' / manager / version)
    return home, node_path, pm_path


def installed(payload):
    home, node, pm = store_paths(payload)
    executable = node / 'bin' / 'node'
    package = pm / payload['package_manager'] / 'package.json'
    try:
        if not executable.is_file() or not os.access(executable, os.X_OK):
            return False
        manifest = json.loads(package.read_text())
        return (manifest['name'] == payload['package_manager'] and
                manifest['version'] == payload['package_manager_version'] and
                os.access(pm / payload['package_manager'] / 'bin' / payload['package_manager'], os.X_OK))
    except (FileNotFoundError, KeyError, ValueError):
        return False


def npm_config(text):
    config = {}
    for line in text.splitlines():
        line = line.strip()
        if line and not line.startswith(('#', ';')) and '=' in line:
            key, value = line.split('=', 1)
            config[key.strip()] = re.sub(r'\$\{([^}]+)\}', lambda match: os.environ.get(match[1], ''), value.strip())
    return config


def download(url, config=None):
    parsed = urllib.parse.urlsplit(url)
    if parsed.scheme != 'https' or parsed.username or parsed.password:
        raise ValueError('Runtime downloads require HTTPS')
    headers = {}
    # Never send registry credentials to a different tarball host or path.
    for key, value in (config or {}).items():
        for suffix, kind in ((':_authToken', 'Bearer '), (':_auth', 'Basic ')):
            if not key.startswith('//') or not key.endswith(suffix):
                continue
            scope = urllib.parse.urlsplit('https:' + key[:-len(suffix)])
            prefix = scope.path.rstrip('/') + '/'
            if parsed.netloc == scope.netloc and (parsed.path or '/').startswith(prefix):
                headers['Authorization'] = kind + value
    class NoRedirect(urllib.request.HTTPRedirectHandler):
        def redirect_request(self, request, fp, code, msg, response_headers, newurl):
            # A redirect must not carry auth to a different origin.
            if urllib.parse.urlsplit(newurl).scheme != 'https' or urllib.parse.urlsplit(newurl).netloc != parsed.netloc:
                raise ValueError('Cross-origin runtime redirect refused')
            return super().redirect_request(request, fp, code, msg, response_headers, newurl)
    with urllib.request.build_opener(NoRedirect()).open(urllib.request.Request(url, headers=headers), timeout=60) as response:
        data = response.read(256 * 1024 * 1024 + 1)
        if len(data) > 256 * 1024 * 1024:
            raise ValueError('Runtime archive too large')
        return data


def verify_integrity(data, integrity):
    for token in integrity.split():
        algorithm, expected = token.split('-', 1)
        if algorithm not in ('sha512', 'sha384', 'sha256'):
            continue
        if base64.b64encode(hashlib.new(algorithm, data).digest()).decode() == expected:
            return
    raise ValueError('Package manager integrity mismatch')


def extract(data, destination):
    import io
    with tarfile.open(fileobj=io.BytesIO(data), mode='r:*') as archive:
        # data_filter is unavailable on some supported Python 3.10 Nodes. Validate explicitly.
        root = destination.resolve()
        for member in archive.getmembers():
            target = (root / member.name).resolve()
            if not target.is_relative_to(root) or member.isdev() or member.isfifo():
                raise ValueError('Unsafe runtime archive')
            if member.issym() or member.islnk():
                link = ((target.parent if member.issym() else root) / member.linkname).resolve()
                if not link.is_relative_to(root):
                    raise ValueError('Unsafe runtime link')
            member.uid = os.getuid()
            member.gid = os.getgid()
            member.mode &= 0o755
            owner_directories(target.parent)
            archive.extract(member, destination)


def node_archive(version):
    system = {'Linux': 'linux', 'Darwin': 'darwin'}[platform.system()]
    arch = {'x86_64': 'x64', 'aarch64': 'arm64', 'arm64': 'arm64'}[platform.machine()]
    name = f'node-v{version}-{system}-{arch}.tar.gz'
    base = f'https://nodejs.org/dist/v{version}/'
    sums = download(base + 'SHASUMS256.txt').decode()
    checksum = next((line.split()[0] for line in sums.splitlines() if line.split()[-1] == name), None)
    if checksum is None or not re.fullmatch(r'[a-f0-9]{64}', checksum):
        raise ValueError('Official Node checksum is missing')
    data = download(base + name)
    if hashlib.sha256(data).hexdigest() != checksum:
        raise ValueError('Node checksum mismatch')
    return data, name[:-7], 'sha256-' + checksum


def manager_archive(payload):
    config = npm_config(payload.get('npmrc', ''))
    registry = config.get('registry', 'https://registry.npmjs.org/').rstrip('/')
    manager, version = payload['package_manager'], payload['package_manager_version']
    metadata = json.loads(download(f'{registry}/{manager}/{version}', config))
    if metadata['name'] != manager or metadata['version'] != version:
        raise ValueError('Registry returned a different runtime')
    integrity = metadata['dist']['integrity']
    data = download(metadata['dist']['tarball'], config)
    verify_integrity(data, integrity)
    pin = payload.get('package_manager_hash')
    if pin:
        algorithm, expected = pin.split('.', 1)
        if hashlib.new(algorithm, data).hexdigest() != expected:
            raise ValueError('Project package manager integrity mismatch')
    return data, integrity


def owner_directories(path):
    # pathlib's parents=True uses the ambient umask for intermediate directories.
    # Supply the mode on every newly created level without changing adopted paths.
    if not path.exists():
        owner_directories(path.parent)
        path.mkdir(mode=0o755)


def write_ledger(home, ledger):
    # A private random temporary file avoids inheriting a group-writable mode or a stale .tmp path.
    with tempfile.NamedTemporaryFile(mode='w', prefix='.orbit-ledger-', dir=home, delete=False) as stream:
        temporary = pathlib.Path(stream.name)
        try:
            json.dump(ledger, stream, sort_keys=True)
            stream.flush()
            temporary.chmod(0o644)
            temporary.replace(home / '.orbit-runtimes.json')
        finally:
            temporary.unlink(missing_ok=True)


def publish(parent, destination, data, source):
    owner_directories(parent)
    # A complete version is immutable. Never repair or overwrite one in place.
    if destination.exists() or destination.is_symlink():
        raise ValueError('An incomplete installed runtime must be repaired by the store owner')
    with tempfile.TemporaryDirectory(prefix='.orbit-stage-', dir=parent) as stage:
        root = pathlib.Path(stage)
        extract(data, root)
        os.rename(root / source, destination)


def install(payload):
    home, node, pm = store_paths(payload)
    ledger_path = home / '.orbit-runtimes.json'
    ledger = json.loads(ledger_path.read_text()) if ledger_path.exists() else {}
    if not (node / 'bin' / 'node').exists():
        data, source, integrity = node_archive(payload['node_version'])
        publish(node.parent, node, data, source)
        ledger[str(node.relative_to(home))] = integrity
        # A later manager failure must not turn an Orbit publication into adopted content on retry.
        write_ledger(home, ledger)
    if not (pm / payload['package_manager'] / 'package.json').exists():
        data, integrity = manager_archive(payload)
        owner_directories(pm.parent)
        if pm.exists() or pm.is_symlink():
            raise ValueError('An incomplete package manager already exists')
        with tempfile.TemporaryDirectory(prefix='.orbit-stage-', dir=pm.parent) as stage:
            root = pathlib.Path(stage)
            extract(data, root)
            package = root / 'package'
            manifest = json.loads((package / 'package.json').read_text())
            for command, script in manifest['bin'].items():
                if command not in ('npm', 'npx', 'pnpm', 'pnpx'):
                    continue
                target = (package / script).resolve()
                if not target.is_relative_to(package.resolve()) or not target.is_file():
                    raise ValueError('Unsafe package manager executable')
                launcher = package / 'bin' / command
                relative = os.path.relpath(target, launcher.parent)
                launcher.write_text('#!/bin/sh\nexec node "$(dirname "$0")/' + relative + '" "$@"\n')
                launcher.chmod(0o755)
            package.rename(root / payload['package_manager'])
            # The staging directory starts private; published runtimes must be readable by app users.
            root.chmod(0o755)
            # Keep the vp native layout: <version>/<manager>/bin/<manager>.
            os.rename(root, pm)
        ledger[str(pm.relative_to(home))] = integrity
        write_ledger(home, ledger)
    if not installed(payload):
        raise ValueError('Installed runtime is incomplete')
    return ledger


def prune(home, references):
    ledger_path = home / '.orbit-runtimes.json'
    ledger = json.loads(ledger_path.read_text()) if ledger_path.exists() else {}
    keep = set()
    recipe_pin = home / RECIPE_NODE_REFERENCE
    if recipe_pin.exists() or recipe_pin.is_symlink():
        if recipe_pin.is_symlink() or recipe_pin.stat().st_uid != os.getuid() or recipe_pin.stat().st_mode & 0o022:
            raise ValueError('Invalid recipe Node reference')
        version = json.loads(recipe_pin.read_text())['version']
        if not isinstance(version, str) or not VERSION.fullmatch(version):
            raise ValueError('Invalid recipe Node version')
        keep.add('js_runtime/node/' + version)
    for reference in references:
        _, node, pm = store_paths(reference)
        keep.update((str(node.relative_to(home)), str(pm.relative_to(home))))
    removed = []
    for name in list(ledger):
        # Only content created by this program is eligible; recipe and adopted versions are not.
        if name not in keep and re.fullmatch(r'(?:js_runtime/node|package_manager/(?:npm|pnpm))/[0-9A-Za-z.-]+', name):
            target = home / name
            if target.is_symlink():
                raise ValueError('Refusing symlink during runtime collection')
            if target.exists():
                shutil.rmtree(target)
            removed.append(name)
            del ledger[name]
    write_ledger(home, ledger)
    return removed


def node_runtime_matches(source, installed):
    """Compare a store publication with the verified archive without running account-writable code."""
    if installed.is_symlink() or not installed.is_dir():
        return False
    expected = {pathlib.Path('.'), *(path.relative_to(source) for path in source.rglob('*'))}
    actual = {pathlib.Path('.'), *(path.relative_to(installed) for path in installed.rglob('*'))}
    if expected != actual:
        return False
    for relative in expected:
        original, current = source / relative, installed / relative
        before, after = original.lstat(), current.lstat()
        if after.st_uid != os.getuid() or stat.S_IFMT(before.st_mode) != stat.S_IFMT(after.st_mode):
            return False
        if stat.S_ISLNK(after.st_mode):
            if os.readlink(original) != os.readlink(current):
                return False
        elif after.st_mode & 0o022 or (before.st_mode & 0o111) != (after.st_mode & 0o111):
            return False
        elif stat.S_ISREG(after.st_mode):
            with original.open('rb') as left, current.open('rb') as right:
                if hashlib.file_digest(left, 'sha256').digest() != hashlib.file_digest(right, 'sha256').digest():
                    return False
    return True



def legacy_node_links(home):
    cache = home / 'js_runtime/node'
    links = []
    if cache.is_symlink():
        raise ValueError('Runtime paths must belong to the store owner')
    for link in cache.iterdir() if cache.is_dir() else ():
        if not link.is_symlink():
            continue
        target = pathlib.Path(os.path.abspath(link.parent / os.readlink(link)))
        relative = str(target.relative_to(home)) if target.is_relative_to(home) else ''
        pattern = r'recipe/node-[a-f0-9]{64}/node-v' + re.escape(link.name) + r'-(?:linux|darwin)-(?:x64|arm64)'
        if not VERSION.fullmatch(link.name) or link.lstat().st_uid != os.getuid() or not re.fullmatch(pattern, relative):
            raise ValueError('Refusing unrelated Node runtime link')
        check_store_path(home, target)
        if not target.is_dir():
            raise ValueError('Former recipe runtime is missing')
        links.append((link, target))
    return links


def normalize_store_directories(home):
    # Only the enrolled account repairs the historical writable store components.
    for path in (home, home / 'js_runtime', home / 'js_runtime/node'):
        if path.is_symlink() or not path.is_absolute():
            raise ValueError('Runtime paths must belong to the store owner')
        if path.exists():
            info = path.stat()
            if not stat.S_ISDIR(info.st_mode) or info.st_uid != os.getuid():
                raise ValueError('Runtime paths must belong to the store owner')
            path.chmod(stat.S_IMODE(info.st_mode) & ~0o022)
        else:
            owner_directories(path)


def migrate_recipe_nodes(home):
    for link, source in legacy_node_links(home):
        with tempfile.TemporaryDirectory(prefix='.orbit-stage-', dir=link.parent) as directory:
            candidate = pathlib.Path(directory) / 'node'
            shutil.copytree(source, candidate, symlinks=True)
            for parent, directories, files in os.walk(candidate):
                pathlib.Path(parent).chmod(0o755)
                for name in directories + files:
                    path = pathlib.Path(parent) / name
                    if not path.is_symlink():
                        path.chmod(path.stat().st_mode & 0o755)
            # rename cannot replace a symlink with a directory. Keep the old target for running processes.
            link.unlink()
            candidate.rename(link)


def recipe_node_version(home):
    store_home(home)
    if legacy_node_links(home):
        return None
    pin = home / RECIPE_NODE_REFERENCE
    if pin.is_symlink() or not pin.is_file() or pin.stat().st_uid != os.getuid() or pin.stat().st_mode & 0o022:
        return None
    version = json.loads(pin.read_text()).get('version')
    node = node_runtime_path(home, version)
    default = json.loads((home / 'config.json').read_text()).get('defaultNodeVersion')
    return version if version == default and (node / 'bin/node').is_file() else None


def publish_verified_node(home, version, archive, sha256):
    data = pathlib.Path(archive).read_bytes()
    if not re.fullmatch(r'[a-f0-9]{64}', sha256) or hashlib.sha256(data).hexdigest() != sha256:
        raise ValueError('Node checksum mismatch')
    # Permit only owned real directories before taking the shared lock; normalize under the lock.
    if not home.is_absolute() or home.is_symlink() or not home.is_dir() or home.stat().st_uid != os.getuid():
        raise ValueError('The vp store must belong to the enrolled account')
    with locked_store(home):
        normalize_store_directories(home)
        migrate_recipe_nodes(home)
        destination = node_runtime_path(home, version)
        with tempfile.TemporaryDirectory(prefix='.orbit-stage-', dir=destination.parent) as directory:
            stage = pathlib.Path(directory)
            extract(data, stage)
            system = {'Linux': 'linux', 'Darwin': 'darwin'}[platform.system()]
            arch = {'x86_64': 'x64', 'aarch64': 'arm64', 'arm64': 'arm64'}[platform.machine()]
            source = stage / f'node-v{version}-{system}-{arch}'
            if not source.is_dir() or source.is_symlink():
                raise ValueError('Node archive has an unexpected layout')
            if destination.exists():
                if not node_runtime_matches(source, destination):
                    raise ValueError('Existing Node runtime differs from the verified archive')
            else:
                source.rename(destination)
        with tempfile.NamedTemporaryFile(mode='w', prefix='.orbit-recipe-', dir=home, delete=False) as stream:
            temporary = pathlib.Path(stream.name)
            try:
                json.dump({'version': version}, stream)
                stream.flush()
                temporary.chmod(0o644)
                temporary.replace(home / RECIPE_NODE_REFERENCE)
            finally:
                temporary.unlink(missing_ok=True)
