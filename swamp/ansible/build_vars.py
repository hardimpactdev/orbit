"""Build private Ansible input from the Gateway's admitted role intent."""
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


def build(folder, kind):
    os.umask(0o077)
    folder = Path(folder)
    intent = json.loads((folder / 'intent.json').read_text())
    hosts, known = {}, set()

    def host(node_id, ssh, keys=None):
        target = ssh.get('target', ssh)
        address = target.get('host', target.get('nodeHost'))
        port = target.get('port', target.get('nodePort'))
        name = address if port == 22 else f'[{address}]:{port}'
        for key in keys or target['host_keys']:
            known.add(f'{name} {key}')
        return hosts.setdefault(f'n{node_id}', {
            'ansible_host': address, 'ansible_user': target.get('user', target.get('nodeUser')),
            'ansible_port': port,
            'ansible_ssh_private_key_file': ssh.get('identity_file', ssh.get('nodeIdentityFile')),
            'publications': [], 'exporters': [],
        })

    host(intent['node_id'], intent['ssh'])
    for publication in intent['publications']:
        host(publication['node_id'], publication['connection'], publication['host_keys'])['publications'].append(publication)
    for publication in intent['publications']:
        for side in ('before', 'after'):
            php = publication[side].get('php')
            if php and php['configurations'] == []:
                php['configurations'] = {}
    for exporter in intent['exporters']:
        host(exporter['exporter_node_id'], exporter['ssh_execution'])['exporters'].append(exporter)
    if intent['prometheus']:
        host(intent['prometheus']['node_id'], intent['prometheus']['ssh'])
    analytics = intent['runtime']['analytics']
    if analytics:
        host(analytics['clickhouse_node_id'], analytics['clickhouse_ssh'])

    groups = {
        'role_node': [f'n{intent["node_id"]}'],
        'clickhouse_node': [f'n{analytics["clickhouse_node_id"]}'] if analytics else [],
        'publication_nodes': [name for name, entry in hosts.items() if entry['publications']],
        'exporter_nodes': [name for name, entry in hosts.items() if entry['exporters']],
        'prometheus_node': [f'n{intent["prometheus"]["node_id"]}'] if intent['prometheus'] else [],
    }
    inventory = {'all': {'hosts': hosts, 'children': {
        name: {'hosts': dict.fromkeys(members, {})} for name, members in groups.items()
    }}}
    secrets = {}
    for path in folder.glob('secret-*'):
        secrets[path.name.removeprefix('secret-')] = path.read_text()
        path.unlink()
    (folder / 'inventory.yml').write_text(json.dumps(inventory))
    (folder / 'known_hosts').write_text(''.join(line + '\n' for line in sorted(known)))
    plans = json.loads((folder / 'plans.json').read_text())
    (folder / 'vars.yml').write_text(unsafe({'intent': intent, 'kind': kind, 'secrets': secrets, 'plans': plans}) + '\n')


if __name__ == '__main__':
    build(*sys.argv[1:3])
