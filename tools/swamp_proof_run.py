"""F13 host runner: real topology CLI admission and exact official native history."""
import json
import os
import shlex
import subprocess
import sys
import tempfile
import time
import uuid
from datetime import UTC, datetime
from pathlib import Path
from types import SimpleNamespace

from swamp_proof import (
    TERMINAL,
    ProofError,
    bind,
    evaluate,
    fixture_for,
    native_step,
    positive,
    topology_for,
    utc,
)
from swamp_proof_api import generate, private_api, redact, secret_file
from swamp_proof_requests import cli_argv, compare_input, json_path, prepare
from swamp_proof_topology import WORKLOAD_ROLES, save_state
from swamp_proof_transport import pins

SWAMP = '/home/orbit/.orbit/swamp'


def parity_outputs(history, fixture):
    selector = fixture['expect'].get('outputs')
    if not isinstance(selector, dict):
        raise ProofError('Parity needs expect.outputs to select the public terminal step.')
    result = native_step(history, selector['job'], selector['step']).get('outputs')
    if not isinstance(result, dict):
        raise ProofError('The selected terminal step has no public outputs object.')
    return result


def execute(argv, *, timeout=60, env=None, cwd=None):
    """Commands contain public arguments only; preserve their complete stderr."""
    result = subprocess.run(argv, capture_output=True, text=True, timeout=timeout, env=env, cwd=cwd, check=False)
    return result


class Topology:
    def __init__(self, state, execute_command=execute):
        self.state = state
        self.execute_command = execute_command
        self.private_values = {}
        self.private_leak = False
        self.root = Path(state['worktree'])
        self.prefix = [str(self.root / 'bin/e2e-topology'), 'exec', state['issue']]

    def execute(self, argv, **kwargs):
        result = self.execute_command(argv, **kwargs)
        stdout, stderr = redact(result.stdout, self.private_values), redact(result.stderr, self.private_values)
        self.private_leak |= stdout != result.stdout or stderr != result.stderr
        if stderr:
            print(stderr, file=sys.stderr, end='' if stderr.endswith('\n') else '\n')
        return SimpleNamespace(returncode=result.returncode, stdout=stdout, stderr=stderr)

    def node(self, name, argv, timeout=60, admitted_request=False, raw_output=False, allow_failure=False, stdin=None):
        if stdin is None:
            result = self.execute([*self.prefix, name, '--argv=' + json.dumps(argv), '--timeout=' + str(timeout), '--json'], timeout=timeout + 15)
        else:
            with tempfile.TemporaryDirectory(prefix='swamp-proof-request-') as tmp:
                path = Path(tmp) / 'command.json'
                with path.open('x') as stream:
                    path.chmod(0o600)
                    json.dump({'argv': argv, 'stdin': stdin}, stream)
                result = self.execute([*self.prefix, name, '--argv-file=' + str(path), '--timeout=' + str(timeout), '--json'], timeout=timeout + 15)
        response = json.loads(result.stdout)
        # e2e-topology exit status includes the guest exit, but its public JSON still carries diagnostics.
        if response.get('stderr'):
            print(response['stderr'], file=sys.stderr)
        if admitted_request and response.get('state') == 'executed':
            parsed = json.loads(response['stdout'])
            if isinstance(parsed, dict) and type(parsed.get('id')) is int and isinstance(parsed.get('kind'), str):
                return parsed
        if response.get('state') != 'executed' or (not allow_failure and (result.returncode or response.get('exit_code') != 0)):
            if response.get('stdout'):
                print(response['stdout'], file=sys.stderr)
            raise ProofError('Topology command failed on ' + name + '; full public stderr is printed above.')
        if raw_output:
            if response['stdout']:
                print(response['stdout'], file=sys.stderr)
            return response['stdout']
        return json.loads(response['stdout'])

    def cli(self, argv):
        return self.node('operator', argv, timeout=120)

    def request(self, argv, timeout=120):
        # The CLI may exit nonzero after a correctly admitted Operation fails. Retain its identity.
        return self.node('operator', argv, timeout=timeout, admitted_request=True)

    def history(self, *argv):
        return self.node('gateway', ['env', 'SWAMP_HOME=' + SWAMP + '/home', 'SWAMP_CONFIG_DIR=' + SWAMP + '/config',
                                    'SWAMP_API_KEY_FILE=' + SWAMP + '/credentials/club',
                                    'swamp', 'workflow', 'history', *argv, '--repo-dir', SWAMP + '/repository',
                                    '--server', 'wss://localhost:19470', '--token-file', SWAMP + '/credentials/operator',
                                    '--ca-cert', SWAMP + '/tls/cert.pem', '--json'])

    def context(self, run):
        rows = self.cli(['orbit', 'node:list', '--json'])['nodes']
        if not isinstance(rows, list):
            raise ProofError('Topology node:list did not return its public Node array.')
        context = {'proof.run': run, 'proof.start_tag': str(uuid.uuid4())}
        # Resolve actual Gateway IDs from the harness's observed addresses, never list order.
        for key, guest in self.state['topology_record']['construction']['nodes'].items():
            found = [row for row in rows if row['public_ssh_host'] == guest['incus_address'] and row['wireguard_ip'] == guest['wireguard_address']]
            if len(found) != 1:
                raise ProofError('Topology Gateway lacks exactly one enrolled Node for ' + key)
            row = found[0]
            for field in ('node_id', 'name', 'wireguard_ip'):
                context['topology.' + key + '.' + field] = positive(row['id'], 'node_id') if field == 'node_id' else row[field]
        return context


def hook_env(topology, run, folder, operation=None, outputs=None):
    # Do not inherit obsolete PROOF_* variables or caller endpoint overrides.
    env = {key: value for key, value in os.environ.items() if not key.startswith(('PROOF_', 'ORBIT_GATEWAY_', 'SWAMP_'))}
    env.update(PROOF_EXEC=shlex.join(topology.prefix), PROOF_RUN=run, PROOF_KIND_DIR=str(folder))
    if operation is not None:
        env['PROOF_OPERATION_ID'] = str(operation)
    if outputs is not None:
        env['PROOF_OUTPUTS'] = str(outputs)
    return env


def bind_repository(topology, fixture, context, kind, run, variant=None):
    folder = topology.root / 'swamp/proof' / kind
    candidates = [folder / 'repository']
    if variant is not None:
        candidates.insert(0, folder / variant / 'repository')
    if not any(path.is_dir() for path in candidates):
        if '${proof.repo.url}' in json.dumps(fixture):
            raise ProofError('proof.repo.url requires a committed repository payload in the kind or variant folder.')
        return None
    argv = ['sudo', '-n', 'python3', '-I', '/home/orbit/orbit/tools/swamp_proof_repository.py', kind, run, 'repositories.orbit']
    if variant is not None:
        argv.append(variant)
    repository = topology.node('gateway', argv)
    context['proof.repo.url'] = repository['url']
    return repository


def exact_history(topology, operation, fixture):
    rows = topology.history('search', '--workflow', fixture['workflow'], '--input', 'operation_id=' + str(operation['id']))['results']
    matches = [row for row in rows if row['workflowName'] == fixture['workflow'] and
               type(row.get('inputs', {}).get('operation_id')) is int and row['inputs']['operation_id'] == operation['id']]
    if len(matches) != 1:
        raise ProofError('Native history must contain exactly one declared workflow root for the admitted Operation.')
    history = topology.history('get', matches[0]['runId'])
    if type(history.get('inputs', {}).get('operation_id')) is not int or history.get('workflowName') != fixture['workflow'] or history.get('inputs', {}).get('operation_id') != operation['id'] or history.get('inputs', {}).get('kind') != fixture['kind'] or history['id'] != matches[0]['runId']:
        raise ProofError('Native history identity differs from the real admitted request.')
    return history



def phase_histories(topology, root, fixture):
    """Official history-search summaries omit parentRun; resolve links from history get."""
    wanted = {phase['workflow'] for phase in fixture['phases'] if phase.get('workflow', root['workflowName']) != root['workflowName']}
    if not wanted:
        return {}, []
    histories = {root['id']: root}
    linked = {}

    def identity(row, summary=False):
        return (row['runId' if summary else 'id'], row['workflowId'], row['workflowName'])

    def read(run_id):
        if run_id not in histories:
            history = topology.history('get', run_id)
            if history['id'] != run_id:
                raise ProofError('Native child history returned another run identity.')
            histories[run_id] = history
        return histories[run_id]

    def lineage(child, seen):
        if child['id'] in seen:
            raise ProofError('Native child history has a cyclic parent link.')
        ref = child.get('parentRun')
        if not ref:
            return None
        parent = read(ref['runId'])
        if (ref['runId'], ref['workflowId'], ref['workflowName']) != identity(parent):
            raise ProofError('Native child history has a conflicting parent link.')
        chain = [] if parent['id'] == root['id'] else lineage(parent, seen | {child['id']})
        if chain is None:
            return None
        steps = [step for job in parent['jobs'] for step in job['steps'] if step['name'] == ref['stepName']]
        if len(steps) != 1:
            raise ProofError('Native child history lacks one exact parent step.')
        # nestedRun exists for suspended children; completed children still carry parentRun.
        link = steps[0].get('nestedRun')
        if link and (link['runId'], link['workflowId'], link['workflowName']) != identity(child):
            raise ProofError('Native parent step names another child run.')
        if child['status'] not in TERMINAL:
            raise ProofError('Native phase child has no terminal history.')
        return [*chain, child]

    selected = {}
    for workflow in sorted(wanted):
        rows = topology.history('search', '--workflow', workflow)['results']
        candidates, seen = [], set()
        for row in rows:
            if row['workflowName'] != workflow:
                continue
            if row['runId'] in seen:
                raise ProofError('Native search returned ambiguous run identities.')
            seen.add(row['runId'])
            child = read(row['runId'])
            if identity(child) != identity(row, True):
                raise ProofError('Native child history identity differs from its search record.')
            chain = lineage(child, set())
            if chain:
                candidates.append(chain)
        if len(candidates) != 1:
            raise ProofError('Native history must contain exactly one linked phase workflow: ' + workflow)
        for child in candidates[0]:
            linked[child['id']] = child
        selected[workflow] = candidates[0][-1]
    return selected, list(linked.values())


def step_report(history):
    result = []
    for job in history['jobs']:
        for step in job['steps']:
            elapsed = None
            if step.get('startedAt') and step.get('completedAt'):
                elapsed = (datetime.fromisoformat(step['completedAt']) - datetime.fromisoformat(step['startedAt'])).total_seconds()
            row = {'workflow': history['workflowName'], 'native_run_id': history['id'],
                   'job': job['name'], 'step': step['name'], 'status': step['status'], 'seconds': elapsed}
            if step.get('error'):
                row['error'] = step['error']
            if step.get('outputs', {}).get('stderr'):
                row['stderr'] = step['outputs']['stderr']
            result.append(row)
            print(json.dumps(row), file=sys.stderr)
    return result


def supplies(held, required):
    nodes = {entry.split('+')[0]: set(entry.split('+')[1:]) for entry in held}
    return all(entry.split('+')[0] in nodes and set(entry.split('+')[1:]) <= nodes[entry.split('+')[0]] for entry in required)


def run_kind(state, kind, receipt, save, topology=None, clock=time.monotonic, pause=time.sleep, variant=None):
    topology = topology or Topology(state)
    fixture = fixture_for(kind, topology.root, variant)
    selected = topology_for(fixture.get('topology', 'app-dev'), topology.root)
    if not supplies(state['nodes'], selected['nodes']):
        raise ProofError('Held topology does not supply the fixture\'s declared Node/role entries.')
    run = uuid.uuid4().hex[:12]
    receipt.update(kind=kind, variant=variant, workflow=fixture['workflow'], proof_run=run, started_at=utc(), proof_pass=False,
                   swamp_commit=state['commit'], swamp_version=pins(topology.root)['version'],
                   topology=state['topology'], topology_version=state['topology_version'], nodes=state['nodes'],
                   seed=state['vm']['seed'], seed_provenance=state.get('seed_provenance'), vm=dict(state['vm']), gateway_install=state.get('gateway_install'),
                   release_runner_exercised=state.get('release_runner_exercised'), adopt_refusal=state.get('adopt_refusal'),
                   gateway_status=state.get('gateway_status'), sandbox_verified=state.get('sandbox_verified'), destroyed=False,
                   limitations=[*state.get('limitations', []), *fixture.get('limitations', [])], **WORKLOAD_ROLES)
    receipt.update(enrollment='fixture-set', topology_ca='fixture-set', fixture_repositories='separate-caddy', github_token_path='not_exercised')
    save()
    try:
        topology.private_values = generate(fixture)
        topology.private_leak = False
        # Root-owned timestamp is the only F11 activity fact; no Gateway metadata is written.
        touched = topology.execute(['sudo', '-n', 'install', '-d', '-o', 'root', '-g', 'root', '-m', '755', '/var/lib/orbit/swamp-proof'])
        if touched.returncode or topology.execute(['sudo', '-n', 'touch', '/var/lib/orbit/swamp-proof/last-run']).returncode:
            raise ProofError('Could not record the F11 run-start mtime.')
        context = topology.context(run)
        now = datetime.now(UTC)
        folder = topology.root / 'swamp/proof' / kind
        repository = bind_repository(topology, fixture, context, kind, run, variant)
        if repository is not None:
            receipt['repository'] = repository
            save()
        prepare(fixture, context, topology.cli)
        # Resolve expected intent before admitting the request, without learning from actual input.
        bind(fixture['envelope'], context, now, allow_matchers=True)
        env = hook_env(topology, run, folder)
        for hook in fixture.get('before_request', []):
            argv = bind(hook['argv'], context, now)
            result = topology.execute([str(arg) for arg in argv], timeout=hook.get('timeout_seconds', 60), env=env, cwd=topology.root)
            if result.stdout:
                print(result.stdout, file=sys.stderr)
            if result.returncode:
                raise ProofError('before_request failed; no kind request was admitted.')
        if 'api' in fixture['request']:
            _body, receipt['request'] = private_api(fixture['request']['api'], context, now, topology.private_values)
            save()
        operation_id = admit(topology, fixture, context, now)
        receipt['operation_id'] = operation_id
        save()
        deadline = clock() + fixture['budget_seconds'] + fixture['queue_timeout_seconds'] + 120
        while True:
            operation = topology.cli(['orbit', 'operation:show', str(operation_id), '--json'])
            if operation.get('id') != operation_id or operation.get('kind') != kind:
                raise ProofError('operation:show returned another request identity.')
            receipt.update(operation=operation, **compare_input(operation, fixture, context, now))
            save()
            if operation['status'] in TERMINAL:
                break
            if clock() >= deadline:
                raise ProofError('Observation deadline reached; Operation remains owned by this held topology. Use down for normal cancellation.')
            pause(1)
        for mismatch in receipt['input_mismatches']:
            print(json.dumps(mismatch), file=sys.stderr)
        history = exact_history(topology, operation, fixture)
        receipt['native_steps'] = step_report(history)
        receipt['native_run_id'] = history['id']
        receipt['native_history'] = history
        save()
        children, child_runs = phase_histories(topology, history, fixture)
        receipt['native_child_histories'] = child_runs
        for child in child_runs:
            receipt['native_steps'].extend(step_report(child))
        save()
        if history['status'] not in TERMINAL:
            raise ProofError('Gateway terminal state has no matching native terminal history.')
        with tempfile.TemporaryDirectory(prefix='swamp-proof-parity-') as tmp:
            outputs = Path(tmp) / 'outputs.json'
            outputs.write_text(json.dumps(parity_outputs(history, fixture)))
            outputs.chmod(0o600)
            env = hook_env(topology, run, folder, operation_id, outputs)
            if topology.private_values:
                env['PROOF_SECRETS'] = str(secret_file(tmp, topology.private_values))
            result = topology.execute([str(arg) for arg in bind(fixture['parity']['command'], context, now)],
                                      timeout=fixture['budget_seconds'] + 120, env=env, cwd=topology.root)
            if result.stdout:
                print(result.stdout, file=sys.stderr)
            receipt.update(evaluate(history, fixture, result.returncode, children))
        receipt['secret_leak_detected'] = bool(topology.private_leak)
        receipt['proof_pass'] = (not topology.private_leak and receipt['expect_pass'] and receipt['budget_pass'] and receipt['parity']['passed'] and
                                 not receipt['input_mismatches'] and operation['status'] == history['status'])
    except (ProofError, OSError, ValueError, KeyError, subprocess.TimeoutExpired) as error:
        receipt['error'] = redact(str(error), topology.private_values)
    finally:
        topology.private_values = {}
        receipt['finished_at'] = utc()
        save()
    return receipt


def admit(topology, fixture, context, now):
    request = fixture['request']
    if 'api' in request:
        body, public = private_api(request['api'], context, now, topology.private_values)
        if not public['path'].startswith('/api/') or any(char in public['path'] for char in '\r\n?#'):
            raise ProofError('Bound API path is outside the normal Gateway API origin.')
        response = topology.node('operator', ['php', '/home/orbit/orbit/tools/swamp-proof-request.php', public['method'], public['path']],
                                 stdin=json.dumps(body), timeout=120)
        return positive(json_path(response, request['operation']), 'Operation id')
    if 'argv' in request:
        operation = topology.request(cli_argv(request['argv'], context, now), timeout=min(3600, fixture['budget_seconds'] + fixture['queue_timeout_seconds'] + 120))
        return positive(json_path(operation, request['operation']), 'Operation id')
    select = bind(request['select'], context, now)
    # --kind is supported by the real CLI. Target flags are not; filter returned records exactly.
    argv = ['orbit', 'operation:list', '--kind=' + fixture['kind'], '--json']
    old = {row['id'] for row in topology.cli(argv)['operations']}
    started = topology.node('gateway', ['python3', '-c', 'import datetime,json; print(json.dumps(datetime.datetime.now(datetime.UTC).replace(microsecond=0).isoformat()))'])
    command_error = None
    try:
        topology.node('gateway', ['env', '-C', '/home/orbit/runtime/current/apps/gateway', 'php', 'artisan', *bind(request['artisan'], context, now), '--no-interaction'],
                      timeout=min(3600, fixture['budget_seconds'] + fixture['queue_timeout_seconds'] + 120), raw_output=True, allow_failure=True)
    except (ProofError, OSError, ValueError, subprocess.TimeoutExpired) as error:
        # Admission may already have happened. Select it once even if command observation failed.
        command_error = str(error)
    rows = topology.cli(argv)['operations']
    matches = [row for row in rows if row['id'] not in old and row['kind'] == select['kind'] and ('target_type' not in select or row['target_type'] == select['target_type']) and ('target_id' not in select or str(row['target_id']) == str(select['target_id'])) and datetime.fromisoformat(row['created_at']) >= datetime.fromisoformat(started)]
    if len(matches) != select['count']:
        raise ProofError('Artisan request did not admit exactly its selected new Operation; no request is repeated.' + (' ' + command_error if command_error else ''))
    return positive(matches[0]['id'], 'Operation id')


def main():
    payload = json.load(sys.stdin)
    state, kind = payload['state'], payload['kind']
    receipt = {}
    # Public receipt goes to stdout; all topology/parity diagnostics go to stderr.
    try:
        path = Path(payload['receipt_path'])
        if path.parent != Path(state['worktree']) / '.e2e/proof' or path.suffix != '.json':
            raise ProofError('Invalid owned receipt path.')
        run_kind(state, kind, receipt, lambda: save_state(receipt, path), variant=payload.get('variant'))
    except (ProofError, OSError, ValueError, KeyError) as error:
        receipt.update(proof_pass=False, error=str(error))
    print(json.dumps(receipt))


if __name__ == '__main__':
    main()
