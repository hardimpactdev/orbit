"""Fixed, synchronous recipe operations. No manager output or caller-supplied programs escape this boundary."""
import hashlib
import json
import os
from pathlib import Path
import pwd
import re
import shutil
import signal
import subprocess
import sys
import tarfile
import tempfile
import zipfile

POLICY = Path('/usr/sbin/policy-rc.d')


class RecipeFailure(Exception):
    pass


def command_output(result):
    """Node convergence keeps manager output private; image builds override this hook for root-only logs."""
    pass


def run(argv, *, required=True, env=None):
    try:
        with subprocess.Popen(argv, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True,
                              start_new_session=True, env=env) as process:
            try:
                stdout, stderr = process.communicate(timeout=600)
            except BaseException:
                os.killpg(process.pid, signal.SIGTERM)
                try:
                    stdout, stderr = process.communicate(timeout=3)
                except subprocess.TimeoutExpired:
                    os.killpg(process.pid, signal.SIGKILL)
                    stdout, stderr = process.communicate()
                command_output(subprocess.CompletedProcess(argv, process.returncode, stdout, stderr))
                raise
            result = subprocess.CompletedProcess(argv, process.returncode, stdout, stderr)
    except FileNotFoundError:
        result = subprocess.CompletedProcess(argv, 127, '', 'Executable not found: ' + argv[0] + '\n')
    command_output(result)
    if required and result.returncode:
        raise RecipeFailure()
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
    result = run(['dpkg-query', '-W', '-f=${Package}\t${Version}\t${Status}\n'])
    return {name: version for name, version, status in
            (line.split('\t', 2) for line in result.stdout.splitlines()) if status == 'install ok installed'}


def binary_version(binary, env=None):
    result = run([str(binary), '--version'], required=False, env=env)
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
    if javascript:
        account = pwd.getpwnam(os.environ['SUDO_USER']) if os.environ.get('SUDO_USER') else pwd.getpwuid(os.getuid())
        scope = vite_scope(account)
    for name, pin in tools.items():
        if pin['source'] == 'ubuntu' or name == 'caddy' or name.startswith('php') or name == 'libgd3':
            versions[name] = installed.get(name)
        elif name == 'bun':
            versions[name] = binary_version('/opt/orbit/bun/bin/bun')
        elif name == 'vp-cli':
            binary = (scope / 'bin/vp').resolve()
            # Even --version can bootstrap an unmarked Vite+ executable. Never invoke one in a read.
            ready = binary.is_file() and (binary.parent / '.vp-setup-complete').is_file()
            versions[name] = binary_version(binary, dict(os.environ, VP_HOME=str(scope))) if ready else None
        elif name == 'vp':
            metadata = scope / 'recipe/vp-current/package.json'
            if not metadata.is_file():
                metadata = scope / 'current/node_modules/vite-plus/package.json'
            versions[name] = json_file(metadata).get('version')
        elif name == 'pnpm':
            versions[name] = json_file(scope / 'recipe/pnpm-current/package.json').get('version')
        elif name == 'node':
            # A moving default can fetch a runtime even for node --version. Inspect exact cached defaults directly.
            default = json_file(scope / 'config.json').get('defaultNodeVersion', '')
            binary = scope / 'js_runtime/node' / default / 'bin/node' if isinstance(default, str) and re.fullmatch(r'[0-9]+\.[0-9]+\.[0-9]+', default) else None
            versions[name] = binary_version(binary) if binary and binary.is_file() else None
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
    if (path.is_symlink() and (not replace_link or path.lstat().st_uid != uid)) or (not path.is_symlink() and path.exists() and (not path.is_file() or path.stat().st_uid != uid)):
        raise RecipeFailure()
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


def active_services():
    output = run(['systemctl', 'list-units', '--type=service', '--state=active', '--no-legend', '--plain']).stdout
    return {line.split()[0] for line in output.splitlines() if line.strip()}


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


def activate_vite_package(scope, package, uid, gid):
    active = scope / 'current/node_modules/vite-plus'
    if not active.is_symlink() or active.lstat().st_uid != uid:
        raise RecipeFailure()
    previous = active.resolve(strict=True)
    manifest = json_file(package / 'package.json')
    dependencies = dict(manifest.get('dependencies', {}))
    architecture = 'x64' if run(['dpkg', '--print-architecture']).stdout.strip() == 'amd64' else 'arm64'
    native = '@voidzero-dev/vite-plus-linux-' + architecture + '-gnu'
    if native in manifest.get('optionalDependencies', {}):
        dependencies[native] = manifest['optionalDependencies'][native]
    # Retain the existing dependency closure only when its exact versions satisfy this distribution.
    # Missing/new dependencies require recipe pins; never let Vite+'s installer fetch them implicitly.
    resolved = {}
    for name, constraint in dependencies.items():
        expected = constraint.rsplit('@', 1)[-1] if constraint.startswith('npm:') else constraint.lstrip('=')
        dependency = previous / 'node_modules' / name
        if not dependency.exists():
            dependency = previous.parent / name
        if not re.fullmatch(r'[0-9]+\.[0-9]+\.[0-9]+(?:-[A-Za-z0-9.-]+)?', expected) or json_file(dependency / 'package.json').get('version') != expected:
            raise RecipeFailure()
        resolved[name] = dependency.resolve(strict=True)
    modules = safe_directory(package / 'node_modules', uid, gid)
    for name, dependency in resolved.items():
        destination = modules / name
        if name.startswith('@'):
            safe_directory(destination.parent, uid, gid)
        if not destination.exists():
            destination.symlink_to(dependency, target_is_directory=True)
            os.chown(destination, uid, gid, follow_symlinks=False)
    candidate = active.parent / '.orbit-recipe-vite-plus'
    if candidate.exists() or candidate.is_symlink():
        raise RecipeFailure()
    candidate.symlink_to(package, target_is_directory=True)
    os.chown(candidate, uid, gid, follow_symlinks=False)
    os.replace(candidate, active)


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


def install_javascript(tools, downloads, work, account):
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
    for name in ('node', 'vp', 'vp-cli', 'pnpm', 'bun'):
        if name not in downloads:
            continue
        unpacked = extract(downloads[name], work / ('unpack-' + name))
        # Every directory is content-addressed; no mutable installer/channel or package resolution runs.
        identifier = hashlib.sha256(downloads[name].read_bytes()).hexdigest()
        target = runtime / (name + '-' + identifier)
        if not target.exists():
            shutil.copytree(unpacked, target, symlinks=True)
            for parent, directories, files in os.walk(target):
                os.chown(parent, uid, gid)
                for entry in directories + files:
                    os.chown(Path(parent) / entry, uid, gid, follow_symlinks=False)
        safe_directory(target, uid, gid)
        if name == 'node':
            root = next(target.iterdir())
            cache = safe_directory(scope / 'js_runtime', uid, gid)
            cache = safe_directory(cache / 'node', uid, gid)
            cached = cache / tools[name]['version']
            if cached.is_symlink():
                if cached.lstat().st_uid != uid:
                    raise RecipeFailure()
                cached.unlink()
            elif cached.exists():
                safe_directory(cached, uid, gid)
                shutil.rmtree(cached)
            cached.symlink_to(root, target_is_directory=True)
            os.chown(cached, uid, gid, follow_symlinks=False)
        elif name == 'vp-cli':
            publish(bin_dir / 'vp', (target / 'vp').read_bytes(), 0o755, uid, gid, replace_link=True)
            # Standalone Vite+ otherwise runs an unpinned installer on its first invocation.
            publish(bin_dir / '.vp-setup-complete', b'', 0o644, uid, gid)
        elif name == 'vp':
            # Retain the separately pinned JS distribution alongside the standalone dispatcher.
            if json.loads((target / 'package/package.json').read_text())['version'] != tools[name]['version']:
                raise RecipeFailure()
            activate_vite_package(scope, target / 'package', uid, gid)
            package_link(runtime, 'vp', target / 'package', uid, gid)
        elif name == 'pnpm':
            package_link(runtime, 'pnpm', target / 'package', uid, gid)
            executable = target / 'package/bin/pnpm.cjs'
            publish(bin_dir / 'pnpm', ('#!/bin/sh\nexec "' + str(bin_dir / 'node') + '" "' + str(executable) + '" "$@"\n').encode(), 0o755, uid, gid, replace_link=True)
        elif name == 'bun':
            executable = next(target.glob('*/bun'))
            os.chmod(executable, 0o755)
            safe_directory('/opt/orbit/bun', uid, gid)
            safe_directory('/opt/orbit/bun/bin', uid, gid)
            publish('/opt/orbit/bun/bin/bun', executable.read_bytes(), 0o755, uid, gid, replace_link=True)
            publish('/usr/local/bin/bun', b'#!/bin/sh\nexec /opt/orbit/bun/bin/bun "$@"\n', 0o755, replace_link=True)
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
    stage = 'preflight'
    try:
        tools = payload['tools']
        account = pwd.getpwnam(os.environ.get('SUDO_USER', 'orbit'))
        before = packages()
        versions = inspect(tools)
        active = active_services()
        policy = POLICY
        if policy.exists() or policy.is_symlink():
            raise RecipeFailure()
        js = set(tools) & {'node', 'vp', 'vp-cli', 'pnpm', 'bun'}
        if js:
            vite_scope(account)
        with tempfile.TemporaryDirectory(prefix='orbit-recipe-') as directory:
            work = Path(directory)
            stage = 'download'
            downloads = {name: download(pin, payload['architecture'], work / (name + ('.deb' if pin['artifacts'][payload['architecture']]['url'].endswith('.deb') else '.archive')))
                         for name, pin in tools.items() if pin['source'] != 'ubuntu' and versions[name] != pin['version']}
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
            snapshot = payload['snapshot']
            source = (f'Types: deb\nURIs: https://snapshot.ubuntu.com/ubuntu/{snapshot}/\n'
                      'Suites: resolute resolute-updates resolute-security\nComponents: main universe restricted multiverse\n'
                      'Signed-By: /usr/share/keyrings/ubuntu-archive-keyring.gpg\nCheck-Valid-Until: no\n')
            publish('/etc/apt/sources.list.d/ubuntu.sources', source.encode())
            # Freeze Ubuntu's normal source; keep other repositories available to existing role operations.
            # Recipe installs resolve against only the snapshot, never a third-party candidate.
            apt_options = ['-o', 'Dir::Etc::sourcelist=sources.list.d/ubuntu.sources', '-o', 'Dir::Etc::sourceparts=-']
            stage = 'packages'
            run(['apt-get', 'update', *apt_options])
            ubuntu = [name + '=' + pin['version'] for name, pin in tools.items()
                      if pin['source'] == 'ubuntu' and versions[name] != pin['version']]
            environment = dict(os.environ, DEBIAN_FRONTEND='noninteractive', NEEDRESTART_SUSPEND='1')
            # This is temporary restart suppression, not a package/configuration rollback.
            publish(policy, b'#!/bin/sh\nexit 101\n', 0o755)
            try:
                if ubuntu or debs:
                    run(['apt-get', 'install', '--yes', '--no-install-recommends', '--no-remove', '--allow-downgrades',
                         '-o', 'Dpkg::Options::=--force-confold', *apt_options, '--', *ubuntu, *debs], env=environment)
            finally:
                policy.unlink()
            stage = 'tools'
            install_javascript(tools, downloads, work, account) if js else None
            stage = 'inventory'
            after_versions = inspect(tools)
            if any(after_versions[name] != pin['version'] for name, pin in tools.items()):
                raise RecipeFailure()
            after = packages()
            changed = sorted(name for name in before.keys() | after.keys() if before.get(name) != after.get(name))
            stage = 'restart'
            restarted = services_for(changed, active)
            for service in restarted:
                stage = 'restart:' + service
                run(['systemctl', 'restart', service])
            stage = 'health'
            # Check all services active before this operation, including unchanged services; restart none here.
            for service in sorted(active):
                stage = 'health:' + service
                run(['systemctl', 'is-active', '--quiet', service])
            return {'changed_packages': changed, 'restarted_services': restarted,
                    'changed_tools': sorted(name for name in tools if versions[name] != after_versions[name]), 'healthy': True}
    except Exception as error:
        raise RecipeFailure(stage) from error


def interrupted(signum, frame):
    raise RecipeFailure('interrupted')


def main():
    signal.signal(signal.SIGTERM, interrupted)
    signal.signal(signal.SIGINT, interrupted)
    mode, document = sys.argv[1:]
    payload = json.loads(document)
    stage = 'platform'
    try:
        platform_check(payload['architecture'])
        stage = 'inventory'
        result = inspect(payload['tools']) if mode == 'inspect' else apply(payload)
        print(json.dumps(result))
        return 0
    except Exception as error:
        if isinstance(error, RecipeFailure) and str(error):
            stage = str(error)
        print('recipe-stage:' + stage)
        return 1


if __name__ == '__main__':
    sys.exit(main())
