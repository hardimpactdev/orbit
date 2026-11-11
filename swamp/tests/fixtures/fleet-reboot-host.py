"""Disposable service and Incus boundaries for the fleet reboot rescue fixtures."""
import json
import os
import sys
from pathlib import Path


def read_state():
    return json.loads((Path(os.environ['REBOOT_FIXTURE']) / 'state.json').read_text())


def save(state):
    (Path(os.environ['REBOOT_FIXTURE']) / 'state.json').write_text(json.dumps(state))


def log(tool, args):
    with (Path(os.environ['REBOOT_FIXTURE']) / 'calls.jsonl').open('a') as calls:
        calls.write(json.dumps([tool, *args]) + '\n')


def systemd():
    from ansible.module_utils.basic import AnsibleModule

    module = AnsibleModule(argument_spec={
        'name': {'type': 'str', 'required': True},
        'state': {'type': 'str', 'required': True, 'choices': ['started', 'stopped']},
    })
    state = read_state()
    name = module.params['name']
    before = state['units'][name]
    wanted = module.params['state']
    if wanted is not None:
        log('systemd', [wanted, name])
        state['units'][name] = 'active' if wanted == 'started' else 'inactive'
        save(state)
    module.exit_json(changed=before != state['units'][name], status={'ActiveState': state['units'][name]})


def command(tool, args):
    state = read_state()
    log(tool, args)
    if tool == 'cat':
        if state.get('ci_read_failure'):
            return 1
        print(Path(args[0]).read_text(), end='')
        return 0
    if tool == 'systemctl' and args[0] == 'show':
        print(state['units'][args[-1]])
        return 0
    if tool == 'systemctl' and args[0] == 'stop':
        state['units'][args[1]] = 'deactivating' if state.get('horizon_timeout') else 'inactive'
        save(state)
        # The stop happened, then the drain command failed: rescue must restore it.
        return 124 if state.get('horizon_timeout') else 1
    if tool == 'incus':
        action = args[0]
        if action == 'list':
            if 'csv' in args:
                for name, status in state['runners'].items():
                    print(f'{name},{status.upper()}')
            else:
                print(json.dumps([{'name': name, 'status': status} for name, status in state['runners'].items()]))
            return 0
        name = args[-1]
        if action == 'pause' and name == 'ci-b' and state.get('ci_partial_failure'):
            # An operator stops another runner after the initial inventory. It does not belong to this drain.
            state['runners']['ci-external'] = 'Stopped'
            save(state)
            return 1
        if action in ['stop', 'start']:
            state['runners'][name] = 'Stopped' if action == 'stop' else 'Running'
        save(state)
        return 0
    raise ValueError(f'Unexpected fixture command: {tool} {args}')


if __name__ == '__main__':
    if len(sys.argv) == 1:
        systemd()
    else:
        sys.exit(command(sys.argv[1], sys.argv[2:]))
