"""Incus boundary for the host-profile/guard contract; no daemon or network access."""
import copy
import json
import os
import sys
from pathlib import Path

path = Path(os.environ['PROBE_FIXTURE']) / 'state.json'
state = json.loads(path.read_text()) if path.exists() else {'profile': {'devices': {}, 'config': {}}, 'containers': {}, 'calls': []}
args = sys.argv[1:]
state['calls'].append(args)
command = args[2:] if args[:1] == ['--project'] else args
if command[:2] == ['profile', 'edit']:
    state['profile'] = json.load(sys.stdin)
elif command[:1] == ['query']:
    url = command[-1]
    if url.startswith('/1.0/profiles/default?'):
        print(json.dumps(state['profile']))
    elif url.startswith('/1.0/instances?'):
        print(json.dumps(list(state['containers'].values())))
    elif url.startswith('/1.0/instances/'):
        print(json.dumps(state['containers'][url.split('/1.0/instances/')[1].split('?')[0]]))
    elif url.startswith('/1.0/storage-pools/') and '/volumes/container/' in url:
        print('{"config":{}}')
    else:
        raise AssertionError(f'Unexpected Incus query: {args}')
elif command[:1] == ['init']:
    config = dict(state['profile']['config'])
    for index, value in enumerate(command):
        if value == '--config':
            key, setting = command[index + 1].split('=', 1)
            config[key] = setting
    state['containers'][command[2]] = {'name': command[2], 'project': args[1], 'expanded_config': config,
                                      'expanded_devices': copy.deepcopy(state['profile']['devices'])}
elif command[:1] == ['copy']:
    state['containers'][command[2]] = {**copy.deepcopy(state['containers'][command[1]]), 'name': command[2]}
elif command[:1] in (['start'], ['stop']):
    assert command[-1] in state['containers']
elif command[:1] == ['delete']:
    del state['containers'][command[-1]]
else:
    raise AssertionError(f'Unexpected Incus command: {args}')
path.write_text(json.dumps(state))
