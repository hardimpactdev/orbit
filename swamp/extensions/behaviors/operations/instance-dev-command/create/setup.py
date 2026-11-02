"""Setup phase: run the admitted setup list in order and stop at the first failed command.

argv[1] is the shipped scripts/run_project_step.py body, which runs one command and keeps a redacted
stderr tail. The private list arrives on stdin. Prints one public JSON object.
"""
import json
import os
import pwd
import subprocess
import sys
from pathlib import Path


def vp_home():
    """The account's Vite+ store, in the order the legacy setup runner used; None when absent."""
    home = Path(pwd.getpwuid(os.geteuid()).pw_dir)
    for store in (Path('/opt/orbit/vite-plus'), home / '.vite-plus', home / '.local/share/vite-plus'):
        if (store / 'bin/vp').is_file():
            return str(store)
    return None


def setup(runner, steps):
    if not isinstance(steps, list) or len(steps) > 32:
        raise ValueError('instance.setup_identity_changed')
    environment = dict(os.environ)
    store = vp_home()
    if store is not None:
        environment['VP_HOME'] = store
    completed = []
    for step in steps:
        seconds = step['timeout_seconds']
        if type(seconds) is not int or not 1 <= seconds <= 540:
            raise ValueError('instance.setup_identity_changed')
        result = subprocess.run(['timeout', str(seconds), sys.executable, '-I', '-c', runner], input=json.dumps(step),
                                env=environment, capture_output=True, text=True, check=False)
        if result.returncode:
            try:
                stderr = json.loads(result.stdout)['stderr']
            except (ValueError, KeyError, TypeError):
                stderr = ''
            code = {124: 'operation.timed_out', 126: 'instance.setup_step_unavailable',
                    127: 'instance.setup_step_unavailable'}.get(result.returncode, 'instance.setup_step_failed')
            return {'ready': False, 'completed': completed, 'failed_command': step['name'],
                    'exitCode': result.returncode, 'stderr': stderr, 'error_code': code}
        completed.append(step['name'])
    return {'ready': True, 'completed': completed}


if __name__ == '__main__':
    try:
        outcome = setup(sys.argv[1], json.load(sys.stdin)['steps'])
    except (ValueError, KeyError, TypeError):
        outcome = {'ready': False, 'error_code': 'instance.setup_identity_changed'}
    print(json.dumps(outcome))
    sys.exit(0 if outcome['ready'] else 1)
