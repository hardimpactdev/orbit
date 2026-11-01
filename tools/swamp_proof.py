"""F10 proof orchestration. Provision and destroy only through the normal Gateway API."""
import json
import re
import ssl
import time
import urllib.error
import urllib.request
import uuid
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


def bind(value, context, now, allow_matchers=False):
    """The frozen v0 substitutions, with whole-value types preserved."""
    def resolve(name):
        if allow_matchers and name in {'match.uuid', 'match.positive_int', 'match.sha256', 'match.nonempty_string', 'match.present'}:
            return '${' + name + '}'
        if re.fullmatch(r'proof\.(now|epoch)\+[0-9]+', name):
            instant = now + timedelta(seconds=int(name.split('+')[1]))
            return int(instant.timestamp()) if name.startswith('proof.epoch') else instant.isoformat().replace('+00:00', 'Z')
        allowed = {'proof.start_tag'}
        dynamic = re.fullmatch(r'capture\.[a-zA-Z_][a-zA-Z0-9_.]*|topology\.[a-zA-Z0-9_-]+\.(node_id|name|wireguard_ip)', name)
        if (name not in allowed | {'proof.run', 'proof.repo.url'} and not dynamic) or name not in context:
            raise ProofError(f'Unknown or unavailable binding: {name}')
        return context[name]
    if isinstance(value, dict):
        return {key: bind(item, context, now, allow_matchers) for key, item in value.items()}
    if isinstance(value, list):
        return [bind(item, context, now, allow_matchers) for item in value]
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


def fixture_for(kind, root=ROOT, variant=None):
    """Validate the effective F13 format before any infrastructure admission."""
    if not KIND.fullmatch(kind):
        raise ProofError('Invalid kind.')
    if variant is not None and (not isinstance(variant, str) or not re.fullmatch(r'[a-zA-Z0-9_-]+', variant)):
        raise ProofError('Invalid fixture variant.')
    path = root / 'swamp/proof' / (kind + '.json') if variant is None else root / 'swamp/proof' / kind / (variant + '.json')
    fixture = json.loads(path.read_text())
    required = {'kind', 'workflow', 'budget_seconds', 'queue_timeout_seconds', 'envelope', 'request', 'expect', 'phases', 'parity'}
    if set(fixture) - required - {'prepare', 'before_request', 'topology', 'secrets', 'limitations'} or required - set(fixture) or fixture['kind'] != kind:
        raise ProofError('Fixture does not match the F13 request format; legacy secrets/target_setup are retired.')
    if not isinstance(fixture.get('limitations', []), list) or any(not isinstance(item, str) or not item.strip() for item in fixture.get('limitations', [])):
        raise ProofError('limitations must be a list of nonempty strings.')
    positive(fixture['budget_seconds'], 'budget_seconds')
    positive(fixture['queue_timeout_seconds'], 'queue_timeout_seconds')
    if not isinstance(fixture['envelope'], dict):
        raise ProofError('envelope must be a partial expected Operation.input object.')
    from swamp_proof_requests import validate_commands
    validate_commands(fixture)
    if not fixture['phases']:
        raise ProofError('Proof requires named legacy phases.')
    for phase in fixture['phases']:
        if not isinstance(phase, dict) or set(phase) - {'name', 'job', 'step', 'budget_seconds', 'workflow'} or not {'name', 'job', 'step', 'budget_seconds'} <= set(phase) or any(not isinstance(phase[key], str) or not phase[key] for key in ('name', 'job', 'step')) or ('workflow' in phase and (not isinstance(phase['workflow'], str) or not phase['workflow'])):
            raise ProofError('Each phase requires its name and exact native job/step.')
        positive(phase['budget_seconds'], 'phase budget_seconds')
    if set(fixture['expect']) != {'status', 'outputs'} or fixture['expect']['status'] not in TERMINAL:
        raise ProofError('Expected native status must be terminal.')
    output = fixture['expect']['outputs']
    if not isinstance(output, dict) or set(output) != {'job', 'step', 'match'} or not isinstance(output['match'], dict) or any(not isinstance(output[key], str) or not output[key] for key in ('job', 'step')):
        raise ProofError('Expected outputs require exact job, step and public partial match.')
    parity = fixture['parity']
    if set(parity) != {'command', 'cases'} or any(not isinstance(parity[key], list) or not parity[key] or any(not isinstance(value, str) or not value for value in parity[key]) for key in ('command', 'cases')):
        raise ProofError('Parity requires a command argv and named legacy cases.')
    import yaml
    workflows = [yaml.safe_load(path.read_text()) for path in (root / 'swamp/workflows').glob('*.yaml')]
    matches = [flow for flow in workflows if flow.get('name') == fixture['workflow']]
    if len(matches) != 1 or matches[0].get('queueTimeout') != fixture['queue_timeout_seconds']:
        raise ProofError('Fixture queue timeout differs from its unique source workflow.')
    topology_for(fixture.get('topology', 'app-dev'), root)
    return fixture


def fixture_variants(kind, variant=None, root=ROOT):
    if not KIND.fullmatch(kind):
        raise ProofError('Invalid kind.')
    if variant is not None:
        return [(variant, fixture_for(kind, root, variant))]
    direct = root / 'swamp/proof' / (kind + '.json')
    variants = sorted((root / 'swamp/proof' / kind).glob('*.json'))
    if direct.is_file() and variants:
        raise ProofError('A kind must use either a single fixture or named variants.')
    if direct.is_file():
        return [(None, fixture_for(kind, root))]
    if not variants:
        raise ProofError('No fixture exists for ' + kind)
    return [(path.stem, fixture_for(kind, root, path.stem)) for path in variants]


def topology_for(value, root=ROOT):
    """Use the existing declarable Node/role catalog, including its prerequisites."""
    if isinstance(value, str) and value.startswith('['):
        value = json.loads(value)
    if isinstance(value, list):
        topology = {'name': 'custom', 'version': 1, 'nodes': value, 'seed': None}
    else:
        if not isinstance(value, str) or not re.fullmatch(r'[a-z][a-z0-9_-]*', value):
            raise ProofError('Topology must name a preset or contain a JSON array of Task Nodes.')
        topology = json.loads((root / 'swamp/proof/topologies' / value / 'topology.json').read_text())
        if topology.get('name') != value or set(topology) != {'name', 'version', 'nodes', 'seed'}:
            raise ProofError('Topology preset requires name, version, nodes and seed; container images are retired.')
    positive(topology.get('version'), 'topology version')
    seed = topology['seed']
    if seed is not None and (not isinstance(seed, str) or not re.fullmatch(r'[a-f0-9]{64}', seed)):
        raise ProofError('Topology seed must be a full base-image SHA256 or null until allocation.')
    catalog = json.loads((root / 'apps/e2e/resources/topology-nodes.json').read_text())
    nodes = topology.get('nodes')
    if not isinstance(nodes, list) or not nodes or any(not isinstance(node, str) for node in nodes):
        raise ProofError('Topology requires normal declarable Task Nodes.')
    bases = []
    for node in nodes:
        base, *roles = node.split('+')
        entry = catalog['nodes'].get(base, {})
        if not entry.get('declarable') or len(set(roles)) != len(roles) or any(role not in catalog['extra_roles'] for role in roles):
            raise ProofError('Topology Node or role is absent from the existing Task catalog: ' + node)
        bases.append(base)
    if len(set(bases)) != len(bases) or any(catalog['nodes'][base].get('requires', base) not in bases for base in bases):
        raise ProofError('Topology repeats a Node or omits its catalog prerequisite.')
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


def input_mismatches(actual, expected, path='$'):
    """F13 expected Operation.input is a recursive subset; arrays compare whole."""
    if isinstance(expected, str) and expected.startswith('${match.'):
        matchers = {
            '${match.uuid}': lambda value: isinstance(value, str) and valid_uuid(value),
            '${match.positive_int}': lambda value: type(value) is int and value > 0,
            '${match.sha256}': lambda value: isinstance(value, str) and re.fullmatch(r'[a-fA-F0-9]{64}', value) is not None,
            '${match.nonempty_string}': lambda value: isinstance(value, str) and bool(value),
            '${match.present}': lambda _value: True,
        }
        if expected not in matchers:
            raise ProofError('Unknown expected-input matcher.')
        return [] if matchers[expected](actual) else [{'path': path, 'expected': expected, 'actual': actual}]
    if isinstance(expected, list):
        if not isinstance(actual, list) or len(actual) != len(expected):
            return [{'path': path, 'expected': expected, 'actual': actual}]
        return [difference for index, value in enumerate(expected)
                for difference in input_mismatches(actual[index], value, path + '[' + str(index) + ']')]
    if isinstance(expected, dict) and isinstance(actual, dict):
        differences = []
        for key, value in expected.items():
            child = path + '[' + json.dumps(key) + ']'
            if key not in actual:
                differences.append({'path': child, 'expected': value, 'missing': True})
            else:
                differences.extend(input_mismatches(actual[key], value, child))
        return differences
    return [] if partial_match(actual, expected) else [{'path': path, 'expected': expected, 'actual': actual}]


def valid_uuid(value):
    try:
        return str(uuid.UUID(value)) == value.lower()
    except ValueError:
        return False


def native_step(history, job, step):
    found = [entry for group in history['jobs'] if group['name'] == job for entry in group['steps'] if entry['name'] == step]
    if len(found) != 1:
        raise ProofError('Native history lacks one exact fixture job/step.')
    return found[0]


def evaluate(history, fixture, parity_exit, child_histories=None):
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
        selected = history
        if phase.get('workflow', history.get('workflowName')) != history.get('workflowName'):
            selected = (child_histories or {}).get(phase['workflow'])
            if selected is None:
                raise ProofError('Native history lacks one exact linked phase workflow.')
        row = native_step(selected, phase['job'], phase['step'])
        elapsed = seconds(row)
        phases.append({'name': phase['name'], 'workflow': selected.get('workflowName'), 'native_run_id': selected['id'],
                       'job': phase['job'], 'step': phase['step'], 'seconds': elapsed, 'budget_seconds': phase['budget_seconds'],
                       'pass': elapsed <= phase['budget_seconds']})
    expected = fixture['expect']
    matches = history['status'] == expected['status']
    if 'outputs' in expected:
        output = expected['outputs']
        matches = matches and partial_match(native_step(history, output['job'], output['step']).get('outputs'), output['match'])
    budget = total <= fixture['budget_seconds'] and all(row['pass'] for row in phases)
    return {'native_run_id': history['id'], 'status': history['status'], 'total_seconds': total, 'phases': phases,
            'budget_pass': budget, 'expect_pass': matches, 'queue_timeout_seconds': fixture['queue_timeout_seconds'],
            'queue_timeout_note': 'explicit workflow policy',
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
        'project_id': project, 'title': kind,
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
    resources = admitted['create']
    if resources.get('topology') is not True or resources.get('cpus') != 4 or resources.get('memory') != '8GiB' or resources.get('disk') != '40GiB':
        save()
        raise ProofError('Proof Project did not receive its configured medium VM and topology seed; acquisition refused.')
    node = gateway.request('GET', f'nodes/{record["node_id"]}')
    if node['status'] != 'active' or node['name'] != f'tvm-{record["task_vm_id"]}' or node['user'] == 'root':
        raise ProofError('Normal provisioning did not return an active owned non-root guest Node.')
    seed = admitted.get('image_fingerprint')
    if not isinstance(seed, str) or not re.fullmatch(r'[a-f0-9]{64}', seed):
        raise ProofError('Normal provision did not record its exact Task VM base-image fingerprint.')
    record.update(host_node_id=13, name=node['name'], instance_id=result['instance_id'], seed=seed,
                  resources={key: resources[key] for key in ('cpus', 'memory', 'disk', 'topology')})
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


def main(argv=None):
    from swamp_proof_topology import cli
    return cli(argv)
