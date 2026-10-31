/** Fixed VM-local implementation. Repository code runs in a transient systemd cgroup. */
export const ScriptProgram = String.raw`
import hashlib, json, os, pathlib, pwd, re, selectors, shutil, signal, stat, subprocess, sys, tempfile, time

class Refusal(Exception):
    pass

class Cancelled(Exception):
    pass

PROCESS = None
UNIT = None
WINDOW = 102400

def git(root, *args, env=None):
    return subprocess.check_output(['git', '-c', 'core.hooksPath=/dev/null', '-c', 'core.fsmonitor=false', '-C', root, *args], env=env, stderr=subprocess.DEVNULL).decode().strip()

def snapshot(root):
    index = git(root, 'rev-parse', '--path-format=absolute', '--git-path', 'index')
    with tempfile.TemporaryDirectory(prefix='orbit-task-index-') as scratch:
        candidate = os.path.join(scratch, 'index')
        if os.path.exists(index):
            shutil.copy2(index, candidate)
        env = {**os.environ, 'GIT_INDEX_FILE': candidate}
        git(root, 'add', '--all', env=env)
        return {'head': git(root, 'rev-parse', 'HEAD'), 'tree': git(root, 'write-tree', env=env)}

def executable(root, relative):
    if not isinstance(relative, str) or not re.fullmatch(r'[A-Za-z0-9_-]+(?:/[A-Za-z0-9_.-]+)*', relative) or any(part in ('.', '..') for part in relative.split('/')):
        raise Refusal('tasks.script_unavailable')
    path = os.path.join(root, relative)
    try:
        details = os.lstat(path)
        if not stat.S_ISREG(details.st_mode) or not details.st_mode & 0o111 or os.path.realpath(path) != path:
            raise Refusal('tasks.script_unavailable')
        content = pathlib.Path(path).read_bytes()
        tracked = subprocess.check_output(['git', '-C', root, 'show', 'HEAD:' + relative], stderr=subprocess.DEVNULL)
        if content != tracked or git(root, 'ls-tree', 'HEAD', '--', relative).split()[0] != '100755':
            raise Refusal('tasks.script_unavailable')
        return hashlib.sha256(content).hexdigest()
    except (OSError, subprocess.SubprocessError, IndexError):
        raise Refusal('tasks.script_unavailable') from None

def redact_text(text):
    text = re.sub(r'\x1b\[[0-?]*[ -/]*[@-~]', '', text)
    text = re.sub(r'-----BEGIN [A-Z0-9 ]+-----[\s\S]*?(?:-----END [A-Z0-9 ]+-----|$)', '[REDACTED]', text)
    # A tail starting inside a PEM must discard through its closing marker.
    if '-----END ' in text:
        text = text.rsplit('-----END ', 1)[1].split('-----', 1)[-1]
    text = re.sub(r'(?i)(\b[a-z][a-z0-9+.-]*://)[^/@\s]+@', r'\1[REDACTED]@', text)
    text = re.sub(r'(?i)(?:Proxy-)?Authorization\s*:[^\r\n]*', 'Authorization: [REDACTED]', text)
    text = re.sub(r'(?i)\bBearer\s+[A-Za-z0-9._+/=-]+', 'Bearer [REDACTED]', text)
    text = re.sub(r'(?<![A-Za-z0-9])(?:gh[pousr]_[A-Za-z0-9_]{16,}|github_pat_[A-Za-z0-9_]{20,})', '[REDACTED]', text)
    def assignment(match):
        return match[0] if re.search(r'(?i)TOKEN|SECRET|PASSWORD|PASSWD|PASSPHRASE|CREDENTIAL|APIKEY|APPKEY|PRIVATEKEY|KEYS?', match[2]) is None else match[1] + match[2] + match[1] + match[3] + '[REDACTED]'
    text = re.sub(r'([\"\x27]?)([A-Za-z][A-Za-z0-9_-]{0,255})\1(\s*[:=]\s*)(?:\"[^\"\r\n]*\"|\x27[^\x27\r\n]*\x27|[^\s,}&{\[\]]+)', assignment, text)
    return text

def safe_output(raw):
    return redact_text(raw.decode('utf-8', errors='replace')).encode()[-16384:].decode('utf-8', errors='ignore')

def stop_unit():
    if UNIT is None:
        return
    result = subprocess.run(['sudo', '-n', '--', 'systemctl', 'stop', UNIT], stdin=subprocess.DEVNULL, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, timeout=30)
    state = subprocess.run(['sudo', '-n', '--', 'systemctl', 'show', UNIT, '--property=ActiveState', '--property=LoadState'], capture_output=True, timeout=10)
    fields = dict(line.split('=', 1) for line in state.stdout.decode().splitlines() if '=' in line)
    if fields.get('LoadState') != 'not-found' and (state.returncode != 0 or fields.get('ActiveState') not in ('inactive', 'failed')):
        raise Refusal('tasks.script_stop_unknown')

def cancel(_signum, _frame):
    stop_unit()
    raise Cancelled()

def command(root, path, args, unit, tmp, ssr):
    user = pwd.getpwuid(os.getuid())
    env = {key: os.environ[key] for key in ('PATH', 'LANG', 'LC_ALL', 'VP_HOME') if key in os.environ}
    env.update({'HOME': user.pw_dir, 'TMPDIR': tmp, 'COMPOSER_CACHE_DIR': user.pw_dir + '/.cache/composer', 'COMPOSER_NO_INTERACTION': '1', 'ORBIT_CHECK_PROCESSES': '1'})
    if ssr is not None:
        env.update({'INERTIA_SSR_PORT': str(ssr), 'INERTIA_SSR_URL': 'http://127.0.0.1:' + str(ssr)})
    return ['sudo', '-n', '--', 'systemd-run', '--quiet', '--wait', '--pipe', '--collect', '--unit=' + unit, '--property=Type=exec', '--property=KillMode=control-group', '--property=RuntimeMaxSec=3600', '--uid=' + str(user.pw_uid), '--gid=' + str(user.pw_gid), '--working-directory=' + root, *['--setenv=' + key + '=' + value for key, value in env.items()], '--', os.path.join(root, path), *args]

def run(value):
    global PROCESS, UNIT
    root = os.path.abspath(value['checkout_path'])
    if os.path.realpath(root) != root or not os.path.isdir(root):
        raise Refusal('tasks.no_workspace')
    args = value['args']
    if not isinstance(args, list) or len(args) > 32 or any(not isinstance(arg, str) or len(arg) > 4096 or '\0' in arg for arg in args):
        raise Refusal('tasks.script_unavailable')
    key = value['execution_key']
    if not re.fullmatch(r'operation-[1-9][0-9]*', key):
        raise Refusal('tasks.script_unavailable')
    directory = pathlib.Path(git(root, 'rev-parse', '--absolute-git-dir')) / 'orbit' / 'scripts' / key
    identity = hashlib.sha256(json.dumps(value, sort_keys=True, separators=(',', ':')).encode()).hexdigest()
    directory.parent.mkdir(parents=True, exist_ok=True, mode=0o700)
    try:
        directory.mkdir(mode=0o700)
    except FileExistsError:
        if (directory / 'input').read_text() != identity:
            raise Refusal('tasks.script_identity_conflict')
        if (directory / 'result.json').is_file():
            return json.loads((directory / 'result.json').read_text())
        raise Refusal('tasks.script_interrupted')
    (directory / 'input').write_text(identity)
    executable(root, value['script'])
    before = snapshot(root)
    total, window, cancelled = 0, bytearray(), False
    UNIT = 'orbit-task-script-' + key + '.service'
    for signum in (signal.SIGTERM, signal.SIGINT, signal.SIGHUP):
        signal.signal(signum, cancel)
    exit_code = None
    try:
        with tempfile.TemporaryDirectory(prefix='orbit-task-script-') as tmp:
            PROCESS = subprocess.Popen(command(root, value['script'], args, UNIT, tmp, value.get('ssr_port')), cwd=root, stdin=subprocess.DEVNULL, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, start_new_session=True)
            try:
                with selectors.DefaultSelector() as selector:
                    selector.register(PROCESS.stdout, selectors.EVENT_READ)
                    deadline = time.monotonic() + 3630
                    while selector.get_map() or PROCESS.poll() is None:
                        if time.monotonic() > deadline:
                            stop_unit()
                            raise Refusal('tasks.script_timeout')
                        for event, _ in selector.select(0.1):
                            chunk = os.read(event.fd, 8192)
                            if not chunk:
                                selector.unregister(event.fileobj)
                            else:
                                total += len(chunk)
                                window.extend(chunk)
                                del window[:-WINDOW]
                exit_code = PROCESS.wait()
            except Cancelled:
                cancelled = True
            finally:
                stop_unit()
                PROCESS.wait(timeout=30)
                PROCESS.stdout.close()
                PROCESS = None
    finally:
        UNIT = None
    after = snapshot(root)
    result = {'outcome': 'passed' if exit_code == 0 else 'failed', 'exit_code': exit_code, 'output': safe_output(bytes(window)), 'output_truncated': total > 16384, 'changed': before != after, **after}
    if cancelled:
        result['cancelled'] = True
    temporary = directory / 'result.tmp'
    temporary.write_text(json.dumps(result))
    temporary.replace(directory / 'result.json')
    return result

def main():
    try:
        value = json.load(sys.stdin)
        result = run(value)
    except Refusal as error:
        result = {'error_code': str(error)}
    except Exception:
        result = {'error_code': 'tasks.script_execution_unknown'}
    print(json.dumps(result), flush=True)

if __name__ == '__main__':
    main()
`;
