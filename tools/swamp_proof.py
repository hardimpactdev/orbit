"""Swamp proof topology: one task VM runs Gateway plus Swamp, nested VMs play the Nodes.

up       provision one Project 62 task VM (or keep the held one), stage this commit, bring up the topology
run      run kind fixtures in the held topology and write a receipt per kind
receipt  print the latest receipt path
down     cancel the task group through the normal API and confirm the VM is destroyed

Each group's title names its lane (`swamp-proof <host>:<session>`), and up and run touch HEARTBEAT on
the guest at their start and end. The proof-reaper Swamp schedule cancels a group whose heartbeat is
older than an hour, or that a newer open group of its lane superseded. Only the scheduled reaper
expires other sessions; this command changes its own session only.
"""
import argparse
import fcntl
import json
import re
import secrets
import shlex
import socket
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
# Outside the staged tree, which every up replaces; the proof-reaper schedule reads its mtime.
HEARTBEAT = '/home/orbit/swamp-proof-activity'
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
    """Root in the owned task VM, through its host's registered Incus account."""

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


def lane():
    """This machine and session: one held VM at a time, so a newer open group of the lane supersedes an older one."""
    return f'{socket.gethostname()}:{SESSION.stem}'


def heartbeat(guest):
    guest.call(['runuser', '-u', 'orbit', '--', 'touch', HEARTBEAT])


def last_json(text):
    return json.loads(text.strip().splitlines()[-1])


def topology(name):
    return json.loads((ROOT / 'swamp/proof/topologies' / name / 'topology.json').read_text())


def provision(gateway, state):
    vm = state['vm']
    if 'group_id' not in vm:
        group = gateway.request('POST', 'task-groups', {
            'project_id': 62, 'title': f'swamp-proof {lane()} {state["topology"]}@{state["topology_version"]}',
            'brief': f'Disposable {state["topology"]} proof topology. No product work, publication or merge.',
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
        host_node_id = admitted.get('host_node_id')
        seed = admitted.get('image_fingerprint')
        if host_node_id not in (9, 13) or admitted.get('provider') != 'incus' or not isinstance(seed, str) or not seed \
                or operation['result'].get('image', {}).get('fingerprint') != seed:
            raise ProofError('Provision did not use its recorded Incus image on Beast or Shark.')
        node = gateway.request('GET', f'nodes/{operation["result"]["node_id"]}')
        vm.update(task_vm_id=operation['result']['task_vm_id'], node_id=node['id'], name=node['name'], host_node_id=host_node_id,
                  seed=seed, provisioned_at=utc())
        save(SESSION, state)
    host = gateway.request('GET', f'nodes/{vm["host_node_id"]}')
    state['guest_host'] = f'{host["user"]}@{host["wireguard_ip"]}'
    save(SESSION, state)
    return Guest(state['guest_host'], vm['name'])


def up(gateway, name='minimal', agent_delivery=None):
    if subprocess.run(['git', '-C', str(ROOT), 'status', '--porcelain'], capture_output=True, text=True, check=True).stdout.strip():
        raise ProofError('Commit the worktree first; the topology serves one exact commit.')
    commit = subprocess.run(['git', '-C', str(ROOT), 'rev-parse', 'HEAD'], capture_output=True, text=True, check=True).stdout.strip()
    state = json.loads(SESSION.read_text()) if SESSION.is_file() else None
    if state and not state['vm'].get('destroyed') and state['topology'] != name:
        raise ProofError(f'The session holds topology {state["topology"]}; use down first.')
    if not state or state['vm'].get('destroyed'):
        spec = topology(name)
        state = {'topology': spec['name'], 'topology_version': spec['version'], 'nonce': secrets.token_hex(8), 'vm': {},
                 'receipts': [], 'up_started_at': utc(), 'up_started_epoch': time.time()}
    state.setdefault('receipts', [])
    state.setdefault('up_started_at', utc())
    state.setdefault('up_started_epoch', time.time())
    # A restage of a held VM serves the staged commit's topology version; each receipt records the one it ran on.
    state.update(commit=commit, topology_version=topology(name)['version'], ready=False, last_up_started_at=utc(), last_up_started_epoch=time.time())
    save(SESSION, state)
    guest = provision(gateway, state)
    heartbeat(guest)
    staged = stage(guest, commit)
    if agent_delivery is not None:
        # A CI-built delivery (apps/agent/scripts/build-delivery); up installs its orbit-agent on every agent Node (version 11).
        binary = Path(agent_delivery) / 'orbit-agent-linux-x86_64'
        if not binary.is_file():
            raise ProofError(f'{binary} is missing; pass a build-delivery directory.')
        guest.call(['sh', '-c', 'install -d -o orbit -g orbit /home/orbit/swamp-proof/agent-delivery && cat > /home/orbit/swamp-proof/agent-delivery/orbit-agent-linux-x86_64'],
                   input=binary.read_bytes(), timeout=300)
    bring_up(guest, state, commit, [{'step': 'stage', 'seconds': round(staged, 1)}])
    return state


def stage(guest, commit):
    """Replace the VM's source tree with a fresh clone of commit; returns the seconds it took."""
    started = time.time()
    with tempfile.TemporaryDirectory() as folder:
        bundle = Path(folder) / 'source.bundle'
        subprocess.run(['git', '-C', str(ROOT), 'bundle', 'create', str(bundle), 'HEAD'], check=True, capture_output=True)
        # A held VM's serve and agent-view subscriber write into the staged tree; stop them before it is replaced.
        script = ('systemctl stop orbit-node-worker orbit-swamp-serve orbit-proof-agent-view 2>/dev/null; rm -rf /home/orbit/swamp-proof'
                  ' && install -d -o orbit -g orbit /home/orbit/swamp-proof && cat > /home/orbit/swamp-proof/source.bundle')
        guest.call(['sh', '-c', script], input=bundle.read_bytes(), timeout=300)
    guest.call(['runuser', '-u', 'orbit', '--', 'git', 'clone', '-q', '--no-checkout', '/home/orbit/swamp-proof/source.bundle', SOURCE])
    guest.call(['runuser', '-u', 'orbit', '--', 'git', '-C', SOURCE, 'checkout', '-q', '--detach', commit])
    return time.time() - started


def bring_up(guest, state, commit, timings, fresh=False):
    """Run the guest's up on the staged commit: fresh Swamp credentials and Gateway database, and with fresh, new Nodes."""
    facts = last_json(guest.call(['python3', '-I', f'{SOURCE}/tools/swamp-proof-guest/up.py', SOURCE, state['topology'],
                                  *(['--fresh'] if fresh else [])], timeout=2700))
    if not facts.get('ready') or facts['commit'] != commit:
        raise ProofError('The topology did not come up on the staged commit.')
    state.update(ready=True, ready_at=utc(), ready_epoch=time.time(), facts=facts, runs_since_up=0,
                 timings=[*timings, *facts['timings']])
    save(SESSION, state)
    heartbeat(guest)


FIXTURE_ONLY = ('swamp/proof/', 'tools/swamp-proof-guest/run.py', 'tools/swamp-proof-guest/gateway.php', 'tools/swamp_proof.py', 'tools/test_swamp_proof.py', 'tools/test_swamp_proof_guest.py')


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
    fixture = json.loads((ROOT / 'swamp/proof' / f'{kind}.json').read_text())
    if fixture.get('topology', 'minimal') != state['topology']:
        raise ProofError(f'{kind} needs topology {fixture.get("topology", "minimal")}; this session holds {state["topology"]}.')
    guest = Guest(state['guest_host'], state['vm']['name'])
    heartbeat(guest)
    refresh(guest, state)
    # Consecutive kinds share one up. A fixture that needs a clean end state gets fresh Nodes and a fresh
    # Gateway database on the same VM, but only when an earlier fixture ran since the last up.
    if fixture.get('clean') is True and state.get('runs_since_up', 0) > 0:
        # A fresh clone too: the Swamp repository in the staged tree records its datastore, which up recreates.
        state['last_up_started_epoch'] = time.time()
        staged = stage(guest, state['commit'])
        bring_up(guest, state, state['commit'], [{'step': 'clean restage', 'seconds': round(staged, 1)}], fresh=True)
    started = time.time()
    body = last_json(guest.call(['python3', '-I', f'{SOURCE}/tools/swamp-proof-guest/run.py', SOURCE, kind], timeout=3600))
    heartbeat(guest)
    receipt = {'kind': kind, 'proof_pass': body.get('proof_pass') is True, 'topology': state['topology'],
               'topology_version': state['topology_version'], 'commit': state['commit'], 'vm': state['vm'],
               'refreshed': state.get('refreshed', []),
               'timings': {'up_steps': state['timings'], 'up_seconds': round(state['ready_epoch'] - state['last_up_started_epoch'], 1),
                           'run_seconds': round(time.time() - started, 1),
                           'up_to_receipt_seconds': round(time.time() - state['last_up_started_epoch'], 1),
                           'since_first_provision_seconds': round(time.time() - state['up_started_epoch'], 1)},
               'up_started_at': state['up_started_at'], 'written_at': utc(), **body}
    destination = EVIDENCE / kind / (datetime.now(UTC).strftime('%Y%m%dT%H%M%SZ') + f'-{state["topology"]}.json')
    save(destination, receipt)
    state['receipts'].append(str(destination))
    state['last_run_epoch'] = time.time()
    state['runs_since_up'] = state.get('runs_since_up', 0) + 1
    save(SESSION, state)
    return destination, receipt


def cancel(gateway, state, session):
    """Cancel the proof group; a cancel still running, or whose reply was lost, is followed under its key.

    A cancel that ended without success is not replayed: the next down admits a new one under the next key.
    """
    vm = state['vm']
    operation = gateway.request('GET', f'operations/{vm["cancel_operation_id"]}') if vm.get('cancel_operation_id') else None
    if operation is not None and operation['status'] in ('failed', 'cancelled'):
        vm['cancel_attempt'] = vm.get('cancel_attempt', 0) + 1
        operation = None
    if operation is None:
        attempt = vm.get('cancel_attempt', 0)
        operation = gateway.request('POST', f'task-groups/{vm["group_id"]}/cancel', {},
                                    f'swamp-proof:{state["nonce"]}:cancel' + (f':{attempt}' if attempt else ''))
        vm['cancel_operation_id'] = operation['id']
        save(session, state)
    return gateway.wait(operation, 'tasks.cancel', vm['group_id'])


def down(gateway, state, session=None):
    session = session or SESSION
    vm = state['vm']
    if vm.get('group_id') and not vm.get('destroyed'):
        cancel(gateway, state, session)
        group = gateway.request('GET', f'task-groups/{vm["group_id"]}')
        if group['status'] != 'cancelled' or ('task_vm_id' in vm and group.get('vm_power') != 'destroyed'):
            raise ProofError(f'Cancellation did not destroy proof group {vm["group_id"]}.')
        vm.update(destroyed=True, destroyed_at=utc())
    state['ready'] = False
    save(session, state)
    for path in map(Path, state.get('receipts', [])):
        receipt = json.loads(path.read_text())
        receipt['vm'] = vm
        save(path, receipt)
    return state


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    sub = parser.add_subparsers(dest='action', required=True)
    upper = sub.add_parser('up')
    upper.add_argument('--topology', default='minimal', help='Topology under swamp/proof/topologies (default minimal).')
    upper.add_argument('--agent-delivery', help='A build-delivery directory; its orbit-agent replaces the pinned agent on agent Nodes.')
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
                state = up(Gateway(), args.topology, args.agent_delivery)
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
