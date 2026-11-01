"""F10 proof orchestration. Provision and destroy only through the normal Gateway API."""
import argparse
import json
import os
import re
import secrets
import signal
import ssl
import sys
import time
import urllib.error
import urllib.request
from datetime import UTC, datetime, timedelta
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
TERMINAL = {'succeeded', 'failed', 'cancelled', 'interrupted'}
BINDING = re.compile(r'\$\{([^{}]+)\}')
KIND = re.compile(r'[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*\Z')
LIFECYCLE_SECONDS = 1800  # Observer deadline, never a kind execution budget.


class ProofError(Exception):
    """A bounded public failure; remote diagnostics and credentials are not copied here."""


def utc():
    return datetime.now(UTC).isoformat().replace('+00:00', 'Z')


def positive(value, field):
    if type(value) is not int or value <= 0:
        raise ProofError(f'Invalid {field}; expected a positive integer.')
    return value


def relative_file(root, value):
    if not isinstance(value, str) or Path(value).is_absolute():
        raise ProofError('Fixture paths must be relative.')
    path = (root / value).resolve()
    if not path.is_relative_to(root.resolve()) or not path.is_file():
        raise ProofError('Fixture file is missing or outside its allowed directory.')
    return path


def bind(value, context, now):
    """The frozen v0 substitutions, with whole-value types preserved."""
    def resolve(name):
        if re.fullmatch(r'proof\.(now|epoch)\+[0-9]+', name):
            instant = now + timedelta(seconds=int(name.split('+')[1]))
            return int(instant.timestamp()) if name.startswith('proof.epoch') else instant.isoformat().replace('+00:00', 'Z')
        allowed = {'proof.operation_id', 'proof.start_tag'} | {
            'proof.control.' + key for key in ('node_id', 'identity_file', 'public_key')
        } | {'proof.target.' + key for key in ('node_id', 'name', 'host', 'user', 'port', 'host_keys')}
        if name not in allowed or name not in context:
            raise ProofError(f'Unknown or unavailable binding: {name}')
        return context[name]
    if isinstance(value, dict):
        return {key: bind(item, context, now) for key, item in value.items()}
    if isinstance(value, list):
        return [bind(item, context, now) for item in value]
    if not isinstance(value, str):
        return value
    match = BINDING.fullmatch(value)
    if match:
        return resolve(match[1])
    def replace(match):
        resolved = resolve(match[1])
        if type(resolved) not in (str, int):
            raise ProofError('A structured binding must fill a whole JSON value.')
        return str(resolved)
    result = BINDING.sub(replace, value)
    if '${' in result:
        raise ProofError('Malformed fixture binding.')
    return result


def fixture_for(kind, root=ROOT):
    if not KIND.fullmatch(kind):
        raise ProofError('Invalid kind.')
    path = root / 'swamp/proof' / (kind + '.json')
    fixture = json.loads(path.read_text())
    required = {'kind', 'workflow', 'budget_seconds', 'queue_timeout_seconds', 'envelope', 'secrets', 'expect', 'phases', 'parity'}
    if set(fixture) - required - {'target_setup', 'topology'} or required - set(fixture) or fixture['kind'] != kind:
        raise ProofError('Fixture does not match frozen v0 fields.')
    positive(fixture['budget_seconds'], 'budget_seconds')
    if fixture['queue_timeout_seconds'] is not None:
        positive(fixture['queue_timeout_seconds'], 'queue_timeout_seconds')
    if fixture['envelope'].get('kind') != kind:
        raise ProofError('Fixture kind and envelope kind differ.')
    if set(fixture['envelope']) != {'operation_id', 'node_id', 'kind', 'intent', 'execution_secrets'}:
        raise ProofError('Fixture requires the exact Gateway input envelope.')
    # Validate every binding before creating any group.
    example = {'proof.operation_id': 1, 'proof.start_tag': 'validation'}
    example.update({'proof.control.' + key: value for key, value in {'node_id': 1, 'identity_file': '/tmp/key', 'public_key': 'public'}.items()})
    example.update({'proof.target.' + key: value for key, value in {'node_id': 2, 'name': 'target', 'host': '127.0.0.1', 'user': 'orbit', 'port': 22, 'host_keys': ['public']}.items()})
    bind(fixture['envelope'], example, datetime.now(UTC))
    folder = root / 'swamp/proof' / kind
    if fixture.get('target_setup'):
        relative_file(root / 'swamp/proof', fixture['target_setup'])
    for secret in fixture['secrets']:
        if set(secret) - {'vault', 'key', 'value', 'stage_to_target'} or not {'vault', 'key', 'value'} <= set(secret):
            raise ProofError('Invalid throwaway secret fields.')
        if any(not isinstance(secret[key], str) or not re.fullmatch(r'[a-zA-Z0-9_.-]+', secret[key]) for key in ('vault', 'key')):
            raise ProofError('Invalid throwaway vault/key name.')
        if 'stage_to_target' in secret and type(secret['stage_to_target']) is not bool:
            raise ProofError('stage_to_target must be boolean.')
        value = secret.get('value')
        if value not in ('generate:hex32', 'generate:ed25519'):
            if not isinstance(value, str) or not value.startswith('file:'):
                raise ProofError('Only v0 throwaway secret sources are accepted.')
            relative_file(folder, value[5:])
    if not fixture['phases'] or not fixture['parity'].get('cases') or not fixture['parity'].get('command'):
        raise ProofError('Proof requires named phases and legacy parity cases/command.')
    for phase in fixture['phases']:
        if set(phase) != {'name', 'job', 'step', 'budget_seconds'}:
            raise ProofError('Invalid phase fields.')
        if any(not isinstance(phase[key], str) or not phase[key] for key in ('name', 'job', 'step')):
            raise ProofError('Each phase needs its name and exact native job/step.')
        positive(phase['budget_seconds'], 'phase budget_seconds')
    if fixture['expect'].get('status') not in TERMINAL or set(fixture['expect']) != {'status', 'outputs'}:
        raise ProofError('Expected native status must be terminal.')
    outputs = fixture['expect'].get('outputs')
    if outputs is not None and (not isinstance(outputs, dict) or set(outputs) != {'job', 'step', 'match'} or not isinstance(outputs['match'], dict)):
        raise ProofError('Expected outputs require job, step and a partial match object.')
    if outputs is None or any(not isinstance(outputs[key], str) or not outputs[key] for key in ('job', 'step')):
        raise ProofError('Expected outputs require the exact terminal job and step names.')
    parity = fixture['parity']
    if set(parity) != {'command', 'cases'} or any(not isinstance(parity[key], list) or not parity[key] or
            any(not isinstance(value, str) or not value for value in parity[key]) for key in ('command', 'cases')):
        raise ProofError('Parity requires a command argv and named legacy cases.')
    # Validate placement/queue policy against the exact unmodified workflow before any VM admission.
    import yaml
    workflows = [yaml.safe_load(path.read_text()) for path in (root / 'swamp/workflows').glob('*.yaml')]
    matches = [flow for flow in workflows if flow.get('name') == fixture['workflow']]
    if len(matches) != 1 or matches[0].get('queueTimeout') != fixture['queue_timeout_seconds']:
        raise ProofError('Fixture queue timeout differs from its unique source workflow.')
    return fixture


def topology_for(name, root=ROOT):
    if not isinstance(name, str) or not re.fullmatch(r'[a-z][a-z0-9_-]*(?:\+ingress)?', name):
        raise ProofError('Invalid topology name.')
    folder = root / 'swamp/proof/topologies' / name
    topology = json.loads((folder / 'topology.json').read_text())
    if topology.get('name') != name or set(topology.get('targets', {})) != {'default'}:
        raise ProofError('v0 requires one named default target in its topology.')
    positive(topology.get('version'), 'topology version')
    relative_file(folder, topology['targets']['default']['setup'])
    image = topology.get('image', {})
    if set(image) != {'alias', 'type', 'fingerprint'} or image.get('alias') != f'orbit-proof-{name}-v{topology["version"]}' or image.get('type') != 'container':
        raise ProofError('Topology image must use its versioned container alias.')
    if image['fingerprint'] is not None and not re.fullmatch(r'[a-f0-9]{64}', image['fingerprint']):
        raise ProofError('Topology image fingerprint must be a full SHA256 or null.')
    return topology


def proof_project(gateway, selected=None):
    if selected is not None:
        return positive(selected, 'proof Project')
    projects = gateway.request('GET', 'projects')
    found = [project for project in projects if project['slug'] == 'swamp-proof']
    if len(found) != 1:
        raise ProofError('The canonical swamp-proof Project must exist before the proof runs.')
    return positive(found[0]['id'], 'proof Project')


def partial_match(actual, expected):
    if isinstance(expected, dict):
        return isinstance(actual, dict) and all(key in actual and partial_match(actual[key], value) for key, value in expected.items())
    return type(actual) is type(expected) and actual == expected


def native_step(history, job, step):
    found = [entry for group in history['jobs'] if group['name'] == job for entry in group['steps'] if entry['name'] == step]
    if len(found) != 1:
        raise ProofError('Native history lacks one exact fixture job/step.')
    return found[0]


def evaluate(history, fixture, parity_exit):
    """Use native timestamps, never wall time spent installing or bootstrapping."""
    def seconds(row):
        start = datetime.fromisoformat(row['startedAt'])
        end = datetime.fromisoformat(row['completedAt'])
        elapsed = (end - start).total_seconds()
        if elapsed < 0:
            raise ProofError('Native history has reversed timestamps.')
        return elapsed
    total = seconds(history)
    phases = []
    for phase in fixture['phases']:
        row = native_step(history, phase['job'], phase['step'])
        elapsed = seconds(row)
        phases.append({'name': phase['name'], 'seconds': elapsed, 'budget_seconds': phase['budget_seconds'],
                       'pass': elapsed <= phase['budget_seconds']})
    expected = fixture['expect']
    matches = history['status'] == expected['status']
    if 'outputs' in expected:
        output = expected['outputs']
        matches = matches and partial_match(native_step(history, output['job'], output['step']).get('outputs'), output['match'])
    budget = total <= fixture['budget_seconds'] and all(row['pass'] for row in phases)
    return {'native_run_id': history['id'], 'status': history['status'], 'total_seconds': total, 'phases': phases,
            'budget_pass': budget, 'expect_pass': matches, 'queue_timeout_seconds': fixture['queue_timeout_seconds'],
            'queue_timeout_note': 'inherits serve default 600' if fixture['queue_timeout_seconds'] is None else 'explicit workflow policy',
            'parity': {'passed': parity_exit == 0, 'failed': [] if parity_exit == 0 else fixture['parity']['cases'],
                       'cases': fixture['parity']['cases']}}


class Gateway:
    def __init__(self, config):
        data = json.loads(config.read_text())
        profile = data['gateways'][data['active_gateway']]
        self.url = profile['url'].rstrip('/')
        if not self.url.startswith('https://'):
            raise ProofError('The existing Gateway profile must use HTTPS.')
        self.tls = ssl.create_default_context(cafile=profile.get('ca_path'))

    def request(self, method, path, body=None, key=None):
        headers = {'Accept': 'application/json'}
        if body is not None:
            headers['Content-Type'] = 'application/json'
        if key:
            headers['Idempotency-Key'] = key
        request = urllib.request.Request(self.url + '/api/v1/' + path, method=method,
                                        data=None if body is None else json.dumps(body).encode(), headers=headers)
        try:
            with urllib.request.urlopen(request, context=self.tls, timeout=30) as response:
                return json.load(response)['data']
        except urllib.error.HTTPError as error:
            payload = json.load(error)
            code = payload.get('error', {}).get('code', 'gateway.request_failed')
            raise ProofError(f'Gateway {method} {path} refused: {code}') from None
        except (OSError, ValueError, KeyError):
            raise ProofError(f'Gateway {method} {path} response unavailable; no mutation is retried.') from None


def wait_operation(gateway, operation, kind, target, timeout=LIFECYCLE_SECONDS):
    deadline = time.monotonic() + timeout
    while True:
        if operation.get('kind') != kind or operation.get('target_type') != 'task_group' or operation.get('target_id') != target:
            raise ProofError('Lifecycle Operation identity does not match the proof group.')
        if operation['status'] in TERMINAL:
            if operation['status'] != 'succeeded':
                raise ProofError(f'{kind} operation {operation["id"]} ended {operation["status"]}: {operation.get("error_code")}')
            return operation
        if time.monotonic() >= deadline:
            raise ProofError(f'Observation deadline reached for {kind} operation {operation["id"]}; native terminal state is unproved.')
        time.sleep(2)
        operation = gateway.request('GET', f'operations/{operation["id"]}')


def provision(gateway, project, kind, role, nonce, record, save):
    group = gateway.request('POST', 'task-groups', {
        'project_id': project, 'title': f'proof {kind}',
        'brief': 'Disposable F10 proof infrastructure. No product work, publication or merge.',
        'orchestration': 'external', 'status': 'todo',
        'tasks': [{'title': 'Run isolated native proof', 'brief': 'Exercise the fixture and retain the proof receipt.',
                   'deliverables': [{'id': 'proof', 'type': 'review', 'description': 'Native parity and timing receipt.'}]}],
    })
    record.update(group_id=positive(group['id'], 'group_id'))
    save()
    operation = gateway.request('POST', f'task-groups/{group["id"]}/provision', {}, f'swamp-proof:{nonce}:{role}:provision')
    record['provision_operation_id'] = positive(operation['id'], 'operation_id')
    save()
    operation = wait_operation(gateway, operation, 'tasks.provision', group['id'])
    result, admitted = operation['result'], operation['input']
    for field in ('task_vm_id', 'node_id', 'instance_id'):
        record[field] = positive(result.get(field), field)
    if admitted.get('host_node_id') != 13 or admitted.get('provider') != 'incus' or admitted.get('vm', {}).get('project') != 'orbit-tasks' or admitted.get('create', {}).get('network') != 'orbittask0':
        save()
        raise ProofError('Normal placement did not return the required Shark/orbit-tasks/orbittask0 VM; proof refused.')
    node = gateway.request('GET', f'nodes/{record["node_id"]}')
    if node['status'] != 'active' or node['name'] != f'tvm-{record["task_vm_id"]}' or node['user'] == 'root':
        raise ProofError('Normal provisioning did not return an active owned non-root guest Node.')
    record.update(host_node_id=13, name=node['name'], instance_id=result['instance_id'])
    save()
    return {'record': record, 'node': node, 'result': result, 'input': admitted}


def destroy(gateway, record, nonce):
    if 'group_id' not in record:
        return
    group = record['group_id']
    operation = gateway.request('POST', f'task-groups/{group}/cancel', {}, f'swamp-proof:{nonce}:{group}:cancel')
    record['cancel_operation_id'] = positive(operation['id'], 'cancel_operation_id')
    wait_operation(gateway, operation, 'tasks.cancel', group)
    remaining = gateway.request('GET', f'task-groups/{group}')
    if remaining.get('status') != 'cancelled' or remaining.get('taskable_id') is not None:
        raise ProofError(f'Cancellation did not clear proof group {group}.')
    if 'task_vm_id' in record and remaining.get('vm_power') != 'destroyed':
        raise ProofError(f'Provider destruction is not confirmed for proof group {group}.')
    record['destroyed'] = True


def run(gateway, project, fixture, execute, receipt, save):
    nonce = secrets.token_hex(8)
    receipt.update(kind=fixture['kind'], workflow=fixture['workflow'], budget_seconds=fixture['budget_seconds'],
                   started_at=utc(), status='failed', destroyed=False, control_vm={}, target_vm={}, phases=[],
                   parity={'passed': False, 'failed': list(fixture['parity']['cases']), 'cases': fixture['parity']['cases']})
    try:
        # A dedicated Project's normal exclusion config is required; never edit placement or capacity here.
        config = gateway.request('GET', f'projects/{project}')
        exclusions = gateway.request('GET', f'projects/{project}/excluded-nodes')
        excluded = {row['id'] for row in exclusions}
        if config.get('slug') != 'swamp-proof' or 9 not in excluded or 13 in excluded or config.get('task_workspace_routed') or config.get('review_and_merge'):
            raise ProofError('Proof Project must exclude Beast, allow Shark, and disable workspace routing and review/merge.')
        title = fixture.get('_group_title', fixture['kind'])
        control = provision(gateway, project, title, 'control', nonce, receipt['control_vm'], save)
        control['host'] = gateway.request('GET', 'nodes/13')
        if control['host'].get('name') != 'shark' or control['host'].get('status') != 'active':
            raise ProofError('The normal provider host is not active Shark.')
        receipt.update(execute(control, fixture))
        receipt['target_vm'] = {'task_vm_id': receipt['control_vm']['task_vm_id'], 'container': 'proof-target', 'type': 'incus-container'}
    except (ProofError, OSError, ValueError, KeyError, KeyboardInterrupt) as error:
        receipt['error'] = str(error) if isinstance(error, ProofError) else 'Proof interrupted or local input unavailable.'
    finally:
        # One failed cleanup must never prevent attempting the other owned VM.
        for role in ('control_vm',):
            try:
                destroy(gateway, receipt[role], nonce)
            except (ProofError, OSError, ValueError, KeyError, KeyboardInterrupt) as error:
                receipt[role]['cleanup_error'] = str(error) if isinstance(error, ProofError) else 'Cleanup observation unavailable.'
                receipt['status'] = 'failed'
            save()
        owned = [receipt['control_vm']] if 'group_id' in receipt['control_vm'] else []
        receipt['destroyed'] = bool(owned) and all(vm.get('destroyed') is True for vm in owned)
        if receipt['target_vm']:
            receipt['target_vm']['destroyed'] = receipt['destroyed']
        receipt['finished_at'] = utc()
        receipt['proof_pass'] = (receipt.get('expect_pass') is True and receipt.get('budget_pass') is True and
                                 receipt['parity'].get('passed') is True and receipt['destroyed'] and not receipt.get('error') and
                                 not receipt['control_vm'].get('cleanup_error'))
        save()
    return receipt['proof_pass']


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('kind', nargs='+')
    parser.add_argument('--topology')
    parser.add_argument('--project', type=int, default=os.environ.get('ORBIT_PROOF_PROJECT_ID'))
    parser.add_argument('--gateway-config', type=Path, default=Path(os.environ.get('ORBIT_HOME', Path.home() / '.orbit')) / 'config.json')
    args = parser.parse_args(argv)
    try:
        fixtures = [fixture_for(kind) for kind in args.kind]
        if len(set(args.kind)) != len(args.kind):
            raise ProofError('Each kind may appear once in a proof batch.')
        secrets_seen = {}
        for item in fixtures:
            for secret in item['secrets']:
                identity = (secret['vault'], secret['key'])
                source = (item['kind'], secret['value']) if secret['value'].startswith('file:') else secret['value']
                if identity in secrets_seen and secrets_seen[identity] != source:
                    raise ProofError('Batch fixtures disagree on one throwaway vault key.')
                secrets_seen[identity] = source
        topology = topology_for(args.topology or fixtures[0].get('topology', 'app-dev'))
        if any(fixture.get('topology', topology['name']) != topology['name'] for fixture in fixtures) and not args.topology:
            raise ProofError('Several default topologies require an explicit --topology selection.')
        for fixture in fixtures:
            if topology['image']['fingerprint'] is None and fixture['kind'] not in topology.get('pilot_kinds', []):
                raise ProofError('app-dev container recipe image is not available for this kind; only the harness pilot is admitted.')
        fixture = dict(fixtures[0], _group_title=' '.join(args.kind) + f' {topology["name"]}@{topology["version"]}')
        gateway = Gateway(args.gateway_config)
        project = proof_project(gateway, args.project)
        from swamp_proof_control import execute
        stamp = datetime.now(UTC).strftime('%Y%m%dT%H%M%S.%fZ') + '.json'
        paths = {item['kind']: Path.home() / 'swamp-execution/evidence/proof' / item['kind'] / stamp for item in fixtures}
        for path in paths.values():
            path.parent.mkdir(parents=True, exist_ok=True)
        receipt = {}
        def save():
            for kind, path in paths.items():
                result = next((row for row in receipt.get('proofs', []) if row['kind'] == kind), {})
                document = dict(receipt, **result)
                document.update(kind=kind, topology=topology['name'], topology_version=topology['version'],
                                image_missing=topology['image']['fingerprint'] is None, image_fingerprint=topology['image']['fingerprint'])
                pending = path.with_suffix('.pending')
                with pending.open('w', opener=lambda name, flags: os.open(name, flags, 0o600)) as stream:
                    stream.write(json.dumps(document, indent=2) + '\n')
                pending.replace(path)
        def interrupted(_signal, _frame):
            raise KeyboardInterrupt
        for number in (signal.SIGTERM, signal.SIGHUP, signal.SIGINT):
            signal.signal(number, interrupted)
        def execute_all(control, _fixture):
            return execute(control, fixtures, topology, receipt, save)
        passed = run(gateway, project, fixture, execute_all, receipt, save)
        for path in paths.values():
            print(path)
        if not passed:
            print(receipt.get('error', 'Proof or cleanup did not pass; inspect the receipt.'), file=sys.stderr)
        return 0 if passed else 1
    except (ProofError, OSError, ValueError, KeyError) as error:
        print(str(error) if isinstance(error, ProofError) else 'Proof configuration is unavailable or invalid.', file=sys.stderr)
        return 2
