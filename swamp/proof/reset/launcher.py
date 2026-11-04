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
    write_json(evidence / 'status.json', {'status': 'succeeded' if code == 0 else 'failed',
                                        'exit': code, 'cases_ran': (evidence / 'cases-started.json').exists()})
    return code


def main():
    if len(sys.argv) not in (3, 4) or sys.argv[1] not in ('reset', 'verify', 'release'):
        sys.stderr.write('Usage: bin/proof-fleet reset|release GROUP | verify GROUP REPOSITORY_CASE_PROGRAM\n')
        return 2
    action, group = sys.argv[1:3]
    if not re.fullmatch('[1-9][0-9]*', group) or (action == 'verify') != (len(sys.argv) == 4):
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
    case = sys.argv[3] if action == 'verify' else None
    if case:
        selected = (candidate / case).resolve()
        if not selected.is_relative_to(candidate) or not selected.is_file():
            raise RuntimeError('Case program must be a file inside the candidate repository.')
        tracked = subprocess.run(['git', '-C', str(candidate), 'ls-files', '--error-unmatch', case], capture_output=True)
        if tracked.returncode:
            ignored = subprocess.run(['git', '-C', str(candidate), 'check-ignore', '-q', case])
            if ignored.returncode != 1:
                raise RuntimeError('Case program must be nonignored candidate source.')
    generation = uuid.uuid4().hex
    evidence = candidate / '.orbit-artifacts' / group / 'reset' / generation
    evidence.mkdir(parents=True, mode=0o700)
    key = home / '.config/swamp/club-api-key'
    request = {**marker, 'issue': 'TASK-' + group, 'root': str(root), 'generation': generation,
               'evidence': str(evidence), 'case': case, 'action': action, 'key_file': str(key)}
    request_path = evidence / 'request.json'
    write_json(request_path, request)
    if not key.is_file() or key.is_symlink() or key.stat().st_uid != os.getuid() or key.stat().st_mode & 0o077:
        raise RuntimeError('The approved private proof club key file is required.')
    (root / 'home').mkdir(exist_ok=True, mode=0o700)
    controller = root / 'controller'
    if not (controller / '.swamp.yaml').is_file():
        code = swamp(request, ['init', str(controller), '--tool', 'none', '--no-vault', '--no-telemetry'], 'init.json', root)
        if code:
            write_json(evidence / 'status.json', {'status': 'initialization_failed', 'cases_ran': False, 'exit': code})
            print(json.dumps({'status': 'initialization_failed', 'evidence': str(evidence)}))
            return code
    # One stable native behavior serializes every action for this owned group.
    # The inner command returns only after native failure cleanup and history collection.
    code = swamp(request, ['behavior', 'run', '@command/shell', 'execute', 'proof-owner',
                          '--input', 'run=python3 "$PROOF_CANDIDATE/swamp/proof/reset/launcher.py" locked',
                          '--input', 'env=' + json.dumps({'PROOF_REQUEST': str(request_path), 'PROOF_CANDIDATE': str(candidate)}),
                          '--input', 'timeout=3600000'], 'owner-run.json')
    if not (evidence / 'status.json').is_file():
        # Includes native owner lock timeout: no implicit release or second attempt.
        write_json(evidence / 'status.json', {'status': 'admission_failed', 'exit': code or 1,
                                            'cases_ran': (evidence / 'cases-started.json').exists()})
    status = json.loads((evidence / 'status.json').read_text())
    print(json.dumps({**status, 'generation': generation, 'evidence': str(evidence)}))
    return code or status['exit']


if __name__ == '__main__':
    if sys.argv[1:] == ['locked']:
        raise SystemExit(run_locked(json.loads(Path(os.environ['PROOF_REQUEST']).read_text())))
    raise SystemExit(main())
