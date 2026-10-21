# Library: recipe apt, package and source helpers shared by convergence and Docker migration.
"""Fixed, synchronous recipe operations. Only bounded, redacted failure diagnostics escape this boundary."""
from contextlib import contextmanager, ExitStack
from datetime import datetime, timezone
import gzip
import fcntl
import hashlib
import json
import os
from pathlib import Path
import pwd
import re
import shutil
import signal
import stat
import subprocess
import sys
import tarfile
import tempfile
import time
import uuid
import zipfile
from urllib.parse import urlsplit

# ORBIT_TASK_UNIT_STATE

POLICY = Path('/usr/sbin/policy-rc.d')
APT_ETC = Path('/etc/apt')
NODE_USR = Path('/usr')
DPKG_LOG = Path('/var/log/dpkg.log')
UBUNTU_KEYRING = '/usr/share/keyrings/ubuntu-archive-keyring.gpg'
MAX_SNAPSHOT_LOOKUPS = 3


class RecipeFailure(Exception):
    def __init__(self, stage='', *, result=None, packages=None, detail=''):
        super().__init__(stage)
        self.result = result
        self.packages = packages or []
        self.detail = detail


class RecipeInterrupted(RecipeFailure):
    pass


def command_output(result):
    """Node convergence keeps manager output private; image builds override this hook for root-only logs."""
    pass


def run(argv, *, required=True, env=None, timeout=600):
    try:
        with subprocess.Popen(argv, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True,
                              start_new_session=True, env=env) as process:
            try:
                stdout, stderr = process.communicate(timeout=timeout)
            except BaseException as error:
                os.killpg(process.pid, signal.SIGTERM)
                try:
                    stdout, stderr = process.communicate(timeout=3)
                except subprocess.TimeoutExpired:
                    os.killpg(process.pid, signal.SIGKILL)
                    stdout, stderr = process.communicate()
                result = subprocess.CompletedProcess(argv, process.returncode, stdout, stderr)
                command_output(result)
                if isinstance(error, RecipeInterrupted):
                    raise
                raise RecipeFailure(result=result) from error
            result = subprocess.CompletedProcess(argv, process.returncode, stdout, stderr)
    except FileNotFoundError:
        result = subprocess.CompletedProcess(argv, 127, '', 'Executable not found: ' + argv[0] + '\n')
    command_output(result)
    if required and result.returncode:
        raise RecipeFailure(result=result)
    return result


def platform_check(architecture):
    values = {}
    for line in Path('/etc/os-release').read_text().splitlines():
        key, sep, value = line.partition('=')
        if sep and key in ('ID', 'VERSION_CODENAME'):
            if not re.fullmatch(r'''(?:[A-Za-z0-9._-]+|"[A-Za-z0-9._-]+"|'[A-Za-z0-9._-]+')''', value):
                raise RecipeFailure('platform:unknown:unknown')
            value = value.strip('"\'')
            if key in values or not re.fullmatch(r'[A-Za-z0-9._-]+', value):
                raise RecipeFailure('platform:unknown:unknown')
            values[key] = value
    if values != {'ID': 'ubuntu', 'VERSION_CODENAME': 'resolute'}:
        raise RecipeFailure('platform:' + values.get('ID', 'unknown') + ':' + values.get('VERSION_CODENAME', 'unknown'))
    if run(['dpkg', '--print-architecture']).stdout.strip() != architecture:
        raise RecipeFailure('architecture')


def packages():
    architecture = run(['dpkg', '--print-architecture']).stdout.strip()
    result = run(['dpkg-query', '-W', '-f=${Package}\t${Architecture}\t${Version}\t${Status}\n'])
    return {name: version for name, arch, version, status in
            (line.split('\t', 3) for line in result.stdout.splitlines())
            if status == 'install ok installed' and arch in (architecture, 'all')}


def ubuntu_origin(package, version, options=None, snapshots=None):
    origins = [policy_origin(package, version, [])]
    if options is not None:
        origins.append(policy_origin(package, version, options))
    # Check every listing before accepting any trusted listing, including cached snapshots.
    if any(untrusted for trusted, untrusted, status in origins):
        return False
    if any(trusted for trusted, untrusted, status in origins):
        return True
    return any(status for trusted, untrusted, status in origins) and snapshot_origin(package, version, snapshots)


def install_snapshot(package, version):
    """Prefer the version's first install/upgrade action; triggers rewrite status records."""
    architecture = run(['dpkg', '--print-architecture']).stdout.strip()
    action = status = None
    try:
        for path in DPKG_LOG.parent.glob(DPKG_LOG.name + '*'):
            opener = gzip.open if path.suffix == '.gz' else open
            with opener(path, 'rt', encoding='utf-8', errors='replace') as stream:
                for line in stream:
                    fields = line.split()
                    if len(fields) != 6 or fields[5] != version:
                        continue
                    is_action = fields[2] in ('install', 'upgrade')
                    name = fields[3] if is_action else fields[4]
                    if (not is_action and fields[2:4] != ['status', 'installed']
                            or name not in (package, package + ':' + architecture, package + ':all')):
                        continue
                    installed = datetime.strptime(' '.join(fields[:2]), '%Y-%m-%d %H:%M:%S').astimezone(timezone.utc)
                    if is_action:
                        action = min(action, installed) if action else installed
                    else:
                        status = min(status, installed) if status else installed
    except (OSError, EOFError, ValueError):
        return None
    installed = action or status
    return installed.strftime('%Y%m%dT%H%M%SZ') if installed else None


def apt_environment(options):
    environment = dict(os.environ, LC_ALL='C')
    if '-c' in options:
        environment['APT_CONFIG'] = options[options.index('-c') + 1]
    return environment


@contextmanager
def temporary_indexes(source):
    """Read authenticated indexes without system hooks, locks, caches, or list writes."""
    with tempfile.TemporaryDirectory(prefix='orbit-recipe-indexes-') as directory:
        work = Path(directory)
        (work / 'ubuntu.sources').write_text(source)
        for name in ('lists', 'cache', 'log'):
            (work / name).mkdir()
        config = work / 'apt.conf'
        # APT_CONFIG is read before the normal apt.conf.d hooks. Apply the same configuration
        # to apt-cache so it can only inspect these temporary, authenticated lists.
        config.write_text('Dir::Etc::parts "-";\nDir::Etc::main "-";\n')
        options = ['-c', str(config), '-o', 'Dir::Etc::sourcelist=' + str(work / 'ubuntu.sources'),
                   '-o', 'Dir::Etc::sourceparts=-', '-o', 'Dir::State::lists=' + str(work / 'lists'),
                   '-o', 'Dir::Cache=' + str(work / 'cache'), '-o', 'Dir::Log=' + str(work / 'log'),
                   '-o', 'Debug::NoLocking=true', '-o', 'APT::Sandbox::User=',
                   '-o', 'Acquire::Languages=none', *apt_fetch_options(),
                   '-o', 'APT::Update::Error-Mode=any']
        apt_fetch(['apt-get', 'update', *options], env=apt_environment(options), timeout=120)
        yield options


class SnapshotLookups(ExitStack):
    """Keep authenticated indexes and failed lookups per timestamp until this run ends."""
    def __init__(self):
        super().__init__()
        self.lookups = {}

    def options(self, snapshot):
        if snapshot not in self.lookups:
            if len(self.lookups) >= MAX_SNAPSHOT_LOOKUPS:
                raise RecipeFailure('snapshot-lookup-limit')
            self.lookups[snapshot] = 'snapshot-unavailable'
            try:
                self.lookups[snapshot] = self.enter_context(temporary_indexes(snapshot_source(snapshot)))
            except RecipeInterrupted:
                raise
            except (RecipeFailure, OSError):
                pass
        result = self.lookups[snapshot]
        if isinstance(result, str):
            raise RecipeFailure(result)
        return result


def snapshot_origin(package, version, snapshots=None):
    if snapshots is None:
        with SnapshotLookups() as snapshots:
            return snapshot_origin(package, version, snapshots)
    snapshot = install_snapshot(package, version)
    if snapshot is None:
        raise RecipeFailure('dpkg-log-missing')
    try:
        options = snapshots.options(snapshot)
        trusted, untrusted, status = policy_origin(package, version, options)
    except RecipeInterrupted:
        raise
    except RecipeFailure as error:
        raise RecipeFailure(str(error) or 'snapshot-unavailable') from error
    except OSError as error:
        raise RecipeFailure('snapshot-unavailable') from error
    if not trusted or untrusted:
        raise RecipeFailure('snapshot-unproved')
    return True


def deb822_fields(paragraph, source='Deb822 source'):
    fields = {}
    key = None
    for number, line in enumerate(paragraph.splitlines(), 1):
        if not line.strip() or line.startswith('#'):
            continue
        if line[:1].isspace():
            if key is None:
                raise RecipeFailure('apt-source', detail=f'{source}: line {number} continues a field before any field was declared.')
            fields[key] += ' ' + line.strip()
            continue
        key, separator, value = line.partition(':')
        key = key.lower()
        if not separator or key in fields or not re.fullmatch(r'[a-z][a-z0-9-]*', key):
            reason = 'missing field separator' if not separator else (f'duplicate field [{key}]' if key in fields else 'invalid field name')
            raise RecipeFailure('apt-source', detail=f'{source}: line {number}: {reason}.')
        fields[key] = value.strip()
    return fields


def ubuntu_signed_sources(options):
    """Match the source URI, suite and component to an explicitly scoped Ubuntu keyring."""
    source = next((option.split('=', 1)[1] for option in options if option.startswith('Dir::Etc::sourcelist=')), None)
    paths = [Path(source)] if source else [APT_ETC / 'sources.list',
        *sorted((APT_ETC / 'sources.list.d').glob('*.sources')),
        *sorted((APT_ETC / 'sources.list.d').glob('*.list'))]
    trusted = set()
    for path in paths:
        if not path.is_file():
            continue
        document = path.read_text()
        entries = []
        if path.suffix == '.sources':
            for paragraph in re.split(r'\n\s*\n', document):
                fields = deb822_fields(paragraph, str(path))
                if (fields.get('signed-by') == UBUNTU_KEYRING and 'deb' in fields.get('types', '').split()
                        and fields.get('enabled', 'yes').lower() != 'no'):
                    entries.append((fields.get('uris', '').split(), fields.get('suites', '').split(), fields.get('components', '').split()))
        else:
            for line in document.splitlines():
                entry = re.fullmatch(r'\s*deb\s+\[([^]]+)\]\s+(\S+)\s+(\S+)\s+([^#]+)(?:#.*)?', line)
                if entry and ('signed-by=' + UBUNTU_KEYRING) in entry.group(1).split():
                    entries.append(([entry.group(2)], [entry.group(3)], entry.group(4).split()))
        for uris, suites, components in entries:
            trusted.update((uri.rstrip('/'), suite + '/' + component)
                           for uri in uris for suite in suites for component in components)
    return trusted


def ubuntu_index(index, signed_sources):
    fields = index.split()
    if len(fields) != 4:
        return False
    uri, suite, architecture, kind = fields
    url = urlsplit(uri)
    host = url.hostname or ''
    ubuntu_host = host in ('archive.ubuntu.com', 'security.ubuntu.com', 'ports.ubuntu.com', 'snapshot.ubuntu.com') or host.endswith('.archive.ubuntu.com')
    return url.scheme in ('http', 'https') and (ubuntu_host or (uri.rstrip('/'), suite) in signed_sources)


def policy_origin(package, version, options):
    """Classify every index for this exact version; a newer candidate proves nothing."""
    environment = apt_environment(options)
    policy = run(['apt-cache', 'policy', *options], env=environment).stdout
    trusted = set()
    signed_sources = ubuntu_signed_sources(options)
    index = None
    for line in policy.splitlines():
        entry = re.fullmatch(r'\s*-?\d+\s+(.+ Packages)\s*', line)
        if entry:
            index = ' '.join(entry.group(1).split())
        elif line.strip().startswith('release '):
            fields = dict(item.split('=', 1) for item in line.strip()[8:].split(',') if '=' in item)
            if (index and ubuntu_index(index, signed_sources) and fields.get('o') == 'Ubuntu' and fields.get('n') == 'resolute'
                    and fields.get('a') in ('resolute', 'resolute-updates', 'resolute-security')):
                trusted.add(index)
            index = None
    architecture = run(['dpkg', '--print-architecture']).stdout.strip()
    policy = run(['apt-cache', 'policy', package + ':' + architecture, *options], env=environment).stdout
    selected = None
    found_trusted = found_untrusted = status = False
    for line in policy.splitlines():
        entry = re.fullmatch(r'\s*(?:\*\*\*\s+)?(\S+)\s+-?\d+(?:\s+\([^)]*\))?\s*', line)
        if entry:
            selected = entry.group(1)
            continue
        entry = re.fullmatch(r'\s*-?\d+\s+(.+?)\s*', line)
        if selected == version and entry:
            index = ' '.join(entry.group(1).split())
            if index == '/var/lib/dpkg/status':
                status = True
            elif index in trusted:
                found_trusted = True
            else:
                found_untrusted = True
        if not entry:
            selected = None
    return found_trusted, found_untrusted, status


def assess(tools, versions=None, options=None, snapshots=None, git_names=()):
    """Ubuntu packages have floors. Artifacts, including the Sury PHP build, stay exact."""
    if snapshots is None:
        with SnapshotLookups() as snapshots:
            return assess(tools, versions, options, snapshots, git_names)
    versions = inspect(tools) if versions is None else versions
    report = {}
    for name, pin in tools.items():
        installed = versions[name]
        status = 'current' if installed == pin['version'] else 'missing' if installed is None else 'different'
        reason = ''
        if name in git_names and pin['source'] == 'artifact' and installed is not None and installed != pin['version']:
            try:
                if not git_owned(name, installed, git_names) and not ubuntu_origin(name, installed, options, snapshots):
                    status = 'unsupported_newer'
            except RecipeInterrupted:
                raise
            except RecipeFailure as error:
                status = 'unsupported_newer'
                reason = ':' + str(error)
        if pin['source'] == 'ubuntu' and installed is not None and installed != pin['version']:
            comparison = run(['dpkg', '--compare-versions', installed, 'gt', pin['version']], required=False)
            if comparison.returncode == 0:
                try:
                    status = 'newer_than_recipe' if ubuntu_origin(pin['package'], installed, options, snapshots) else 'unsupported_newer'
                except RecipeInterrupted:
                    raise
                except RecipeFailure as error:
                    status = 'unsupported_newer'
                    reason = ':' + str(error)
            elif comparison.returncode == 1:
                status = 'older_than_recipe'
            else:
                raise RecipeFailure('inventory:' + name)
        report[name] = {'source': pin['source'], 'version': pin['version'], 'installed': installed,
                        'changed': status not in ('current', 'newer_than_recipe', 'unsupported_newer'), 'status': status}
        if status == 'unsupported_newer':
            report[name]['refusal'] = 'origin:' + name + reason
    return report


def audit_packages(environment):
    result = run(['dpkg', '--audit'], env=environment)
    # dpkg reports incomplete packages on stdout but can still exit successfully.
    if result.stdout.strip() or result.stderr.strip():
        raise RecipeFailure('packages', result=result)


def supported(report):
    for tool in report.values():
        if tool['status'] == 'unsupported_newer':
            raise RecipeFailure(tool['refusal'])


def newer_packages(report):
    return {name: {'installed': tool['installed'], 'version': tool['version']}
            for name, tool in report.items() if tool['status'] == 'newer_than_recipe'}


def snapshot_source(snapshot):
    # The snapshot /ubuntu/ archive includes arm64. /ubuntu-ports/ is not public.
    return (f"Types: deb\nURIs: https://snapshot.ubuntu.com/ubuntu/{snapshot}/\n"
              'Suites: resolute resolute-updates resolute-security\nComponents: main universe restricted multiverse\n'
              'Signed-By: /usr/share/keyrings/ubuntu-archive-keyring.gpg\nCheck-Valid-Until: no\n')


def ubuntu_sources_repair():
    path = APT_ETC / 'sources.list.d/ubuntu.sources'
    if not path.is_file() or path.is_symlink():
        return None
    content = path.read_bytes()
    match = re.fullmatch(rb'Types: deb\nURIs: https://snapshot\.ubuntu\.com/ubuntu/(\d{8}T\d{6}Z)/\n.*', content, re.DOTALL)
    if not match or content != snapshot_source(match.group(1).decode()).encode():
        return None
    return content


def rolling_source(architecture):
    archive = 'http://ports.ubuntu.com/ubuntu-ports' if architecture == 'arm64' else 'http://archive.ubuntu.com/ubuntu'
    security = 'http://ports.ubuntu.com/ubuntu-ports' if architecture == 'arm64' else 'http://security.ubuntu.com/ubuntu'
    return (f'Types: deb\nURIs: {archive}\nSuites: resolute resolute-updates resolute-backports\n'
              f'Components: main universe restricted multiverse\nSigned-By: {UBUNTU_KEYRING}\n\n'
              f'Types: deb\nURIs: {security}\nSuites: resolute-security\n'
              f'Components: main universe restricted multiverse\nSigned-By: {UBUNTU_KEYRING}\n')


def backup_apt_source(path, content, owner, *, check_only=False):
    """All source backups live directly in /etc/apt, outside APT's source directories."""
    backup = APT_ETC / (path.name + '.orbit-' + owner + '-backup')
    if backup.exists() or backup.is_symlink():
        if (backup.is_symlink() or not backup.is_file() or backup.read_bytes() != content):
            reason = 'is a symlink' if backup.is_symlink() else ('is not a regular file' if not backup.is_file() else 'differs from the source')
            raise RecipeFailure('apt-source', detail=f'Backup [{backup}] {reason}; refusing to replace source [{path}].')
    elif not check_only:
        publish(backup, content)
    return backup


def git_packages(payload):
    return {name: payload['tools'][name] for name in payload.get('git_packages', []) if name in payload['tools']}


def check_git_siblings(payload):
    tools = git_packages(payload)
    if not tools:
        return
    output = run(['dpkg-query', '-W', '-f=${Package}\t${source:Package}\t${db:Status-Status}\n']).stdout
    for line in output.splitlines():
        name, source, status = line.split('\t', 2)
        if status == 'installed' and source == 'git' and name not in tools:
            raise RecipeFailure('git-dependency:' + name)


def repair_ubuntu_sources(architecture):
    content = ubuntu_sources_repair()
    if content is None:
        return False
    path = APT_ETC / 'sources.list.d/ubuntu.sources'
    backup = backup_apt_source(path, content, 'snapshot')
    publish(path, rolling_source(architecture).encode())
    run(['logger', '--tag', 'orbit-recipe', '--', 'Restored generic Ubuntu sources in ' + str(path) + '; backup: ' + str(backup)])
    return True


def git_owned(name, version, names):
    """Only root's receipt of a verified recipe install can carry ownership across a later pin bump."""
    path = APT_ETC / 'orbit-git.json'
    if path.is_symlink() or not path.is_file() or path.stat().st_uid != 0 or path.stat().st_mode & 0o022:
        return False
    try:
        receipt = json_file(path)
    except (OSError, ValueError):
        return False
    if set(receipt) != set(names):
        return False
    if any(not isinstance(value, str) or not re.fullmatch(r'[0-9][0-9A-Za-z.+:~_-]*', value)
           for value in receipt.values()):
        return False
    return receipt[name] == version and len(set(receipt.values())) == 1


def record_git(payload, versions):
    tools = git_packages(payload)
    if not tools:
        return
    if any(versions.get(name) != pin['version'] for name, pin in tools.items()):
        raise RecipeFailure('inventory:git')
    publish(APT_ETC / 'orbit-git.json', json.dumps({name: pin['version'] for name, pin in tools.items()}, sort_keys=True).encode())


def provider_sources(pattern, allowed_uris, suite_pattern, components, owner):
    """Scan list and Deb822 entries once; refuse mixed entries and preflight durable backups."""
    changes = []
    paths = [APT_ETC / 'sources.list', *sorted((APT_ETC / 'sources.list.d').glob('*.sources')),
             *sorted((APT_ETC / 'sources.list.d').glob('*.list'))]
    for path in paths:
        if not path.is_file():
            continue
        content = path.read_text()
        if not re.search(pattern, content):
            continue
        if path.is_symlink():
            raise RecipeFailure('apt-source', detail=f'Provider source [{path}] is a symlink; refusing to retire it.')
        blocks = re.split(r'(\n\s*\n)', content) if path.suffix == '.sources' else content.splitlines(keepends=True)
        result = []
        for block in blocks:
            active = '\n'.join(line for line in block.splitlines() if not line.lstrip().startswith('#'))
            if not re.search(pattern, active):
                result.append(block)
                continue
            if path.suffix == '.sources':
                fields = deb822_fields(block, str(path))
                uris = fields.get('uris', '').split()
                suites = fields.get('suites', '').split()
                entry_components = fields.get('components', '').split()
                types = fields.get('types', '').split()
            else:
                entry = re.fullmatch(r'\s*(deb(?:-src)?)\s+(?:\[[^]]+\]\s+)?(\S+)\s+(\S+)\s+([^#]+?)(?:\s*#.*)?\s*', block)
                if not entry:
                    raise RecipeFailure('apt-source', detail=f'Provider source [{path}] has an invalid deb/deb-src list entry.')
                types, uris, suites, components_value = [entry[1]], [entry[2]], [entry[3]], entry[4]
                entry_components = components_value.split()
            if (not uris or any(uri.rstrip('/') not in allowed_uris for uri in uris)
                    or len(suites) != 1 or not re.fullmatch(suite_pattern, suites[0])
                    or entry_components != components or not types
                    or set(types) - {'deb', 'deb-src'}):
                raise RecipeFailure('apt-source', detail=f'Provider source [{path}] has unsupported or mixed fields: '
                                    f'URIs={uris!r}, Suites={suites!r}, Components={entry_components!r}, Types={types!r}; '
                                    f'expected URIs from {sorted(allowed_uris)!r}, one suite matching {suite_pattern!r}, '
                                    f'Components={components!r}, and only deb/deb-src Types.')
        replacement = ''.join(result)
        if replacement != content:
            backup_apt_source(path, content.encode(), owner, check_only=True)
            changes.append((path, content.encode(), replacement.encode()))
    return changes


def git_sources():
    """Retire only git-core's resolute/main entries."""
    allowed = {scheme + '://' + host + '/git-core/ppa/ubuntu'
               for scheme in ('http', 'https') for host in ('ppa.launchpad.net', 'ppa.launchpadcontent.net')}
    return provider_sources(r'(?:ppa\.launchpadcontent\.net|ppa\.launchpad\.net)/git-core/ppa',
                            allowed, 'resolute', ['main'], 'git')


def docker_sources():
    return provider_sources(r'download\.docker\.com', {'https://download.docker.com/linux/ubuntu'},
                            r'[a-z][a-z0-9-]*', ['stable'], 'docker')


def node_sources():
    return provider_sources(r'deb\.nodesource\.com',
                            {'https://deb.nodesource.com/node_' + str(major) + '.x' for major in range(10, 27)},
                            'nodistro', ['main'], 'node')


def node_config(changes):
    """Validate NodeSource-owned preference/key files and preflight durable backups."""
    files = []
    for name in ('nodejs', 'nsolid'):
        path = APT_ETC / 'preferences.d' / name
        if not path.exists() and not path.is_symlink():
            continue
        if path.is_symlink() or not path.is_file():
            raise RecipeFailure('node-ownership', detail=f'Refusing unexpected NodeSource preference [{path}].')
        content = path.read_bytes()
        fields = deb822_fields(content.decode(), str(path))
        if (set(fields) != {'package', 'pin', 'pin-priority'} or fields['package'] != name
                or fields['pin'] != 'origin deb.nodesource.com' or not re.fullmatch(r'-?[0-9]+', fields['pin-priority'])):
            raise RecipeFailure('node-ownership', detail=f'Refusing mixed or unexpected NodeSource preference [{path}].')
        files.append((path, content, 'node-preference'))
    replacements = {path: after.decode() for path, before, after in changes}
    sources = [APT_ETC / 'sources.list', *sorted((APT_ETC / 'sources.list.d').glob('*.sources')),
               *sorted((APT_ETC / 'sources.list.d').glob('*.list'))]
    for path, owner in ((NODE_USR / 'share/keyrings/nodesource.gpg', 'node-usr-keyring'),
                        (APT_ETC / 'keyrings/nodesource.gpg', 'node-apt-keyring')):
        if not path.exists() and not path.is_symlink():
            continue
        if path.is_symlink() or not path.is_file():
            raise RecipeFailure('node-ownership', detail=f'Refusing unexpected NodeSource keyring [{path}].')
        for source in sources:
            if source.is_file():
                remaining = replacements[source] if source in replacements else source.read_text()
                active = '\n'.join(line for line in remaining.splitlines() if not line.lstrip().startswith('#'))
                if str(path) in active:
                    raise RecipeFailure('node-ownership', detail=f'NodeSource keyring [{path}] is still referenced by [{source}].')
        files.append((path, path.read_bytes(), owner))
    for path, content, owner in files:
        backup_apt_source(path, content, owner, check_only=True)
    return files


def dangling_corepack_links():
    links = []
    for name in ('yarn', 'yarnpkg', 'pnpm', 'pnpx'):
        path = NODE_USR / 'bin' / name
        expected = NODE_USR / 'lib/node_modules/corepack/dist' / (name + '.js')
        if (path.is_symlink() and not path.exists()
                and Path(os.path.abspath(path.parent / os.readlink(path))) == expected):
            links.append(path)
    return links


def consolidate_node(tools, *, apply=False):
    """The recipe owns Node. Retire duplicate APT ownership only after verifying its replacement."""
    if 'node' not in tools:
        return []
    changes = node_sources()
    config = node_config(changes)
    installed = 'nodejs' in packages()
    actions = (['remove_nodejs_package'] if installed else []) + (['retire_node_source'] if changes else [])
    actions += (['retire_node_config'] if config else []) + (['remove_corepack_links'] if dangling_corepack_links() else [])
    if not apply:
        return actions
    if inspect({'node': tools['node']})['node'] != tools['node']['version']:
        raise RecipeFailure('node-ownership', detail='Verify the recipe Node runtime before removing the APT source.')
    if installed:
        environment = dict(os.environ, LC_ALL='C', DEBIAN_FRONTEND='noninteractive', NEEDRESTART_SUSPEND='1')
        result = package_command(['apt-get', '--simulate', 'remove', '--', 'nodejs'], ['nodejs'], env=environment)
        removed = [line.split()[1] for line in result.stdout.splitlines() if line.startswith('Remv ')]
        if removed != ['nodejs']:
            raise RecipeFailure('node-ownership', detail='Node removal would affect other packages; inspect dependencies before retrying.')
        package_command(['apt-get', 'remove', '--yes', '--', 'nodejs'], ['nodejs'], env=environment)
        if 'nodejs' in packages():
            raise RecipeFailure('node-ownership', detail='The duplicate nodejs package is still installed.')
    retire_sources(changes, 'node')
    for path, content, owner in config:
        backup_apt_source(path, content, owner)
        path.unlink()
    for path in dangling_corepack_links():
        path.unlink()
    return actions


def git_preferences(tools):
    return ''.join(f'Package: {name}\nPin: version {tools[name]["version"]}\nPin-Priority: 1001\n\n'
                   f'Package: {name}\nPin: version *\nPin-Priority: -1\n\n' for name in tools).encode()


def configure_git(payload, *, apply=False):
    tools = git_packages(payload)
    if not tools:
        return []
    changes = git_sources()
    preference = APT_ETC / 'preferences.d/orbit-git'
    if preference.is_symlink():
        raise RecipeFailure('apt-source', detail=f'Git preference [{preference}] is a symlink; refusing to replace it.')
    expected = git_preferences(tools)
    pin_changed = not preference.is_file() or preference.read_bytes() != expected
    actions = (['retire_git_source'] if changes else []) + (['pin_git'] if pin_changed else [])
    if apply:
        retire_sources(changes, 'git')
    return actions


def retire_sources(changes, owner):
    """Retire validated entries with the same durable backup contract for Git and Docker."""
    for path, before, after in changes:
        backup_apt_source(path, before, owner)
        if after.strip():
            publish(path, after)
        else:
            path.unlink()


def pin_git(payload):
    tools = git_packages(payload)
    if tools:
        publish(APT_ETC / 'preferences.d/orbit-git', git_preferences(tools))


def plan(payload):
    check_git_siblings(payload)
    actions = configure_git(payload) + consolidate_node(payload['tools'])
    if ubuntu_sources_repair() is None:
        report = assess(payload['tools'], git_names=git_packages(payload))
    else:
        with temporary_indexes(rolling_source(payload['architecture'])) as options:
            report = assess(payload['tools'], options=options, git_names=git_packages(payload))
        actions.insert(0, 'repair_ubuntu_sources')
    return {**report, **({'_actions': actions} if actions else {})}


def snapshot_options(payload, work):
    source = snapshot_source(payload['snapshot'])
    path = work / 'ubuntu.sources'
    publish(path, source.encode())
    # Keep normal sources and their cached origin metadata for unattended-upgrades and read-only plans.
    return apt_source_options(path)


def apt_fetch_options():
    # APT's built-in retries include 4xx and omit some 5xx. The fetch boundary owns retries.
    return ['-o', 'Acquire::Retries=0',
            '-o', 'Acquire::http::Timeout=15', '-o', 'Acquire::https::Timeout=15']


def apt_fetch(argv, **kwargs):
    kwargs['env'] = dict(kwargs.get('env') or os.environ, LC_ALL='C')
    for delay in (10, 20, 30, None):
        try:
            return run(argv, **kwargs)
        except RecipeInterrupted:
            raise
        except RecipeFailure as error:
            output = (error.result.stdout + '\n' + error.result.stderr) if error.result is not None else ''
            failures = re.findall(r'^[EW]: Failed to fetch (\S+)[ \t]+([^\n]+)', output, re.MULTILINE)
            # A failed Release fetch also produces a derived "not signed" error on a fresh list.
            unsigned = re.findall(r"^E: The repository '(\S+) (\S+) (?:InRelease|Release)' is not signed\.$", output, re.MULTILINE)
            derived_unsigned = unsigned and all(any(
                url in (root.rstrip('/') + '/dists/' + suite + '/InRelease', root.rstrip('/') + '/dists/' + suite + '/Release')
                for url, _ in failures) for root, suite in unsigned)
            # Retry only explicit transport failures, never authentication, digest, or other package errors.
            if (delay is None or not failures
                    or re.search(r'(?i)hash sum mismatch|unexpected size|signature|NO_PUBKEY', output)
                    or ('not signed' in output and not derived_unsigned)
                    or not all(re.match(r'5\d\d\b', reason) or any(message in reason for message in ('Connection reset by peer', 'Connection failed'))
                               for _, reason in failures)):
                raise
            time.sleep(delay)


def apt_source_options(path):
    return [*apt_fetch_options(), '-o', 'Dir::Etc::sourcelist=' + str(path), '-o', 'Dir::Etc::sourceparts=-',
            '-o', 'APT::Get::List-Cleanup=false', '-o', 'APT::Update::Error-Mode=any',
            '-o', 'Acquire::Languages=none', '-o', 'Acquire::IndexTargets::deb::DEP-11::DefaultEnabled=false',
            '-o', 'Acquire::IndexTargets::deb::CNF::DefaultEnabled=false']


def refresh_ubuntu_indexes(payload, resources):
    """Refresh origin evidence from Ubuntu without reading unrelated host sources."""
    work = Path(resources.enter_context(tempfile.TemporaryDirectory(prefix='orbit-recipe-sources-')))
    path = work / 'ubuntu.sources'
    source = rolling_source(payload['architecture'])
    path.write_text(source)
    options = apt_source_options(path)
    package_command(['apt-get', 'update', *options], [])
    # Origin checks also need cached snapshot evidence. Installation still uses only the pinned snapshot.
    path.write_text(source + '\n' + snapshot_source(payload['snapshot']))
    return options


def package_command(argv, packages, **kwargs):
    try:
        execute = apt_fetch if argv[:2] in (['apt-get', 'update'], ['apt-get', 'install']) else run
        return execute(argv, **kwargs)
    except RecipeFailure as error:
        error.packages = packages
        raise


def install_exact_debs(debs, tools, installed, options, environment):
    """APT's downgrade switch is global. Check its plan and hold every Ubuntu floor in place."""
    artifacts = {Path(deb).stem for deb in debs}
    retained = [pin['package'] + '=' + installed[pin['package']] for pin in tools.values()
                if pin['source'] == 'ubuntu' and pin['package'] in installed]
    argv = ['apt-get', 'install', '--yes', '--no-install-recommends', '--no-remove', '--allow-downgrades',
            '-o', 'Dpkg::Options::=--force-confold', *options, '--', *debs, *retained]
    environment = dict(environment, LC_ALL='C')
    plan = package_command([*argv[:2], '--simulate', *argv[2:]], sorted(artifacts), env=environment)
    for line in plan.stdout.splitlines():
        if not line.startswith('Inst '):
            continue
        entry = re.match(r'Inst (\S+)(?: \[([^]]+)\])? \((\S+)', line)
        if not entry:
            raise RecipeFailure(packages=sorted(artifacts))
        name, old, new = entry.groups()
        name = name.split(':', 1)[0]
        old = old or installed.get(name)
        if old is not None and name not in artifacts:
            comparison = run(['dpkg', '--compare-versions', new, 'lt', old], required=False)
            if comparison.returncode != 1:
                raise RecipeFailure(packages=[name])
    package_command(argv, sorted(artifacts), env=environment)


def failure_output(value):
    """Redact before bounding output; the Gateway also applies its shared activity sanitizer."""
    value = re.sub(r'-----BEGIN [A-Z0-9 ]+-----[\s\S]*?-----END [A-Z0-9 ]+-----', '[REDACTED]', value)
    value = re.sub(r'(?i)\b([a-z][a-z0-9+.-]*://)[^/@\s]+@', r'\1[REDACTED]@', value)
    value = re.sub(r'(?i)\b((?:proxy-)?authorization)\s*:[^\r\n]*', r'\1: [REDACTED]', value)
    value = re.sub(r"(?i)\b([\w-]*(?:token|secret|password|passwd|passphrase|credential|key|cookie|authorization)[\w-]*)\s*[:=]\s*(?:\"[^\"]*\"|'[^']*'|[^\s&#]+)", r'\1=[REDACTED]', value)
    value = re.sub(r'(?i)\bBearer\s+\S+', 'Bearer [REDACTED]', value)
    value = re.sub(r'[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]', '', value)
    return value.encode('utf-8', errors='replace')[-8000:].decode('utf-8', errors='replace')


def binary_version(binary, env=None, *, prefix=()):
    result = run([*prefix, str(binary), '--version'], required=False, env=env)
    match = re.search(r'(?:^|[\s v])([0-9]+\.[0-9]+\.[0-9]+)\b', result.stdout) if result.returncode == 0 else None
    return match.group(1) if match else None


def json_file(path):
    try:
        value = json.loads(path.read_text())
        return value if isinstance(value, dict) else {}
    except FileNotFoundError:
        return {}


def inspect(tools):
    installed = packages()
    versions = {}
    javascript = set(tools) & {'node', 'pnpm', 'bun', 'vp', 'vp-cli'}
    scope = None
    prefix = []
    if javascript:
        account = pwd.getpwnam(os.environ['SUDO_USER']) if os.environ.get('SUDO_USER') else pwd.getpwuid(os.getuid())
        scope = vite_scope(account)
        # Store executables are account-writable. Never inspect them with container/host root privileges.
        if os.geteuid() == 0:
            prefix = ['sudo', '-u', account.pw_name, '-H']
        prefix += ['env', 'VP_HOME=' + str(scope)]
    for name, pin in tools.items():
        if pin['source'] == 'ubuntu' or (pin['source'] == 'artifact' and any(a['url'].endswith('.deb') for a in pin.get('artifacts', {}).values())):
            versions[name] = installed.get(pin['package'] if pin['source'] == 'ubuntu' else name)
        elif name == 'bun':
            versions[name] = binary_version('/opt/orbit/bun/bin/bun', prefix=prefix)
        elif name == 'vp-cli':
            binary = (scope / 'bin/vp').resolve()
            # Even --version can bootstrap an unmarked Vite+ executable. Never invoke one in a read.
            ready = binary.is_file() and (binary.parent / '.vp-setup-complete').is_file()
            versions[name] = binary_version(binary, prefix=prefix) if ready else None
        elif name == 'vp':
            digest = pin.get('lock_sha256')
            target = scope / 'recipe' / ('vp-runtime-' + str(digest))
            metadata = target / 'node_modules/vite-plus/package.json'
            lock = target / 'package-lock.json'
            active = scope / 'node_modules/vite-plus'
            current = (isinstance(digest, str) and lock.is_file()
                       and hashlib.sha256(lock.read_bytes()).hexdigest() == digest
                       and active.is_symlink() and active.resolve() == metadata.parent)
            versions[name] = json_file(metadata).get('version') if current else None
        elif name == 'pnpm':
            versions[name] = json_file(scope / 'recipe/pnpm-current/package.json').get('version')
        elif name == 'node':
            # A moving default can fetch a runtime even for node --version. Inspect exact cached defaults directly.
            default = json_file(scope / 'config.json').get('defaultNodeVersion', '')
            binary = scope / 'js_runtime/node' / default / 'bin/node' if isinstance(default, str) and re.fullmatch(r'[0-9]+\.[0-9]+\.[0-9]+', default) else None
            pinned = json_file(scope / '.orbit-recipe-node.json').get('version') == default
            versions[name] = binary_version(binary, prefix=prefix) if pinned and binary and not binary.parent.parent.is_symlink() and binary.is_file() else None
        else:
            raise RecipeFailure()
        if versions[name] is not None and not isinstance(versions[name], str):
            raise RecipeFailure()
    return versions


def safe_directory(path, uid=0, gid=0):
    path = Path(path)
    if path.is_symlink() or (path.exists() and (not path.is_dir() or path.stat().st_uid != uid)):
        raise RecipeFailure()
    if not path.exists():
        path.mkdir(mode=0o755)
        os.chown(path, uid, gid)
    return path


def publish(path, content, mode=0o644, uid=0, gid=0, replace_link=False):
    path = Path(path)
    if path.is_symlink() and not replace_link:
        raise RecipeFailure(detail=f'Refusing to publish [{path}]: symlink replacement is not allowed.')
    if path.exists() or path.is_symlink():
        if not path.is_symlink() and not path.is_file():
            raise RecipeFailure(detail=f'Refusing to publish [{path}]: target is not a regular file.')
        owner = path.lstat().st_uid
        if owner != uid:
            raise RecipeFailure(detail=f'Refusing to publish [{path}]: target owned by UID [{owner}], not owned by UID [{uid}].')
    fd, candidate = tempfile.mkstemp(prefix='.orbit-recipe-', dir=path.parent)
    try:
        with os.fdopen(fd, 'wb') as stream:
            stream.write(content)
        os.chmod(candidate, mode)
        os.chown(candidate, uid, gid)
        os.replace(candidate, path)
    finally:
        Path(candidate).unlink(missing_ok=True)


def download(pin, architecture, destination):
    artifact = pin['artifacts'][architecture]
    algorithm = 'sha256' if 'sha256' in artifact else 'sha512'
    url = artifact['url']
    if not url.startswith('https://'):
        raise RecipeFailure()
    # curl permits HTTPS redirects only, including GitHub's release-asset redirect.
    run(['curl', '--fail', '--silent', '--show-error', '--location', '--proto', '=https',
         '--proto-redir', '=https', '--connect-timeout', '15', '--max-time', '600',
         '--output', str(destination), '--', url])
    with destination.open('rb') as stream:
        digest = hashlib.file_digest(stream, algorithm).hexdigest()
    if digest != artifact[algorithm]:
        raise RecipeFailure()
    return destination


def download_deb(name, pin, payload, destination):
    artifact = pin['artifacts'][payload['architecture']]
    last_failure = None
    for url in artifact_candidates(name, artifact, payload.get('artifact_mirror', '')):
        selected = {**pin, 'artifacts': {payload['architecture']: {**artifact, 'url': url}}}
        try:
            return download(selected, payload['architecture'], destination)
        except RecipeInterrupted:
            raise
        except RecipeFailure as error:
            # A digest mismatch has no failed curl result and must never fall back.
            if error.result is None:
                raise
            last_failure = error
    raise last_failure or RecipeFailure()


def extract(archive, destination):
    destination.mkdir()
    if zipfile.is_zipfile(archive):
        with zipfile.ZipFile(archive) as source:
            for member in source.infolist():
                if member.filename.startswith('/') or '..' in Path(member.filename).parts or (member.external_attr >> 16) & 0o170000 == 0o120000:
                    raise RecipeFailure()
            source.extractall(destination)
    else:
        with tarfile.open(archive) as source:
            source.extractall(destination, filter='data')
    return destination


DEPENDENT_PROPERTIES = ('RequiredBy', 'BoundBy', 'ConsistsOf')


def service_state(service):
    properties = 'LoadState,ActiveState,Result,ControlGroup,Job,' + ','.join(DEPENDENT_PROPERTIES)
    output = run(['systemctl', 'show', '--no-pager', '--property=' + properties, service]).stdout
    return dict(line.split('=', 1) for line in output.splitlines() if '=' in line)


def active_units():
    output = run(['systemctl', 'list-units', '--state=active', '--no-legend', '--plain']).stdout
    return {line.split()[0] for line in output.splitlines() if line.strip()}


def affected_units(candidates, active):
    selected, visited = set(), set()
    pending = set(candidates)
    while pending:
        unit = pending.pop()
        visited.add(unit)
        try:
            state = service_state(unit)
        except RecipeFailure as error:
            raise RecipeFailure('restart:' + unit, result=error.result) from error
        if vanished(state):
            continue
        if state.get('LoadState') != 'loaded':
            raise RecipeFailure('restart:' + unit)
        selected.add(unit)
        dependents = {name for property_name in DEPENDENT_PROPERTIES for name in state[property_name].split()}
        pending.update((dependents & active) - visited)
    return selected


def services_for(changed, before_active):
    services = set()
    for package in changed:
        output = run(['dpkg-query', '-L', package], required=False).stdout
        for filename in output.splitlines():
            if re.fullmatch(r'/(?:usr/)?lib/systemd/system/[A-Za-z0-9@_.:-]+\.service', filename):
                unit = Path(filename).name
                if '@.service' in unit:
                    prefix = unit.removesuffix('@.service') + '@'
                    services.update(active for active in before_active if active.startswith(prefix) and active.endswith('.service'))
                else:
                    services.add(unit)
        php = re.match(r'php([0-9]+\.[0-9]+)-', package)
        if php:
            services.add('php' + php.group(1) + '-fpm.service')
    return sorted(services & before_active)


def vite_scope(account):
    home = Path(account.pw_dir)
    candidates = [Path('/opt/orbit/vite-plus'), home / '.vite-plus', home / '.local/share/vite-plus']
    for candidate in candidates:
        if candidate.is_symlink() or (candidate.exists() and (not candidate.is_dir() or candidate.stat().st_uid != account.pw_uid)):
            raise RecipeFailure()
        if (candidate / 'bin/vp').exists():
            return candidate
    return home / '.local/share/vite-plus'


def package_link(runtime, name, package, uid, gid):
    alias = runtime / (name + '-current')
    if alias.exists() or alias.is_symlink():
        if not alias.is_symlink() or alias.lstat().st_uid != uid:
            raise RecipeFailure()
    candidate = runtime / (name + '-candidate')
    if candidate.exists() or candidate.is_symlink():
        raise RecipeFailure()
    candidate.symlink_to(package, target_is_directory=True)
    os.chown(candidate, uid, gid, follow_symlinks=False)
    os.replace(candidate, alias)


def install_vite_runtime(scope, runtime, tools, lock_content, account):
    """Materialize the recipe's complete lock before atomically switching the runtime package."""
    uid, gid = account.pw_uid, account.pw_gid
    if not isinstance(lock_content, str) or hashlib.sha256(lock_content.encode()).hexdigest() != tools['vp']['lock_sha256']:
        raise RecipeFailure()
    lock = json.loads(lock_content)
    if lock['packages']['']['dependencies'] != {'vite-plus': tools['vp']['version']}:
        raise RecipeFailure()
    target = runtime / ('vp-runtime-' + tools['vp']['lock_sha256'])
    if not target.exists():
        with tempfile.TemporaryDirectory(prefix='.vp-runtime-', dir=runtime) as directory:
            candidate = Path(directory)
            os.chown(candidate, uid, gid)
            manifest = {'name': lock['name'], 'version': lock['version'], 'private': True,
                        'dependencies': lock['packages']['']['dependencies']}
            publish(candidate / 'package.json', json.dumps(manifest).encode(), 0o644, uid, gid)
            publish(candidate / 'package-lock.json', lock_content.encode(), 0o644, uid, gid)
            node = scope / 'js_runtime/node' / tools['node']['version']
            cache = safe_directory(runtime / 'npm-cache', uid, gid)
            run(['sudo', '-u', account.pw_name, '-H', 'env', 'PATH=' + str(node / 'bin') + ':' + os.defpath,
                 'npm_config_cache=' + str(cache), str(node / 'bin/node'), str(node / 'lib/node_modules/npm/bin/npm-cli.js'),
                 'ci', '--ignore-scripts', '--no-audit', '--no-fund', '--prefix', str(candidate)])
            os.replace(candidate, target)
    safe_directory(target, uid, gid)
    if hashlib.sha256((target / 'package-lock.json').read_bytes()).hexdigest() != tools['vp']['lock_sha256']:
        raise RecipeFailure()
    package = target / 'node_modules/vite-plus'
    if json_file(package / 'package.json').get('version') != tools['vp']['version']:
        raise RecipeFailure()
    modules = scope / 'node_modules'
    if modules.is_symlink():
        if modules.lstat().st_uid != uid:
            raise RecipeFailure()
        safe_directory(modules.resolve(strict=True), uid, gid)
    else:
        safe_directory(modules, uid, gid)
    active = modules / 'vite-plus'
    if active.exists() or active.is_symlink():
        if not active.is_symlink() or active.lstat().st_uid != uid:
            raise RecipeFailure()
    candidate = modules / '.orbit-recipe-vite-plus'
    if candidate.exists() or candidate.is_symlink():
        raise RecipeFailure()
    candidate.symlink_to(package, target_is_directory=True)
    os.chown(candidate, uid, gid, follow_symlinks=False)
    os.replace(candidate, active)
    package_link(runtime, 'vp', package, uid, gid)


def node_runtime_matches(source, installed, uid):
    """Compare a store publication with the verified archive without running account-writable code."""
    if installed.is_symlink() or not installed.is_dir():
        return False
    expected = {Path('.'), *(path.relative_to(source) for path in source.rglob('*'))}
    actual = {Path('.'), *(path.relative_to(installed) for path in installed.rglob('*'))}
    if expected != actual:
        return False
    for relative in expected:
        original, current = source / relative, installed / relative
        before, after = original.lstat(), current.lstat()
        if after.st_uid != uid or stat.S_IFMT(before.st_mode) != stat.S_IFMT(after.st_mode):
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


def install_node_runtime(scope, source, version, identifier, uid, gid):
    """Publish the recipe archive using the same immutable, real-directory layout as deploys."""
    cache = safe_directory(scope / 'js_runtime', uid, gid)
    cache = safe_directory(cache / 'node', uid, gid)
    cached = cache / version
    lock_path = scope / '.orbit-store.lock'
    try:
        descriptor = os.open(lock_path, os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW | os.O_RDWR, 0o644)
        os.fchown(descriptor, uid, gid)
    except FileExistsError:
        descriptor = os.open(lock_path, os.O_NOFOLLOW | os.O_RDONLY)
    with os.fdopen(descriptor, 'rb') as lock:
        info = os.fstat(lock.fileno())
        if not stat.S_ISREG(info.st_mode) or info.st_uid != uid:
            raise RecipeFailure(detail='The Vite+ store lock must belong to the store owner.')
        # Normalize historical deploy-created locks in place; keep the shared lock inode.
        os.fchmod(lock.fileno(), 0o644)
        deadline = time.monotonic() + 120
        while True:
            try:
                fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
                break
            except BlockingIOError:
                if time.monotonic() >= deadline:
                    raise RecipeFailure(detail='Timed out waiting for the Vite+ store lock.') from None
                time.sleep(0.1)
        if cached.is_symlink():
            previous = scope / 'recipe' / ('node-' + identifier) / source.name
            if cached.lstat().st_uid != uid or Path(os.path.abspath(cached.parent / os.readlink(cached))) != previous:
                raise RecipeFailure(detail=f'Refusing unrelated Node runtime link [{cached}].')
        elif cached.exists():
            if not node_runtime_matches(source, cached, uid):
                raise RecipeFailure(detail=f'Existing Node runtime [{cached}] differs from the verified archive; the store owner must repair it.')
        if cached.is_symlink() or not cached.exists():
            with tempfile.TemporaryDirectory(prefix='.orbit-stage-', dir=cache) as directory:
                candidate = Path(directory) / source.name
                shutil.copytree(source, candidate, symlinks=True)
                for parent, directories, files in os.walk(candidate):
                    os.chown(parent, uid, gid)
                    os.chmod(parent, 0o755)
                    for entry in directories + files:
                        path = Path(parent) / entry
                        os.chown(path, uid, gid, follow_symlinks=False)
                        if not path.is_symlink():
                            os.chmod(path, path.stat().st_mode & 0o755)
                if cached.is_symlink():
                    cached.unlink()
                os.rename(candidate, cached)
        # Collection must retain the recipe runtime even when no Instance references it.
        publish(scope / '.orbit-recipe-node.json', json.dumps({'version': version}).encode(), 0o644, uid, gid)


def install_javascript(tools, downloads, work, account, vp_lock=None):
    """Keep the existing Vite+ scope and global store; replace only its pinned runtime inputs."""
    uid, gid = account.pw_uid, account.pw_gid
    home = Path(account.pw_dir)
    scope = vite_scope(account)
    if not scope.exists():
        safe_directory(home / '.local', uid, gid)
        safe_directory(home / '.local/share', uid, gid)
    safe_directory('/opt/orbit')
    safe_directory(scope, uid, gid)
    bin_dir = safe_directory(scope / 'bin', uid, gid)
    runtime = safe_directory(scope / 'recipe', uid, gid)
    for name in ('node', 'vp-cli', 'pnpm', 'bun'):
        if name not in downloads:
            continue
        unpacked = extract(downloads[name], work / ('unpack-' + name))
        # Artifact bytes are verified before extraction; no mutable installer or channel runs.
        identifier = hashlib.sha256(downloads[name].read_bytes()).hexdigest()
        if name == 'node':
            install_node_runtime(scope, next(unpacked.iterdir()), tools[name]['version'], identifier, uid, gid)
            continue
        target = runtime / (name + '-' + identifier)
        if not target.exists():
            shutil.copytree(unpacked, target, symlinks=True)
            for parent, directories, files in os.walk(target):
                os.chown(parent, uid, gid)
                for entry in directories + files:
                    os.chown(Path(parent) / entry, uid, gid, follow_symlinks=False)
        safe_directory(target, uid, gid)
        if name == 'vp-cli':
            publish(bin_dir / 'vp', (target / 'vp').read_bytes(), 0o755, uid, gid, replace_link=True)
            # Standalone Vite+ otherwise runs an unpinned installer on its first invocation.
            publish(bin_dir / '.vp-setup-complete', b'', 0o644, uid, gid)
        elif name == 'pnpm':
            package_link(runtime, 'pnpm', target / 'package', uid, gid)
            native = target / 'package/pnpm'
            executable = target / 'package/bin/pnpm.cjs'
            if json_file(target / 'package/package.json').get('version') != tools[name]['version']:
                raise RecipeFailure()
            if native.is_file() and native.read_bytes()[:4] == b'\x7fELF':
                publish(bin_dir / 'pnpm', native.read_bytes(), 0o755, uid, gid, replace_link=True)
            elif executable.is_file():
                publish(bin_dir / 'pnpm', ('#!/bin/sh\nexec "' + str(bin_dir / 'node') + '" "' + str(executable) + '" "$@"\n').encode(), 0o755, uid, gid, replace_link=True)
            else:
                raise RecipeFailure()
        elif name == 'bun':
            executable = next(target.glob('*/bun'))
            os.chmod(executable, 0o755)
            safe_directory('/opt/orbit/bun', uid, gid)
            safe_directory('/opt/orbit/bun/bin', uid, gid)
            publish('/opt/orbit/bun/bin/bun', executable.read_bytes(), 0o755, uid, gid, replace_link=True)
            publish('/usr/local/bin/bun', b'#!/bin/sh\nexec /opt/orbit/bun/bin/bun "$@"\n', 0o755, replace_link=True)
    if 'vp' in tools:
        install_vite_runtime(scope, runtime, tools, vp_lock, account)
    if 'node' in tools:
        for binary in ('node', 'npm', 'npx'):
            shim = bin_dir / binary
            if shim.exists() or shim.is_symlink():
                if shim.lstat().st_uid != uid:
                    raise RecipeFailure()
                shim.unlink()
            shim.symlink_to('vp')
            os.chown(shim, uid, gid, follow_symlinks=False)
        run(['sudo', '-u', account.pw_name, '-H', 'env', 'VP_HOME=' + str(scope),
             str(bin_dir / 'vp'), 'env', 'default', tools['node']['version']])
    for binary in ('vp', 'node', 'pnpm', 'npm', 'npx'):
        target = bin_dir / binary
        launcher = Path('/usr/local/bin') / binary
        content = ('#!/bin/sh\nexport VP_HOME="' + str(scope) + '"\nexec "' + str(target) + '" "$@"\n').encode()
        publish(launcher, content, 0o755)


def apply(payload):
    with SnapshotLookups() as snapshots:
        return apply_recipe(payload, snapshots)


def apply_recipe(payload, snapshots):
    stage = 'preflight'
    try:
        tools = payload['tools']
        configure_git(payload)
        policy = POLICY
        if policy.exists() or policy.is_symlink():
            raise RecipeFailure()
        stage = 'apt-source'
        repair_ubuntu_sources(payload['architecture'])
        origin_options = refresh_ubuntu_indexes(payload, snapshots)
        stage = 'preflight'
        account = pwd.getpwnam(os.environ.get('SUDO_USER', 'orbit'))
        before = packages()
        versions = inspect(tools)
        check_git_siblings(payload)
        assessment = assess(tools, versions, origin_options, snapshots, git_packages(payload))
        supported(assessment)
        active = active_units()
        recipe_packages = {pin['package'] for pin in tools.values() if pin['source'] == 'ubuntu'}
        recipe_packages.update(name for name, pin in tools.items() if pin['source'] != 'ubuntu'
                               and pin['artifacts'][payload['architecture']]['url'].endswith('.deb'))
        health_services = set(services_for(recipe_packages, active))
        js = set(tools) & {'node', 'vp', 'vp-cli', 'pnpm', 'bun'}
        if js:
            vite_scope(account)
        with tempfile.TemporaryDirectory(prefix='orbit-recipe-') as directory:
            work = Path(directory)
            stage = 'download'
            downloads = {name: download_deb(name, pin, payload, work / (name + ('.deb' if pin['artifacts'][payload['architecture']]['url'].endswith('.deb') else '.archive')))
                         for name, pin in tools.items() if name != 'vp' and pin['source'] != 'ubuntu' and versions[name] != pin['version']}
            debs = [str(path) for name, path in downloads.items() if tools[name]['artifacts'][payload['architecture']]['url'].endswith('.deb')]
            # Validate Debian package identity before allowing maintainer scripts to run.
            for deb in debs:
                name = Path(deb).stem
                metadata = run(['dpkg-deb', '-f', deb, 'Package', 'Version', 'Architecture']).stdout.splitlines()
                expected = [name, tools[name]['version'], payload['architecture']]
                actual = [line.split(': ', 1)[-1] for line in metadata]
                if actual[:2] != expected[:2] or actual[2:] not in ([payload['architecture']], ['all']):
                    raise RecipeFailure()
            stage = 'apt-source'
            configure_git(payload, apply=True)
            ubuntu = [pin['package'] + '=' + pin['version'] for name, pin in tools.items()
                      if pin['source'] == 'ubuntu' and assessment[name]['changed']]
            apt_options = snapshot_options(payload, work) if ubuntu or debs else None
            stage = 'packages'
            if apt_options is not None:
                package_command(['apt-get', 'update', *apt_options], [])
            environment = dict(os.environ, DEBIAN_FRONTEND='noninteractive', NEEDRESTART_SUSPEND='1')
            # This is temporary restart suppression, not a package/configuration rollback.
            publish(policy, b'#!/bin/sh\nexit 101\n', 0o755)
            try:
                if ubuntu:
                    package_command(['apt-get', 'install', '--yes', '--no-install-recommends', '--no-remove',
                                     '-o', 'Dpkg::Options::=--force-confold', *apt_options, '--', *ubuntu], ubuntu, env=environment)
                if debs:
                    install_exact_debs(debs, tools, packages(), apt_options, environment)
                if ubuntu or debs:
                    package_command(['apt-get', 'check'], ubuntu + [Path(deb).stem for deb in debs], env=environment)
                    audit_packages(environment)
            finally:
                policy.unlink()
            pin_git(payload)
            stage = 'tools'
            install_javascript(tools, downloads, work, account, payload.get('vp_lock')) if js else None
            stage = 'inventory'
            after_versions = inspect(tools)
            after_assessment = assess(tools, after_versions, origin_options, snapshots, git_packages(payload))
            supported(after_assessment)
            if any(tool['status'] not in ('current', 'newer_than_recipe') for tool in after_assessment.values()):
                raise RecipeFailure()
            stage = 'node-ownership'
            consolidate_node(tools, apply=True)
            after = packages()
            changed = sorted(name for name in before.keys() | after.keys() if before.get(name) != after.get(name))
            stage = 'restart'
            package_services = set(services_for(changed, active))
            affected = affected_units(package_services, active_units()) if package_services else set()
            restarted = sorted(package_services & affected)
            if restarted:
                stage = 'restart:' + ','.join(restarted)
                run(['systemctl', 'restart', *restarted])
                fence = 'orbit-recipe-health-' + uuid.uuid4().hex + '.service'
                try:
                    run(['systemd-run', '--quiet', '--wait', '--collect', '--unit=' + fence,
                         '--property=Type=oneshot', '--property=DefaultDependencies=no',
                         '--property=After=' + ' '.join(sorted(affected)), '/usr/bin/true'])
                except RecipeFailure as error:
                    if isinstance(error.__cause__, subprocess.TimeoutExpired):
                        run(['systemctl', 'stop', '--no-block', fence])
                    raise
            stage = 'health'
            # Check package services and every active unit their restart propagates to; Doctor checks role requirements.
            for service in sorted(health_services | affected):
                stage = 'health:' + service
                result = run(['systemctl', 'is-active', '--quiet', service], required=False)
                if result.returncode != 0:
                    state = service_state(service)
                    if vanished(state) or (state.get('LoadState') == 'loaded' and state.get('ActiveState') == 'inactive'
                                          and state.get('Result') == 'success'):
                        continue
                    raise RecipeFailure(result=result)
            record_git(payload, after_versions)
            return {'changed_packages': changed, 'restarted_services': restarted,
                    'changed_tools': sorted(name for name in tools if versions[name] != after_versions[name]),
                    'newer_than_recipe': newer_packages(after_assessment), 'healthy': True}
    except Exception as error:
        if isinstance(error, RecipeFailure):
            raise RecipeFailure(str(error) or stage, result=error.result, packages=error.packages, detail=error.detail) from error
        raise RecipeFailure(stage, detail=f'{type(error).__name__}: {error}') from error


def interrupted(signum, frame):
    raise RecipeInterrupted('interrupted')


def main():
    signal.signal(signal.SIGTERM, interrupted)
    signal.signal(signal.SIGINT, interrupted)
    mode, document = sys.argv[1:]
    payload = json.loads(document)
    stage = 'platform'
    try:
        platform_check(payload['architecture'])
        stage = 'inventory'
        if 'docker-ce' in packages():
            raise RecipeFailure('docker-provider:docker-ce')
        result = plan(payload) if mode == 'inspect' else apply(payload)
        print(json.dumps(result))
        return 0
    except Exception as error:
        if isinstance(error, RecipeFailure) and str(error):
            stage = str(error)
        if isinstance(error, RecipeFailure) and (error.result is not None or error.detail):
            failure = {'stage': 'recipe-stage:' + stage}
            if error.detail:
                failure['reason'] = failure_output(error.detail)
            if error.result is not None:
                result = error.result
                failure.update(packages=error.packages, command=result.args, exit_code=result.returncode,
                               stdout=failure_output(result.stdout), stderr=failure_output(result.stderr))
            print(json.dumps({'failure': failure}, ensure_ascii=False))
        else:
            print('recipe-stage:' + stage)
        return 1
