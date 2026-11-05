#!/usr/bin/env python3
"""Thin adapter to the one fixture bootstrap, inside the owned nested Gateway."""
import importlib
import json
import shutil
import sys
from pathlib import Path

SOURCE = Path('/home/orbit/orbit')
sys.path.insert(0, str(SOURCE / 'tools/swamp-proof-guest'))
boot = importlib.import_module('boot')
fixture = importlib.import_module('fixture')


def context(facts):
    nodes = facts['nodes']  # the Incus transport addresses; the private network is facts['wireguard']
    wireguard = facts.get('wireguard')  # absent only for phases that never seed or serve the Gateway
    topology = {'network': {'gateway': nodes['gateway']['incus_address'], 'prefix': 24, 'name': 'eth0'},
                'operator': {'address': wireguard['nodes']['operator']['ip'] if wireguard else None},
                'wireguard': wireguard, 'websocket': True}
    state = Path('/home/orbit/.orbit/proof-control')
    return fixture.context(SOURCE, topology, state=state, repo=state / 'repo', nested=True)


def driver(ctx, action, value):
    output = fixture.run(ctx, ['php', str(SOURCE / 'tools/swamp-proof-guest/gateway.php'), action],
                         user='orbit', cwd=ctx.GATEWAY, input=json.dumps(value))
    return json.loads(output.strip().splitlines()[-1]) if output.strip() else None


def candidate(ctx):
    """Apply the candidate to a Gateway restored from a ready copy.

    The caller has synced the candidate's code into the guest's own checkout: its runtime state stays on disk, so only
    the candidate's changes remain. Caddy needs access to the new directories, the schema moves to the candidate's migrations, the Swamp
    repository takes the candidate's workflows (serve hot-reloads them), and the PHP workers that load the code again.
    """
    fixture.caddy_access(ctx)
    fixture.run(ctx, ['php', 'artisan', 'package:discover'], user=ctx.ACCOUNT, cwd=ctx.GATEWAY)
    fixture.run(ctx, ['php', 'artisan', 'migrate', '--force'], user=ctx.ACCOUNT, cwd=ctx.GATEWAY, timeout=600)
    pins = 'extensions/models/upstream_extensions.json'
    before = json.loads((ctx.REPO / pins).read_text())
    # `.swamp.yaml` holds the datastore the build set up, and Swamp rewrites `.gitignore`: both belong to this repository.
    shutil.copytree(SOURCE / 'swamp', ctx.REPO, dirs_exist_ok=True,
                    ignore=shutil.ignore_patterns('.swamp', '.swamp.yaml', '.gitignore', 'node_modules', '__pycache__'))
    fixture.run(ctx, ['chown', '-R', 'orbit:orbit', str(ctx.REPO)])
    after = json.loads((ctx.REPO / pins).read_text())
    pulled = [name + '@' + entry['version'] for name, entry in after.items() if (before.get(name) or {}).get('version') != entry['version']]
    for pull in pulled:
        fixture.native(ctx, 'extension', 'pull', pull, timeout=300)
    fixture.run(ctx, ['systemctl', 'restart', 'orbit-proof-operations', 'orbit-proof-agent-view'])
    return {'migrated': True, 'workflows': 'synced', 'extensions_pulled': pulled}


def main(action, facts):
    ctx = context(facts)
    if action == 'swamp-state':
        ctx.STATE.mkdir(parents=True, exist_ok=True, mode=0o700)
        shutil.copytree(SOURCE / 'swamp', ctx.REPO, ignore=shutil.ignore_patterns('.swamp', 'node_modules', '__pycache__'))
        ctx.IDENTITY.parent.mkdir(parents=True, exist_ok=True, mode=0o700)
        fixture.run(ctx, ['ssh-keygen', '-q', '-t', 'ed25519', '-N', '', '-f', str(ctx.IDENTITY)])
        fixture.run(ctx, ['chown', '-R', 'orbit:orbit', str(ctx.IDENTITY.parent)])
        fixture.run(ctx, ['install', '-D', '-o', 'root', '-g', 'orbit', '-m', '0440', str(ctx.IDENTITY), str(ctx.ROLE_CREDENTIALS / 'ssh-identity')])
        fixture.run(ctx, ['install', '-D', '-m', '0600', str(ctx.IDENTITY), '/etc/orbit/node-proxy/id_ed25519'])
        fixture.swamp(ctx)
        return {'configured': True, 'worker_public_key': ctx.IDENTITY.with_suffix('.pub').read_text()}
    if action == 'gateway-db':
        seeded = fixture.gateway(ctx, facts['targets'])
        fixture.private(ctx, ctx.STATE / 'fixture.json', json.dumps(seeded))
        return {'seeded': True, 'command_proof': False, **seeded}
    if action == 'gateway-runtime':
        seeded = json.loads((ctx.STATE / 'fixture.json').read_text())
        fixture.realtime(ctx, seeded['nodes'])
        fixture.projection_queue(ctx)
        boot.apply(lambda argv, input=None: fixture.run(ctx, argv, input=input), 'gateway', fixture.member_address(ctx), checkout=str(SOURCE))
        baseline = json.loads(fixture.run(ctx, ['php', str(SOURCE / 'tools/swamp-proof-guest/gateway.php'), 'proof-state'], user=ctx.ACCOUNT, cwd=ctx.GATEWAY, input='{}'))
        return {'baseline_commands': baseline['baseline_commands'], 'agents': {key: driver(ctx, 'agent', {'node': value}) for key, value in seeded['nodes'].items() if key != 'operator'}}
    if action == 'candidate':
        return candidate(ctx)
    if action == 'worker':
        rows = fixture.native(ctx, 'worker', 'list', '--server', ctx.URL, '--token-file', str(ctx.STATE / 'credentials/operator'), timeout=15)['workers']
        enrolled = [row for row in rows if row.get('tokenName') == 'orbit-node-209' and row.get('status') in ('idle', 'busy')]
        if not enrolled:
            raise RuntimeError('The orbit-node-209 worker is not enrolled in Swamp serve yet.')
        return {'enrolled': True, 'workers': len(enrolled)}
    if action == 'install-agent':
        fixture.install_agent(ctx, facts['rendered'], facts['secret'])
        return {'installed': True}
    if action == 'readiness':
        # A real execution, not worker-list polling. Native queueTimeout bounds dispatch.
        steps = []
        for key, node in facts['managed'].items():
            steps.append({'name': key, 'labels': {'role': 'swamp'}, 'task': {
                'type': 'behavior', 'behaviorType': '@swamp/ssh', 'behaviorName': 'proof-ssh-' + key,
                'methodName': 'exec', 'globalArgs': {'name': 'proof-ssh-' + key,
                    'transport': {'kind': 'ssh', 'user': 'orbit', 'port': 22,
                                  'identityFile': '/etc/orbit/swamp-role-worker/ssh-identity',
                                  'hostKeys': ['ssh-ed25519 ' + node['host_key']]},
                    'hosts': [{'name': 'target', 'address': node['address']}]},
                'inputs': {'hosts': 'name:target', 'sudo': True, 'argv': ['timeout', '30', '/usr/sbin/ufw', 'status', 'numbered']}}})
        definition = {'id': '0ca8181b-8678-497e-995f-a80dec477893', 'name': 'proof-worker-ssh',
                      'version': 1, 'queueTimeout': 30, 'jobs': [{'name': 'probe', 'steps': steps}]}
        (ctx.REPO / 'workflows/workflow-proof-worker-ssh.yaml').write_text(json.dumps(definition))
        for workflow in ('operation-firewall-direct', 'proof-worker-ssh'):
            fixture.native(ctx, 'workflow', 'validate', workflow)
        probe = fixture.native(ctx, 'workflow', 'run', 'proof-worker-ssh', '--server', ctx.URL,
                               '--token-file', str(ctx.STATE / 'credentials/operator'), timeout=180)
        if probe.get('status') != 'succeeded':
            raise RuntimeError('Native worker SSH probe failed: ' + json.dumps(probe))
        return {'passed': True, 'ssh_probe': probe, 'workflows': ['operation-firewall-direct', 'proof-worker-ssh']}
    raise RuntimeError('Unknown guest phase: ' + action)


if __name__ == '__main__':
    print(json.dumps(main(sys.argv[1], json.load(sys.stdin))))
