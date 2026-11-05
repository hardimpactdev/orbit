"""Bootstrap and invoke Swamp; its native owner behavior holds the group lock."""
import json
import os
import re
import shutil
import subprocess
import sys
import uuid
from pathlib import Path


def write_json(path, value):
    path.parent.mkdir(parents=True, exist_ok=True, mode=0o700)
    temporary = path.with_suffix('.tmp')
    temporary.write_text(json.dumps(value, indent=2) + '\n')
    temporary.replace(path)


NOT_READY = 'the test fleet was not ready'
STOPPED = 'the case phase stopped before this case'
DEFAULT_BUDGET = 1800.0
# The cases step of proof-command-verification times out at 2400 s; the budget plus baselines must fit inside it.
MAX_BUDGET = 2100.0
USAGE = 'Usage: bin/proof-fleet reset|release GROUP | verify GROUP PROGRAM [PROGRAM...]\n'


def parse_budget(raw):
    """Seconds for the whole case phase, from PROOF_BUDGET_SECONDS."""
    if raw is None or raw == '':
        return DEFAULT_BUDGET
    try:
        value = float(raw)
    except ValueError:
        raise RuntimeError('PROOF_BUDGET_SECONDS must be a number of seconds.') from None
    if not value > 0 or value == float('inf'):
        raise RuntimeError('PROOF_BUDGET_SECONDS must be a positive number of seconds.')
    return value


def unrun_case(program, note):
    return {'program': program, 'verdict': 'unrun', 'exit_code': None, 'seconds': 0, 'note': note}


def case_results(request):
    """Every selected program with a verdict: recorded cases first, the rest unrun."""
    evidence = Path(request['evidence'])
    recorded = []
    if (evidence / 'cases.json').is_file():
        try:
            recorded = json.loads((evidence / 'cases.json').read_text())['cases']
        except (ValueError, KeyError, TypeError):
            recorded = []
    seen = {case['program'] for case in recorded}
    note = STOPPED if (evidence / 'cases-started.json').exists() else NOT_READY
    return recorded + [unrun_case(program, note) for program in request['cases'] if program not in seen]


def summary(cases):
    """One [letter, seconds] pair per case in order: p passed, f failed, u unrun. Short enough to survive Swamp's 4096-byte output cut."""
    letters = {'passed': 'p', 'failed': 'f', 'unrun': 'u'}
    return [[letters[case['verdict']], 0 if case['verdict'] == 'unrun' else round(case['seconds'], 1)] for case in cases]


def verification(request):
    cases = case_results(request)
    return {'summary': summary(cases), 'cases': cases}


def final_line(status, generation, evidence):
    """The one JSON line on stdout: status, then summary, so a truncated tail never loses either."""
    value = {'status': status['status']}
    if 'summary' in status:
        value['summary'] = status['summary']
    value.update({key: item for key, item in status.items() if key not in value})
    return json.dumps({**value, 'generation': generation, 'evidence': str(evidence)}, separators=(',', ':'))


def swamp(request, args, name, cwd=None):
    root = Path(request['root'])
    env = {'PATH': os.environ['PATH'], 'HOME': str(root / 'home'),
           'SWAMP_HOME': str(root / 'runtime'), 'SWAMP_CONFIG_DIR': str(root / 'config'),
           'SWAMP_API_KEY_FILE': request['key_file'], 'SWAMP_NO_TELEMETRY': '1', 'SWAMP_NO_UPDATE_CHECK': '1'}
    with (Path(request['evidence']) / name).open('w') as log:
        return subprocess.run(['/usr/local/bin/swamp', *args, '--json'], env=env,
                              cwd=cwd or root / 'controller', stdout=log, stderr=subprocess.STDOUT).returncode


def run_locked(request):
    """Called only by the group's native proof-owner command/shell transaction."""
    root, evidence = Path(request['root']), Path(request['evidence'])
    if json.loads((root / 'owner.json').read_text()) != {k: request[k] for k in ('candidate', 'group')}:
        raise RuntimeError('Proof root belongs to a different candidate or group.')
    controller = root / 'controller'
    (controller / 'workflows').mkdir(exist_ok=True)
    for definition in (Path(request['candidate']) / 'swamp/proof/reset').glob('workflow-*.yaml'):
        shutil.copyfile(definition, controller / 'workflows' / definition.name)
    workflow = {'reset': 'proof-fleet-reset', 'verify': 'proof-command-verification',
                'release': 'proof-fleet-release'}[request['action']]
    code = swamp(request, ['workflow', 'validate', workflow], 'validate.json')
    if not code:
        code = swamp(request, ['workflow', 'run', workflow, '--input', 'request=' + str(evidence / 'request.json'),
                              '--input', 'candidate=' + request['candidate']], 'run.json')
        swamp(request, ['workflow', 'history', 'get', workflow], 'history.json')
        if code:
            swamp(request, ['report', 'get', '@swamp/workflow-summary', '--workflow', workflow], 'failure-report.json')
    verified = {}
    if request['action'] == 'verify':
        verified = verification(request)
        write_json(evidence / 'cases.json', {'generation': request['generation'], 'cases': verified['cases']})
        # Any case that did not pass makes the run exit 1; otherwise the workflow's own exit stands.
        code = 1 if any(case['verdict'] != 'passed' for case in verified['cases']) else code
    status = {'status': 'succeeded' if code == 0 else 'failed', 'summary': verified.get('summary'), 'exit': code,
              'cases_ran': (evidence / 'cases-started.json').exists(), 'cases': verified.get('cases')}
    write_json(evidence / 'status.json', {key: value for key, value in status.items() if value is not None or key == 'exit'})
    return code


def validated_programs(candidate, programs):
    """Refuse a duplicate, a program outside the candidate, and an ignored or missing one."""
    seen = set()
    for program in programs:
        selected = (candidate / program).resolve()
        if not selected.is_relative_to(candidate) or not selected.is_file():
            raise RuntimeError('Case program must be a file inside the candidate repository: ' + program)
        if selected in seen:
            raise RuntimeError('Case program listed twice: ' + program)
        seen.add(selected)
        tracked = subprocess.run(['git', '-C', str(candidate), 'ls-files', '--error-unmatch', program], capture_output=True)
        if tracked.returncode:
            ignored = subprocess.run(['git', '-C', str(candidate), 'check-ignore', '-q', program])
            if ignored.returncode != 1:
                raise RuntimeError('Case program must be nonignored candidate source: ' + program)
    return list(programs)


def main():
    if len(sys.argv) < 3 or sys.argv[1] not in ('reset', 'verify', 'release'):
        sys.stderr.write(USAGE)
        return 2
    action, group = sys.argv[1:3]
    programs = sys.argv[3:]
    if not re.fullmatch('[1-9][0-9]*', group) or (action == 'verify') != bool(programs):
        sys.stderr.write(USAGE)
        return 2
    os.umask(0o077)
    candidate, home = Path(__file__).resolve().parents[3], Path.home()
    root = home / '.local/state' / ('orbit-proof-' + group)
    root.mkdir(parents=True, exist_ok=True, mode=0o700)
    if root.is_symlink() or root.stat().st_uid != os.getuid():
        raise RuntimeError('Proof root must be an owned real directory.')
    marker = {'group': group, 'candidate': str(candidate)}
    owner = root / 'owner.json'
    if owner.exists() and json.loads(owner.read_text()) != marker:
        raise RuntimeError('Proof root belongs to a different candidate or group.')
    if not owner.exists():
        write_json(owner, marker)
    if action == 'verify':
        programs = validated_programs(candidate, programs)
    budget = parse_budget(os.environ.get('PROOF_BUDGET_SECONDS'))
    if budget > MAX_BUDGET:
        raise RuntimeError(f'PROOF_BUDGET_SECONDS may not exceed {MAX_BUDGET:g}; the cases step times out at 2400 s.')
    generation = uuid.uuid4().hex
    evidence = candidate / '.orbit-artifacts' / group / 'reset' / generation
    evidence.mkdir(parents=True, mode=0o700)
    key = home / '.config/swamp/club-api-key'
    request = {**marker, 'issue': 'TASK-' + group, 'root': str(root), 'generation': generation,
               'evidence': str(evidence), 'cases': programs if action == 'verify' else None,
               'budget_seconds': budget, 'action': action, 'key_file': str(key)}
    request_path = evidence / 'request.json'
    write_json(request_path, request)
    if not key.is_file() or key.is_symlink() or key.stat().st_uid != os.getuid() or key.stat().st_mode & 0o077:
        raise RuntimeError('The approved private proof club key file is required.')
    (root / 'home').mkdir(exist_ok=True, mode=0o700)
    controller = root / 'controller'
    if not (controller / '.swamp.yaml').is_file():
        code = swamp(request, ['init', str(controller), '--tool', 'none', '--no-vault', '--no-telemetry'], 'init.json', root)
        if code:
            failed = {'status': 'initialization_failed', 'cases_ran': False, 'exit': code}
            if action == 'verify':
                failed.update(verification(request))
            write_json(evidence / 'status.json', failed)
            print(final_line(failed, generation, evidence))
            return code
    # One stable native behavior serializes every action for this owned group.
    # The inner command returns only after native failure cleanup and history collection.
    code = swamp(request, ['behavior', 'run', '@command/shell', 'execute', 'proof-owner',
                          '--input', 'run=python3 "$PROOF_CANDIDATE/swamp/proof/reset/launcher.py" locked',
                          '--input', 'env=' + json.dumps({'PROOF_REQUEST': str(request_path), 'PROOF_CANDIDATE': str(candidate)}),
                          '--input', 'timeout=3600000'], 'owner-run.json')
    if not (evidence / 'status.json').is_file():
        # Includes native owner lock timeout: no implicit release or second attempt.
        refused = {'status': 'admission_failed', 'exit': code or 1, 'cases_ran': (evidence / 'cases-started.json').exists()}
        if action == 'verify':
            refused.update(verification(request))
        write_json(evidence / 'status.json', refused)
    status = json.loads((evidence / 'status.json').read_text())
    print(final_line(status, generation, evidence))
    # A verification exits 1 for any case that did not pass, whatever the native owner returned.
    return (status['exit'] or code) if action == 'verify' else (code or status['exit'])


if __name__ == '__main__':
    if sys.argv[1:] == ['locked']:
        raise SystemExit(run_locked(json.loads(Path(os.environ['PROOF_REQUEST']).read_text())))
    raise SystemExit(main())
