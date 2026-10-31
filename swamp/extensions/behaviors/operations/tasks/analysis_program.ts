import { ScriptProgram } from "./script_program.ts";

/** Fixed stdin-only repository analyzer using the shared workspace/systemd boundary. */
export const AnalysisProgram =
  ScriptProgram.slice(0, ScriptProgram.lastIndexOf("\nif __name__")) +
  String.raw`
import datetime, math

MAX_REPORT = 65536

def strict_json(raw):
    def fields(pairs):
        result = {}
        for key, value in pairs:
            if key in result:
                raise ValueError('Duplicate JSON field')
            result[key] = value
        return result
    def constant(_):
        raise ValueError('Non-finite JSON number')
    value = json.loads(raw.decode('utf-8', errors='strict'), object_pairs_hook=fields, parse_constant=constant)
    def depth(item, level):
        if level > 24 or isinstance(item, float) and not math.isfinite(item):
            raise ValueError('Invalid JSON depth or number')
        if isinstance(item, str):
            item.encode('utf-8', errors='strict')
        if isinstance(item, dict):
            for key in item:
                key.encode('utf-8', errors='strict')
        if isinstance(item, (dict, list)):
            for child in item.values() if isinstance(item, dict) else item:
                depth(child, level + 1)
    depth(value, 1)
    return value

def object_fields(value, fields):
    if not isinstance(value, dict) or set(value) != set(fields):
        raise ValueError('Invalid object fields')
    return value

def report(raw, identity, code):
    if len(raw) > MAX_REPORT:
        return {'error_code': 'tasks.analysis_report_too_large', 'schema': 1, 'error': {'code': 'tasks.analysis_report_too_large', 'required_bytes': None}}
    value = strict_json(raw)
    if code != 0 and isinstance(value, dict) and 'error' in value:
        object_fields(value, ['schema', 'error'])
        error = object_fields(value['error'], ['code', 'required_bytes'])
        if type(value['schema']) != int or value['schema'] != 1:
            raise ValueError('Invalid version')
        if error['code'] == 'tasks.analysis_unavailable' and error['required_bytes'] is None:
            return {**value, 'error_code': error['code']}
        if error['code'] == 'tasks.analysis_report_too_large' and type(error['required_bytes']) == int and MAX_REPORT < error['required_bytes'] <= 9007199254740991:
            return {**value, 'error_code': error['code']}
        raise ValueError('Invalid failure')
    object_fields(value, ['schema', 'execution_id', 'plan_sha256', 'base', 'head', 'tree', 'status', 'diagnostics', 'report'])
    if type(value['schema']) != int or value['schema'] != 1 or value['status'] not in ('ready', 'action_required', 'blocked'):
        raise ValueError('Invalid report version or status')
    for key in ('execution_id', 'plan_sha256', 'base', 'head', 'tree'):
        if value[key] != identity[key]:
            raise ValueError('Different report identity')
    if not isinstance(value['diagnostics'], list):
        raise ValueError('Invalid diagnostics')
    for item in value['diagnostics']:
        object_fields(item, ['code', 'message'])
        if not isinstance(item['code'], str) or not item['code'].strip() or not isinstance(item['message'], str):
            raise ValueError('Invalid diagnostic')
    data = object_fields(value['report'], ['schema', 'data'])
    if not isinstance(data['schema'], str) or not data['schema'].strip() or not isinstance(data['data'], dict):
        raise ValueError('Invalid repository report')
    # Structured evidence must never be silently altered by redaction.
    serialized = json.dumps(value, ensure_ascii=False)
    if redact_text(raw.decode()) != raw.decode() or redact_text(serialized) != serialized:
        raise ValueError('Private report data')
    return value

def inspect(value):
    root = os.path.abspath(value['checkout_path'])
    if os.path.realpath(root) != root or not os.path.isdir(root):
        raise Refusal('tasks.no_workspace')
    try:
        if git(root, 'rev-parse', '--verify', value['base'] + '^{commit}') != value['base']:
            raise Refusal('tasks.analysis_stale')
        subprocess.run(['git', '-C', root, 'merge-base', '--is-ancestor', value['base'], 'HEAD'], check=True, capture_output=True)
        before = snapshot(root)
        if (before != {key: value[key] for key in ('head', 'tree')} or git(root, 'symbolic-ref', '--short', 'HEAD') != value['branch']
                or git(root, 'remote', 'get-url', 'origin') != value['repository']):
            raise Refusal('tasks.analysis_stale')
        digest = executable(root, 'bin/task-analyze')
    except Refusal as error:
        raise Refusal('tasks.analysis_unavailable' if str(error) == 'tasks.script_unavailable' else str(error)) from None
    except subprocess.SubprocessError:
        raise Refusal('tasks.analysis_stale') from None
    return root, before, digest

def now():
    return datetime.datetime.now(datetime.timezone.utc).isoformat().replace('+00:00', 'Z')

def analyze(value):
    global PROCESS, UNIT
    root = os.path.abspath(value['checkout_path'])
    key = value['execution_key']
    if not re.fullmatch(r'operation-[1-9][0-9]*', key):
        raise Refusal('tasks.analysis_unavailable')
    identity = hashlib.sha256(json.dumps(value, sort_keys=True, separators=(',', ':')).encode()).hexdigest()
    directory = pathlib.Path(git(root, 'rev-parse', '--absolute-git-dir')) / 'orbit' / 'analysis' / key
    directory.parent.mkdir(parents=True, exist_ok=True, mode=0o700)
    try:
        directory.mkdir(mode=0o700)
    except FileExistsError:
        if (directory / 'input').read_text() != identity:
            raise Refusal('tasks.analysis_identity_conflict')
        if (directory / 'result.json').is_file():
            return json.loads((directory / 'result.json').read_text())
        UNIT = 'orbit-task-analysis-' + key + '.service'
        try:
            stop_unit()
        finally:
            UNIT = None
        raise Refusal('tasks.analysis_interrupted')
    (directory / 'input').write_text(identity)
    root, before, digest = inspect(value)
    execution = hashlib.sha256(key.encode()).hexdigest()[:32]
    admitted = {key: value[key] for key in ('plan', 'plan_sha256', 'base', 'head', 'tree')}
    admitted['execution_id'] = execution
    UNIT = 'orbit-task-analysis-' + value['execution_key'] + '.service'
    started_at, started = now(), time.monotonic()
    report_window, stderr_window, total = bytearray(), bytearray(), 0
    cancelled = False
    for signum in (signal.SIGTERM, signal.SIGINT, signal.SIGHUP):
        signal.signal(signum, cancel)
    try:
        with tempfile.TemporaryDirectory(prefix='orbit-task-analysis-') as tmp, tempfile.TemporaryFile() as admitted_input:
            admitted_input.write(json.dumps(admitted, ensure_ascii=False).encode())
            admitted_input.seek(0)
            PROCESS = subprocess.Popen(command(root, 'bin/task-analyze', [], UNIT, tmp, value.get('ssr_port')), cwd=root, stdin=admitted_input, stdout=subprocess.PIPE, stderr=subprocess.PIPE, start_new_session=True)
            try:
                with selectors.DefaultSelector() as selector:
                    selector.register(PROCESS.stdout, selectors.EVENT_READ, 'stdout')
                    selector.register(PROCESS.stderr, selectors.EVENT_READ, 'stderr')
                    deadline = time.monotonic() + 3630
                    while selector.get_map() or PROCESS.poll() is None:
                        if time.monotonic() > deadline:
                            stop_unit()
                            raise Refusal('tasks.analysis_timeout')
                        for event, _ in selector.select(0.1):
                            chunk = os.read(event.fd, 8192)
                            if not chunk:
                                selector.unregister(event.fileobj)
                            elif event.data == 'stdout':
                                total += len(chunk)
                                report_window.extend(chunk[:max(0, MAX_REPORT + 1 - len(report_window))])
                            else:
                                stderr_window.extend(chunk)
                                del stderr_window[:-WINDOW]
                code = PROCESS.wait()
            except Cancelled:
                cancelled = True
                code = None
            finally:
                stop_unit()
                PROCESS.wait(timeout=30)
                PROCESS.stdout.close()
                PROCESS.stderr.close()
                PROCESS = None
    finally:
        UNIT = None
    after = snapshot(root)
    if cancelled:
        result = {'cancelled': True}
    else:
        try:
            result = report(bytes(report_window), admitted, code)
            if 'error_code' not in result:
                changed = after != before
                if not changed and code != 0:
                    raise Refusal('tasks.analysis_failed')
                raw = bytes(report_window).decode('utf-8')
                result = {'outcome': 'changed' if changed else result['status'],
                    'artifact': {'sha256': hashlib.sha256(bytes(report_window)).hexdigest(), 'bytes': total, 'content': raw},
                    'provenance': {'operation_id': value['operation_id'], 'group': value['group_id'], 'subtask': value['subtask'],
                        'repository': value['repository'], 'branch': value['branch'], 'instance_id': value['instance_id'], 'node_id': value['node_id'],
                        'plan_id': value['plan']['id'], 'plan_revision': value['plan']['revision'], 'plan_sha256': value['plan_sha256'],
                        'base': value['base'], 'head': before['head'], 'tree': before['tree'], 'before': before, 'after': after,
                        'execution_id': execution, 'entrypoint_sha256': digest, 'started_at': started_at, 'finished_at': now(),
                        'duration_ms': int((time.monotonic() - started) * 1000), 'exit_code': code, 'mechanical_llm_calls': 0}}
        except (ValueError, UnicodeError):
            result = {'error_code': 'tasks.analysis_report_invalid'}
        except Refusal as error:
            result = {'error_code': str(error)}
    temporary = directory / 'result.tmp'
    temporary.write_text(json.dumps(result))
    temporary.replace(directory / 'result.json')
    return result

def analysis_main():
    try:
        result = analyze(json.load(sys.stdin))
    except Cancelled:
        result = {'cancelled': True}
    except Refusal as error:
        result = {'error_code': str(error)}
    except Exception:
        result = {'error_code': 'tasks.analysis_execution_unknown'}
    print(json.dumps(result), flush=True)

if __name__ == '__main__':
    analysis_main()
`;
