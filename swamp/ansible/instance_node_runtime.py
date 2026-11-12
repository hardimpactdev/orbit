"""Private Instance Node stores and Vite+-owned resolution/downloads. No Process actions."""
import ctypes
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


def private_store(home):
    store = Path(home) / 'js_runtime'
    return not store.is_symlink() and store.is_dir() and not (store / 'node').is_symlink() and (store / 'node').is_dir()


def prepare(home=HOME, shared=SHARED):
    """Launch only, as the app user. Copy the recipe once; retain private downloads."""
    home, shared = Path(home), Path(shared)
    store = home / 'js_runtime'
    home.mkdir(parents=True, exist_ok=True)
    stage = None
    changed = False
    if store.is_symlink():
        # A snapshot may contain app-controlled links. Never traverse the inherited store.
        stage = Path(tempfile.mkdtemp(prefix='.node-store-', dir=home))
        stage.chmod(0o2770)
    elif store.exists() and not private_store(home):
        raise RuntimeError('the private Node store must contain a real js_runtime/node directory')
    target = stage if stage is not None else store
    try:
        if not target.exists():
            target.mkdir(mode=0o2770)
            changed = True
        node = target / 'node'
        node.mkdir(mode=0o2770, exist_ok=True)
        for version in versions(shared):
            path = node / version
            if path.is_symlink():
                path.unlink()
            if not path.exists():
                shutil.copytree(shared / 'js_runtime/node' / version, path, symlinks=True)
                changed = True
        if stage is not None:
            libc = ctypes.CDLL(None, use_errno=True)
            if libc.renameat2(-100, os.fsencode(stage), -100, os.fsencode(store), 2) != 0:
                errno = ctypes.get_errno()
                raise OSError(errno, os.strerror(errno))
            stage.unlink()
            changed = True
    finally:
        if stage is not None:
            if stage.is_symlink():
                stage.unlink()
            elif stage.exists():
                shutil.rmtree(stage)
    return changed


def unit_contexts(container):
    contexts = []
    for process in container.get('processes', []):
        content = process['content']
        commands = [shlex.split(line.split('=', 1)[1]) for line in content.splitlines() if line.startswith('ExecStart=')]
        consumers = {'vp', 'node', 'npm', 'npx', 'pnpm', 'yarn'}
        def executable(command):
            words = iter(command)
            first = next(words, '')
            if Path(first).name == 'env':
                for word in words:
                    if word in ('-u', '--unset'):
                        next(words, '')
                    elif not word.startswith('-') and '=' not in word:
                        return Path(word).name
                return ''
            return Path(first.removeprefix('-')).name
        if not any(executable(command) in consumers for command in commands):
            continue
        cwd = next((x.split('=', 1)[1] for x in content.splitlines() if x.startswith('WorkingDirectory=')), container['workspace'])
        cwd_words = shlex.split(cwd)
        contexts.append({'directory': cwd_words[0] if len(cwd_words) == 1 else cwd,
                         'unit': process.get('name'), 'content': content})
    return contexts


def tool_failure(stderr, status):
    """Closed, public diagnostics: never return raw tool output, URLs or environment values."""
    text = stderr.lower()
    if 'checksum' in text or 'hash mismatch' in text or 'shasums' in text:
        return 'checksum verification failed'
    if 'signature' in text:
        return 'signature verification failed'
    if 'no space left' in text or 'os error 28' in text or 'disk quota' in text:
        return 'not enough disk space for Node'
    if 'read-only file system' in text or 'os error 30' in text:
        return 'the runtime store is read-only'
    if 'permission denied' in text or 'os error 13' in text:
        return 'permission denied while writing the runtime store'
    if 'invalid' in text and any(word in text for word in ('version', 'node', 'lts', 'alias')):
        return 'invalid Node version'
    if any(word in text for word in ('no matching', 'no version matching', 'no version found', 'unknown version', 'unknown lts', 'version not found')) or re.search(r'version[^\n]*not found', text):
        return 'unknown Node version'
    http = re.search(r'(?:http[^\n]*?|status[^\n]*?)\b([45][0-9]{2})\b', text)
    if http:
        return 'Node download failed with HTTP ' + http[1]
    if any(word in text for word in ('network', 'dns', 'resolve host', 'connection', 'connect error', 'timed out', 'timeout', 'proxy', 'request', 'tls', 'handshake', 'certificate')):
        return 'network failure while resolving or downloading Node'
    return 'Vite+ exited with status ' + str(status) + ' while preparing Node'


def pin_candidates(directory, workspace):
    """Diagnostic only: collect candidates; Vite+ alone chooses precedence and meaning."""
    result = []
    directory, workspace = Path(directory), Path(workspace)
    while directory.is_relative_to(workspace):
        for name in ('.node-version', '.nvmrc'):
            path = directory / name
            if path.is_file():
                value = path.read_text().strip()
                if name == '.nvmrc':
                    declarations = [line.split('#', 1)[0].strip() for line in value.splitlines()
                                    if line.split('#', 1)[0].strip() and '=' not in line.split('#', 1)[0]]
                    if not declarations:
                        continue
                if value:
                    result.append((str(path.relative_to(workspace)), value[:200]))
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
    # Root-only environment files arrive through private standard input.
    for line in context.get('content', '').splitlines():
        if line.startswith('EnvironmentFile='):
            for name in shlex.split(line.split('=', 1)[1]):
                path = Path(name.removeprefix('-'))
                contents = private_environments.get(str(path))
                if contents is None and path.is_file() and os.access(path, os.R_OK):
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
    contexts = unit_contexts(container)
    if not contexts:
        return False, []
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
    for context in contexts:
        environment = node_environment(context, base, private_environments or {})
        candidates = pin_candidates(context['directory'], container['workspace'])
        if environment.get('VP_NODE_VERSION'):
            candidates.insert(0, ('VP_NODE_VERSION', environment['VP_NODE_VERSION']))
        label = ', '.join(source + ' pins Node ' + value.replace(chr(10), r'\n') for source, value in candidates) or 'the runtime default'
        def invoke(arguments, seconds, context=context, environment=environment, label=label, directory=None):
            result = subprocess.run([str(vp), *arguments], cwd=directory or context['directory'], env=environment,
                                    capture_output=True, text=True, check=False, timeout=max(0.001, min(seconds, deadline - time.monotonic())), umask=0o002, **identity)
            diagnostic = result.stderr.lower()
            if result.returncode or ('invalid' in diagnostic and ('version' in diagnostic or 'node' in diagnostic)):
                raise RuntimeError(label + ': ' + tool_failure(result.stderr, result.returncode))
            return result.stdout
        try:
            node = json.loads(invoke(['env', 'current', 'node', '--json'], 30))['node']
            selected = node.get('source_path', node.get('source')) if node.get('source') != 'default' else None
            if selected and node.get('source_path') and Path(selected).is_relative_to(container['workspace']):
                selected = str(Path(selected).relative_to(container['workspace']))
                if node['source'] in ('engines.node', 'devEngines.runtime'):
                    selected += ':' + node['source']
            # Ask Vite+ about each unselected candidate in isolation. A malformed child
            # must not fall back to a valid parent; valid lower-priority pins remain valid.
            for source, value in candidates:
                if source == 'VP_NODE_VERSION' or source == selected:
                    continue
                isolated_environment = dict(environment)
                isolated_environment.pop('VP_NODE_VERSION', None)
                with tempfile.TemporaryDirectory(prefix='pin-check-', dir=home / 'cache') as scratch:
                    scratch = Path(scratch)
                    if source.endswith(':engines.node'):
                        (scratch / 'package.json').write_text(json.dumps({'engines': {'node': value}}))
                    elif source.endswith(':devEngines.runtime'):
                        (scratch / 'package.json').write_text(json.dumps({'devEngines': {'runtime': {'name': 'node', 'version': value}}}))
                    elif Path(source).name in ('.nvmrc', '.node-version'):
                        (scratch / Path(source).name).write_text(value)
                    else:
                        raise RuntimeError(source + ': unreadable Node pin manifest')
                    checked = json.loads(invoke(['env', 'current', 'node', '--json'], 30,
                        environment=isolated_environment, label=source + ' pins Node ' + value.replace(chr(10), r'\n'),
                        directory=str(scratch)))['node']
                    if checked.get('source') == 'default' or not Path(checked.get('source_path', '/')).is_relative_to(scratch):
                        reason = 'Vite+ ignored the requested pin as invalid or unsupported'
                        if source.endswith('.nvmrc') and len([line for line in value.splitlines()
                                if line.split('#', 1)[0].strip() and '=' not in line.split('#', 1)[0]]) > 1:
                            reason = 'ambiguous .nvmrc: more than one version declaration'
                        raise RuntimeError(source + ' pins Node ' + value.replace(chr(10), r'\n') + ': ' + reason)
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
        os.umask(0o002)
        if os.geteuid() == 0:
            raise RuntimeError('Node store preparation must run as the app user')
        if action not in ('prepare', 'fetch', 'launch-fetch'):
            raise RuntimeError('unknown Node preparation action')
        if action == 'fetch' and not private_store(HOME):
            raise RuntimeError('fetch requires an existing private Node store; legacy stores are not converted')
        changed = prepare() if action in ('prepare', 'launch-fetch') else False
        pins = []
        if action in ('fetch', 'launch-fetch'):
            fetched, pins = fetch(container, private_environments=json.loads(sys.stdin.read() or '{}'))
            changed = changed or fetched
        print(json.dumps({'status': 'converted' if changed else 'unchanged', 'versions': versions(HOME), 'pins': pins}))
    except (OSError, ValueError, RuntimeError, subprocess.SubprocessError) as error:
        print(json.dumps({'error_code': CODE, 'error_message': str(error)[:1000]}))
        sys.exit(1)


if __name__ == '__main__':
    main()
