"""Signed recipe policy. Each native step calls exactly one ACTION. SSH owns process lifetime."""
import contextlib
import datetime
import gzip
import hashlib
import json
import os
import pwd
import re
import shutil
import socket
import stat
import struct
import subprocess
import sys
import tarfile
import tempfile
import time
import urllib.parse
import zipfile
from pathlib import Path


class RecipeRefusal(Exception):

    def __init__(self, stage, code='node.recipe_failed'):
        self.stage, self.code = (stage, code)
        super().__init__(stage)

class RecipeUncertain(Exception):
    pass

class RecipeHost:

    def __init__(self, root=Path('/'), uid=0, gid=0):
        self.root, self.uid, self.gid = (root, uid, gid)

    def path(self, absolute):
        return self.root / absolute.lstrip('/')

    def read(self, absolute):
        return self.path(absolute).read_text()

    def run(self, argv, required=True, timeout=600, environment=None):
        programs = {'dpkg': '/usr/bin/dpkg', 'dpkg-query': '/usr/bin/dpkg-query', 'dpkg-deb': '/usr/bin/dpkg-deb', 'apt-get': '/usr/bin/apt-get', 'apt-cache': '/usr/bin/apt-cache', 'incus': '/usr/bin/incus', 'systemctl': '/usr/bin/systemctl', 'systemd-run': '/usr/bin/systemd-run', 'curl': '/usr/bin/curl', 'logger': '/usr/bin/logger', 'sudo': '/usr/bin/sudo', 'env': '/usr/bin/env', 'phpenmod': '/usr/sbin/phpenmod', 'phpdismod': '/usr/sbin/phpdismod', 'apparmor_parser': '/usr/sbin/apparmor_parser'}
        if not argv:
            raise RecipeRefusal('command')
        executable = programs.get(argv[0])
        if executable is None and re.fullmatch('/usr/(?:sbin/php-fpm|bin/php)[0-9]+\\.[0-9]+', argv[0]):
            executable = argv[0]
        if executable is None:
            raise RecipeRefusal('command')
        env = {'PATH': '/usr/sbin:/usr/bin:/sbin:/bin', 'HOME': '/root', 'LC_ALL': 'C'}
        env.update(environment or {})
        reply = subprocess.run([executable, *argv[1:]], stdin=subprocess.DEVNULL, stdout=subprocess.PIPE, stderr=subprocess.PIPE, env=env, text=True)
        if required and reply.returncode != 0:
            raise RecipeRefusal('command:' + argv[0])
        return {'code': reply.returncode, 'stdout': reply.stdout, 'stderr': reply.stderr}

    def publish(self, absolute, content, mode=420, uid=None, gid=None, replace_link=False):
        uid = self.uid if uid is None else uid
        gid = self.gid if gid is None else gid
        path = self.path(absolute)
        for parent in reversed(path.parents):
            if parent == self.root.parent and self.root != Path('/'):
                continue
            if parent.exists() and (parent.is_symlink() or not parent.is_dir()):
                raise RecipeRefusal('unsafe-path')
        if path.exists() or path.is_symlink():
            meta = path.lstat()
            if meta.st_uid != uid or not (stat.S_ISREG(meta.st_mode) or (replace_link and stat.S_ISLNK(meta.st_mode))):
                raise RecipeRefusal('unsafe-path')
        path.parent.mkdir(mode=493, parents=True, exist_ok=True)
        fd, temporary = tempfile.mkstemp(prefix='.orbit-recipe-', dir=path.parent)
        try:
            with os.fdopen(fd, 'wb') as stream:
                stream.write(content.encode() if isinstance(content, str) else content)
                stream.flush()
                os.fsync(stream.fileno())
                os.fchmod(stream.fileno(), mode)
                os.fchown(stream.fileno(), uid, gid)
            os.replace(temporary, path)
            directory = os.open(path.parent, os.O_DIRECTORY)
            try:
                os.fsync(directory)
            finally:
                os.close(directory)
        finally:
            if os.path.exists(temporary):
                os.unlink(temporary)

def require(value, stage='input'):
    if not value:
        raise RecipeRefusal(stage)

def https(url):
    try:
        parsed = urllib.parse.urlsplit(url)
        return parsed.scheme == 'https' and bool(parsed.hostname) and (not parsed.username) and (not parsed.password) and (not parsed.fragment)
    except (ValueError, TypeError):
        return False

def valid_package(value):
    return isinstance(value, str) and re.fullmatch('[a-z0-9][a-z0-9+.-]*', value) is not None

def valid_version(value):
    return isinstance(value, str) and re.fullmatch('[0-9][0-9A-Za-z.+:~_-]*', value) is not None

def valid_digest(value, length=64):
    return isinstance(value, str) and re.fullmatch('[a-f0-9]{' + str(length) + '}', value) is not None

def validate_recipe(data):
    require(isinstance(data, dict) and set(data) <= set(['user', 'snapshot', 'architecture', 'git_packages', 'artifact_mirror', 'tools', 'vp_lock']))
    require(isinstance(data.get('user'), str) and re.fullmatch('[a-z_][a-z0-9_-]*', data['user']) and (data['user'] != 'root'))
    require(data.get('architecture') == 'amd64')
    require(isinstance(data.get('snapshot'), str) and re.fullmatch('[0-9]{8}T[0-9]{6}Z', data['snapshot']))
    require(not data.get('artifact_mirror') or https(data['artifact_mirror']))
    require(isinstance(data.get('git_packages', []), list) and all((valid_package(name) for name in data.get('git_packages', []))))
    require(isinstance(data.get('tools'), dict))
    for name, pin in data['tools'].items():
        require(valid_package(name) and isinstance(pin, dict) and (set(pin) <= set(['source', 'version', 'package', 'artifacts', 'lock_sha256', 'source_commit'])))
        require(valid_version(pin.get('version')))
        artifacts = pin.get('artifacts', {})
        require(isinstance(artifacts, dict))
        if pin.get('source') == 'ubuntu':
            require(valid_package(pin.get('package')) and (not artifacts))
        else:
            require(pin.get('source') == 'artifact')
            artifact = artifacts.get('amd64')
            require(isinstance(artifact, dict) and set(artifact) <= set(['url', 'filename', 'version', 'sha256', 'sha512']))
            require(valid_digest(artifact.get('sha256')) or valid_digest(artifact.get('sha512'), 128))
            require(name == 'pi' or https(artifact.get('url')))
            require(artifact.get('version') is None or valid_version(artifact['version']))
        if name == 'vp':
            require(valid_digest(pin.get('lock_sha256')))
    return data

def packages(host):
    require(host.run(['dpkg', '--print-architecture'])['stdout'].strip() == 'amd64', 'architecture')
    template = '-f=' + ''.join(('$' + '{' + field + '}' + ending for field, ending in [('Package', '\t'), ('Architecture', '\t'), ('Version', '\t'), ('Status', '\n')]))
    rows = host.run(['dpkg-query', '-W', template])['stdout'].splitlines()
    installed = {}
    for row in rows:
        fields = row.split('\t', 3)
        require(len(fields) == 4, 'inventory')
        if fields[3] == 'install ok installed' and fields[1] in ('amd64', 'all'):
            installed[fields[0]] = fields[2]
    return installed

def platform(host, data):
    validate_recipe(data)
    values = {}
    for line in host.read('/etc/os-release').splitlines():
        for key in ('ID', 'VERSION_CODENAME'):
            if not line.startswith(key + '='):
                continue
            require(key not in values, 'platform')
            value = line[len(key) + 1:]
            if value[:1] in ('"', "'"):
                require(len(value) >= 2 and value[-1] == value[0], 'platform')
                value = value[1:-1]
            require(re.fullmatch('[A-Za-z0-9._-]+', value), 'platform')
            values[key] = value
    require(values.get('ID') == 'ubuntu' and values.get('VERSION_CODENAME') == 'resolute', 'platform')
    installed = packages(host)
    if 'docker-ce' in installed:
        raise RecipeRefusal('docker-provider:docker-ce', 'node.recipe_docker_provider')
    return installed
UBUNTU_KEYRING = '/usr/share/keyrings/ubuntu-archive-keyring.gpg'

def source_paths(host):
    directory = host.path('/etc/apt/sources.list.d')
    return [host.path('/etc/apt/sources.list')] + (sorted(directory.glob('*.sources')) + sorted(directory.glob('*.list')) if directory.exists() else [])

def deb822(paragraph):
    fields = {}
    previous = None
    for line in paragraph.splitlines():
        if not line.strip() or line.startswith('#'):
            continue
        if line[:1].isspace():
            require(previous is not None, 'apt-source')
            fields[previous] += ' ' + line.strip()
            continue
        require(':' in line, 'apt-source')
        key, value = line.split(':', 1)
        key = key.lower()
        require(re.fullmatch('[a-z][a-z0-9-]*', key) and key not in fields, 'apt-source')
        fields[key] = value.strip()
        previous = key
    return fields

def provider_mentions(owner, text):
    return re.search({'git': '(?:ppa\\.launchpadcontent\\.net|ppa\\.launchpad\\.net)/git-core/ppa', 'docker': 'download\\.docker\\.com', 'node': 'deb\\.nodesource\\.com', 'incus': 'pkgs\\.zabbly\\.com/incus'}[owner], text) is not None

def provider_accepts(owner, uris, suites, components, types):
    if not uris or len(suites) != 1 or (not types) or any((kind not in ('deb', 'deb-src') for kind in types)):
        return False

    def uri_ok(uri):
        uri = uri.rstrip('/')
        if owner == 'git':
            return uri in [scheme + '://' + domain + '/git-core/ppa/ubuntu' for scheme in ('http', 'https') for domain in ('ppa.launchpad.net', 'ppa.launchpadcontent.net')]
        if owner == 'docker':
            return uri == 'https://download.docker.com/linux/ubuntu'
        if owner == 'incus':
            return uri == 'https://pkgs.zabbly.com/incus/lts-7.0'
        return uri in ['https://deb.nodesource.com/node_' + str(major) + '.x' for major in range(10, 27)]
    if not all((uri_ok(uri) for uri in uris)):
        return False
    if owner in ('git', 'incus'):
        return suites == ['resolute'] and components == ['main']
    if owner == 'node':
        return suites == ['nodistro'] and components == ['main']
    return bool(re.fullmatch('[a-z][a-z0-9-]*', suites[0])) and components == ['stable']

def source_backup(host, path, before, owner, check_only):
    target = host.path('/etc/apt') / (path.name + '.orbit-' + owner + '-backup')
    if target.exists() or target.is_symlink():
        require(not target.is_symlink() and target.is_file() and (target.read_bytes() == before), 'apt-source')
    elif not check_only:
        host.publish('/etc/apt/' + target.name, before)
    return target

def provider_changes(host, owner):
    changes = []
    for path in source_paths(host):
        if not path.is_file():
            continue
        content = path.read_text()
        if not provider_mentions(owner, content):
            continue
        require(not path.is_symlink(), 'apt-source')
        blocks = re.split('(\\n\\s*\\n)', content) if path.suffix == '.sources' else content.splitlines(keepends=True)
        replacement = ''
        for block in blocks:
            active = '\n'.join((line for line in block.splitlines() if not line.lstrip().startswith('#')))
            if not provider_mentions(owner, active):
                replacement += block
                continue
            if path.suffix == '.sources':
                fields = deb822(block)
                uris, suites, components, types = [fields.get(key, '').split() for key in ('uris', 'suites', 'components', 'types')]
            else:
                entry = re.fullmatch('\\s*(deb(?:-src)?)\\s+(?:\\[[^]]+\\]\\s+)?(\\S+)\\s+(\\S+)\\s+([^#]+?)(?:\\s*#.*)?\\s*', block)
                require(entry is not None, 'apt-source')
                kind, uri, suite, component = entry.groups()
                uris = [uri]
                suites = [suite]
                components = component.split()
                types = [kind]
            require(provider_accepts(owner, uris, suites, components, types), 'apt-source')
        if replacement != content:
            source_backup(host, path, content.encode(), owner, True)
            changes.append((path, content.encode(), replacement.encode()))
    return changes

def retire_sources(host, changes, owner):
    for path, before, after in changes:
        source_backup(host, path, before, owner, True)
        require(not path.is_symlink() and path.read_bytes() == before, 'apt-source')
    for path, before, after in changes:
        source_backup(host, path, before, owner, False)
        if after.strip():
            host.publish('/' + str(path.relative_to(host.root)), after)
        else:
            path.unlink()

def preferences(tools):
    return ''.join(('Package: ' + name + '\nPin: version ' + tools[name]['version'] + '\nPin-Priority: 1001\n\nPackage: ' + name + '\nPin: version *\nPin-Priority: -1\n\n' for name in sorted(tools))).encode()

def preference_changed(host, tools, owner):
    path = host.path('/etc/apt/preferences.d/orbit-' + owner)
    if path.exists() or path.is_symlink():
        require(path.is_file() and (not path.is_symlink()), 'apt-source')
        return path.read_bytes() != preferences(tools)
    return True

def incus_tools(data):
    return {name: pin for name, pin in data['tools'].items() if name in ('incus', 'incus-base', 'incus-client') and pin['source'] == 'artifact'}

def incus_preflight(host, data, installed=None):
    installed = platform(host, data) if installed is None else installed
    tools = incus_tools(data)
    if not tools:
        return {'ready': True}
    changed = any((installed.get(name) != pin['version'] for name, pin in tools.items()))
    if not changed:
        require(len(tools) == 3, 'incus:incomplete')
        changed = bool(provider_changes(host, 'incus')) or 'incus-agent' in packages(host) or preference_changed(host, tools, 'incus')
    if not changed:
        changed = 'incus-agent' in packages(host)
    if not changed or ('incus' not in installed and 'incus-base' not in installed):
        return {'ready': True}
    rows = json.loads(host.run(['incus', 'list', '--all-projects', '--format=json'])['stdout'])
    require(isinstance(rows, list), 'incus:inventory')
    for row in rows:
        require(isinstance(row, dict) and all((isinstance(row.get(key), str) and row[key] for key in ('project', 'name', 'type', 'status'))), 'incus:inventory')
        if row['status'] != 'Stopped':
            raise RecipeRefusal('incus:running', 'node.recipe_incus_busy')
    return {'ready': True}
MAX_ARCHIVE = 512 * 1024 * 1024
MAX_EXPANDED = 2 * 1024 * 1024 * 1024
JS_NAMES = {'node', 'vp', 'vp-cli', 'pnpm', 'bun'}

def digest_file(path, algorithm='sha256'):
    digest = hashlib.new(algorithm)
    with path.open('rb') as stream:
        for block in iter(lambda: stream.read(65536), b''):
            digest.update(block)
    return digest.hexdigest()

def verify_artifact(path, artifact):
    require(path.is_file() and (not path.is_symlink()) and (path.stat().st_size <= MAX_ARCHIVE), 'download')
    algorithm = 'sha256' if artifact.get('sha256') is not None else 'sha512'
    require(digest_file(path, algorithm) == artifact.get(algorithm), 'download')

def download_artifact(host, name, pin, data, target):
    artifact = pin['artifacts']['amd64']
    urls = []
    url = artifact.get('url')
    require(https(url), 'download')
    if data.get('artifact_mirror') and url.endswith('.deb'):
        urls.append(data['artifact_mirror'].rstrip('/') + '/' + name + '_' + (artifact.get('sha256') or artifact['sha512']) + '.deb')
    if url not in urls:
        urls.append(url)
    for candidate in urls:
        require(https(candidate), 'download')
        result = host.run(['curl', '--fail', '--silent', '--show-error', '--location', '--proto', '=https', '--proto-redir', '=https', '--connect-timeout', '15', '--max-time', '600', '--max-filesize', str(MAX_ARCHIVE), '--output', str(target), '--', candidate], required=False)
        if result['code'] == 0:
            verify_artifact(target, artifact)
            return
    raise RecipeRefusal('download')

def archive_path(value):
    require(isinstance(value, str) and value and (not value.startswith('/')) and ('\\' not in value) and ('\x00' not in value), 'archive')
    parts = value.split('/')
    require('..' not in parts, 'archive')
    normal = Path(value)
    require(str(normal) not in ('.', ''), 'archive')
    return normal

def safe_archive_link(name, target, hard=False):
    require(isinstance(target, str) and target and (not target.startswith('/')) and ('\\' not in target), 'archive')
    depth = [] if hard else list(name.parent.parts)
    for part in target.split('/'):
        if part in ('', '.'):
            continue
        if part == '..':
            require(bool(depth), 'archive')
            depth.pop()
        else:
            depth.append(part)
    require(bool(depth), 'archive')
    return Path(*depth)

def extract_archive(archive, destination):
    require(not destination.exists() and (not destination.is_symlink()), 'archive')
    entries = []
    total = 0
    names = {}
    iszip = zipfile.is_zipfile(archive)
    with zipfile.ZipFile(archive) if iszip else tarfile.open(archive, 'r:*') as stream:
        for member in stream.infolist() if iszip else stream:
            raw = member.filename if iszip else member.name
            directory = member.is_dir() if iszip else member.isdir()
            if raw in ('.', './') and directory:
                continue
            path = archive_path(raw)
            if iszip:
                mode = member.external_attr >> 16
                require(stat.S_IFMT(mode) in (0, stat.S_IFREG, stat.S_IFDIR), 'archive')
                kind = 'directory' if member.is_dir() else 'file'
                size = member.file_size
            else:
                require(member.isfile() or member.isdir() or member.issym() or member.islnk(), 'archive')
                kind = 'file' if member.isfile() else 'directory' if member.isdir() else 'symlink' if member.issym() else 'hardlink'
                mode = member.mode
                size = member.size
            if path in names and names[path] == kind == 'directory':
                continue
            require(path not in names, 'archive')
            names[path] = kind
            total += size
            require(total <= MAX_EXPANDED, 'archive')
            target = safe_archive_link(path, member.linkname, kind == 'hardlink') if kind in ('symlink', 'hardlink') else None
            entries.append((member, path, kind, mode, target))
        for _, path, kind, _, target in entries:
            require(all((names.get(parent, 'directory') == 'directory' for parent in path.parents if str(parent) != '.')), 'archive')
            if kind == 'hardlink':
                require(names.get(target) == 'file', 'archive')
        destination.mkdir(mode=448)
        for member, path, kind, mode, target in sorted(entries, key=lambda entry: len(entry[1].parts)):
            output = destination / path
            output.parent.mkdir(mode=493, parents=True, exist_ok=True)
            if kind == 'directory':
                output.mkdir(mode=493, exist_ok=True)
            elif kind == 'file':
                source = stream.open(member) if iszip else stream.extractfile(member)
                with source, output.open('xb') as writer:
                    shutil.copyfileobj(source, writer, 65536)
                output.chmod(mode & 493)
        for member, path, kind, _, target in entries:
            if kind == 'symlink':
                (destination / path).symlink_to(member.linkname)
            elif kind == 'hardlink':
                os.link(destination / target, destination / path, follow_symlinks=False)

def owned_directory(path, uid, gid, mode=493):
    if path.exists() or path.is_symlink():
        meta = path.lstat()
        require(stat.S_ISDIR(meta.st_mode) and meta.st_uid == uid, 'ownership')
    else:
        path.mkdir(mode=mode)
        os.chown(path, uid, gid)

def own_tree(path, uid, gid):
    meta = path.lstat()
    os.chown(path, uid, gid, follow_symlinks=False)
    if not stat.S_ISLNK(meta.st_mode):
        path.chmod(meta.st_mode & 493)
    if stat.S_ISDIR(meta.st_mode):
        for child in path.iterdir():
            own_tree(child, uid, gid)

def same_tree(source, current, uid):

    def manifest(root):
        result = {}

        def walk(path):
            relative = path.relative_to(root)
            meta = path.lstat()
            kind = stat.S_IFMT(meta.st_mode)
            value = os.readlink(path) if stat.S_ISLNK(meta.st_mode) else digest_file(path) if stat.S_ISREG(meta.st_mode) else None
            result[str(relative)] = (kind, meta.st_mode & 73, value, meta.st_uid, meta.st_mode & 18)
            if stat.S_ISDIR(meta.st_mode):
                for child in path.iterdir():
                    walk(child)
        walk(root)
        return result
    if current.is_symlink() or not current.is_dir():
        return False
    expected, actual = (manifest(source), manifest(current))
    return expected.keys() == actual.keys() and all((actual[key][:3] == value[:3] and actual[key][3] == uid and (not actual[key][4]) for key, value in expected.items() if value[0] != stat.S_IFLNK)) and all((actual[key][:4] == value[:4] for key, value in expected.items() if value[0] == stat.S_IFLNK))

def account_for(host, user):
    account = pwd.getpwnam(user)
    require(account.pw_uid != 0 and account.pw_dir.startswith('/') and ('\n' not in account.pw_dir), 'account')
    return {'name': user, 'uid': account.pw_uid, 'gid': account.pw_gid, 'home': host.path(account.pw_dir)}

def user_command(host, account, scope, args, environment=None):
    env = {'HOME': str(account['home']), 'PATH': '/usr/bin:/bin', 'VP_HOME': str(scope), 'LC_ALL': 'C'}
    env.update(environment or {})
    return host.run(['sudo', '-n', '-u', account['name'], '-H', '--', '/usr/bin/env', '-i', '-C', str(account['home']), *[key + '=' + value for key, value in env.items()], *map(str, args)])

def runtime_scope(host, account):
    candidates = [host.path('/opt/orbit/vite-plus'), account['home'] / '.vite-plus', account['home'] / '.local/share/vite-plus']
    for path in candidates:
        if path.exists() or path.is_symlink():
            require(path.is_dir() and (not path.is_symlink()) and (path.stat().st_uid == account['uid']), 'ownership')
            if (path / 'bin/vp').exists():
                return path
    return candidates[-1]

def check_store_path(scope, path, account):
    require(path.is_relative_to(scope), 'ownership')
    for entry in [scope, *reversed(path.relative_to(scope).parents)]:
        candidate = entry if entry == scope else scope / entry
        if candidate.exists() or candidate.is_symlink():
            meta = candidate.lstat()
            require(not stat.S_ISLNK(meta.st_mode) and meta.st_uid == account['uid'] and (not meta.st_mode & 18), 'ownership')
    if path.exists() or path.is_symlink():
        meta = path.lstat()
        require(not stat.S_ISLNK(meta.st_mode) and meta.st_uid == account['uid'] and (not meta.st_mode & 18), 'ownership')

def publish_owned(host, path, content, account, mode=420, replace_link=False):
    absolute = '/' + str(path.relative_to(host.root))
    host.publish(absolute, content, mode, account['uid'], account['gid'], replace_link)

def replace_owned_link(path, target, account):
    if path.exists() or path.is_symlink():
        meta = path.lstat()
        require(stat.S_ISLNK(meta.st_mode) and meta.st_uid == account['uid'], 'ownership')
    candidate = path.with_name('.orbit-link-' + os.urandom(8).hex())
    try:
        candidate.symlink_to(target)
        os.chown(candidate, account['uid'], account['gid'], follow_symlinks=False)
        os.replace(candidate, path)
    finally:
        if candidate.is_symlink():
            candidate.unlink()

def publish_node(host, scope, account, version, archive):
    require(re.fullmatch('[0-9]+\\.[0-9]+\\.[0-9]+(?:-[0-9A-Za-z.-]+)?', version), 'node-version')
    for parent in (scope / 'js_runtime', scope / 'js_runtime/node'):
        owned_directory(parent, account['uid'], account['gid'])
        parent.chmod(parent.stat().st_mode & 493)
    cache = scope / 'js_runtime/node'
    for link in sorted(cache.iterdir()):
        if not link.is_symlink():
            continue
        target = Path(os.path.normpath(link.parent / os.readlink(link)))
        expected = 'recipe/node-[a-f0-9]{64}/node-v' + re.escape(link.name) + '-linux-x64'
        require(link.lstat().st_uid == account['uid'] and target.is_relative_to(scope) and re.fullmatch(expected, str(target.relative_to(scope))), 'ownership')
        check_store_path(scope, target, account)
        require(target.is_dir(), 'ownership')
        stage = cache / ('.orbit-stage-' + os.urandom(8).hex())
        shutil.copytree(target, stage, symlinks=True)
        own_tree(stage, account['uid'], account['gid'])
        link.unlink()
        os.rename(stage, link)
    destination = cache / version
    check_store_path(scope, destination, account)
    with tempfile.TemporaryDirectory(prefix='.orbit-node-', dir=cache) as temporary:
        stage = Path(temporary) / 'archive'
        extract_archive(archive, stage)
        source = stage / ('node-v' + version + '-linux-x64')
        require(source.is_dir() and (not source.is_symlink()), 'node-layout')
        own_tree(source, account['uid'], account['gid'])
        if destination.exists():
            require(same_tree(source, destination, account['uid']), 'node-content')
        else:
            os.rename(source, destination)
    publish_owned(host, scope / '.orbit-recipe-node.json', json.dumps({'version': version}), account)

def metadata_version(path):
    try:
        value = json.loads(path.read_text())
    except FileNotFoundError:
        return None
    require(isinstance(value, dict), 'runtime-version')
    version = value.get('version')
    require(version is None or isinstance(version, str), 'runtime-version')
    return version

def binary_version(host, account, scope, binary):
    if not binary.is_file():
        return None
    try:
        output = user_command(host, account, scope, [binary, '--version'])['stdout']
    except RecipeRefusal:
        return None
    match = re.search('(?:^|[\\s v])([0-9]+\\.[0-9]+\\.[0-9]+)\\b', output)
    return match[1] if match else None

def javascript_versions(host, data, account, scope):
    versions = {}
    for name, pin in data['tools'].items():
        if name not in JS_NAMES:
            continue
        if name == 'node':
            reference = scope / '.orbit-recipe-node.json'
            version = None
            if reference.is_file() and (not reference.is_symlink()) and (reference.stat().st_uid == account['uid']) and (not reference.stat().st_mode & 18):
                recorded = json.loads(reference.read_text()).get('version')
                require(recorded is None or isinstance(recorded, str), 'node-version')
                if recorded and re.fullmatch('[0-9]+\\.[0-9]+\\.[0-9]+(?:-[0-9A-Za-z.-]+)?', recorded):
                    target = scope / 'js_runtime/node' / recorded
                    check_store_path(scope, target, account)
                    configuration = json.loads((scope / 'config.json').read_text()) if (scope / 'config.json').is_file() else {}
                    if configuration.get('defaultNodeVersion') == recorded:
                        version = binary_version(host, account, scope, target / 'bin/node')
        elif name == 'bun':
            version = binary_version(host, account, scope, host.path('/opt/orbit/bun/bin/bun'))
        elif name == 'vp-cli':
            version = binary_version(host, account, scope, scope / 'bin/vp') if (scope / 'bin/.vp-setup-complete').is_file() else None
        elif name == 'pnpm':
            version = metadata_version(scope / 'recipe/pnpm-current/package.json')
            if version != binary_version(host, account, scope, scope / 'bin/pnpm'):
                version = None
        else:
            digest = pin['lock_sha256']
            target = scope / 'recipe' / ('vp-runtime-' + digest)
            active = scope / 'node_modules/vite-plus'
            lock = target / 'package-lock.json'
            version = metadata_version(target / 'node_modules/vite-plus/package.json') if lock.is_file() and digest_file(lock) == digest and active.is_symlink() and (active.resolve() == target / 'node_modules/vite-plus') else None
        versions[name] = version
    return versions

def install_javascript(host, data, account=None, downloads=None):
    validate_recipe(data)
    require(set(data['tools']) <= JS_NAMES, 'javascript-input')
    if not data['tools']:
        return {'changed': False, 'retired': []}
    account = account or account_for(host, data['user'])
    scope = runtime_scope(host, account)
    for parent in (account['home'] / '.local', account['home'] / '.local/share'):
        owned_directory(parent, account['uid'], account['gid'])
    owned_directory(scope, account['uid'], account['gid'])
    scope.chmod(scope.stat().st_mode & 493)
    for parent in (scope / 'bin', scope / 'recipe'):
        owned_directory(parent, account['uid'], account['gid'])
    observed = javascript_versions(host, data, account, scope)
    current = all((observed.get(name) == pin['version'] for name, pin in data['tools'].items()))
    if current:
        return {'changed': False, 'retired': consolidate_node(host, data, account, scope)}
    with tempfile.TemporaryDirectory(prefix='orbit-recipe-js-') as temporary:
        work = Path(temporary)
        downloads = dict(downloads or {})
        for name, pin in data['tools'].items():
            if name == 'vp' or name in downloads:
                continue
            target = work / (name + '.archive')
            download_artifact(host, name, pin, data, target)
            downloads[name] = target
        for name in ('node', 'vp-cli', 'pnpm', 'bun'):
            if name not in downloads:
                continue
            pin = data['tools'][name]
            archive = downloads[name]
            verify_artifact(archive, pin['artifacts']['amd64'])
            if name == 'node':
                publish_node(host, scope, account, pin['version'], archive)
                continue
            stage = work / ('unpack-' + name)
            extract_archive(archive, stage)
            target = scope / 'recipe' / (name + '-' + digest_file(archive))
            own_tree(stage, account['uid'], account['gid'])
            if target.exists():
                require(same_tree(stage, target, account['uid']), 'runtime-content')
            else:
                os.rename(stage, target)
            if name == 'vp-cli':
                publish_owned(host, scope / 'bin/vp', (target / 'vp').read_bytes(), account, 493, True)
                publish_owned(host, scope / 'bin/.vp-setup-complete', '', account)
            elif name == 'pnpm':
                package = target / 'package'
                require(metadata_version(package / 'package.json') == pin['version'], 'pnpm-version')
                native = package / 'pnpm'
                script = package / 'bin/pnpm.cjs'
                if native.is_file() and native.read_bytes().startswith(b'\x7fELF'):
                    content = native.read_bytes()
                else:
                    require(script.is_file(), 'pnpm-layout')
                    content = '#!/bin/sh\nexec "' + str(scope / 'bin/node') + '" "' + str(script) + '" "$@"\n'
                replace_owned_link(scope / 'recipe/pnpm-current', package, account)
                publish_owned(host, scope / 'bin/pnpm', content, account, 493, True)
            else:
                executables = [path / 'bun' for path in target.iterdir() if (path / 'bun').is_file()]
                require(len(executables) == 1, 'bun-layout')
                owned_directory(host.path('/opt/orbit'), host.uid, host.gid)
                for parent in (host.path('/opt/orbit/bun'), host.path('/opt/orbit/bun/bin')):
                    owned_directory(parent, account['uid'], account['gid'])
                publish_owned(host, host.path('/opt/orbit/bun/bin/bun'), executables[0].read_bytes(), account, 493, True)
                host.publish('/usr/local/bin/bun', '#!/bin/sh\nexec /opt/orbit/bun/bin/bun "$@"\n', 493, replace_link=True)
        if 'vp' in data['tools']:
            pin = data['tools']['vp']
            content = data.get('vp_lock')
            require(isinstance(content, str) and hashlib.sha256(content.encode()).hexdigest() == pin['lock_sha256'], 'vite-lock')
            lock = json.loads(content)
            dependencies = {'vite-plus': pin['version']}
            require(lock.get('packages', {}).get('', {}).get('dependencies') == dependencies and isinstance(lock.get('name'), str) and isinstance(lock.get('version'), str), 'vite-lock')
            require('node' in data['tools'], 'vite-node')
            target = scope / 'recipe' / ('vp-runtime-' + pin['lock_sha256'])
            if not target.exists():
                candidate = scope / 'recipe' / ('.vp-runtime-' + os.urandom(8).hex())
                owned_directory(candidate, account['uid'], account['gid'])
                try:
                    manifest = {'name': lock['name'], 'version': lock['version'], 'private': True, 'dependencies': dependencies}
                    publish_owned(host, candidate / 'package.json', json.dumps(manifest), account)
                    publish_owned(host, candidate / 'package-lock.json', content, account)
                    node = scope / 'js_runtime/node' / data['tools']['node']['version']
                    cache = scope / 'recipe/npm-cache'
                    owned_directory(cache, account['uid'], account['gid'])
                    user_command(host, account, scope, [node / 'bin/node', node / 'lib/node_modules/npm/bin/npm-cli.js', 'ci', '--ignore-scripts', '--no-audit', '--no-fund', '--prefix', candidate], {'PATH': str(node / 'bin') + ':/bin:/usr/bin', 'npm_config_cache': str(cache)})
                    os.rename(candidate, target)
                finally:
                    if candidate.exists():
                        shutil.rmtree(candidate)
            require(not target.is_symlink() and digest_file(target / 'package-lock.json') == pin['lock_sha256'], 'vite-lock')
            package = target / 'node_modules/vite-plus'
            require(metadata_version(package / 'package.json') == pin['version'], 'vite-version')
            modules = scope / 'node_modules'
            if modules.is_symlink():
                require(modules.lstat().st_uid == account['uid'], 'ownership')
                owned_directory(modules.resolve(), account['uid'], account['gid'])
            else:
                owned_directory(modules, account['uid'], account['gid'])
            replace_owned_link(modules / 'vite-plus', package, account)
            replace_owned_link(scope / 'recipe/vp-current', package, account)
        if 'node' in data['tools']:
            require((scope / 'bin/vp').is_file(), 'vp-cli-required')
            for binary in ('node', 'npm', 'npx'):
                path = scope / 'bin' / binary
                if path.exists() or path.is_symlink():
                    require(path.lstat().st_uid == account['uid'] and (not path.is_dir()), 'ownership')
                    path.unlink()
                path.symlink_to('vp')
                os.chown(path, account['uid'], account['gid'], follow_symlinks=False)
            user_command(host, account, scope, [scope / 'bin/vp', 'env', 'default', data['tools']['node']['version']])
        for binary in ('vp', 'node', 'pnpm', 'npm', 'npx'):
            content = '#!/bin/sh\nexport VP_HOME="' + str(scope) + '"\nexec "' + str(scope / 'bin' / binary) + '" "$@"\n'
            host.publish('/usr/local/bin/' + binary, content, 493)
        after = javascript_versions(host, data, account, scope)
        require(all((after.get(name) == pin['version'] for name, pin in data['tools'].items())), 'javascript-verify')
    retired = consolidate_node(host, data, account, scope)
    return {'changed': True, 'retired': retired}

def consolidate_node(host, data, account, scope):
    pin = data['tools'].get('node')
    if not pin:
        return []
    require(javascript_versions(host, {'tools': {'node': pin}}, account, scope).get('node') == pin['version'], 'node-replacement')
    changes = provider_changes(host, 'node')
    retired = []
    config = []
    for name in ('nodejs', 'nsolid'):
        path = host.path('/etc/apt/preferences.d/' + name)
        if not path.exists() and (not path.is_symlink()):
            continue
        require(path.is_file() and (not path.is_symlink()), 'node-preference')
        fields = deb822(path.read_text())
        require(set(fields) == {'package', 'pin', 'pin-priority'} and fields['package'] == name and (fields['pin'] == 'origin deb.nodesource.com') and re.fullmatch('-?[0-9]+', fields['pin-priority']), 'node-preference')
        source_backup(host, path, path.read_bytes(), 'node-preference', True)
        config.append((path, 'node-preference'))
    for absolute, owner in (('/usr/share/keyrings/nodesource.gpg', 'node-usr-keyring'), ('/etc/apt/keyrings/nodesource.gpg', 'node-apt-keyring')):
        path = host.path(absolute)
        if not path.exists() and (not path.is_symlink()):
            continue
        require(path.is_file() and (not path.is_symlink()), 'node-keyring')
        for source in source_paths(host):
            if not source.is_file():
                continue
            remaining = next((after.decode() for changed, _, after in changes if changed == source), source.read_text())
            require(not any((absolute in line for line in remaining.splitlines() if not line.lstrip().startswith('#'))), 'node-keyring-referenced')
        source_backup(host, path, path.read_bytes(), owner, True)
        config.append((path, owner))
    if 'nodejs' in packages(host):
        simulation = host.run(['apt-get', '--simulate', 'remove', '--', 'nodejs'])['stdout']
        removed = [line.split()[1] for line in simulation.splitlines() if line.startswith('Remv ')]
        require(removed == ['nodejs'], 'node-removal')
        host.run(['apt-get', 'remove', '--yes', '--', 'nodejs'], environment={'DEBIAN_FRONTEND': 'noninteractive', 'NEEDRESTART_SUSPEND': '1'})
        require('nodejs' not in packages(host), 'node-removal')
        retired.append('remove_nodejs_package')
    retire_sources(host, changes, 'node')
    if changes:
        retired.append('retire_node_source')
    for path, owner in config:
        source_backup(host, path, path.read_bytes(), owner, False)
        path.unlink()
    if config:
        retired.append('retire_node_config')
    for name in ('yarn', 'yarnpkg', 'pnpm', 'pnpx'):
        path = host.path('/usr/bin/' + name)
        expected = host.path('/usr/lib/node_modules/corepack/dist/' + name + '.js')
        if path.is_symlink() and (not path.exists()) and (Path(os.path.normpath(path.parent / os.readlink(path))) == expected):
            path.unlink()
            retired.append('remove_corepack_links')
    return sorted(set(retired))

def read_release(host):
    values = {}
    for line in host.read('/etc/os-release').splitlines():
        if '=' not in line:
            continue
        key, value = line.split('=', 1)
        if key not in ('ID', 'VERSION_CODENAME'):
            continue
        require(key not in values, 'platform')
        if value[:1] in ('"', "'"):
            require(value[-1:] == value[:1] and len(value) > 1, 'platform')
            value = value[1:-1]
        require(re.fullmatch('[A-Za-z0-9._-]+', value), 'platform')
        values[key] = value
    return values

def fpm_units(host, version, scope='all'):
    shared = 'php' + version + '-fpm.service'
    units = []
    skipped = []
    if host.run(['systemctl', 'is-active', '--quiet', shared], required=False)['code'] == 0:
        units.append((shared, host.path('/etc/php/' + version + '/fpm/php-fpm.conf')))
    listed = host.run(['systemctl', 'list-units', '--type=service', '--state=active', '--no-legend', '--plain', 'orbit-*-php' + version + '-fpm.service'], required=False)
    pattern = 'orbit-[a-z0-9_-]+-php' + re.escape(version) + '-fpm\\.service'
    for line in listed['stdout'].splitlines():
        fields = line.split()
        if not fields or not re.fullmatch(pattern, fields[0]):
            continue
        unit = fields[0]
        start = host.run(['systemctl', 'show', '-p', 'ExecStart', '--value', unit], required=False)['stdout']
        match = re.search('--fpm-config\\s+(\\S+)', start)
        configuration = host.path(match[1]) if match and match[1].startswith('/') else None
        if scope == 'shared':
            skipped.append(unit)
        else:
            units.append((unit, configuration))
    return (units, skipped)

def php_modules(host, binary):
    return {line.strip().lower() for line in host.run([str(binary), '-m'])['stdout'].splitlines() if line.strip() and (not line.strip().startswith('['))}

def restore_file(host, path, before):
    if before is None:
        if path.exists() or path.is_symlink():
            path.unlink()
    else:
        host.publish('/' + str(path.relative_to(host.root)), before)

def php_fail(stage, code):
    raise RecipeRefusal(stage, 'php.build_' + code)

def validate_php(data):
    if not isinstance(data, dict) or not set(data) <= set(['versions', 'mirror', 'pcov', 'opentelemetry', 'extras', 'fpm', 'repack', 'release']):
        php_fail('input', 'input_invalid')
    require(isinstance(data.get('versions'), dict) and bool(data['versions']), 'php-input')
    require(data.get('extras') in ('refuse', 'keep', 'purge') and data.get('fpm') in ('none', 'shared', 'all', 'disabled'), 'php-input')
    require(type(data.get('pcov')) is bool and type(data.get('opentelemetry')) is bool, 'php-input')
    require(not data.get('mirror') or https(data['mirror']), 'php-input')
    repack = data.get('repack')
    require(isinstance(repack, dict) and set(repack) == {'package', 'version'} and (repack['package'] == 'dpkg-repack') and valid_version(repack['version']), 'php-input')
    shared = {}
    for version, build in data['versions'].items():
        require(re.fullmatch('[0-9]+\\.[0-9]+', version) and isinstance(build, dict) and (set(build) == {'packages', 'extensions'}), 'php-input')
        require(isinstance(build['extensions'], list) and build['extensions'] and all((isinstance(name, str) and re.fullmatch('[a-z0-9_ ]+', name) for name in build['extensions'])), 'php-input')
        require(isinstance(build['packages'], list) and build['packages'], 'php-input')
        identities = set()
        for pin in build['packages']:
            require(isinstance(pin, dict) and set(pin) == {'name', 'version', 'architecture', 'url', 'sha256'} and valid_package(pin['name']) and valid_version(pin['version']) and (pin['architecture'] == 'amd64') and https(pin['url']) and valid_digest(pin['sha256']), 'php-input')
            require(pin['name'] not in identities, 'php-input')
            identities.add(pin['name'])
            if pin['name'] in shared:
                require(shared[pin['name']] == pin, 'php-input')
            shared[pin['name']] = pin
    release = data.get('release')
    if release is not None:
        require(isinstance(release, dict) and set(release) == {'id', 'codenames'} and valid_package(release['id']) and isinstance(release['codenames'], list) and release['codenames'] and all((valid_package(value) for value in release['codenames'])), 'php-input')
    return shared

def deb_fields(host, path):
    output = host.run(['dpkg-deb', '--field', str(path), 'Package', 'Version', 'Architecture'])['stdout']
    fields = {}
    for line in output.splitlines():
        require(': ' in line, 'deb-metadata')
        key, value = line.split(': ', 1)
        require(key not in fields, 'deb-metadata')
        fields[key] = value
    return fields

def deb_matches(host, path, name, version, architecture='amd64'):
    fields = deb_fields(host, path)
    return fields.get('Package') == name and fields.get('Version') == version and (fields.get('Architecture') in (architecture, 'all'))

def php_sources(host):
    found = []
    for path in source_paths(host):
        if path == host.path('/etc/apt/sources.list.d/orbit-php.sources') or not path.is_file():
            continue
        if re.search('ppa\\.launchpadcontent\\.net/ondrej/php|packages\\.sury\\.org/php|ondrej/php', path.read_text(), re.I):
            require(not path.is_symlink(), 'php-source')
            found.append(path)
    return found

def php_links(host, version):
    result = {}
    for sapi in ('cli', 'fpm'):
        directory = host.path('/etc/php/' + version + '/' + sapi + '/conf.d')
        result[sapi] = {path.name: os.readlink(path) for path in directory.glob('*.ini') if path.is_symlink()}
    return result

def restore_php_links(host, version, wanted):
    current = php_links(host, version)
    for sapi, links in wanted.items():
        directory = host.path('/etc/php/' + version + '/' + sapi + '/conf.d')
        for name, target in current[sapi].items():
            if links.get(name) != target:
                (directory / name).unlink()
        for name, target in links.items():
            if current[sapi].get(name) == target:
                continue
            path = directory / name
            require(not path.exists() and (not path.is_symlink()), 'php-link-restore')
            path.symlink_to(target)

def php_unowned(host, version, extensions):
    loading = {}
    all_paths = set()
    for ini in host.path('/etc/php/' + version + '/mods-available').glob('*.ini'):
        libraries = []
        for match in re.finditer('^\\s*(?:zend_)?extension\\s*=\\s*"?([^"\\s;]+)', ini.read_text(), re.M):
            library = Path(match[1])
            stem = library.stem.lower()
            module = 'zend opcache' if stem == 'opcache' else stem
            candidates = [host.path(str(library))] if library.is_absolute() else list(host.path('/usr/lib/php').glob('*/' + stem + '.so'))
            present = [path for path in candidates if path.is_file()]
            all_paths.update(present)
            libraries.append((module, present))
        if libraries:
            all_paths.add(ini)
            loading[ini] = libraries
    if not all_paths:
        return []
    reply = host.run(['dpkg', '-S', '--', *map(str, sorted(all_paths))], required=False)
    require(reply['code'] in (0, 1), 'php-ownership')
    owned = {Path(line.split(': ', 1)[1]) for line in reply['stdout'].splitlines() if ': ' in line} & all_paths
    found = []
    for ini, libraries in loading.items():
        if ini in owned:
            continue
        modules = set()
        files = [ini]
        for module, present in libraries:
            unowned = [path for path in present if path not in owned]
            if module not in extensions or unowned:
                modules.add(module)
            files.extend(unowned)
        if modules:
            found.append({'module': '+'.join(sorted(modules)), 'files': files})
    return found

def prepare_php(host, data):
    try:
        pins = validate_php(data)
    except RecipeRefusal as error:
        php_fail(error.stage, 'input_invalid')
    if data.get('release') is not None:
        release = read_release(host)
        if release.get('ID') != data['release']['id'] or release.get('VERSION_CODENAME') not in data['release']['codenames']:
            php_fail('platform', 'platform_unsupported')
    installed = packages(host)
    sources = php_sources(host)
    if sources and data['extras'] != 'purge':
        php_fail('source', 'conflicting_source')
    extras = []
    unowned = {}
    for version, build in data['versions'].items():
        selected = {pin['name'] for pin in build['packages']}
        extras.extend((name for name in installed if name.startswith('php' + version + '-') and name not in selected and (name != 'php' + version + '-opentelemetry')))
        unowned[version] = php_unowned(host, version, set(build['extensions']))
    extras = sorted(set(extras))
    if extras and data['extras'] == 'refuse':
        php_fail('extra', 'extra_packages')
    names = sorted(set(('php' + version + ':' + entry['module'] for version, found in unowned.items() for entry in found)))
    if names and data['extras'] == 'refuse':
        php_fail('unowned', 'unowned_extensions')
    pending = sorted((name for name, pin in pins.items() if installed.get(name) != pin['version']))
    repair = []
    for name in sorted(set(pins) - set(pending)):
        result = host.run(['dpkg', '--verify', '--', name], required=False)
        require(result['code'] in (0, 1), 'php-integrity')
        for line in result['stdout'].splitlines():
            fields = line.split()
            if len(fields) < 2 or (len(fields) == 3 and fields[1] == 'c'):
                continue
            if fields[0] == 'missing' or (len(fields[0]) > 2 and fields[0][2] == '5'):
                repair.append(name)
                break
    return {'pins': pins, 'installed': installed, 'sources': sources, 'unowned': unowned, 'plan': {'pending': pending, 'repair': repair, 'extras': extras, 'unowned': names}}

def keep_php_package(host, source, name):
    digest = digest_file(source)
    target = host.path('/var/cache/orbit/php-packages') / (name + '_' + digest + '.deb')
    if target.exists() or target.is_symlink():
        meta = target.lstat()
        require(stat.S_ISREG(meta.st_mode) and meta.st_uid == host.uid, 'php-cache')
        if digest_file(target) == digest:
            return target
    host.publish('/' + str(target.relative_to(host.root)), source.read_bytes())
    require(digest_file(target) == digest, 'php-cache')
    return target

def download_php_package(host, pin, mirror, work):
    cached = host.path('/var/cache/orbit/php-packages') / (pin['name'] + '_' + pin['sha256'] + '.deb')
    if cached.is_file() and (not cached.is_symlink()) and (digest_file(cached) == pin['sha256']):
        require(deb_matches(host, cached, pin['name'], pin['version']), 'php-download')
        return cached
    artifact = {'url': pin['url'], 'sha256': pin['sha256']}
    data = {'artifact_mirror': mirror}
    target = work / (pin['name'] + '.deb')
    try:
        download_artifact(host, pin['name'], {'artifacts': {'amd64': artifact}}, data, target)
        require(deb_matches(host, target, pin['name'], pin['version']), 'php-download')
        return keep_php_package(host, target, pin['name'])
    except RecipeRefusal as error:
        php_fail(error.stage, 'download_failed')

def cached_matches_installed(host, path, name, work):
    info = host.path('/var/lib/dpkg/info')
    manifests = sorted(info.glob(name + ':*.md5sums'))
    preferred = info / (name + '.md5sums')
    if preferred.is_file():
        manifests.insert(0, preferred)
    if not manifests:
        return False
    target = work / ('extract-' + os.urandom(8).hex())
    target.mkdir()
    if host.run(['dpkg-deb', '--extract', '--', str(path), str(target)], required=False)['code'] != 0:
        return False
    for line in manifests[0].read_text().splitlines():
        if '  ' not in line:
            return False
        expected, relative = line.split('  ', 1)
        relative = relative.strip()
        try:
            member = target / archive_path(relative)
        except RecipeRefusal:
            return False
        if not member.is_file() or digest_file(member, 'md5') != expected.strip():
            return False
    return True

def previous_php_package(host, name, version, installed, repack, work):
    for path in sorted(host.path('/var/cache/orbit/php-packages').glob(name + '_*.deb')):
        require(not path.is_symlink(), 'php-cache')
        if path.stem == name + '_' + digest_file(path) and deb_matches(host, path, name, version):
            return path
    for path in sorted(host.path('/var/cache/apt/archives').glob(name + '_*.deb')):
        if not path.is_symlink() and deb_matches(host, path, name, version) and cached_matches_installed(host, path, name, work):
            return keep_php_package(host, path, name)
    if 'dpkg-repack' not in installed:
        if host.run(['apt-get', '-o', 'DPkg::Lock::Timeout=300', 'install', '--yes', '--no-install-recommends', '--no-remove', 'dpkg-repack=' + repack['version']], required=False, environment={'DEBIAN_FRONTEND': 'noninteractive'})['code'] != 0:
            php_fail('rollback', 'rollback_unavailable')
        installed['dpkg-repack'] = repack['version']
    directory = work / ('repack-' + os.urandom(8).hex())
    directory.mkdir()
    host.run(['env', '-C', str(directory), 'dpkg-repack', '--generate', '--', name], required=False)
    trees = [path for path in directory.iterdir() if path.is_dir() and (not path.is_symlink()) and path.name.startswith('dpkg-repack.' + name + '.')]
    if len(trees) != 1:
        php_fail('rollback', 'rollback_unavailable')
    tree = trees[0]
    conffiles = tree / 'DEBIAN/conffiles'
    if conffiles.is_file():
        before = conffiles.read_text().splitlines()
        kept = []
        for line in before:
            if not line.strip():
                continue
            relative = line.strip().lstrip('/')
            archive_path(relative)
            if (tree / relative).exists():
                kept.append(line)
        if kept:
            host.publish('/' + str(conffiles.relative_to(host.root)), ('\n'.join(kept) + '\n').encode()) if conffiles.is_relative_to(host.root) else conffiles.write_text('\n'.join(kept) + '\n')
        else:
            conffiles.unlink()
    output = directory / (name + '_repacked.deb')
    host.run(['dpkg-deb', '--build', '--', str(tree), str(output)], required=False)
    if not output.is_file() or not deb_matches(host, output, name, version):
        php_fail('rollback', 'rollback_unavailable')
    return keep_php_package(host, output, name)

@contextlib.contextmanager
def restart_policy(host, allow_existing=False):
    path = host.path('/usr/sbin/policy-rc.d')
    owned = not path.exists() and (not path.is_symlink())
    if not owned and (not allow_existing):
        raise RecipeRefusal('restart-policy')
    if owned:
        host.publish('/usr/sbin/policy-rc.d', '#!/bin/sh\nexit 101\n', 493)
    try:
        yield
    finally:
        if owned:
            path.unlink()

def php_install_packages(host, paths, reinstall=False):
    if not paths:
        return True
    with restart_policy(host, True):
        args = ['apt-get', '-o', 'DPkg::Lock::Timeout=300', '-o', 'Dpkg::Options::=--force-confold', 'install', '--yes', '--no-install-recommends', '--no-remove', '--allow-downgrades']
        if reinstall:
            args.append('--reinstall')
        args += ['--', *map(str, paths)]
        return host.run(args, required=False, environment={'DEBIAN_FRONTEND': 'noninteractive', 'NEEDRESTART_SUSPEND': '1'})['code'] == 0

def fpm_sockets(host, configuration):
    pending = [configuration]
    seen = set()
    found = set()
    while pending:
        path = pending.pop()
        if path in seen or not path.is_file():
            continue
        seen.add(path)
        for line in path.read_text().splitlines():
            if '=' not in line:
                continue
            key, value = line.split('=', 1)
            key = key.strip()
            value = value.split(';', 1)[0].strip()
            if key == 'include':
                pattern = str(host.path(value)) if value.startswith('/') else str(path.parent / value)
                pending.extend((Path(value) for value in __import__('glob').glob(pattern)))
            elif key == 'listen' and value.startswith('/'):
                found.add(host.path(value))
    return found

def fpm_ping(path):

    def record(kind, content):
        return struct.pack('!BBHHBB', 1, kind, 1, len(content), 0, 0) + content
    parameters = b''
    for key, value in ((b'SCRIPT_FILENAME', b'/orbit-health-check.php'), (b'REQUEST_METHOD', b'GET')):
        parameters += bytes([len(key), len(value)]) + key + value
    payload = record(1, b'\x00\x01\x00\x00\x00\x00\x00\x00') + record(4, parameters) + record(4, b'') + record(5, b'')

    def receive(connection, length):
        result = b''
        while len(result) < length:
            block = connection.recv(length - len(result))
            if not block:
                raise RecipeRefusal('php-fastcgi')
            result += block
        return result
    deadline = time.monotonic() + 5
    total = 0
    with socket.socket(socket.AF_UNIX, socket.SOCK_STREAM) as connection:
        connection.settimeout(5)
        connection.connect(str(path))
        connection.sendall(payload)
        for record_number in range(131072):
            connection.settimeout(max(0.001, deadline - time.monotonic()))
            header = receive(connection, 8)
            version, kind, request, length, padding, _ = struct.unpack('!BBHHBB', header)
            require(version == 1 and request == 1, 'php-fastcgi')
            total += 8 + length + padding
            require(total <= 1048576, 'php-fastcgi')
            receive(connection, length + padding)
            if kind == 3:
                require(length == 8, 'php-fastcgi')
                return
        raise RecipeRefusal('php-fastcgi')

def refresh_php_fpm(host, version, unit, configuration, opentelemetry, action, touched):
    if configuration is None or not configuration.is_file():
        return 'fpm_invalid'
    binary = host.path('/usr/sbin/php-fpm' + version)
    args = [str(binary), '-t'] + (['--fpm-config', str(configuration)] if not unit.startswith('php') else [])
    if host.run(args, required=False)['code'] != 0:
        return 'fpm_invalid'
    touched.append(unit)
    if host.run(['systemctl', action, unit], required=False)['code'] != 0 or host.run(['systemctl', 'is-active', '--quiet', unit], required=False)['code'] != 0:
        return 'fpm_inactive'
    loaded = php_modules(host, binary)
    required = {'bcmath', 'curl', 'gd', 'imagick', 'intl', 'mbstring', 'mysqli', 'pdo_mysql', 'pdo_pgsql', 'pdo_sqlite', 'redis', 'simplexml', 'xml', 'zip'}
    if 'pcov' in loaded or not required <= loaded or ('opentelemetry' in loaded) != opentelemetry:
        return 'fpm_modules'
    for path in sorted(fpm_sockets(host, configuration)):
        fpm_ping(path)
    return None

def install_php_build(host, data):
    prepared = prepare_php(host, data)
    pins = prepared['pins']
    old = dict(prepared['installed'])
    plan = prepared['plan']
    selected = plan['pending'] + plan['repair']
    purge = plan['extras'] if data['extras'] == 'purge' else []
    replaced = sorted({name for name in selected + purge if name in old})
    added = [name for name in plan['pending'] if name not in old]
    links = {version: php_links(host, version) for version in data['versions']}
    disabled_pools = [host.path('/etc/php/' + version + '/fpm/pool.d/www.conf') for version in data['versions'] if 'php' + version + '-fpm' in old and (not host.path('/etc/php/' + version + '/fpm/pool.d/www.conf').exists())]
    with tempfile.TemporaryDirectory(prefix='orbit-php-build-') as temporary:
        work = Path(temporary)
        downloads = [download_php_package(host, pins[name], data.get('mirror', ''), work) for name in selected]
        previous = {name: previous_php_package(host, name, old[name], prepared['installed'], data['repack'], work) for name in replaced}
    moved = []
    ini = {}
    restarted = []
    reloaded = []
    deferred = []
    stale = []
    fpm_disabled = False

    def keep_disabled():
        for path in disabled_pools:
            if path.exists() or path.is_symlink():
                path.unlink()
    try:
        for path in [host.path('/etc/apt/sources.list.d/orbit-php.sources'), host.path('/usr/share/keyrings/orbit-sury-php.gpg'), *prepared['sources']]:
            if path.exists() or path.is_symlink():
                path.unlink()
        if data['extras'] == 'purge':
            backup = host.path('/var/cache/orbit/php-unowned-extensions') / os.urandom(8).hex()
            for version, found in prepared['unowned'].items():
                for entry in found:
                    for sapi, entries in php_links(host, version).items():
                        for name, target in entries.items():
                            if Path(target).name == entry['files'][0].name:
                                host.path('/etc/php/' + version + '/' + sapi + '/conf.d/' + name).unlink()
                    for path in entry['files']:
                        if any((before == path for before, _ in moved)):
                            continue
                        target = backup / version / path.relative_to(host.root)
                        target.parent.mkdir(parents=True, exist_ok=True)
                        os.rename(path, target)
                        moved.append((path, target))
        if purge and host.run(['dpkg', '--purge', '--', *purge], required=False)['code'] != 0:
            php_fail('install', 'install_failed')
        installed = php_install_packages(host, downloads, bool(plan['repair']))
        keep_disabled()
        if not installed:
            php_fail('install', 'install_failed')
        current = packages(host)
        if any((current.get(name) != pin['version'] for name, pin in pins.items())):
            php_fail('inventory', 'install_failed')
        for version, build in data['versions'].items():
            for tool, sapi, module in [('phpenmod' if data['pcov'] else 'phpdismod', 'cli', 'pcov'), ('phpdismod', 'fpm', 'pcov'), ('phpdismod', 'cli', 'opentelemetry'), ('phpenmod' if data['opentelemetry'] else 'phpdismod', 'fpm', 'opentelemetry')]:
                host.run([tool, '-v', version, '-s', sapi, module])
            for sapi in ('cli', 'fpm'):
                path = host.path('/etc/php/' + version + '/' + sapi + '/conf.d/99-cve-2026-109618-upload-progress.ini')
                before = path.read_bytes() if path.is_file() else None
                content = b'session.upload_progress.enabled = 0\n'
                if before != content:
                    ini[path] = before
                    host.publish('/' + str(path.relative_to(host.root)), content)
            expected = set(build['extensions']) - ({'pcov'} if not data['pcov'] else set())
            loaded = php_modules(host, host.path('/usr/bin/php' + version))
            if not expected <= loaded or (data['extras'] != 'keep' and expected != loaded):
                php_fail('modules', 'modules_mismatch')
        package_changed = bool(selected or purge or moved) or any((php_links(host, version) != links[version] for version in data['versions']))
        action = 'restart' if package_changed else 'reload'
        changed = package_changed or bool(ini)
        if data['fpm'] == 'disabled':
            for version in data['versions']:
                unit = 'php' + version + '-fpm.service'
                enabled = host.run(['systemctl', 'is-enabled', unit], required=False)['stdout'].strip()
                active = host.run(['systemctl', 'is-active', '--quiet', unit], required=False)['code'] == 0
                if enabled != 'masked' and active and (host.run(['systemctl', 'stop', unit], required=False)['code'] != 0):
                    php_fail('fpm', 'fpm_invalid')
                if enabled != 'masked' and host.run(['systemctl', 'disable', '--now', unit], required=False)['code'] != 0:
                    php_fail('fpm', 'fpm_invalid')
                require(host.run(['systemctl', 'is-enabled', unit], required=False)['stdout'].strip() in ('disabled', 'masked') and host.run(['systemctl', 'is-active', '--quiet', unit], required=False)['code'] != 0, 'php-fpm-disable')
                fpm_disabled = fpm_disabled or active or enabled not in ('disabled', 'masked')
        scope = 'all' if action == 'reload' else data['fpm']
        if changed and data['fpm'] != 'disabled' and (scope != 'none'):
            for version in data['versions']:
                units, skipped = fpm_units(host, version, scope)
                stale.extend(skipped)
                for unit, configuration in units:
                    reason = refresh_php_fpm(host, version, unit, configuration, data['opentelemetry'], action, restarted if action == 'restart' else reloaded)
                    if reason is None:
                        continue
                    if reason == 'fpm_invalid' and (data['fpm'] == 'shared' or (action == 'reload' and data['fpm'] == 'none')):
                        deferred.append(unit)
                        continue
                    php_fail(reason, 'fpm_invalid' if reason == 'fpm_invalid' else 'rolled_back')
        return {'outcome': 'applied' if changed or fpm_disabled else 'current', 'data': {'changed': plan['pending'], 'repaired': plan['repair'], 'purged': purge, 'disabled': plan['unowned'] if moved else [], 'restarted': restarted, 'reloaded': reloaded, 'deferred': deferred, 'stale_masters': sorted(set(stale)), 'fpm_disabled': fpm_disabled}}
    except (RecipeRefusal, RecipeUncertain, OSError, ValueError) as error:
        restored = True
        try:
            present = [name for name in added if name in packages(host)]
            if present:
                restored = host.run(['dpkg', '--remove', '--', *present], required=False)['code'] == 0 and restored
            if previous:
                restored = php_install_packages(host, list(previous.values()), bool(plan['repair'])) and restored
                keep_disabled()
        except Exception:
            restored = False
        try:
            current = packages(host)
            restored = all((current.get(name) == old.get(name) for name in replaced)) and all((name not in current for name in added)) and restored
        except Exception:
            restored = False
        for before, target in reversed(moved):
            try:
                os.rename(target, before)
            except Exception:
                restored = False
        for version, wanted in links.items():
            try:
                restore_php_links(host, version, wanted)
            except Exception:
                restored = False
        for path, before in ini.items():
            try:
                restore_file(host, path, before)
            except Exception:
                restored = False
        for action, units in [('restart', restarted), ('reload', reloaded)]:
            for unit in units:
                try:
                    restored = host.run(['systemctl', action, unit], required=False)['code'] == 0 and host.run(['systemctl', 'is-active', '--quiet', unit], required=False)['code'] == 0 and restored
                except Exception:
                    restored = False
        failure = RecipeRefusal(getattr(error, 'stage', 'php-build'), getattr(error, 'code', 'php.build_execution_failed') if restored else 'php.build_rollback_failed')
        failure.restored = restored
        raise failure from error
APT_ENV = {'DEBIAN_FRONTEND': 'noninteractive', 'NEEDRESTART_SUSPEND': '1'}

def snapshot_source(snapshot):
    require(re.fullmatch('[0-9]{8}T[0-9]{6}Z', snapshot), 'snapshot')
    return 'Types: deb\nURIs: https://snapshot.ubuntu.com/ubuntu/' + snapshot + '/\nSuites: resolute resolute-updates resolute-security\nComponents: main universe restricted multiverse\nSigned-By: ' + UBUNTU_KEYRING + '\nCheck-Valid-Until: no\n'

def rolling_source():
    return 'Types: deb\nURIs: http://archive.ubuntu.com/ubuntu\nSuites: resolute resolute-updates resolute-backports\nComponents: main universe restricted multiverse\nSigned-By: ' + UBUNTU_KEYRING + '\n\nTypes: deb\nURIs: http://security.ubuntu.com/ubuntu\nSuites: resolute-security\nComponents: main universe restricted multiverse\nSigned-By: ' + UBUNTU_KEYRING + '\n'

def apt_source_options(path):
    result = []
    for option in ['Acquire::Retries=0', 'Acquire::http::Timeout=15', 'Acquire::https::Timeout=15', 'Dir::Etc::sourcelist=' + str(path), 'Dir::Etc::sourceparts=-', 'APT::Get::List-Cleanup=false', 'APT::Update::Error-Mode=any', 'Acquire::Languages=none', 'Acquire::IndexTargets::deb::DEP-11::DefaultEnabled=false', 'Acquire::IndexTargets::deb::CNF::DefaultEnabled=false']:
        result += ['-o', option]
    return result

def signed_sources(host, source=None):
    trusted = set()
    for path in [source] if source else source_paths(host):
        if not path.is_file():
            continue
        content = path.read_text()
        if path.suffix == '.sources':
            for paragraph in re.split('\\n\\s*\\n', content):
                fields = deb822(paragraph)
                if fields.get('signed-by') != UBUNTU_KEYRING or 'deb' not in fields.get('types', '').split() or fields.get('enabled', '').lower() == 'no':
                    continue
                for uri in fields.get('uris', '').split():
                    for suite in fields.get('suites', '').split():
                        for component in fields.get('components', '').split():
                            trusted.add((uri.rstrip('/'), suite + '/' + component))
        else:
            for line in content.splitlines():
                match = re.fullmatch('\\s*deb\\s+\\[([^]]+)\\]\\s+(\\S+)\\s+(\\S+)\\s+([^#]+)(?:#.*)?', line)
                if match and 'signed-by=' + UBUNTU_KEYRING in match[1].split():
                    trusted.update(((match[2].rstrip('/'), match[3] + '/' + component) for component in match[4].split()))
    return trusted

def parse_ubuntu_origin(global_text, package_text, version, signed):
    trusted = set()
    index = None
    for line in global_text.splitlines():
        match = re.fullmatch('\\s*-?\\d+\\s+(.+ Packages)\\s*', line)
        if match:
            index = ' '.join(match[1].split())
        elif line.strip().startswith('release '):
            fields = dict((value.split('=', 1) for value in line.strip()[8:].split(',') if '=' in value))
            if index:
                entries = index.split()
                require(len(entries) == 4, 'apt-origin')
                parsed = urllib.parse.urlsplit(entries[0])
                host_name = parsed.hostname or ''
                source_ok = parsed.scheme in ('http', 'https') and (host_name in ('archive.ubuntu.com', 'security.ubuntu.com', 'snapshot.ubuntu.com') or host_name.endswith('.archive.ubuntu.com') or (entries[0].rstrip('/'), entries[1]) in signed)
                if source_ok and fields.get('o') == 'Ubuntu' and (fields.get('n') == 'resolute') and (fields.get('a') in ('resolute', 'resolute-updates', 'resolute-security')):
                    trusted.add(index)
            index = None
    result = {'trusted': False, 'untrusted': False, 'installed': False}
    selected = False
    for line in package_text.splitlines():
        match = re.fullmatch('\\s*(?:\\*\\*\\*\\s+)?(\\S+)\\s+-?\\d+(?:\\s+\\([^)]*\\))?\\s*', line)
        if match:
            selected = match[1] == version
            continue
        match = re.fullmatch('\\s*-?\\d+\\s+(.+?)\\s*', line)
        if match and selected:
            origin = ' '.join(match[1].split())
            result['installed' if origin == '/var/lib/dpkg/status' else 'trusted' if origin in trusted else 'untrusted'] = True
        elif not match:
            selected = False
    return result

def policy_origin(host, name, version, options=None, source=None, environment=None):
    options = options or []
    global_text = host.run(['apt-cache', 'policy', *options], environment=environment)['stdout']
    package_text = host.run(['apt-cache', 'policy', name + ':amd64', *options], environment=environment)['stdout']
    return parse_ubuntu_origin(global_text, package_text, version, signed_sources(host, source))

def install_snapshot(host, name, version):
    actions = []
    statuses = []
    for path in sorted(host.path('/var/log').glob('dpkg.log*')):
        if not path.is_file():
            continue
        with gzip.open(path, 'rt') if path.suffix == '.gz' else path.open() as stream:
            for line in stream:
                fields = line.split()
                if len(fields) != 6 or fields[5] != version:
                    continue
                action = fields[2] in ('install', 'upgrade')
                package = fields[3] if action else fields[4]
                if not action and fields[2:4] != ['status', 'installed'] or package not in (name, name + ':amd64', name + ':all'):
                    continue
                try:
                    timestamp = datetime.datetime.strptime(fields[0] + ' ' + fields[1], '%Y-%m-%d %H:%M:%S').astimezone(datetime.timezone.utc).strftime('%Y%m%dT%H%M%SZ')
                except ValueError:
                    raise RecipeRefusal('dpkg-log')
                (actions if action else statuses).append(timestamp)
    return min(actions or statuses) if actions or statuses else None

class SnapshotOrigins:

    def __init__(self, host, work):
        self.host, self.work, self.lookups = (host, work, {})

    def origin(self, name, version, snapshot):
        if snapshot not in self.lookups:
            require(len(self.lookups) < 3, 'snapshot-lookup-limit')
            directory = self.work / ('snapshot-' + snapshot)
            directory.mkdir()
            source = directory / 'ubuntu.sources'
            source.write_text(snapshot_source(snapshot))
            config = directory / 'apt.conf'
            config.write_text('Dir::Etc::parts "-";\nDir::Etc::main "-";\n')
            options = ['-c', str(config), *apt_source_options(source)]
            for leaf in ('lists', 'cache', 'log'):
                (directory / leaf).mkdir()
            for setting in ('Dir::State::lists=' + str(directory / 'lists'), 'Dir::Cache=' + str(directory / 'cache'), 'Dir::Log=' + str(directory / 'log'), 'Debug::NoLocking=true', 'APT::Sandbox::User='):
                options += ['-o', setting]
            environment = {'APT_CONFIG': str(config)}
            try:
                self.host.run(['apt-get', 'update', *options], timeout=120, environment=environment)
                self.lookups[snapshot] = (source, options, environment)
            except (RecipeRefusal, RecipeUncertain) as error:
                self.lookups[snapshot] = error
        found = self.lookups[snapshot]
        if isinstance(found, Exception):
            raise found
        source, options, environment = found
        return policy_origin(self.host, name, version, options, source, environment)

def ubuntu_origin(host, name, version, snapshots, options=None, source=None):
    origins = [policy_origin(host, name, version)]
    if options:
        origins.append(policy_origin(host, name, version, options, source))
    if any((origin['untrusted'] for origin in origins)):
        return False
    if any((origin['trusted'] for origin in origins)):
        return True
    if not any((origin['installed'] for origin in origins)):
        return False
    snapshot = install_snapshot(host, name, version)
    require(snapshot is not None, 'dpkg-log-missing')
    origin = snapshots.origin(name, version, snapshot)
    require(origin['trusted'] and (not origin['untrusted']), 'snapshot-unproved')
    return True

def recipe_groups(data):
    return {'git': {name: data['tools'][name] for name in data.get('git_packages', []) if name in data['tools']}, 'incus': incus_tools(data)}

def artifact_owned(host, name, version, tools, owner):
    path = host.path('/etc/apt/orbit-' + owner + '.json')
    if not path.is_file() or path.is_symlink():
        return False
    meta = path.stat()
    if meta.st_uid != host.uid or meta.st_mode & 18:
        return False
    try:
        receipt = json.loads(path.read_text())
    except (ValueError, OSError):
        return False
    return isinstance(receipt, dict) and set(receipt) == set(tools) and (receipt.get(name) == version) and all((valid_version(value) and value == version for value in receipt.values()))

def compare_versions(host, first, relation, second):
    code = host.run(['dpkg', '--compare-versions', first, relation, second], required=False)['code']
    require(code in (0, 1), 'version-comparison')
    return code == 0

def assess_recipe(host, data, versions, snapshots, options=None, source=None):
    report = {}
    groups = recipe_groups(data)
    for name, pin in data['tools'].items():
        installed = versions[name]
        status = 'current' if installed == pin['version'] else 'missing' if installed is None else 'different'
        if installed is not None and installed != pin['version']:
            if name in groups['git'] and pin['source'] == 'artifact' and (not artifact_owned(host, name, installed, groups['git'], 'git')):
                if not ubuntu_origin(host, name, installed, snapshots, options, source):
                    status = 'unsupported_newer'
            if name in groups['incus'] and pin['source'] == 'artifact':
                owned = artifact_owned(host, name, installed, groups['incus'], 'incus') or ubuntu_origin(host, name, installed, snapshots, options, source)
                if not owned or compare_versions(host, installed, 'gt', pin['version']):
                    status = 'unsupported_newer'
            if pin['source'] == 'ubuntu':
                if compare_versions(host, installed, 'gt', pin['version']):
                    status = 'newer_than_recipe' if ubuntu_origin(host, pin['package'], installed, snapshots, options, source) else 'unsupported_newer'
                else:
                    status = 'older_than_recipe'
        report[name] = {'source': pin['source'], 'version': pin['version'], 'installed': installed, 'changed': status not in ('current', 'newer_than_recipe', 'unsupported_newer'), 'status': status, 'refusal': 'origin:' + name if status == 'unsupported_newer' else None}
    return report

def recipe_supported(report):
    for name, entry in report.items():
        require(entry['status'] != 'unsupported_newer', 'origin:' + name)

def is_deb(pin):
    return pin['source'] == 'artifact' and any((artifact.get('url', '').endswith('.deb') for artifact in pin['artifacts'].values()))

def recipe_versions(host, data, account):
    installed = packages(host)
    result = {}
    js = {name: pin for name, pin in data['tools'].items() if name in JS_NAMES}
    if js:
        result.update(javascript_versions(host, {'tools': js}, account, runtime_scope(host, account)))
    for name, pin in data['tools'].items():
        if pin['source'] == 'ubuntu' or is_deb(pin):
            result[name] = installed.get(pin['package'] if pin['source'] == 'ubuntu' else name)
        elif name == 'chrome-headless-shell':
            result[name] = browser_installed(host, pin)
        elif name == 'swamp':
            binary = host.path('/usr/local/bin/swamp')
            result[name] = None
            if binary.is_file() and binary.stat().st_mode & 73:
                try:
                    verify_artifact(binary, pin['artifacts']['amd64'])
                    result[name] = pin['version']
                except RecipeRefusal:
                    pass
        else:
            require(name in JS_NAMES, 'unknown-tool')
    return result

def check_git_siblings(host, data):
    git = recipe_groups(data)['git']
    if not git:
        return
    template = '-f=' + ''.join(('$' + '{' + name + '}' + ending for name, ending in [('Package', '\t'), ('source:Package', '\t'), ('db:Status-Status', '\n')]))
    for line in host.run(['dpkg-query', '-W', template])['stdout'].splitlines():
        fields = line.split('\t', 2)
        require(len(fields) == 3, 'git-inventory')
        require(not (fields[1] == 'git' and fields[2] == 'installed' and (fields[0] not in git)), 'git-dependency')

def exact_deb_install(host, debs, retained, installed, options):
    names = {path.stem for path in debs}
    require(len(names) == len(debs), 'artifact-name')
    args = ['apt-get', 'install', '--yes', '--no-install-recommends', '--no-remove', '--allow-downgrades', '-o', 'Dpkg::Options::=--force-confold', *options, '--', *map(str, debs), *[name + '=' + version for name, version in sorted(retained.items())]]
    simulation = host.run([*args[:2], '--simulate', *args[2:]], environment=APT_ENV)['stdout']
    for line in simulation.splitlines():
        if not line.startswith('Inst '):
            continue
        match = re.match('Inst (\\S+)(?: \\[([^]]+)\\])? \\((\\S+)', line)
        require(match is not None, 'apt-plan')
        name = match[1].split(':')[0]
        old = match[2] or installed.get(name)
        if old and name not in names:
            require(not compare_versions(host, match[3], 'lt', old), 'dependency-downgrade')
    host.run(args, environment=APT_ENV)

def active_units(host):
    return {line.split()[0] for line in host.run(['systemctl', 'list-units', '--state=active', '--no-legend', '--plain'])['stdout'].splitlines() if line.split()}

def services_for_packages(host, selected, active):
    units = set()
    for package in sorted(selected):
        for line in host.run(['dpkg-query', '-L', package], required=False)['stdout'].splitlines():
            match = re.fullmatch('/(?:usr/)?lib/systemd/system/([A-Za-z0-9@_.:-]+\\.service)', line)
            if not match:
                continue
            unit = match[1]
            if unit.endswith('@.service'):
                units.update((name for name in active if name.startswith(unit[:-9] + '@') and name.endswith('.service')))
            else:
                units.add(unit)
        match = re.match('php([0-9]+\\.[0-9]+)-', package)
        if match:
            units.add('php' + match[1] + '-fpm.service')
    return units & active

def service_state(host, unit):
    fields = {}
    for line in host.run(['systemctl', 'show', '--no-pager', '--property=LoadState,ActiveState,Result,ControlGroup,Job,RequiredBy,BoundBy,ConsistsOf', unit])['stdout'].splitlines():
        if '=' in line:
            key, value = line.split('=', 1)
            fields[key] = value
    return fields

def service_vanished(state):
    return all((state.get(key) == value for key, value in [('LoadState', 'not-found'), ('ActiveState', 'inactive'), ('ControlGroup', ''), ('Job', '')]))

def service_plan(host, data):
    pending = set(data['restart_units'])
    affected = set()
    active = active_units(host)
    while pending:
        unit = min(pending)
        pending.remove(unit)
        if unit in affected:
            continue
        state = service_state(host, unit)
        if service_vanished(state):
            continue
        require(state.get('LoadState') == 'loaded', 'restart:' + unit)
        affected.add(unit)
        for prop in ('RequiredBy', 'BoundBy', 'ConsistsOf'):
            require(prop in state, 'restart:' + unit)
            pending.update(set(state[prop].split()) & active - affected)
    return {'restart_units': sorted(set(data['restart_units']) & affected), 'health_units': sorted(set(data['health_units']) | affected)}

def service_health(host, unit):
    if host.run(['systemctl', 'is-active', '--quiet', unit], required=False)['code'] != 0:
        state = service_state(host, unit)
        require(service_vanished(state) or all((state.get(key) == value for key, value in [('LoadState', 'loaded'), ('ActiveState', 'inactive'), ('Result', 'success')])), 'health:' + unit)
    return {'ready': True}
BROWSER_BASE = '/opt/orbit/chrome-headless-shell'
BROWSER_EXECUTABLE = 'chrome-headless-shell-linux64/chrome-headless-shell'
BROWSER_POLICY = '/etc/apparmor.d/orbit-chrome-headless-shell'

def browser_valid(pin):
    return pin['source'] == 'artifact' and bool(re.fullmatch('[0-9]+\\.[0-9]+\\.[0-9]+\\.[0-9]+', pin['version'])) and (set(pin['artifacts']) == {'amd64'}) and (pin['artifacts']['amd64'].get('version') is None) and valid_digest(pin['artifacts']['amd64'].get('sha256')) and (pin['artifacts']['amd64'].get('url') == 'https://storage.googleapis.com/chrome-for-testing-public/' + pin['version'] + '/linux64/chrome-headless-shell-linux64.zip')

def browser_policy(version):
    return '# Managed by Orbit machine recipe.\nabi <abi/5.0>,\ninclude <tunables/global>\nprofile orbit-chrome-headless-shell ' + BROWSER_BASE + '/' + version + '/' + BROWSER_EXECUTABLE + ' flags=(unconfined) {\n  userns,\n  @{exec_path} mr,\n}\n'

def root_trusted(host, path):
    while path.is_relative_to(host.root):
        if path.exists() or path.is_symlink():
            meta = path.lstat()
            require(not stat.S_ISLNK(meta.st_mode) and meta.st_uid == host.uid and (not meta.st_mode & 18), 'browser-ownership')
        if path == host.root:
            break
        path = path.parent

def browser_tree(host, path):
    root_trusted(host, path)
    if path.is_dir():
        for child in path.iterdir():
            browser_tree(host, child)
    else:
        require(path.is_file(), 'browser-layout')

def browser_runtime(host, pin):
    require(browser_valid(pin), 'browser-pin')
    directory = host.path(BROWSER_BASE + '/' + pin['version'])
    root_trusted(host, directory)
    if not directory.exists():
        return False
    browser_tree(host, directory)
    binary = directory / BROWSER_EXECUTABLE
    require(binary.is_file() and binary.stat().st_mode & 73, 'browser-layout')
    require((directory / '.orbit-recipe').read_text() == pin['artifacts']['amd64']['sha256'] + '\n' + digest_file(binary) + '\n', 'browser-receipt')
    return True

def previous_browser_policy(host):
    path = host.path(BROWSER_POLICY)
    root_trusted(host, path)
    if not path.exists():
        return None
    previous = path.read_text()
    match = re.search('/opt/orbit/chrome-headless-shell/([0-9]+\\.[0-9]+\\.[0-9]+\\.[0-9]+)/', previous)
    require(match is not None and previous == browser_policy(match[1]), 'browser-policy')
    return previous

def browser_installed(host, pin):
    if not browser_runtime(host, pin):
        return None
    return pin['version'] if previous_browser_policy(host) == browser_policy(pin['version']) else None

def install_browser(host, pin, archive):
    require(browser_valid(pin), 'browser-pin')
    previous = previous_browser_policy(host)
    if not browser_runtime(host, pin):
        require(archive is not None, 'browser-archive')
        verify_artifact(archive, pin['artifacts']['amd64'])
        for absolute in ('/opt', '/opt/orbit', BROWSER_BASE):
            path = host.path(absolute)
            root_trusted(host, path)
            owned_directory(path, host.uid, host.gid)
        base = host.path(BROWSER_BASE)
        with tempfile.TemporaryDirectory(prefix='.orbit-browser-', dir=base) as temporary:
            stage = Path(temporary) / 'runtime'
            extract_archive(archive, stage)
            own_tree(stage, host.uid, host.gid)
            browser_tree(host, stage)
            binary = stage / BROWSER_EXECUTABLE
            require(binary.is_file() and binary.stat().st_mode & 73, 'browser-layout')
            host.publish('/' + str((stage / '.orbit-recipe').relative_to(host.root)), pin['artifacts']['amd64']['sha256'] + '\n' + digest_file(binary) + '\n')
            os.rename(stage, base / pin['version'])
    browser_runtime(host, pin)
    text = browser_policy(pin['version'])
    path = host.path(BROWSER_POLICY)
    with tempfile.TemporaryDirectory(prefix='.orbit-browser-policy-', dir=path.parent) as temporary:
        candidate = Path(temporary) / 'policy'
        candidate.write_text(text)
        host.run(['apparmor_parser', '--skip-kernel-load', '--skip-cache', str(candidate)])
    host.publish(BROWSER_POLICY, text)
    try:
        host.run(['apparmor_parser', '--replace', '--skip-cache', str(path)])
    except (RecipeRefusal, RecipeUncertain):
        if previous is not None:
            host.publish(BROWSER_POLICY, previous)
            host.run(['apparmor_parser', '--replace', '--skip-cache', str(path)])
        else:
            host.run(['apparmor_parser', '--remove', '--skip-cache', str(path)], required=False)
            path.unlink()
        raise

def incus_firmware(host, data):
    if not incus_tools(data):
        return
    directory = host.path('/opt/incus/share/qemu')
    create = []
    for name, target in [('OVMF_CODE_4M.ms.fd', 'OVMF_CODE.4MB.fd'), ('OVMF_CODE_4M.secboot.fd', 'OVMF_CODE.4MB.fd'), ('OVMF_CODE_4M.fd', 'OVMF_CODE.4MB.fd'), ('OVMF_VARS_4M.ms.fd', 'OVMF_VARS.4MB.ms.fd'), ('OVMF_VARS_4M.fd', 'OVMF_VARS.4MB.fd'), ('OVMF_VARS_4M.secboot.fd', 'OVMF_VARS.4MB.ms.fd')]:
        path = directory / name
        if path.is_symlink() and os.readlink(path) == target:
            continue
        require(not path.exists() and (not path.is_symlink()) and (directory / target).is_file(), 'incus-firmware')
        create.append((path, target))
    for path, target in create:
        path.symlink_to(target)

def inspect_recipe(host, data):
    platform(host, data)
    account = account_for(host, data['user'])
    check_git_siblings(host, data)
    actions = []
    for owner, tools in recipe_groups(data).items():
        if not tools:
            continue
        if owner == 'incus':
            require(len(tools) == 3, 'incus-incomplete')
        if provider_changes(host, owner):
            actions.append('retire_' + owner + '_source')
        if preference_changed(host, tools, owner):
            actions.append('pin_' + owner)
        if owner == 'incus' and 'incus-agent' in packages(host):
            actions.append('remove_incus_agent')
    with tempfile.TemporaryDirectory(prefix='orbit-recipe-observe-') as temporary:
        report = assess_recipe(host, data, recipe_versions(host, data, account), SnapshotOrigins(host, Path(temporary)))
    if actions:
        report['_actions'] = actions
    return report

def apply_packages(host, data, account=None):
    data = {**data, 'tools': {name: pin for name, pin in data['tools'].items() if name not in JS_NAMES and name != 'chrome-headless-shell'}}
    platform(host, data)
    account = account or account_for(host, data['user'])
    groups = recipe_groups(data)
    incus_preflight(host, data)
    check_git_siblings(host, data)
    provider_plans = {owner: provider_changes(host, owner) for owner, tools in groups.items() if tools}
    for owner, tools in groups.items():
        if not tools:
            continue
        if owner == 'incus':
            require(len(tools) == 3, 'incus-incomplete')
        preference_changed(host, tools, owner)
    require(not host.path('/usr/sbin/policy-rc.d').exists() and (not host.path('/usr/sbin/policy-rc.d').is_symlink()), 'restart-policy')
    ubuntu = host.path('/etc/apt/sources.list.d/ubuntu.sources')
    if ubuntu.is_file() and (not ubuntu.is_symlink()):
        text = ubuntu.read_text()
        match = re.match('Types: deb\\nURIs: https://snapshot\\.ubuntu\\.com/ubuntu/(\\d{8}T\\d{6}Z)/\\n', text)
        if match and text == snapshot_source(match[1]):
            source_backup(host, ubuntu, text.encode(), 'snapshot', False)
            host.publish('/etc/apt/sources.list.d/ubuntu.sources', rolling_source())
    with tempfile.TemporaryDirectory(prefix='orbit-recipe-apply-') as temporary:
        work = Path(temporary)
        source = work / 'ubuntu.sources'
        source.write_text(rolling_source())
        options = apt_source_options(source)
        host.run(['apt-get', 'update', *options])
        source.write_text(rolling_source() + '\n' + snapshot_source(data['snapshot']))
        snapshots = SnapshotOrigins(host, work)
        before = packages(host)
        versions = recipe_versions(host, data, account)
        report = assess_recipe(host, data, versions, snapshots, options, source)
        recipe_supported(report)
        active = active_units(host)
        recipe_packages = {pin['package'] if pin['source'] == 'ubuntu' else name for name, pin in data['tools'].items() if pin['source'] == 'ubuntu' or is_deb(pin)}
        health = services_for_packages(host, recipe_packages, active)
        downloads = {}
        debs = []
        for name, pin in data['tools'].items():
            if name == 'vp' or pin['source'] == 'ubuntu' or versions.get(name) == pin['version']:
                continue
            target = work / (name + ('.deb' if is_deb(pin) else '.archive'))
            download_artifact(host, name, pin, data, target)
            if is_deb(pin):
                require(deb_matches(host, target, name, pin['version']), 'artifact-metadata')
                debs.append(target)
            downloads[name] = target
        for owner, changes in provider_plans.items():
            retire_sources(host, changes, owner)
        ubuntu_packages = [pin['package'] + '=' + pin['version'] for name, pin in data['tools'].items() if pin['source'] == 'ubuntu' and report[name]['changed']]
        if ubuntu_packages or debs:
            source.write_text(snapshot_source(data['snapshot']))
            options = apt_source_options(source)
            host.run(['apt-get', 'update', *options])
        incus_preflight(host, data)
        with restart_policy(host):
            if ubuntu_packages:
                host.run(['apt-get', 'install', '--yes', '--no-install-recommends', '--no-remove', '-o', 'Dpkg::Options::=--force-confold', *options, '--', *ubuntu_packages], environment=APT_ENV)
            if debs:
                installed = packages(host)
                retained = {pin['package']: installed[pin['package']] for pin in data['tools'].values() if pin['source'] == 'ubuntu' and pin['package'] in installed}
                exact_deb_install(host, debs, retained, installed, options)
            if groups['incus'] and 'incus-agent' in packages(host):
                host.run(['dpkg', '--remove', 'incus-agent'])
            incus_firmware(host, data)
            if ubuntu_packages or debs:
                host.run(['apt-get', 'check'], environment=APT_ENV)
                audit = host.run(['dpkg', '--audit'], environment=APT_ENV)
                require(not audit['stdout'].strip() and (not audit['stderr'].strip()), 'package-audit')
        for owner, tools in groups.items():
            if tools:
                host.publish('/etc/apt/preferences.d/orbit-' + owner, preferences(tools))
        after_versions = recipe_versions(host, data, account)
        after_report = assess_recipe(host, data, after_versions, snapshots, options, source)
        recipe_supported(after_report)
        require(all((entry['status'] in ('current', 'newer_than_recipe') for entry in after_report.values())), 'recipe-inventory')
        after = packages(host)
        changed = sorted((name for name in set(before) | set(after) if before.get(name) != after.get(name)))
        restarted = sorted(services_for_packages(host, set(changed), active))
        for owner, tools in groups.items():
            if tools:
                require(all((after_versions[name] == pin['version'] for name, pin in tools.items())), 'recipe-receipt')
                host.publish('/etc/apt/orbit-' + owner + '.json', json.dumps({name: pin['version'] for name, pin in tools.items()}, sort_keys=True))
        return {'changed_packages': changed, 'changed_tools': sorted((name for name in data['tools'] if versions.get(name) != after_versions.get(name))), 'restarted_services': restarted, 'newer_than_recipe': {name: {'installed': entry['installed'], 'version': entry['version']} for name, entry in after_report.items() if entry['status'] == 'newer_than_recipe'}, 'healthy': True, 'restart_units': restarted, 'health_units': sorted(health)}

def verify_recipe_delivery(host, data, account=None):
    require(isinstance(data, dict) and set(data) == {'user', 'identity', 'agent_sha256', 'cli_version'}, 'delivery-input')
    require(isinstance(data['user'], str) and re.fullmatch('[a-z_][a-z0-9_-]*', data['user']) and (data['user'] != 'root'), 'delivery-input')
    require(isinstance(data['identity'], str) and re.fullmatch('sha256:[a-f0-9]{64}', data['identity']) and valid_digest(data['agent_sha256']) and re.fullmatch('0\\.[1-9][0-9]{0,9}\\.0', data['cli_version']), 'delivery-input')
    account = account or account_for(host, data['user'])
    binary = host.path('/usr/local/bin/orbit-agent')
    require(binary.is_file() and digest_file(binary) == data['agent_sha256'], 'delivery-agent')
    cli = host.path('/usr/local/bin/orbit')
    if cli.is_symlink():
        target = os.readlink(cli)
        require(re.fullmatch('orbit-0\\.[1-9][0-9]{0,9}\\.0', target), 'delivery-cli')
        cli = cli.parent / target
    meta = cli.lstat()
    require(stat.S_ISREG(meta.st_mode) and meta.st_uid == host.uid and meta.st_mode & 73, 'delivery-cli')
    with cli.open('rb') as stream:
        require(stream.read(4) == b'\x7fELF', 'delivery-cli')
    reply = user_command(host, account, runtime_scope(host, account), [str(cli), '--version'])
    line = reply['stdout'].splitlines()[0] if reply['stdout'].splitlines() else ''
    require(line == 'Orbit ' + data['cli_version'], 'delivery-cli')
    return {'identity': data['identity'], 'agent_sha256': data['agent_sha256'], 'cli_version': data['cli_version']}

def browser(host, data):
    pin = data['tools'].get('chrome-headless-shell')
    if pin is None:
        return {'changed': False}
    with tempfile.TemporaryDirectory(prefix='orbit-recipe-browser-') as temporary:
        archive = Path(temporary) / 'browser.zip'
        if not browser_runtime(host, pin):
            download_artifact(host, 'chrome-headless-shell', pin, data, archive)
        install_browser(host, pin, archive if archive.exists() else None)
    return {'changed': True}

def javascript(host, data):
    tools = {name: pin for name, pin in data['tools'].items() if name in JS_NAMES}
    return install_javascript(host, {**data, 'tools': tools}) if tools else {'changed': False, 'retired': []}

def inspect_supported(host,data):
    report=inspect_recipe(host,data)
    recipe_supported({k:v for k,v in report.items() if k!='_actions'})
    return report

def verify_recipe(host, data):
    report=inspect_supported(host,data)
    require(all(v['status'] in ('current','newer_than_recipe') for k,v in report.items() if k!='_actions'),'recipe-inventory')
    return {'ready':True,'inventory':{k:v['installed'] for k,v in report.items() if k!='_actions'},'newer_than_recipe':{k:{'installed':v['installed'],'version':v['version']} for k,v in report.items() if k!='_actions' and v['status']=='newer_than_recipe'}}

ACTIONS = {'inspect': inspect_supported, 'packages': apply_packages, 'browser': browser, 'javascript': javascript, 'php-plan': lambda h, d: prepare_php(h, d)['plan'], 'php-build': install_php_build, 'services': service_plan, 'health': service_health, 'verify': verify_recipe, 'delivery': verify_recipe_delivery}
if __name__ == '__main__':
    try:
        result = ACTIONS[sys.argv[1]](RecipeHost(), json.loads(os.environ['ORBIT_RECIPE_INPUT']) if 'ORBIT_RECIPE_INPUT' in os.environ else json.load(sys.stdin))
        if isinstance(result, dict) and 'error_code' in result:
            raise RecipeRefusal(sys.argv[1], result['error_code'])
        print(json.dumps(result, separators=(',', ':')))
    except RecipeRefusal as error:
        print(json.dumps({'error_code': error.code, 'stage': error.stage}))
        sys.exit(1)
