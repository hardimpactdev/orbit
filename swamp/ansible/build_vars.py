"""Build private Ansible input from the Gateway's admitted role intent."""
import json
import os
import shlex
import sys
from pathlib import Path

TASK_VM_KINDS = ('task_vm.image_refresh', 'tasks.provision', 'task_vm.nested_ready', 'tasks.idle_save')
SSH_EXECUTION_KINDS = ('task_vm.nested_ready', 'tasks.idle_save')


def unsafe(value):
    """Keep Gateway text literal while preserving ordinary YAML scalar types."""
    if isinstance(value, str):
        return ('!unsafe ' if any(m in value for m in ('{{', '{%', '{#')) else '') + json.dumps(value)
    if isinstance(value, dict):
        return '{' + ', '.join(json.dumps(k) + ': ' + unsafe(v) for k, v in value.items()) + '}'
    if isinstance(value, list):
        return '[' + ', '.join(unsafe(v) for v in value) + ']'
    return json.dumps(value)


def known_host_lines(target):
    name = target['host'] if target['port'] == 22 else f'[{target["host"]}]:{target["port"]}'
    return [f'{name} {key}' for key in target['host_keys']]


def build_task_vm(folder, kind):
    """The inventory, known hosts and variables of the task VM role. A builder behind its host is reached through a jump."""
    folder = Path(folder)
    intent = json.loads((folder / 'intent.json').read_text())
    # A task start's one run holds the whole start intent in the file; the role converges the part that names it.
    if os.environ.get('ORBIT_INTENT_KEY'):
        intent = intent[os.environ['ORBIT_INTENT_KEY']]
    # The readiness and idle save intents name their connection ssh_execution; the other task VM intents name it ssh.
    ssh = intent['ssh_execution' if kind in SSH_EXECUTION_KINDS else 'ssh']
    target = dict(ssh['target'])
    # A guest of an Incus host has no address or host key when the Gateway admits the run; the run reads both from the host.
    if os.environ.get('ORBIT_TARGET_HOST'):
        target.update(host=os.environ['ORBIT_TARGET_HOST'].strip(), host_keys=[os.environ['ORBIT_TARGET_KEY'].strip()])
    known = set(known_host_lines(target))
    host = {'ansible_host': target['host'], 'ansible_user': target['user'], 'ansible_port': target['port'],
            'ansible_ssh_private_key_file': ssh['identity_file']}
    # A task VM with its identity is reached over WireGuard; only the base VM, which has none, is reached through its host.
    jump = None if intent.get('direct') else intent.get('jump')
    if jump:
        known.update(known_host_lines(jump['target']))
        # The jump asks the same pinned host keys as the build, so it never trusts a key on first use.
        command = ['ssh', '-W', '%h:%p', '-i', jump['identity_file'], '-p', str(jump['target']['port']), '-o', 'IdentitiesOnly=yes',
                   '-o', 'StrictHostKeyChecking=yes', '-o', 'GlobalKnownHostsFile=/dev/null', '-o', f'UserKnownHostsFile={folder}/known_hosts',
                   f'{jump["target"]["user"]}@{jump["target"]["host"]}']
        host['ansible_ssh_common_args'] = '-o ProxyCommand=' + shlex.quote(' '.join(shlex.quote(part) for part in command))
    inventory = {'all': {'children': {'task_vm': {'hosts': {'vm': host}}}}}
    (folder / 'inventory.yml').write_text(json.dumps(inventory))
    (folder / 'known_hosts').write_text(''.join(line + '\n' for line in sorted(known)))
    (folder / 'vars.yml').write_text(unsafe({'intent': intent, 'kind': kind}) + '\n')


def build_node_add(folder):
    """The inventory, known hosts and variables of a Linux Node enrollment. Every host key is pinned; nothing is scanned."""
    folder = Path(folder)
    intent = json.loads((folder / 'intent.json').read_text())
    observed = {'host_key': os.environ['ORBIT_HOST_KEY'].strip(),
                'bootstrap_authorized_keys': os.environ['ORBIT_BOOTSTRAP_AUTHORIZED_KEYS'].strip()}
    network = intent['network']
    control = intent['identity']['identity_file']
    known = {f'{intent["address"]} {observed["host_key"]}'}
    hosts = {'swamp': {'ansible_connection': 'local', 'ansible_python_interpreter': '{{ ansible_playbook_python }}'},
             'node-managed': {'ansible_host': intent['address'], 'ansible_user': intent['managed_user'], 'ansible_port': 22,
                         'ansible_ssh_private_key_file': control}}
    groups = {'controller': ['swamp'], 'managed_linux': ['node-managed'], 'bootstrap': [], 'node': [],
              'hubs': [], 'dns': []}

    def remote(name, group, ssh, key, **extra):
        target = ssh.get('target', ssh)
        known.update(f'{target["host"] if target["port"] == 22 else "[" + target["host"] + "]:" + str(target["port"])} {pin}'
                     for pin in target.get('host_keys', [observed['host_key']]))
        hosts[name] = {'ansible_host': target['host'], 'ansible_user': target['user'], 'ansible_port': target['port'],
                       'ansible_ssh_private_key_file': key, **extra}
        groups[group].append(name)

    bootstrap = intent['bootstrap']
    # Enrollment connects as the bootstrap account: first with the bootstrap key, which authorizes the Swamp key, then with that.
    remote('node-bootstrap', 'bootstrap', {**bootstrap, 'host_keys': [observed['host_key']]}, str(folder / 'bootstrap-identity'))
    remote('node-control', 'node', {**bootstrap, 'host_keys': [observed['host_key']]}, control)
    for index, hub in enumerate(network['hubs']):
        remote(f'hub{index}', 'hubs', hub['ssh'], hub['ssh']['identity_file'], hub_index=index)
    for index, dns in enumerate(intent['private_dns']):
        # The DNS host is the Node being enrolled; the Gateway holds a placeholder for its host key until the run observes it.
        remote(f'dns{index}', 'dns', {'target': {**dns['ssh']['target'], 'host_keys': [observed['host_key']]}},
               dns['ssh']['identity_file'], dns_index=index)
    secrets = {}
    for path in folder.glob('secret-*'):
        secrets[path.name.removeprefix('secret-')] = path.read_text()
        path.unlink()
    hub_files = [[file for file in network['hub_files'] if file['ssh']['target']['host'] == hub['ssh']['target']['host']]
                 for hub in network['hubs']]
    inventory = {'all': {'hosts': hosts, 'children': {name: {'hosts': {key: {} for key in members}} for name, members in groups.items()}}}
    (folder / 'inventory.yml').write_text(json.dumps(inventory))
    (folder / 'known_hosts').write_text(''.join(line + '\n' for line in sorted(known)))
    # The role plans, CLI plan and DNS publications belong to child workflows; the playbook needs none of them.
    kept = {key: value for key, value in intent.items() if key not in ('roles', 'cli', 'dns')}
    (folder / 'vars.yml').write_text(unsafe({'intent': kept, 'kind': 'node.add', 'observed': observed,
                                         'secrets': secrets, 'hub_files': hub_files}) + '\n')


def build(folder, kind):
    os.umask(0o077)
    if kind == 'node.role_add.composed':
        folder = Path(folder)
        (folder / 'composed.yml').write_text(unsafe(json.loads((folder / 'composed.json').read_text())) + '\n')
        return
    if kind == 'node.add':
        return build_node_add(folder)
    if kind in TASK_VM_KINDS:
        return build_task_vm(folder, kind)
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
        name: {'hosts': {key: {} for key in members}} for name, members in groups.items()
    }}}
    secrets = {}
    for path in folder.glob('secret-*'):
        secrets[path.name.removeprefix('secret-')] = path.read_text()
        path.unlink()
    (folder / 'inventory.yml').write_text(json.dumps(inventory))
    (folder / 'known_hosts').write_text(''.join(line + '\n' for line in sorted(known)))
    plans = json.loads((folder / 'plans.json').read_text())
    containers = []
    for service in (intent['runtime'].get('metrics') or {}).get('plan', {}).get('services', []):
        argv = service['argv']
        def values(flag, argv=argv):
            return [argv[index + 1] for index, value in enumerate(argv[:-1]) if value == flag]
        def mapping(flag, values=values):
            return dict(value.split('=', 1) for value in values(flag))
        separator = argv.index('--')
        containers.append({
            **service, 'labels': mapping('--label'), 'env': mapping('--env'),
            'log_options': mapping('--log-opt'), 'log_driver': values('--log-driver')[0],
            'network': values('--network')[0], 'command': argv[separator + 2:],
            'healthcheck': {'test': ['CMD-SHELL', values('--health-cmd')[0]],
                            'start_period': values('--health-start-period')[0],
                            'interval': values('--health-interval')[0], 'retries': int(values('--health-retries')[0])},
            'volume': {'name': service['volume_args'][-1],
                       'labels': dict(service['volume_args'][index + 1].split('=', 1)
                                      for index, value in enumerate(service['volume_args'][:-1]) if value == '--label')},
        })
    (folder / 'vars.yml').write_text(unsafe({'intent': intent, 'kind': kind, 'secrets': secrets,
                                         'plans': plans, 'containers': containers}) + '\n')


if __name__ == '__main__':
    build(*sys.argv[1:3])
