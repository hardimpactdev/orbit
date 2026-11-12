"""Private Instance Node stores and Vite+-owned resolution/downloads. No Process actions."""
import ctypes
import grp
import json
import os
import pwd
import re
import shlex
import shutil
import subprocess
import sys
import tempfile
import time
from pathlib import Path

HOME = Path('/var/lib/orbit/vite-plus-home')
SHARED = Path('/opt/orbit-runtime/current/vite-plus-home')
VP = '/opt/orbit-runtime/current/bin/vp'
CODE = 'instance.node_runtime_prepare_failed'


def versions(home):
    return sorted(p.name for p in (Path(home) / 'js_runtime/node').glob('*')
                  if re.fullmatch(r'[0-9]+[.][0-9]+[.][0-9]+', p.name) and (p / 'bin/node').is_file())


def copy_tree(source, target):
    subprocess.run(['cp', '-a', '--reflink=auto', '--no-preserve=ownership', str(source), str(target)], check=True,
                   stdout=subprocess.DEVNULL, stderr=subprocess.PIPE, timeout=30)


def prepare(home=HOME, shared=SHARED, owner=None):
    """Copy verified recipe bytes once; preserve every previously downloaded version."""
    home, shared = Path(home), Path(shared)
    home.mkdir(parents=True, exist_ok=True)
    store = home / 'js_runtime'
    changed = False
    if store.is_symlink():
        stage = Path(tempfile.mkdtemp(prefix='.node-store-', dir=home))
        stage.rmdir()
        try:
            copy_tree(store.resolve(), stage)
            # Linux atomically exchanges the complete directory and old link. Open executables stay open.
            libc = ctypes.CDLL(None, use_errno=True)
            if libc.renameat2(-100, os.fsencode(stage), -100, os.fsencode(store), 2) != 0:
                errno = ctypes.get_errno()
                raise OSError(errno, os.strerror(errno))
            stage.unlink()
            changed = True
        finally:
            if stage.is_symlink():
                stage.unlink()
            elif stage.exists():
                shutil.rmtree(stage)
    if not store.exists():
        store.mkdir(mode=0o2770)
        changed = True
    node = store / 'node'
    node.mkdir(mode=0o2770, exist_ok=True)
    for version in versions(shared):
        if (node / version / 'bin/node').is_file():
            continue
        stage = Path(tempfile.mkdtemp(prefix='.node-copy-', dir=store))
        try:
            copy_tree(shared / 'js_runtime/node' / version, stage / version)
            try:
                (stage / version).rename(node / version)
                changed = True
            except FileExistsError:
                if not (node / version / 'bin/node').is_file():
                    raise RuntimeError('A Node installation is incomplete; repeat runtime preparation.')
        finally:
            shutil.rmtree(stage)
    index = shared / 'js_runtime/node/index_cache.json'
    if index.is_file() and not (node / index.name).exists():
        shutil.copy2(index, node / index.name)
        changed = True
    if owner is not None and changed:
        uid, gid = owner
        for directory, children, files in os.walk(store):
            os.chown(directory, uid, gid)
            os.chmod(directory, 0o2770)
            for name in files:
                path = Path(directory) / name
                if not path.is_symlink():
                    os.chown(path, uid, gid)
                    os.chmod(path, path.stat().st_mode | 0o660)
    return changed


def unit_contexts(container):
    contexts = []
    for process in container.get('processes', []):
        content = process['content']
        cwd = next((x.split('=', 1)[1] for x in content.splitlines() if x.startswith('WorkingDirectory=')), container['workspace'])
        cwd_words = shlex.split(cwd)
        contexts.append({'directory': cwd_words[0] if len(cwd_words) == 1 else cwd,
                         'unit': process.get('name'), 'content': content})
    if not contexts:
        contexts.append({'directory': container['workspace'], 'content': ''})
    return contexts


def pin_candidates(directory, workspace):
    """Diagnostic only: collect candidates; Vite+ alone chooses precedence and meaning."""
    result = []
    directory, workspace = Path(directory), Path(workspace)
    while directory.is_relative_to(workspace):
        for name in ('.node-version', '.nvmrc'):
            path = directory / name
            if path.is_file():
                result.append((str(path.relative_to(workspace)), path.read_text().strip()[:200]))
        path = directory / 'package.json'
        if path.is_file():
            try:
                package = json.loads(path.read_text())
                if package.get('engines', {}).get('node'):
                    result.append((str(path.relative_to(workspace)) + ':engines.node', str(package['engines']['node'])))
                runtime = package.get('devEngines', {}).get('runtime', [])
                for entry in runtime if isinstance(runtime, list) else [runtime]:
                    if isinstance(entry, dict) and entry.get('name') == 'node':
                        result.append((str(path.relative_to(workspace)) + ':devEngines.runtime', str(entry.get('version', ''))))
            except (ValueError, AttributeError):
                result.append((str(path.relative_to(workspace)), '[unreadable manifest]'))
        if directory == workspace:
            break
        directory = directory.parent
    return result


def node_environment(context, base, private_environments):
    environment = dict(base)
    # Read systemd's environment files as root; only the Node override is needed by this tool.
    for line in context.get('content', '').splitlines():
        if line.startswith('EnvironmentFile='):
            for name in shlex.split(line.split('=', 1)[1]):
                path = Path(name.removeprefix('-'))
                contents = private_environments.get(str(path))
                if contents is None and path.is_file():
                    contents = path.read_text()
                if contents is not None:
                    for entry in contents.splitlines():
                        if entry.startswith('VP_NODE_VERSION='):
                            value = shlex.split(entry.split('=', 1)[1])
                            environment['VP_NODE_VERSION'] = value[0] if value else ''
        if line.startswith('Environment='):
            for entry in shlex.split(line.split('=', 1)[1]):
                key, _, value = entry.partition('=')
                if key == 'VP_NODE_VERSION':
                    environment[key] = value
    return environment


def fetch(container, home=HOME, vp=VP, app_user='orbit', private_environments=None):
    home = Path(home)
    account = pwd.getpwnam(app_user)
    base = dict(os.environ, HOME=account.pw_dir, VP_HOME=str(home), VP_NO_UPDATE_CHECK='1', TMPDIR=str(home / 'cache'))
    for key in ('VP_BIN_DIR', 'VP_DATA_DIR', 'VP_CACHE_DIR', 'VP_CONFIG_DIR'):
        base.pop(key, None)
    (home / 'cache').mkdir(exist_ok=True)
    identity = {'user': account.pw_uid, 'group': account.pw_gid,
                'extra_groups': os.getgrouplist(app_user, account.pw_gid)} if os.geteuid() == 0 else {}
    pins = []
    changed = False
    deadline = time.monotonic() + 90
    for context in unit_contexts(container):
        environment = node_environment(context, base, private_environments or {})
        candidates = pin_candidates(context['directory'], container['workspace'])
        if environment.get('VP_NODE_VERSION'):
            candidates.insert(0, ('VP_NODE_VERSION', environment['VP_NODE_VERSION']))
        label = ', '.join(source + ' pins Node ' + value for source, value in candidates) or 'the runtime default'
        def invoke(arguments, seconds, context=context, environment=environment, label=label):
            result = subprocess.run([str(vp), *arguments], cwd=context['directory'], env=environment,
                                    capture_output=True, text=True, check=False, timeout=max(0.001, min(seconds, deadline - time.monotonic())), umask=0o002, **identity)
            if result.returncode:
                text = result.stderr.lower()
                reason = ('checksum verification failed' if 'checksum' in text or 'hash mismatch' in text else
                          'signature verification failed' if 'signature' in text else
                          'Node version could not be resolved' if 'no matching' in text or 'invalid version' in text else
                          'Vite+ could not download or prepare Node')
                raise RuntimeError(label + ': ' + reason)
            return result.stdout
        try:
            node = json.loads(invoke(['env', 'current', 'node', '--json'], 30))['node']
            binary = Path(node['bin_path'])
            if not binary.is_relative_to(home / 'js_runtime'):
                raise RuntimeError(label + ': Vite+ selected a Node outside the private store')
            if not binary.is_file():
                invoke(['env', 'install', node['version']], 60)
                if not binary.is_file():
                    raise RuntimeError(label + ': Vite+ did not provide the resolved Node executable')
                changed = True
            if node.get('source') != 'default':
                source = node.get('source_path', node['source'])
                if node.get('source_path') and Path(source).is_relative_to(container['workspace']):
                    source = str(Path(source).relative_to(container['workspace']))
                    if node['source'] in ('engines.node', 'devEngines.runtime'):
                        source += ':' + node['source']
                pinned = next((value for key, value in candidates if key == source), node['version'])
                pins.append({'pin_source': source, 'pinned': pinned,
                             'version': node['version'], 'directory': context['directory']})
        except subprocess.TimeoutExpired as error:
            raise RuntimeError(label + ': Node preparation timed out') from error
    return changed, pins


def main():
    action = sys.argv[1]
    container = json.loads(sys.argv[2])
    try:
        owner = (pwd.getpwnam('agent').pw_uid, grp.getgrnam('work').gr_gid) if os.geteuid() == 0 else None
        changed = prepare(owner=owner)
        pins = []
        if action == 'fetch':
            fetched, pins = fetch(container, private_environments=json.loads(sys.stdin.read() or '{}'))
            changed = changed or fetched
        print(json.dumps({'status': 'converted' if changed else 'unchanged', 'versions': versions(HOME), 'pins': pins}))
    except (OSError, ValueError, RuntimeError, subprocess.SubprocessError) as error:
        print(json.dumps({'error_code': CODE, 'error_message': str(error)[:1000]}))
        sys.exit(1)


if __name__ == '__main__':
    main()
