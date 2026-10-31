// Fixed root program. Input contains rendered pool data only.
export const PhpSharedProgram = String
  .raw`"""Fixed local shared PHP pool publication. Swamp owns the native Node lock and ordering."""
import json
import os
from pathlib import Path
import re
import shutil
import stat
import subprocess
import sys
import tempfile

ROOT = Path('/')
UID = 0
GID = 0
VERSION = re.compile(r'^[1-9][0-9]*\.[0-9]+$')
POOL = re.compile(r'^orbit-(?:instance|workspace|app-instance)-[1-9][0-9]*(?:-[0-9a-f]{8})?$')
class Refusal(Exception):
    def __init__(self, code): self.code = code

def fail(stage): raise Refusal('php.shared_pool_failed')
def path(name): return ROOT / name.lstrip('/')
def metadata(name, directory=False):
    try: info = name.lstat()
    except FileNotFoundError: return None
    if info.st_uid != UID or not (stat.S_ISDIR(info.st_mode) if directory else stat.S_ISREG(info.st_mode)):
        raise Refusal('php.shared_pool_unsafe')
    return info

def parents(version):
    for name in ['/etc', '/etc/php', f'/etc/php/{version}', f'/etc/php/{version}/fpm', f'/etc/php/{version}/fpm/pool.d']:
        if metadata(path(name), True) is None: fail('directory')
def poolpath(version): return path(f'/etc/php/{version}/fpm/pool.d/orbit-scopes.conf')
def parse(text):
    if not isinstance(text, str) or len(text.encode()) > 1048576 or '\0' in text: raise Refusal('php.shared_pool_invalid')
    prefix, pools, names = '', [], set()
    for line in text.splitlines(True):
        trimmed = line.strip()
        if trimmed.startswith('['):
            name = trimmed[1:-1] if trimmed.endswith(']') else ''
            if not POOL.fullmatch(name) or name in names: raise Refusal('php.shared_pool_invalid')
            names.add(name); pools.append(dict(name=name, directory=None, text=''))
        if pools:
            if '=' in trimmed:
                key, value = map(str.strip, trimmed.split('=', 1))
                if key == 'chdir':
                    if not value.startswith('/') or any(ord(c) < 32 for c in value) or pools[-1]['directory'] is not None: raise Refusal('php.shared_pool_invalid')
                    pools[-1]['directory'] = value
            pools[-1]['text'] += line
        elif not trimmed or trimmed.startswith((';', '#')): prefix += line
        else: raise Refusal('php.shared_pool_invalid')
    return prefix, pools

def render(config, keep=lambda pool: True):
    prefix, pools = config
    selected = [p for p in pools if keep(p)]
    return '' if pools and not selected else prefix + ''.join(p['text'] for p in selected)
def present(config): return render(config, lambda p: p['directory'] is None or path(p['directory']).is_dir())
def call(argv):
    try: reply = subprocess.run(argv, stdin=subprocess.DEVNULL, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, timeout=110)
    except (OSError, subprocess.TimeoutExpired): fail('command')
    return reply.returncode

def state(action, version):
    code = call(['/usr/bin/systemctl', action, '--quiet', f'php{version}-fpm'])
    if code == 0: return True
    if code == (1 if action == 'is-enabled' else 3): return False
    fail(action)
def control(action, version):
    if call(['/usr/bin/systemctl', action, f'php{version}-fpm']) != 0: fail(action)

def validate(version, text):
    parents(version)
    live = poolpath(version)
    main = live.parent.parent / 'php-fpm.conf'
    if metadata(main) is None: fail('main')
    with tempfile.TemporaryDirectory(prefix='orbit-php-') as temporary:
        work = Path(temporary); pools = work / 'pool.d'; pools.mkdir()
        for source in live.parent.iterdir():
            if source.name == live.name or source.suffix != '.conf': continue
            if metadata(source) is None: fail('read')
            shutil.copyfile(source, pools / source.name)
        (pools / live.name).write_text(text)
        count, lines = 0, []
        expected = f'/etc/php/{version}/fpm/pool.d/*.conf'
        for line in main.read_text().splitlines(True):
            parts = list(map(str.strip, line.strip().split('=', 1)))
            if parts == ['include', expected]:
                count += 1; lines.append(f'include = {pools}/*.conf\n')
            else: lines.append(line)
        if count != 1: raise Refusal('php.shared_pool_invalid')
        candidate = work / 'php-fpm.conf'; candidate.write_text(''.join(lines))
        if call([f'/usr/sbin/php-fpm{version}', '--test', '--fpm-config', str(candidate)]) != 0: fail('validate')

def publish(version, text, previous=None):
    target = poolpath(version)
    info = metadata(target)
    if not text and previous is None:
        if info is not None: target.unlink()
        return
    descriptor, name = tempfile.mkstemp(prefix='.orbit-php-', dir=target.parent)
    try:
        with os.fdopen(descriptor, 'w') as output:
            output.write(text); output.flush(); os.fsync(output.fileno())
            os.fchmod(output.fileno(), stat.S_IMODE(previous.st_mode) if previous else 0o644)
            os.fchown(output.fileno(), previous.st_uid if previous else UID, previous.st_gid if previous else GID)
        os.replace(name, target)
    finally:
        if os.path.exists(name): os.unlink(name)

def converge(payload):
    configurations = payload['configurations']
    if not isinstance(configurations, dict): raise Refusal('php.shared_pool_invalid')
    requested, desired, allnames, missing = {}, {}, set(), []
    for version, text in sorted(configurations.items()):
        if not VERSION.fullmatch(version): raise Refusal('php.shared_pool_invalid')
        config = parse(text)
        for pool in config[1]:
            if pool['directory'] is None or pool['name'] in allnames: raise Refusal('php.shared_pool_invalid')
            allnames.add(pool['name'])
            if not path(pool['directory']).is_dir(): missing.append(dict(pool=pool['name'],version=version,directory=pool['directory'],installed=False))
        requested[version] = config; desired[version] = parse(present(config))
    if metadata(path('/etc'), True) is None: raise Refusal('php.shared_pool_unsafe')
    snapshots = {}
    if metadata(path('/etc/php'), True) is not None:
        for directory in sorted(path('/etc/php').iterdir()):
            version = directory.name
            if not VERSION.fullmatch(version): continue
            if metadata(directory, True) is None: fail('inspect')
            if metadata(directory / 'fpm', True) is None or metadata(directory / 'fpm/pool.d', True) is None: continue
            parents(version); info = metadata(poolpath(version))
            if info is not None:
                text = poolpath(version).read_text(); snapshots[version] = dict(config=parse(text),text=text,metadata=info)
    for version, config in desired.items():
        if config[1]:
            parents(version); snapshots.setdefault(version, dict(config=('', []),text=None,metadata=None))
    desired_versions = {p['name']:version for version, config in desired.items() for p in config[1]}
    moving, retiring, activating = set(), set(), set()
    for version, previous in snapshots.items():
        for pool in previous['config'][1]:
            to = desired_versions.get(pool['name'])
            if to != version:
                moving.add(pool['name']); retiring.add(version)
                if to is not None: activating.add(to)
        if version not in desired: retiring.add(version)
    publications = [(v, render(desired.get(v, ('', [])), lambda p: p['name'] not in moving)) for v in sorted(retiring)]
    publications += [(v, render(desired.get(v, ('', [])))) for v in sorted(activating)]
    publications += [(v, render(desired.get(v, ('', [])))) for v in sorted(snapshots) if v not in retiring | activating]
    for version, text in publications: validate(version, text)
    for version, previous in snapshots.items():
        previous['active'] = state('is-active', version); previous['enabled'] = state('is-enabled', version)
    touched, changed = [], False
    try:
        for version, text in publications:
            target = poolpath(version); info = metadata(target)
            same = info is None if not text else info is not None and target.read_text() == text and info.st_gid == GID and stat.S_IMODE(info.st_mode) == 0o644
            active, enabled = state('is-active', version), state('is-enabled', version)
            if same and active and enabled: continue
            touched.append(version)
            if not same: publish(version, text)
            if not enabled: control('enable', version)
            if not same or not active: control('reload-or-restart', version)
            changed = True
    except (OSError, Refusal) as error:
        recovered = True
        for version in dict.fromkeys(reversed(touched)):
            previous = snapshots[version]; text = present(previous['config']) if previous['text'] is not None else ''
            try:
                publish(version, text, previous['metadata'] if text or previous['text'] == '' else None)
                control('enable' if previous['enabled'] else 'disable', version)
                control('reload-or-restart' if previous['active'] else 'stop', version)
            except (OSError, Refusal): recovered = False
        if not recovered: raise Refusal('php.shared_pool_recovery_failed')
        raise error
    return dict(changed=changed,missing_pools=missing)

def main(payload):
    try: return converge(payload)
    except Refusal as error: return dict(error_code=error.code)
    except (OSError, ValueError, TypeError, KeyError): return dict(error_code='php.shared_pool_failed')
if __name__ == '__main__':
    print(json.dumps(main(json.load(sys.stdin))))
`;
