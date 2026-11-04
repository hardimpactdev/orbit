"""Build private Ansible input for the task VM slot identity phase from the Gateway's admitted slot intent."""
import json
import os
import sys
from pathlib import Path


def unsafe(value):
    """Keep Gateway text literal while preserving ordinary YAML scalar types."""
    if isinstance(value, str):
        return ('!unsafe ' if any(m in value for m in ('{{', '{%', '{#')) else '') + json.dumps(value)
    if isinstance(value, dict):
        return '{' + ', '.join(json.dumps(k) + ': ' + unsafe(v) for k, v in value.items()) + '}'
    if isinstance(value, list):
        return '[' + ', '.join(unsafe(v) for v in value) + ']'
    return json.dumps(value)


def build(folder):
    os.umask(0o077)
    folder = Path(folder)
    intent = json.loads((folder / 'intent.json').read_text())
    ssh = intent['host_ssh_execution']
    target = ssh['target']
    address, port = target['host'], target['port']
    host = {'ansible_host': address, 'ansible_user': target['user'], 'ansible_port': port,
            'ansible_ssh_private_key_file': ssh['identity_file']}
    name = address if port == 22 else f'[{address}]:{port}'
    secrets = {}
    for path in folder.glob('secret-*'):
        secrets[path.name.removeprefix('secret-')] = path.read_text()
        path.unlink()
    (folder / 'inventory.yml').write_text(json.dumps({'all': {'children': {'slot_host': {'hosts': {'host': host}}}}}))
    (folder / 'known_hosts').write_text(''.join(f'{name} {key}\n' for key in target['host_keys']))
    (folder / 'vars.yml').write_text(unsafe({'identity': intent['identity'], 'run_folder': str(folder), 'secrets': secrets}) + '\n')


if __name__ == '__main__':
    build(sys.argv[1])
