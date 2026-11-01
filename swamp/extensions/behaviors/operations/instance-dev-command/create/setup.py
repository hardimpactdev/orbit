"""Run the legacy ordered setup list in one phase, using canonical command policy."""
import json
import subprocess
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'scripts'))
from run_project_step import command_input


def setup(value):
    steps = value['steps']
    if not isinstance(steps, list) or len(steps) > 32:
        raise ValueError('instance.setup_identity_changed')
    programs = []
    for step in steps:
        seconds = step['timeout_seconds']
        if type(seconds) is not int or not 1 <= seconds <= 540:
            raise ValueError('instance.setup_identity_changed')
        programs.append((*command_input(step), seconds, step['name']))
    completed = []
    for command, directory, environment, seconds, name in programs:
        result = subprocess.run(['/usr/bin/timeout', '--kill-after=1s', str(seconds) + 's',
                                 '/bin/bash', '-eu', '-c', command], cwd=directory, env=environment, check=False,
                                stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        if result.returncode:
            return {'ready': False, 'completed': completed, 'failed_command': name,
                    'error_code': 'operation.timed_out' if result.returncode in (124, 137) else 'instance.setup_step_failed'}
        completed.append(name)
    return {'ready': True, 'completed': completed}


if __name__ == '__main__':
    result = setup(json.load(sys.stdin))
    print(json.dumps(result))
    sys.exit(0 if result['ready'] else 124 if result['error_code'] == 'operation.timed_out' else 1)
