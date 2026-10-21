# Program: ci-runners.host
# Uses: lib/program.py
# Outcomes: converged matches drift occupied retained removed failed
# Timeout: 180
# Input: {"action": "converge"|"inspect"|"remove"|"purge"}
# Result: no data. occupied refuses removal while any instance remains, including stopped instances.
# Owns only the reserved Incus host resources. Never creates, registers, changes, or deletes runners.
import json
import subprocess

PROJECT = 'orbit-ci'
NETWORK = 'orbitci0'
ACL = 'orbit-ci-egress'
POOL = 'default'
PROJECT_CONFIG = {
    'features.images': 'true', 'features.profiles': 'true', 'features.storage.volumes': 'true',
    'features.storage.buckets': 'true', 'features.networks': 'false',
    'restricted': 'true', 'restricted.networks.access': NETWORK,
    'restricted.containers.nesting': 'block', 'restricted.containers.privilege': 'unprivileged',
    'restricted.virtual-machines.lowlevel': 'block', 'restricted.containers.lowlevel': 'allow',
    'limits.virtual-machines': '0',
}
NETWORK_CONFIG = {
    'ipv4.nat': 'true', 'ipv6.address': 'none', 'security.acls': ACL,
    'security.acls.default.egress.action': 'reject', 'security.acls.default.ingress.action': 'reject',
}
PROFILE = {
    'description': 'Orbit CI shard runner',
    'config': {'boot.autostart': 'true', 'limits.cpu': '6', 'limits.memory': '12GiB',
               'limits.processes': '8192', 'security.nesting': 'false', 'security.privileged': 'false'},
    'devices': {'eth0': {'name': 'eth0', 'network': NETWORK, 'type': 'nic'},
                'root': {'path': '/', 'pool': POOL, 'type': 'disk'}},
}
ACL_RULES = [
    {'action': 'reject', 'destination': '0.0.0.0/8,10.0.0.0/8,100.64.0.0/10,127.0.0.0/8,169.254.0.0/16,172.16.0.0/12,192.0.0.0/24,192.168.0.0/16,198.18.0.0/15,224.0.0.0/3', 'state': 'enabled'},
    {'action': 'reject', 'destination': '::/0', 'state': 'enabled'},
    {'action': 'allow', 'destination': '0.0.0.0/0', 'state': 'enabled'},
]


def incus(*args, document=None):
    # Query rejects --project; query URLs carry their own scope. Other commands override CLI defaults.
    command = ['incus', *args] if args[0] == 'query' else ['incus', '--project', 'default', *args]
    result = subprocess.run(command,
                            input=None if document is None else json.dumps(document),
                            text=True, capture_output=True, check=True, timeout=30)
    return result.stdout


def query(path):
    return json.loads(incus('query', path))


def default_path(resource):
    scope = '?project=default' if resource.split('/', 1)[0] in ('networks', 'network-acls') else ''
    return f'/1.0/{resource}{scope}'


def exists(collection, name):
    return any(path.split('?', 1)[0] == f'/1.0/{collection}/{name}'
               for path in query(default_path(collection)))


def project_path(suffix=''):
    return f'/1.0/{suffix}?project={PROJECT}'


def includes(actual, expected):
    return all(actual.get(key) == value for key, value in expected.items())


def rules(value):
    return [{key: item for key, item in rule.items() if item != ''} for rule in value]


def matches():
    if not all(exists(collection, name) for collection, name in [
        ('projects', PROJECT), ('networks', NETWORK), ('network-acls', ACL), ('storage-pools', POOL),
    ]):
        return False
    project = query(f'/1.0/projects/{PROJECT}')
    network = query(default_path(f'networks/{NETWORK}'))
    acl = query(default_path(f'network-acls/{ACL}'))
    profile = query(project_path('profiles/default'))
    return (includes(project['config'], PROJECT_CONFIG)
            and network['type'] == 'bridge' and network['managed']
            and network['config'].get('ipv4.address') not in (None, '', 'none', 'auto')
            and includes(network['config'], NETWORK_CONFIG)
            and rules(acl['egress']) == ACL_RULES and acl['ingress'] == []
            and profile['config'] == PROFILE['config'] and profile['devices'] == PROFILE['devices'])


def converge():
    if not exists('storage-pools', POOL):
        incus('storage', 'create', POOL, 'dir')
    if not exists('projects', PROJECT):
        incus('project', 'create', PROJECT, *[part for key, value in PROJECT_CONFIG.items()
                                            if key.startswith('features.') for part in ('-c', f'{key}={value}')])
    project = query(f'/1.0/projects/{PROJECT}')
    # Existing setup.sh resources are adopted by their reserved names. Refuse a different isolation model.
    if project['config'].get('features.networks') != 'false':
        raise RuntimeError('orbit-ci must share the default project networks')
    incus('project', 'set', PROJECT, *[f'{key}={value}' for key, value in PROJECT_CONFIG.items()])
    if not exists('network-acls', ACL):
        incus('network', 'acl', 'create', ACL)
    incus('network', 'acl', 'edit', ACL,
          document={'description': 'Orbit CI runner boundary', 'egress': ACL_RULES, 'ingress': [], 'config': {}})
    if not exists('networks', NETWORK):
        incus('network', 'create', NETWORK, '--type=bridge', 'ipv4.address=auto', 'ipv4.nat=true', 'ipv6.address=none')
    network = query(default_path(f'networks/{NETWORK}'))
    if network['type'] != 'bridge' or not network['managed']:
        raise RuntimeError('orbitci0 must be a managed bridge')
    incus('network', 'set', NETWORK, *[f'{key}={value}' for key, value in NETWORK_CONFIG.items()])
    incus('profile', 'edit', 'default', '--project', PROJECT, document=PROFILE)
    if not matches():
        raise RuntimeError('CI host projection did not converge')
    return 'converged', {}


def remove(purge):
    if exists('projects', PROJECT):
        if query(project_path('instances')):
            return 'occupied', {}
        if purge:
            # Incus refuses project deletion while images, volumes, or other profiles use it.
            incus('project', 'delete', PROJECT)
    if not purge:
        return 'retained', {}
    if exists('networks', NETWORK):
        incus('network', 'delete', NETWORK)
    if exists('network-acls', ACL):
        incus('network', 'acl', 'delete', ACL)
    return 'removed', {}


def main(payload):
    action = payload.get('action')
    if action == 'converge':
        return converge()
    if action == 'inspect':
        return ('matches' if matches() else 'drift'), {}
    if action in ('remove', 'purge'):
        return remove(action == 'purge')
    require(False, 'failed', 'invalid CI host action')


run_program(main)
