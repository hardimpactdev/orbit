"""Only lock/bootstrap/invoke Swamp here. The native workflows own the lifecycle."""
import fcntl
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import sys
import uuid


def write_json(path, value):
    path.parent.mkdir(parents=True, exist_ok=True, mode=0o700)
    temporary = path.with_suffix('.tmp')
    temporary.write_text(json.dumps(value, indent=2) + '\n')
    temporary.replace(path)


def main():
    if len(sys.argv) not in (3, 4) or sys.argv[1] not in ('reset', 'verify', 'release'):
        sys.stderr.write('Usage: bin/proof-fleet reset|release GROUP | verify GROUP REPOSITORY_CASE_PROGRAM\n')
        return 2
    action, group = sys.argv[1:3]
    if not re.fullmatch('[1-9][0-9]*', group) or (action == 'verify') != (len(sys.argv) == 4):
        return 2
    os.umask(0o077)
    candidate = Path(__file__).resolve().parents[3]
    home = Path.home()
    root = home / '.local/state' / ('orbit-proof-' + group)
    root.mkdir(parents=True, exist_ok=True, mode=0o700)
    if root.is_symlink() or root.stat().st_uid != os.getuid():
        raise RuntimeError('Proof root must be an owned real directory.')
    with (root / 'execution.lock').open('a') as lock:
        fcntl.flock(lock, fcntl.LOCK_EX)
        marker = {'group': group, 'candidate': str(candidate)}
        owner = root / 'owner.json'
        if owner.exists() and json.loads(owner.read_text()) != marker:
            raise RuntimeError('Proof root belongs to a different candidate or group.')
        write_json(owner, marker)
        case = sys.argv[3] if action == 'verify' else None
        if case:
            selected = (candidate / case).resolve()
            if not selected.is_relative_to(candidate) or not selected.is_file():
                raise RuntimeError('Case program must be a file inside the candidate repository.')
            tracked = subprocess.run(['git', '-C', str(candidate), 'ls-files', '--error-unmatch', case], capture_output=True)
            if tracked.returncode:
                # New candidate assets are allowed before the task orchestrator commits them.
                ignored = subprocess.run(['git', '-C', str(candidate), 'check-ignore', '-q', case])
                if ignored.returncode != 1:
                    raise RuntimeError('Case program must be nonignored candidate source.')
        generation = uuid.uuid4().hex
        evidence = candidate / '.orbit-artifacts' / group / 'reset' / generation
        evidence.mkdir(parents=True, mode=0o700)
        request = {**marker, 'issue': 'TASK-' + group, 'root': str(root), 'generation': generation,
                   'evidence': str(evidence), 'case': case, 'action': action,
                   'key_file': str(home / '.config/swamp/club-api-key')}
        request_path = evidence / 'request.json'
        write_json(request_path, request)
        key = home / '.config/swamp/club-api-key'
        if not key.is_file() or key.is_symlink() or key.stat().st_uid != os.getuid() or key.stat().st_mode & 0o077:
            raise RuntimeError('The approved private proof club key file is required.')
        env = {'PATH': os.environ['PATH'], 'HOME': str(root / 'home'),
               'SWAMP_HOME': str(root / 'runtime'), 'SWAMP_CONFIG_DIR': str(root / 'config'),
               'SWAMP_API_KEY_FILE': str(key), 'SWAMP_NO_TELEMETRY': '1', 'SWAMP_NO_UPDATE_CHECK': '1'}
        (root / 'home').mkdir(exist_ok=True, mode=0o700)
        controller = root / 'controller'

        def swamp(args, name, cwd=None):
            with (evidence / name).open('w') as log:
                return subprocess.run(['/usr/local/bin/swamp', *args, '--json'], env=env,
                                      cwd=cwd or controller, stdout=log, stderr=subprocess.STDOUT).returncode

        if not (controller / '.swamp.yaml').is_file():
            code = swamp(['init', str(controller), '--tool', 'none', '--no-vault', '--no-telemetry'], 'init.json', root)
            if code:
                write_json(evidence / 'status.json', {'status': 'initialization_failed', 'cases_ran': False, 'exit': code})
                print(json.dumps({'status': 'initialization_failed', 'evidence': str(evidence)}))
                return code
        (controller / 'workflows').mkdir(exist_ok=True)
        for definition in (candidate / 'swamp/proof/reset').glob('workflow-*.yaml'):
            shutil.copyfile(definition, controller / 'workflows' / definition.name)
        workflow = {'reset': 'proof-fleet-reset', 'verify': 'proof-command-verification',
                    'release': 'proof-fleet-release'}[action]
        code = swamp(['workflow', 'validate', workflow], 'validate.json')
        if not code:
            code = swamp(['workflow', 'run', workflow, '--input', 'request=' + str(request_path),
                          '--input', 'candidate=' + str(candidate)], 'run.json')
            swamp(['workflow', 'history', 'get', workflow], 'history.json')
            if code:
                swamp(['report', 'get', '@swamp/workflow-summary', '--workflow', workflow], 'failure-report.json')
        cases_ran = (evidence / 'cases-started.json').exists()
        write_json(evidence / 'status.json', {'status': 'succeeded' if code == 0 else 'failed',
                                             'exit': code, 'cases_ran': cases_ran})
        print(json.dumps({'status': 'succeeded' if code == 0 else 'failed', 'generation': generation,
                          'evidence': str(evidence), 'cases_ran': cases_ran}))
        return code
