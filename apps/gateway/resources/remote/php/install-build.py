# Program: php.install-build
# Uses: lib/program.py
# Outcomes: planned current applied platform_unsupported conflicting_source extra_packages download_failed rollback_unavailable install_failed modules_mismatch fpm_invalid rolled_back rollback_failed
# Timeout: 900
# Input: {"mode": "plan" or "apply", "versions": {"8.5": {"packages": [{"name", "version", "architecture", "url",
#   "sha256"}], "extensions": [...]}}, "mirror": base URL or "", "pcov": bool, "extras": "refuse", "keep", or "purge",
#   "fpm": "none", "shared", or "all", "repack": {"package", "version"}, "release": {"id", "codenames"} or
#   null, "root": "/" (tests only)}
# Result: planned: {"pending": [...], "extras": [...]}. current or applied: {"changed": [...], "purged": [...],
#   "restarted": [...], "deferred": [...], "stale_masters": [...]}. A refusal names what it found: {"packages": [...]}, {"sources": [...]},
#   {"package": ...}, {"missing": [...], "extra": [...]}, {"id", "codename"}, or {"unit", "reason"}. A failure after the
#   swap adds "restored": true once the previous build is back and checked; otherwise it ends with rollback_failed.
# The one installer of the recipe's PHP build (ADR 0216). Recipe convergence, the PHP step of an Instance, the task VM
# image build, and bin/php-build run it. It refuses another PHP apt source and PHP packages outside the recipe set
# before it changes anything, keeps the previous build as .deb files for a rollback, installs the recipe's files with
# --no-remove and --allow-downgrades, places PCOV, checks the loaded extensions, and restarts PHP-FPM only after
# `php-fpm -t` passes. A failed check after the swap reinstalls the previous build and restarts PHP-FPM again.
import hashlib
import os
import re
import subprocess
import tempfile
from pathlib import Path

SURY = re.compile(r'ppa\.launchpadcontent\.net/ondrej/php|packages\.sury\.org/php|ondrej/php', re.I)
RETIRED_SOURCE = 'etc/apt/sources.list.d/orbit-php.sources'
RETIRED_KEYRING = 'usr/share/keyrings/orbit-sury-php.gpg'
CACHE = 'var/cache/orbit/php-packages'
POLICY = 'usr/sbin/policy-rc.d'
FPM_MODULES = ['bcmath', 'curl', 'gd', 'imagick', 'intl', 'mbstring', 'mysqli', 'pdo_mysql', 'pdo_pgsql', 'pdo_sqlite',
               'redis', 'simplexml', 'xml', 'zip']
# Sends one FastCGI request for a script that does not exist and waits for the pool's END_REQUEST record. Any answer,
# such as "Primary script unknown", proves that the pool accepts and serves requests.
PING = r'''
import socket, struct, sys
def record(kind, content):
    return struct.pack('!BBHHBx', 1, kind, 1, len(content), 0) + content
def pair(name, value):
    return bytes([len(name), len(value)]) + name + value
params = pair(b'SCRIPT_FILENAME', b'/orbit-health-check.php') + pair(b'REQUEST_METHOD', b'GET')
connection = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
connection.settimeout(5)
connection.connect(sys.argv[1])
connection.sendall(record(1, struct.pack('!HB5x', 1, 0)) + record(4, params) + record(4, b'') + record(5, b''))
data = b''
while True:
    chunk = connection.recv(8192)
    if not chunk:
        break
    data += chunk
    offset = 0
    while offset + 8 <= len(data):
        _, kind, _, length, padding = struct.unpack('!BBHHB', data[offset:offset + 7])
        if kind == 3:
            sys.exit(0)
        offset += 8 + length + padding
sys.exit(1)
'''


class Host:
    def __init__(self, payload):
        self.root = Path(payload.get('root') or '/')
        self.elevate = [] if os.geteuid() == 0 else ['sudo', '-n']

    def path(self, relative):
        return self.root / relative

    def run(self, argv, *, privileged=False, check=True, **options):
        argv = [*self.elevate, *argv] if privileged else argv
        completed = subprocess.run(argv, capture_output=True, text=True, **options)
        if completed.stderr:
            log(completed.stderr.strip()[-2000:])
        if check and completed.returncode:
            raise RuntimeError(f'{" ".join(argv[:4])} exited {completed.returncode}')
        return completed

    def ok(self, argv, *, privileged=False):
        return self.run(argv, privileged=privileged, check=False).returncode == 0


def installed(host):
    output = host.run(['dpkg-query', '-W', '-f=${Package}\t${Status}\t${Version}\n']).stdout
    versions = {}
    for line in output.splitlines():
        fields = line.split('\t')
        if len(fields) == 3 and fields[1] == 'install ok installed':
            versions[fields[0]] = fields[2]
    return versions


def php_sources(host):
    found = []
    candidates = [host.path('etc/apt/sources.list'), *sorted(host.path('etc/apt/sources.list.d').glob('*'))]
    for source in candidates:
        if source == host.path(RETIRED_SOURCE) or not source.is_file() or source.suffix not in ('.list', '.sources', ''):
            continue
        if SURY.search(source.read_text(errors='replace')):
            found.append(str(source))
    return found


def selected(payload, architecture):
    """Per version, the pins of this Node's architecture, and every pinned package name."""
    versions = {}
    for version, build in sorted(payload['versions'].items()):
        pins = {}
        for pin in build['packages']:
            if pin['architecture'] == architecture:
                pins[pin['name']] = pin
        names = {pin['name'] for pin in build['packages']}
        if names - set(pins):
            raise Outcome('download_failed', {'package': sorted(names - set(pins))[0],
                                              'reason': f'the recipe has no {architecture} package'})
        versions[version] = {'pins': pins, 'extensions': build['extensions']}
    return versions


def extra_packages(versions, packages):
    """Installed php<version>-* packages of a handled version that are not in its recipe set."""
    extras = []
    for version, build in versions.items():
        prefix = f'php{version}-'
        extras += [name for name in packages if name.startswith(prefix) and name not in build['pins']]
    return sorted(set(extras))


def sha256(path):
    with open(path, 'rb') as stream:
        return hashlib.file_digest(stream, 'sha256').hexdigest()


def deb_fields(host, path):
    fields = host.run(['dpkg-deb', '--field', str(path), 'Package', 'Version', 'Architecture']).stdout
    return dict(line.split(': ', 1) for line in fields.splitlines() if ': ' in line)


def keep(host, file, name):
    """Copy a .deb into the cache as <name>_<sha256>.deb. Cached files are never deleted: they are the rollback."""
    target = host.path(CACHE) / f'{name}_{sha256(file)}.deb'
    if not target.is_file():
        host.run(['install', '-o', 'root', '-g', 'root', '-m', '0644', str(file), str(target)], privileged=True)
    return target


def download(host, pin, mirror, work, architecture):
    """The pinned file from the cache, the Orbit mirror, or the pin's URL, checked before use."""
    cached = host.path(CACHE) / f'{pin["name"]}_{pin["sha256"]}.deb'
    if cached.is_file() and sha256(cached) == pin['sha256']:
        return cached
    candidate = work / 'candidate.deb'
    sources = ([f'{mirror.rstrip("/")}/{pin["name"]}_{pin["sha256"]}.deb'] if mirror else []) + [pin['url']]
    for url in sources:
        candidate.unlink(missing_ok=True)
        if not url.startswith('https://'):
            continue
        if not host.ok(['curl', '--fail', '--silent', '--show-error', '--location', '--proto', '=https',
                        '--proto-redir', '=https', '--tlsv1.2', '--output', str(candidate), '--', url]):
            continue
        if sha256(candidate) != pin['sha256']:
            log(f'{url} does not match its recipe SHA-256 digest')
            continue
        fields = deb_fields(host, candidate)
        if fields.get('Package') != pin['name'] or fields.get('Version') != pin['version'] \
                or fields.get('Architecture') not in (architecture, 'all'):
            log(f'{url} does not carry the name, version, and architecture of its pin')
            continue
        return keep(host, candidate, pin['name'])
    raise Outcome('download_failed', {'package': pin['name'],
                                      'reason': 'no source served the pinned file; Sury may have a newer build'})


def previous_files(host, names, packages, repack, work):
    """A .deb of each installed version that the change replaces or removes, so a rollback can reinstall it."""
    cache = host.path(CACHE)
    files = {}
    for name in names:
        version = packages[name]
        for cached in sorted(cache.glob(f'{name}_*.deb')):
            if deb_fields(host, cached).get('Version') == version:
                files[name] = cached
                break
        if name in files:
            continue
        if 'dpkg-repack' not in packages and not host.ok(
                ['env', 'DEBIAN_FRONTEND=noninteractive', 'apt-get', '-o', 'DPkg::Lock::Timeout=300', 'install', '--yes',
                 '--no-install-recommends', '--no-remove', f'{repack["package"]}={repack["version"]}'], privileged=True):
            raise Outcome('rollback_unavailable', {'package': name, 'reason': 'dpkg-repack could not be installed'})
        packages['dpkg-repack'] = repack['version']
        directory = Path(tempfile.mkdtemp(dir=work))
        os.chmod(directory, 0o777)
        if host.run(['dpkg-repack', '--', name], privileged=True, check=False, cwd=directory).returncode:
            raise Outcome('rollback_unavailable', {'package': name, 'reason': 'dpkg-repack failed'})
        repacked = sorted(directory.glob(f'{name}_*.deb'))
        if len(repacked) != 1:
            raise Outcome('rollback_unavailable', {'package': name, 'reason': 'dpkg-repack wrote no file'})
        files[name] = keep(host, repacked[0], name)
    return files


def apt_install(host, files):
    """Install .deb files without removing any package, and keep package hooks from starting services."""
    policy = host.path(POLICY)
    owned = not (policy.exists() or policy.is_symlink())
    if owned:
        host.run(['tee', '--', str(policy)], privileged=True, input='#!/bin/sh\nexit 101\n')
        host.run(['chmod', '0755', '--', str(policy)], privileged=True)
    try:
        return host.ok(['env', 'DEBIAN_FRONTEND=noninteractive', 'NEEDRESTART_SUSPEND=1', 'apt-get', '-o',
                        'DPkg::Lock::Timeout=300', '-o', 'Dpkg::Options::=--force-confold', 'install', '--yes',
                        '--no-install-recommends', '--no-remove', '--allow-downgrades', '--', *map(str, files)],
                       privileged=True)
    finally:
        if owned:
            host.run(['rm', '-f', '--', str(policy)], privileged=True)


def links(host, version):
    """The module links of the CLI and PHP-FPM of one version: SAPI to {file name: target}."""
    found = {}
    for sapi in ('cli', 'fpm'):
        directory = host.path(f'etc/php/{version}/{sapi}/conf.d')
        found[sapi] = {path.name: os.readlink(path) for path in directory.glob('*.ini') if path.is_symlink()} \
            if directory.is_dir() else {}
    return found


def restore_links(host, version, recorded):
    for sapi, wanted in recorded.items():
        directory = host.path(f'etc/php/{version}/{sapi}/conf.d')
        current = links(host, version)[sapi]
        for name, target in current.items():
            if wanted.get(name) != target:
                host.run(['rm', '-f', '--', str(directory / name)], privileged=True)
        for name, target in wanted.items():
            if current.get(name) != target:
                host.run(['ln', '-sfn', '--', target, str(directory / name)], privileged=True)


def place_pcov(host, version, pcov):
    """PCOV loads in the CLI of app-dev and CI machines only, and never in PHP-FPM."""
    host.run(['phpenmod' if pcov else 'phpdismod', '-v', version, '-s', 'cli', 'pcov'], privileged=True)
    host.run(['phpdismod', '-v', version, '-s', 'fpm', 'pcov'], privileged=True)


def modules(host, binary):
    output = host.run([binary, '-m'], check=False).stdout
    return sorted({line.strip().lower() for line in output.splitlines() if line.strip() and not line.startswith('[')})


def check_cli(host, version, extensions, pcov, extras):
    expected = sorted(set(extensions) - (set() if pcov else {'pcov'}))
    loaded = modules(host, str(host.path(f'usr/bin/php{version}')))
    missing = sorted(set(expected) - set(loaded))
    extra = sorted(set(loaded) - set(expected)) if extras != 'keep' else []
    if missing or extra:
        log(f'PHP {version} CLI modules differ from the recipe. Missing: {missing}. Extra: {extra}.')
        raise Outcome('modules_mismatch', {'version': version, 'missing': missing, 'extra': extra})


def pool_sockets(host, configuration):
    """The Unix sockets that the pools of one PHP-FPM master listen on, following its include lines."""
    sockets, seen, pending = [], set(), [Path(configuration)]
    while pending:
        file = pending.pop()
        if file in seen or not file.is_file():
            continue
        seen.add(file)
        for line in file.read_text(errors='replace').splitlines():
            key, _, value = line.partition('=')
            key, value = key.strip(), value.split(';', 1)[0].strip()
            if key == 'include':
                pending += sorted(host.root.glob(value.lstrip('/')) if value.startswith('/') else file.parent.glob(value))
            elif key == 'listen' and value.startswith('/'):
                sockets.append(str(host.path(value.lstrip('/'))))
    return sorted(set(sockets))


def fpm_units(host, version, scope):
    """The active PHP-FPM masters of one version: the shared service and, for `all`, each dedicated master."""
    units = []
    shared = f'php{version}-fpm.service'
    if host.ok(['systemctl', 'is-active', '--quiet', shared]):
        units.append((shared, str(host.path(f'etc/php/{version}/fpm/php-fpm.conf'))))
    listed = host.run(['systemctl', 'list-units', '--type=service', '--state=active', '--no-legend', '--plain',
                       f'orbit-*-php{version}-fpm.service'], check=False).stdout
    for line in listed.splitlines():
        unit = line.split()[0] if line.split() else ''
        if not re.fullmatch(rf'orbit-[a-z0-9_-]+-php{re.escape(version)}-fpm\.service', unit):
            continue
        start = host.run(['systemctl', 'show', '-p', 'ExecStart', '--value', unit], check=False).stdout
        match = re.search(r'--fpm-config\s+(\S+)', start)
        units.append((unit, str(host.path(match.group(1).lstrip('/'))) if match else ''))
    if scope == 'shared':
        return [unit for unit in units if unit[0] == shared], [unit[0] for unit in units if unit[0] != shared]
    return units, []


def restart(host, version, unit, configuration):
    """Validate, restart, and check one master. Returns the reason it failed, or None."""
    binary = str(host.path(f'usr/sbin/php-fpm{version}'))
    test = [binary, '-t'] + (['--fpm-config', configuration] if not unit.startswith('php') else [])
    if not host.ok(test, privileged=True):
        return 'fpm_invalid'
    if not host.ok(['systemctl', 'restart', unit], privileged=True) \
            or not host.ok(['systemctl', 'is-active', '--quiet', unit]):
        return 'the service did not start'
    loaded = modules(host, binary)
    if 'pcov' in loaded or set(FPM_MODULES) - set(loaded):
        return 'PHP-FPM loads PCOV or lacks a required extension'
    for socket in pool_sockets(host, configuration):
        if not host.ok(['python3', '-I', '-c', PING, socket], privileged=True):
            return f'the pool at {socket} does not answer FastCGI'
    return None


def os_release(host):
    """ID and VERSION_CODENAME of /etc/os-release, read as data and never evaluated."""
    values = {}
    path = host.path('etc/os-release')
    for line in (path.read_text(errors='replace').splitlines() if path.is_file() else []):
        key, _, value = line.partition('=')
        if key in ('ID', 'VERSION_CODENAME'):
            value = value.strip()
            if len(value) >= 2 and value[0] == value[-1] and value[0] in '"\'':
                value = value[1:-1]
            values[key] = value if re.fullmatch(r'[A-Za-z0-9._-]+', value) else ''
    return values.get('ID', ''), values.get('VERSION_CODENAME', '')


def main(payload):
    host = Host(payload)
    release = payload.get('release')
    if release:
        system, codename = os_release(host)
        if system != release['id'] or codename not in release['codenames']:
            raise Outcome('platform_unsupported', {'id': system, 'codename': codename})
    architecture = host.run(['dpkg', '--print-architecture']).stdout.strip()
    versions = selected(payload, architecture)
    extras_policy = payload['extras']
    sources = php_sources(host)
    if sources and extras_policy != 'purge':
        log('A conflicting PHP package source is configured: ' + ', '.join(sources))
        raise Outcome('conflicting_source', {'sources': sources})
    packages = installed(host)
    extras = extra_packages(versions, packages)
    if extras and extras_policy == 'refuse':
        log('PHP packages outside the recipe are installed: ' + ', '.join(extras) + '. Remove them, then retry.')
        raise Outcome('extra_packages', {'packages': extras})
    purge = extras if extras_policy == 'purge' else []
    pins = {name: pin for build in versions.values() for name, pin in build['pins'].items()}
    pending = sorted(name for name, pin in pins.items() if packages.get(name) != pin['version'])
    if payload['mode'] == 'plan':
        return 'planned', {'pending': pending, 'extras': extras}

    # Orbit installs PHP from the recipe's files. These sources would let apt move PHP to another build.
    for path in [host.path(RETIRED_SOURCE), host.path(RETIRED_KEYRING), *map(Path, sources)]:
        if path.exists() or path.is_symlink():
            host.run(['rm', '-f', '--', str(path)], privileged=True)
    host.run(['install', '-d', '-o', 'root', '-g', 'root', '-m', '0755', str(host.path(CACHE))], privileged=True)
    before = {version: links(host, version) for version in versions}
    with tempfile.TemporaryDirectory(prefix='orbit-php-build-') as directory:
        work = Path(directory)
        files = [download(host, pins[name], payload.get('mirror', ''), work, architecture) for name in pending]
        replaced = [name for name in pending if name in packages] + purge
        previous = previous_files(host, replaced, packages, payload['repack'], work)

    # Packages that the change adds: a rollback removes them first, because the core Sury extensions of the previous
    # build depend on its exact php<version>-common.
    added = sorted(name for name in pending if name not in packages)

    def roll_back(reason, restarted):
        """Put the previous build back and check it. Returns True only when every package is at its old version."""
        log(f'Rolling back the PHP build: {reason}')
        ok = True
        present = [name for name in added if name in installed(host)]
        if present:
            ok = host.ok(['dpkg', '--remove', '--', *present], privileged=True) and ok
        if previous:
            ok = apt_install(host, list(previous.values())) and ok
        restored = installed(host)
        wrong = sorted(name for name in replaced if restored.get(name) != packages[name])
        wrong += sorted(name for name in added if name in restored)
        if wrong:
            log('The previous PHP build is not restored: ' + ', '.join(wrong))
            ok = False
        for version, recorded in before.items():
            restore_links(host, version, recorded)
        for unit in restarted:
            ok = host.ok(['systemctl', 'restart', unit], privileged=True) and ok
            ok = host.ok(['systemctl', 'is-active', '--quiet', unit]) and ok
        return ok

    def fail(outcome, data, reason, restarted):
        """Roll back and end with `outcome` and restored: true, or with rollback_failed when the rollback failed."""
        if not roll_back(reason, restarted):
            raise Outcome('rollback_failed', {**data, 'reason': f'{reason}; the rollback failed too'})
        raise Outcome(outcome, {**data, 'reason': data.get('reason', reason), 'restored': True})

    if purge and not host.ok(['dpkg', '--purge', '--', *purge], privileged=True):
        fail('install_failed', {'package': purge[0]}, 'dpkg --purge failed', [])
    if files and not apt_install(host, files):
        fail('install_failed', {'package': pending[0]}, 'apt-get install failed', [])
    current = installed(host)
    wrong = sorted(name for name, pin in pins.items() if current.get(name) != pin['version'])
    if wrong:
        fail('install_failed', {'package': wrong[0]}, 'not at its recipe version after install', [])

    try:
        for version, build in versions.items():
            place_pcov(host, version, payload['pcov'])
            check_cli(host, version, build['extensions'], payload['pcov'], extras_policy)
    except Outcome as refused:
        fail(refused.outcome, refused.data, refused.outcome, [])
    restarted, stale, deferred = [], [], []
    try:
        for version, build in versions.items():
            place_pcov(host, version, payload['pcov'])
            check_cli(host, version, build['extensions'], payload['pcov'], extras_policy)
    except Outcome as refused:
        roll_back(refused.outcome, [])
        raise
    changed = bool(pending or purge) or any(links(host, version) != before[version] for version in versions)
    if changed and payload['fpm'] != 'none':
        for version in versions:
            units, skipped = fpm_units(host, version, payload['fpm'])
            stale += skipped
            for unit, configuration in units:
                reason = restart(host, version, unit, configuration)
                if reason == 'fpm_invalid' and payload['fpm'] == 'shared':
                    # An Instance step leaves a running service alone when its pools fail validation, as before a
                    # change: the pool publication that follows removes a stale pool and reloads the service.
                    deferred.append(unit)
                    continue
                if reason != 'fpm_invalid':
                    restarted.append(unit)
                if reason is None:
                    continue
                fail('fpm_invalid' if reason == 'fpm_invalid' else 'rolled_back', {'unit': unit, 'reason': reason},
                     f'{unit}: {reason}', restarted)
    result = {'changed': pending, 'purged': purge, 'restarted': restarted, 'deferred': deferred,
              'stale_masters': sorted(stale)}
    return ('applied' if changed else 'current'), result


run_program(main)
