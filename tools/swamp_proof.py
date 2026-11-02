"""Minimal Swamp proof topology: one task VM runs Gateway plus Swamp, one nested VM plays the Node.

up       provision one Project 62 task VM (or keep the held one), stage this commit, bring up the topology
run      run kind fixtures in the held topology and write a receipt per kind
receipt  print the latest receipt path
down     cancel the task group through the normal API and confirm the VM is destroyed
"""
import argparse
import fcntl
import json
import re
import secrets
import shlex
import ssl
import subprocess
import sys
import tempfile
import time
import urllib.error
import urllib.request
from datetime import UTC, datetime
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SESSION = Path.home() / 'swamp-execution/proof-sessions/minimal.json'
EVIDENCE = Path.home() / 'swamp-execution/evidence/proof'
SOURCE = '/home/orbit/swamp-proof/src'
TERMINAL = {'succeeded', 'failed', 'cancelled', 'interrupted'}


class ProofError(Exception):
    pass


def utc():
    return datetime.now(UTC).isoformat(timespec='seconds').replace('+00:00', 'Z')


def save(path, value):
    path.parent.mkdir(parents=True, exist_ok=True)
    pending = path.with_suffix('.pending')
    pending.write_text(json.dumps(value, indent=2) + '\n')
    pending.chmod(0o600)
    pending.replace(path)


class Gateway:
    """The operator's existing Gateway profile; the caller is this Node's WireGuard identity."""

    def __init__(self):
        data = json.loads((Path.home() / '.orbit/config.json').read_text())
        profile = data['gateways'][data['active_gateway']]
        self.url = profile['url'].rstrip('/')
        self.tls = ssl.create_default_context(cafile=profile.get('ca_path'))

    def request(self, method, path, body=None, key=None):
        headers = {'Accept': 'application/json', **({'Content-Type': 'application/json'} if body is not None else {}),
                   **({'Idempotency-Key': key} if key else {})}
        request = urllib.request.Request(f'{self.url}/api/v1/{path}', method=method, headers=headers,
                                         data=None if body is None else json.dumps(body).encode())
        try:
            with urllib.request.urlopen(request, context=self.tls, timeout=120) as response:
                return json.load(response)['data']
        except urllib.error.HTTPError as error:
            payload = json.load(error).get('error', {})
            raise ProofError(f'Gateway {method} {path} refused: {payload.get("code")}: {payload.get("message")}') from None

    def wait(self, operation, kind, group, timeout=2400):
        deadline = time.monotonic() + timeout
        while operation['status'] not in TERMINAL:
            if time.monotonic() > deadline:
                raise ProofError(f'{kind} operation {operation["id"]} is still {operation["status"]} after {timeout}s')
            time.sleep(5)
            operation = self.request('GET', f'operations/{operation["id"]}')
        if operation['kind'] != kind or operation['target_id'] != group:
            raise ProofError('Lifecycle operation identity does not match the proof group.')
        if operation['status'] != 'succeeded':
            raise ProofError(f'{kind} operation {operation["id"]} ended {operation["status"]}: {operation.get("error_code")}')
        return operation


class Guest:
    """Root in the owned task VM, through Shark's Incus as the Gateway-registered host account."""

    def __init__(self, host, name):
        if not re.fullmatch(r'tvm-[1-9][0-9]*', name):
            raise ProofError('Guest name is not a task VM.')
        self.host, self.name = host, name

    def call(self, argv, input=None, timeout=120):
        remote = shlex.join(['incus', 'exec', '--project', 'orbit-tasks', self.name, '--', *argv])
        result = subprocess.run(['timeout', str(timeout + 30), 'ssh', '-o', 'BatchMode=yes', '-o', 'ConnectTimeout=15', self.host, remote],
                                input=input, capture_output=True, timeout=timeout + 60, check=False)
        sys.stderr.buffer.write(result.stderr)
        if result.returncode:
            raise ProofError(f'Guest command failed ({result.returncode}): {argv[:4]}\n{result.stdout.decode()[-3000:]}')
        return result.stdout.decode()


def last_json(text):
    return json.loads(text.strip().splitlines()[-1])


def topology():
    return json.loads((ROOT / 'swamp/proof/topologies/minimal/topology.json').read_text())


def provision(gateway, state):
    vm = state['vm']
    if 'group_id' not in vm:
        group = gateway.request('POST', 'task-groups', {
            'project_id': 62, 'title': f'topology minimal@{state["topology_version"]} {ROOT.name}',
            'brief': 'Disposable minimal proof topology. No product work, publication or merge.',
            'orchestration': 'external', 'status': 'todo',
            'tasks': [{'title': 'Host the proof topology', 'brief': 'Run kind fixtures and keep their receipts.',
                       'deliverables': [{'id': 'proof', 'type': 'review', 'description': 'Proof receipt.'}]}]})
        vm['group_id'] = group['id']
        save(SESSION, state)
    if 'task_vm_id' not in vm:
        operation = gateway.request('POST', f'task-groups/{vm["group_id"]}/provision', {}, f'swamp-proof:{state["nonce"]}:provision')
        vm['provision_operation_id'] = operation['id']
        save(SESSION, state)
        operation = gateway.wait(operation, 'tasks.provision', vm['group_id'])
        admitted = operation['input']
        if admitted.get('host_node_id') != 13 or admitted.get('create', {}).get('topology') is not True:
            raise ProofError('Provision did not place a topology-seeded VM on Shark.')
        node = gateway.request('GET', f'nodes/{operation["result"]["node_id"]}')
        vm.update(task_vm_id=operation['result']['task_vm_id'], node_id=node['id'], name=node['name'], host_node_id=13,
                  seed=admitted.get('image_fingerprint'), provisioned_at=utc())
        save(SESSION, state)
    host = gateway.request('GET', 'nodes/13')
    state['guest_host'] = f'{host["user"]}@{host["wireguard_ip"]}'
    save(SESSION, state)
    return Guest(state['guest_host'], vm['name'])


def up(gateway):
    if subprocess.run(['git', '-C', str(ROOT), 'status', '--porcelain'], capture_output=True, text=True, check=True).stdout.strip():
        raise ProofError('Commit the worktree first; the topology serves one exact commit.')
    commit = subprocess.run(['git', '-C', str(ROOT), 'rev-parse', 'HEAD'], capture_output=True, text=True, check=True).stdout.strip()
    state = json.loads(SESSION.read_text()) if SESSION.is_file() else None
    if not state or state['vm'].get('destroyed'):
        spec = topology()
        state = {'topology': spec['name'], 'topology_version': spec['version'], 'nonce': secrets.token_hex(8), 'vm': {},
                 'receipts': [], 'up_started_at': utc(), 'up_started_epoch': time.time()}
    state.setdefault('receipts', [])
    state.setdefault('up_started_at', utc())
    state.setdefault('up_started_epoch', time.time())
    state.update(commit=commit, ready=False, last_up_started_at=utc(), last_up_started_epoch=time.time())
    save(SESSION, state)
    guest = provision(gateway, state)
    started = time.time()
    with tempfile.TemporaryDirectory() as folder:
        bundle = Path(folder) / 'source.bundle'
        subprocess.run(['git', '-C', str(ROOT), 'bundle', 'create', str(bundle), 'HEAD'], check=True, capture_output=True)
        stage = 'rm -rf /home/orbit/swamp-proof && install -d -o orbit -g orbit /home/orbit/swamp-proof && cat > /home/orbit/swamp-proof/source.bundle'
        guest.call(['sh', '-c', stage], input=bundle.read_bytes(), timeout=300)
    guest.call(['runuser', '-u', 'orbit', '--', 'git', 'clone', '-q', '--no-checkout', '/home/orbit/swamp-proof/source.bundle', SOURCE])
    guest.call(['runuser', '-u', 'orbit', '--', 'git', '-C', SOURCE, 'checkout', '-q', '--detach', commit])
    staged = time.time() - started
    facts = last_json(guest.call(['python3', '-I', f'{SOURCE}/tools/swamp-proof-guest/up.py', SOURCE], timeout=2700))
    if not facts.get('ready') or facts['commit'] != commit:
        raise ProofError('The topology did not come up on the staged commit.')
    state.update(ready=True, ready_at=utc(), ready_epoch=time.time(), facts=facts,
                 timings=[{'step': 'stage', 'seconds': round(staged, 1)}, *facts['timings']])
    save(SESSION, state)
    return state


FIXTURE_ONLY = ('swamp/proof/', 'tools/swamp-proof-guest/run.py', 'tools/swamp-proof-guest/gateway.php', 'tools/swamp_proof.py')


def git(*argv):
    return subprocess.run(['git', '-C', str(ROOT), *argv], capture_output=True, text=True, check=True).stdout.strip()


def refresh(guest, state):
    """Move the held topology to HEAD when only fixtures or the runner changed; anything else needs a new up."""
    if git('status', '--porcelain'):
        raise ProofError('Commit the worktree first; a receipt names one exact commit.')
    commit = git('rev-parse', 'HEAD')
    if commit == state['commit']:
        return
    changed = git('diff', '--name-only', state['commit'], commit).splitlines()
    if any(not path.startswith(FIXTURE_ONLY) for path in changed):
        raise ProofError('Gateway or Swamp source changed since up. Use down, then up for this commit.')
    with tempfile.TemporaryDirectory() as folder:
        bundle = Path(folder) / 'source.bundle'
        subprocess.run(['git', '-C', str(ROOT), 'bundle', 'create', str(bundle), 'HEAD'], check=True, capture_output=True)
        guest.call(['sh', '-c', 'cat > /home/orbit/swamp-proof/update.bundle'], input=bundle.read_bytes(), timeout=300)
    guest.call(['runuser', '-u', 'orbit', '--', 'git', '-C', SOURCE, 'fetch', '-q', '/home/orbit/swamp-proof/update.bundle', 'HEAD'])
    guest.call(['runuser', '-u', 'orbit', '--', 'git', '-C', SOURCE, 'checkout', '-q', '--detach', commit])
    state.setdefault('refreshed', []).append({'from': state['commit'], 'to': commit, 'at': utc(), 'paths': changed})
    state['commit'] = commit
    save(SESSION, state)


def run(state, kind):
    if not state.get('ready') or state['vm'].get('destroyed'):
        raise ProofError('Use up first.')
    guest = Guest(state['guest_host'], state['vm']['name'])
    refresh(guest, state)
    started = time.time()
    body = last_json(guest.call(['python3', '-I', f'{SOURCE}/tools/swamp-proof-guest/run.py', SOURCE, kind], timeout=3600))
    receipt = {'kind': kind, 'proof_pass': body.get('proof_pass') is True, 'topology': state['topology'],
               'topology_version': state['topology_version'], 'commit': state['commit'], 'vm': state['vm'],
               'refreshed': state.get('refreshed', []),
               'timings': {'up_steps': state['timings'], 'up_seconds': round(state['ready_epoch'] - state['last_up_started_epoch'], 1),
                           'run_seconds': round(time.time() - started, 1),
                           'up_to_receipt_seconds': round(time.time() - state['last_up_started_epoch'], 1),
                           'since_first_provision_seconds': round(time.time() - state['up_started_epoch'], 1)},
               'up_started_at': state['up_started_at'], 'written_at': utc(), **body}
    destination = EVIDENCE / kind / (datetime.now(UTC).strftime('%Y%m%dT%H%M%SZ') + '-minimal.json')
    save(destination, receipt)
    state['receipts'].append(str(destination))
    save(SESSION, state)
    return destination, receipt


def cancel(gateway, state):
    """Cancel the proof group, retrying a failed cancel once.

    The Gateway replays an idempotency key's original Operation, so a retry of a failed cancel needs a new key.
    A cancel that is still running, or whose reply was lost, is followed under its own key.
    """
    vm = state['vm']
    retried = False
    while True:
        operation = gateway.request('GET', f'operations/{vm["cancel_operation_id"]}') if vm.get('cancel_operation_id') else None
        if operation is not None and operation['status'] in TERMINAL and operation['status'] != 'succeeded':
            if retried:
                raise ProofError(f'tasks.cancel operation {operation["id"]} ended {operation["status"]}: {operation.get("error_code")}')
            retried = True
            vm['cancel_attempt'] = vm.get('cancel_attempt', 1) + 1
            operation = None
        if operation is None:
            suffix = '' if vm.get('cancel_attempt', 1) == 1 else f'-{vm["cancel_attempt"]}'
            operation = gateway.request('POST', f'task-groups/{vm["group_id"]}/cancel', {}, f'swamp-proof:{state["nonce"]}:cancel{suffix}')
            vm['cancel_operation_id'] = operation['id']
            save(SESSION, state)
        try:
            return gateway.wait(operation, 'tasks.cancel', vm['group_id'])
        except ProofError:
            # Only a terminal failure earns the one retry; a cancel still running after the wait stops here.
            if retried or gateway.request('GET', f'operations/{operation["id"]}')['status'] not in TERMINAL:
                raise


def down(gateway, state):
    vm = state['vm']
    if vm.get('group_id') and not vm.get('destroyed'):
        cancel(gateway, state)
        group = gateway.request('GET', f'task-groups/{vm["group_id"]}')
        if group['status'] != 'cancelled' or ('task_vm_id' in vm and group.get('vm_power') != 'destroyed'):
            raise ProofError(f'Cancellation did not destroy proof group {vm["group_id"]}.')
        vm.update(destroyed=True, destroyed_at=utc())
    state['ready'] = False
    save(SESSION, state)
    for path in map(Path, state.get('receipts', [])):
        receipt = json.loads(path.read_text())
        receipt['vm'] = vm
        save(path, receipt)
    return state


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    sub = parser.add_subparsers(dest='action', required=True)
    sub.add_parser('up')
    runner = sub.add_parser('run')
    runner.add_argument('kind', nargs='+')
    sub.add_parser('receipt')
    sub.add_parser('down')
    parser.add_argument('--session', default='minimal', help='Session name; each owns one proof VM (default minimal).')
    args = parser.parse_args(argv)
    if not re.fullmatch(r'[a-z0-9-]{1,32}', args.session):
        print('swamp-proof: invalid --session name', file=sys.stderr)
        return 2
    global SESSION
    SESSION = SESSION.with_name(args.session + '.json')
    SESSION.parent.mkdir(parents=True, exist_ok=True)
    with SESSION.with_suffix('.lock').open('a') as lock:
        try:
            fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            print('Another swamp-proof command holds the session.', file=sys.stderr)
            return 2
        try:
            if args.action == 'up':
                state = up(Gateway())
                print(json.dumps({key: state[key] for key in ('topology', 'topology_version', 'commit', 'vm', 'timings')}))
                return 0
            state = json.loads(SESSION.read_text())
            if args.action == 'receipt':
                print(state['receipts'][-1] if state.get('receipts') else '')
                return 0 if state.get('receipts') else 1
            if args.action == 'down':
                print(json.dumps(down(Gateway(), state)['vm']))
                return 0
            failed = False
            for kind in args.kind:
                destination, receipt = run(state, kind)
                print(destination)
                failed |= not receipt['proof_pass']
            return 1 if failed else 0
        except (ProofError, OSError, ValueError, KeyError, subprocess.SubprocessError) as error:
            print(f'swamp-proof: {error}', file=sys.stderr)
            return 2


if __name__ == '__main__':
    sys.exit(main())
