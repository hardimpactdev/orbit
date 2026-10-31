import { ciCacheImportProgram } from "../instance-deploy/app_step_runtime.ts";
import { ScriptProgram } from "./script_program.ts";

/** Shared workspace/systemd functions plus the check's isolated base-tree operations. */
export const CheckProgram =
  ScriptProgram.slice(0, ScriptProgram.lastIndexOf("\nif __name__")) +
  String.raw`
def git_arguments(checkout, *arguments):
    return ['git', '-c', 'core.hooksPath=/dev/null', '-c', 'core.fsmonitor=false', '-c', 'safe.directory=' + checkout, '-C', checkout, *arguments]

def inside(checkout, relative):
    """The absolute path of a directory inside the checkout, or None when it points outside."""
    root = os.path.realpath(checkout)
    path = os.path.realpath(os.path.join(root, relative or '.'))
    return path if path == root or path.startswith(root + os.sep) else None

def deliverable_path(checkout, relative):
    """Return a safe workspace path, rejecting absolute and parent-traversal paths."""
    if not isinstance(relative, str) or relative == '' or relative.startswith('/'):
        return None
    if any(part in ('', '.', '..') for part in relative.split('/')):
        return None
    return inside(checkout, relative)

def commit_exists(checkout, revision):
    if not isinstance(revision, str) or revision == '' or revision.startswith('-') or any(character.isspace() for character in revision):
        return False
    return subprocess.run(git_arguments(checkout, 'rev-parse', '--verify', '--quiet', revision + '^{commit}'), capture_output=True).returncode == 0

def extract_archive(checkout, start, scratch):
    if not commit_exists(checkout, start):
        return False
    archive = subprocess.Popen(git_arguments(checkout, 'archive', start), stdout=subprocess.PIPE, stderr=subprocess.DEVNULL)
    extract = subprocess.run(['tar', '-xf', '-', '-C', scratch], stdin=archive.stdout, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    archive.stdout.close()
    return archive.wait() == 0 and extract.returncode == 0

def copy_dependencies(checkout, scratch):
    """Make installed dependency directories available to the archived base tree."""
    for root, directories, _files in os.walk(checkout):
        directories[:] = [name for name in directories if name not in {'.git', 'vendor', 'node_modules'}]
        for name in ('vendor', 'node_modules'):
            source = os.path.join(root, name)
            if not os.path.isdir(source):
                continue
            relative = os.path.relpath(source, checkout)
            destination = os.path.join(scratch, relative)
            if os.path.exists(destination):
                shutil.rmtree(destination)
            os.makedirs(os.path.dirname(destination), exist_ok=True)
            shutil.copytree(source, destination, symlinks=True, copy_function=shutil.copy2)

def base_run(checkout, start, command, log):
    """Run a command on the start commit with only its declared paths overlaid."""
    timeout = BASE_RUN_TIMEOUT
    scratch = None
    try:
        os.makedirs(base_directories(), exist_ok=True)
        scratch = tempfile.mkdtemp(prefix='orbit-base-', dir=base_directories())
        log.write(f'# orbit base tree {scratch}\n'.encode())
        log.flush()
        if not extract_archive(checkout, start, scratch):
            return {'base_started': False, 'base_exit_code': 127, 'base_output': 'Could not extract the start commit.'}
        copy_dependencies(checkout, scratch)
        for relative in command.get('paths', []):
            source = deliverable_path(checkout, relative)
            destination = deliverable_path(scratch, relative)
            if source is None or destination is None or not os.path.isfile(source) or os.path.islink(source):
                return {'base_started': False, 'base_exit_code': 127, 'base_output': f'Could not overlay path {relative}.'}
            os.makedirs(os.path.dirname(destination), exist_ok=True)
            shutil.copyfile(source, destination)
            shutil.copymode(source, destination)
        directory = inside(scratch, command.get('directory', '.'))
        if directory is None or not os.path.isdir(directory):
            return {'base_started': False, 'base_exit_code': 127, 'base_output': 'The command directory is outside the base tree.'}
        log.write(f"$ {command['command']}   # base on start commit\n".encode())
        code, output, timed_out = run_base(command['command'], directory, log, timeout)
        result = {'base_started': True, 'base_exit_code': code if code is not None else 124, 'base_output': output}
        if timed_out:
            result.update({'base_timed_out': True, 'base_timeout_seconds': timeout})
        return result
    except (OSError, subprocess.SubprocessError):
        return {'base_started': False, 'base_exit_code': 127, 'base_output': 'The base command could not be started.'}
    finally:
        if scratch is not None:
            remove_base_tree(scratch)

def diff(checkout, start_commit, tree):
    """The subtask's diff from its start commit to the checked tree, as paths with their Git status."""
    try:
        lines = git(checkout, 'diff-tree', '-r', '--no-renames', '--name-status', start_commit, tree).splitlines()
    except subprocess.CalledProcessError:
        return None
    entries = []
    for line in lines:
        status, _, path = line.partition('\t')
        if path:
            entries.append({'status': status[:1], 'path': path})
    return entries

DIRECTORY = None
BASE_RUN_TIMEOUT = 600
SSR = None
SEQUENCE = 0
EXECUTION_KEY = None

def working_tree(root):
    return snapshot(root)['tree']

def base_directories():
    return os.path.join(DIRECTORY, 'bases')

def remove_base_tree(path):
    shutil.rmtree(path, ignore_errors=True)

def output_records(raw):
    return safe_output(raw)[-4096:]

def execute_command(root, source, timeout=3600):
    global PROCESS, UNIT, SEQUENCE
    SEQUENCE += 1
    UNIT = 'orbit-task-check-' + EXECUTION_KEY + '-' + str(SEQUENCE) + '.service'
    window, total, timed_out = bytearray(), 0, False
    with tempfile.TemporaryDirectory(prefix='orbit-task-check-') as tmp:
        PROCESS = subprocess.Popen(command(root, '/bin/bash', ['-lc', source], UNIT, tmp, SSR), cwd=root, stdin=subprocess.DEVNULL, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, start_new_session=True)
        try:
            with selectors.DefaultSelector() as selector:
                selector.register(PROCESS.stdout, selectors.EVENT_READ)
                deadline = time.monotonic() + timeout
                while selector.get_map() or PROCESS.poll() is None:
                    if time.monotonic() > deadline:
                        timed_out = True
                        stop_unit()
                    for event, _ in selector.select(0.1):
                        chunk = os.read(event.fd, 8192)
                        if not chunk:
                            selector.unregister(event.fileobj)
                        else:
                            total += len(chunk)
                            window.extend(chunk)
                            del window[:-WINDOW]
            code = PROCESS.wait()
        finally:
            stop_unit()
            PROCESS.wait(timeout=30)
            PROCESS.stdout.close()
            PROCESS = None
            UNIT = None
    return (124 if timed_out else code), bytes(window), timed_out, total

def run_base(source, cwd, log, timeout):
    code, output, timed_out, _ = execute_command(cwd, source, timeout)
    log.write(output)
    return code, output_records(output), timed_out

def record(path, value):
    temporary = path.with_suffix('.tmp')
    temporary.write_text(json.dumps(value))
    temporary.replace(path)

def check(value):
    global DIRECTORY, SSR, EXECUTION_KEY
    root = os.path.abspath(value['checkout_path'])
    if os.path.realpath(root) != root:
        raise Refusal('tasks.no_workspace')
    EXECUTION_KEY = value['execution_key']
    if not re.fullmatch(r'operation-[1-9][0-9]*', EXECUTION_KEY):
        raise Refusal('tasks.check_invalid')
    SSR = value.get('ssr_port')
    directory = pathlib.Path(git(root, 'rev-parse', '--absolute-git-dir')) / 'orbit' / 'checks' / EXECUTION_KEY
    directory.mkdir(parents=True, exist_ok=True, mode=0o700)
    if value['mode'] == 'snapshot':
        return snapshot(root)
    if value['mode'] == 'commit':
        ref = 'refs/orbit/check-operations/' + EXECUTION_KEY[10:]
        existing = subprocess.run(git_arguments(root, 'rev-parse', '--verify', '--quiet', ref + '^{commit}'), capture_output=True)
        if existing.returncode == 0:
            candidate = existing.stdout.decode().strip()
            return {'commit': candidate, 'tree': git(root, 'rev-parse', candidate + '^{tree}')}
        expected = {'head': value['head'], 'tree': value['tree']}
        if snapshot(root) != expected:
            return {'changed': True}
        git(root, 'add', '--all')
        if git(root, 'write-tree') != value['tree']:
            return {'changed': True}
        env = {**os.environ, 'GIT_AUTHOR_NAME': 'Orbit', 'GIT_AUTHOR_EMAIL': 'orbit@users.noreply.github.com', 'GIT_COMMITTER_NAME': 'Orbit', 'GIT_COMMITTER_EMAIL': 'orbit@users.noreply.github.com'}
        candidate = subprocess.check_output(git_arguments(root, 'commit-tree', value['tree'], '-p', value['head']), input=value['message'].encode(), env=env).decode().strip()
        transaction = 'start\nupdate HEAD ' + candidate + ' ' + value['head'] + '\ncreate ' + ref + ' ' + candidate + '\nprepare\ncommit\n'
        subprocess.run(git_arguments(root, 'update-ref', '--stdin'), input=transaction.encode(), check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        return {'commit': candidate, 'tree': value['tree']}
    if value['mode'] == 'push':
        import base64
        repo = value['repository']
        if not re.fullmatch(r'[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+', repo) or not re.fullmatch(r'task-[1-9][0-9]*-handoff', value['branch']):
            raise Refusal('tasks.check_invalid')
        url = 'https://github.com/' + repo + '.git'
        env = {key: os.environ[key] for key in ('PATH', 'HOME', 'LANG') if key in os.environ}
        env.update({'GIT_TERMINAL_PROMPT': '0', 'GIT_CONFIG_COUNT': '3', 'GIT_CONFIG_KEY_0': 'http.https://github.com/.extraHeader',
            'GIT_CONFIG_VALUE_0': 'Authorization: Basic ' + base64.b64encode(('x-access-token:' + value['token']).encode()).decode(),
            'GIT_CONFIG_KEY_1': 'credential.helper', 'GIT_CONFIG_VALUE_1': '', 'GIT_CONFIG_KEY_2': 'core.hooksPath', 'GIT_CONFIG_VALUE_2': '/dev/null'})
        remote = 'refs/heads/' + value['branch']
        observed = subprocess.check_output(git_arguments(root, 'ls-remote', '--refs', url, remote), env=env, stderr=subprocess.DEVNULL, timeout=60).decode().split()
        if observed and observed[0] == value['commit']:
            return {'pushed': True}
        subprocess.run(git_arguments(root, 'push', '--porcelain', '--', url, value['commit'] + ':' + remote), env=env, check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, timeout=120)
        return {'pushed': True}
    marker = directory / 'started'
    saved = directory / 'local.json'
    identity = hashlib.sha256(json.dumps(value, sort_keys=True, separators=(',', ':')).encode()).hexdigest()
    try:
        with marker.open('x') as file:
            file.write(identity)
    except FileExistsError:
        if marker.read_text() != identity:
            raise Refusal('tasks.check_identity_conflict')
        if saved.is_file():
            return json.loads(saved.read_text())
        raise Refusal('tasks.check_interrupted')
    for signum in (signal.SIGTERM, signal.SIGINT, signal.SIGHUP):
        signal.signal(signum, cancel)
    before = snapshot(root)
    original = before.copy()
    output = bytearray()
    failed_step, code, evidence = None, 0, None
    deliverables = value['deliverables']
    with tempfile.TemporaryDirectory(prefix='orbit-task-base-') as scratch:
        DIRECTORY = scratch
        import io
        log = io.BytesIO()
        try:
            for item in deliverables:
                if item['type'] != 'command':
                    continue
                if inside(root, item.get('directory', '.')) is None or any(deliverable_path(root, path) is None or not os.path.isfile(deliverable_path(root, path)) or os.path.islink(deliverable_path(root, path)) for path in item.get('paths', [])):
                    raise Refusal('tasks.deliverable_invalid')
            for step in value['setup']:
                source = ${
    JSON.stringify(ciCacheImportProgram)
  } if step['command'] == '@orbit/ci-caches' else step['command']
                code, raw, _, _ = execute_command(root, source, step['timeout_seconds'])
                output.extend(raw)
                del output[:-WINDOW]
                if code != 0:
                    failed_step = step['name']
                    break
            before = snapshot(root)
            if code == 0 and value['command']:
                code, raw, _, _ = execute_command(root, value['command'])
                output.extend(raw)
                del output[:-WINDOW]
            if code == 0 and deliverables:
                evidence = {'start': value['base'] or None, 'diff': diff(root, value['base'], before['tree']) if value['base'] else None, 'commands': {}}
                for item in deliverables:
                    if item['type'] != 'command':
                        continue
                    cwd = inside(root, item.get('directory', '.'))
                    if cwd is None or not os.path.isdir(cwd):
                        raise Refusal('tasks.deliverable_invalid')
                    base = base_run(root, value['base'], item, log) if item.get('fails_on_base') else {}
                    exit_code, raw, _, _ = execute_command(cwd, item['command'])
                    output.extend(raw)
                    del output[:-WINDOW]
                    evidence['commands'][item['id']] = {'exit_code': exit_code, 'output': output_records(raw), **base}
            after = snapshot(root)
            paths = [entry['path'] for entry in diff(root, before['tree'], after['tree']) or []]
            outcome = 'changed' if before != after else ('passed' if code == 0 else 'failed')
            result = {'outcome': outcome, 'exit_code': code, 'head_before': before['head'], 'tree_before': before['tree'],
                'head': after['head'], 'tree': after['tree'], 'changed_paths': paths, 'failed_step': failed_step,
                'output': safe_output(bytes(output)), 'evidence': evidence, 'subtask_start_commit': original['head']}
            record(saved, result)
            return result
        finally:
            log.close()

def main():
    try:
        result = check(json.load(sys.stdin))
    except Cancelled:
        result = {'cancelled': True}
    except Refusal as error:
        result = {'error_code': str(error)}
    except Exception:
        result = {'error_code': 'tasks.check_execution_unknown'}
    print(json.dumps(result), flush=True)

if __name__ == '__main__':
    main()
`;
