"""Run the legacy ordered setup list in one phase, using canonical command policy."""
import json
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'scripts'))
from run_project_step import command_input, execute_command


def setup(value):
    steps = value['steps']
    if not isinstance(steps, list) or len(steps) > 32:
        raise ValueError('instance.setup_identity_changed')
    programs = []
    for step in steps:
        seconds = step['timeout_seconds']
        if type(seconds) is not int or not 1 <= seconds <= 540:
            raise ValueError('instance.setup_identity_changed')
        command_input(step)
        programs.append(step)
    completed = []
    for step in programs:
        result = execute_command(step, step['timeout_seconds'])
        if result['exitCode']:
            return {'ready': False, 'completed': completed, 'failed_command': step['name'], **result,
                    'error_code': 'operation.timed_out' if result['exitCode'] in (124, 137) else 'instance.setup_step_failed'}
        completed.append(step['name'])
    return {'ready': True, 'completed': completed, 'exitCode': 0, 'stderr': ''}


if __name__ == '__main__':
    value = json.load(sys.stdin)
    if len(sys.argv) > 1 and sys.argv[1]:
        for step in value['steps']:
            step['vp_home'] = sys.argv[1]
    result = setup(value)
    print(json.dumps(result))
    sys.exit(0 if result['ready'] else 124 if result['error_code'] == 'operation.timed_out' else 1)
