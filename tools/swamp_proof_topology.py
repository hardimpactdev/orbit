"""F13 sessions compose the existing whole-product Incus topology in an owned Task VM."""
import argparse
import fcntl
import hashlib
import json
import os
import secrets
import signal
import subprocess
import sys
import tempfile
from datetime import UTC, datetime, timedelta
from pathlib import Path

from swamp_proof import (
    ROOT,
    Gateway,
    ProofError,
    destroy,
    proof_project,
    provision,
    topology_for,
    utc,
)
from swamp_proof_transport import Guest, command

CLUB_FILE = '/home/orbit/.config/swamp/club-api-key'


def state_path(root=ROOT):
    lane = hashlib.sha256(str(root.resolve()).encode()).hexdigest()[:16]
    return Path.home() / 'swamp-execution/proof-sessions' / (lane + '.json')


def save_state(state, path=None):
    path = path or state_path()
    path.parent.mkdir(parents=True, exist_ok=True)
    pending = path.with_suffix('.pending')
    with open(pending, 'w', opener=lambda name, flags: os.open(name, flags, 0o600)) as stream:
        stream.write(json.dumps(state, indent=2) + '\n')
    pending.chmod(0o600)
    pending.replace(path)


def load_state(path=None):
    return json.loads((path or state_path()).read_text())


def expired(group, last_run=None, now=None):
    created = datetime.fromisoformat(group['created_at'])
    now = now or datetime.now(UTC)
    if group['title'].startswith('topology '):
        activity = datetime.fromtimestamp(last_run, UTC) if last_run is not None else created
        return now >= created + timedelta(hours=8) or now >= activity + timedelta(minutes=60)
    return now >= created + timedelta(minutes=45)


def owned_guest(state):
    return Guest({'record': state['vm']}, state['operator'])


def user_call(guest, user, argv, source=None, timeout=60, public=False):
    return guest.call(['sudo', '-n', '-u', user, '--', *argv], source, timeout, public=public)


def bundle_source():
    if command(['git', '-C', str(ROOT), 'status', '--porcelain']).strip():
        raise ProofError('Commit the PR worktree before bringing up its proof topology.')
    commit = command(['git', '-C', str(ROOT), 'rev-parse', 'HEAD']).decode().strip()
    with tempfile.TemporaryDirectory(prefix='swamp-proof-source-') as tmp:
        path = Path(tmp) / 'source.bundle'
        command(['git', '-C', str(ROOT), 'bundle', 'create', str(path), 'HEAD'], timeout=120)
        return commit, path.read_bytes()


def acquisition_record(acquired, state):
    if acquired.get('state') != 'acquired' or acquired.get('issue') != state['issue']:
        raise ProofError('The existing topology harness did not confirm acquisition.')
    record = acquired['topology']
    source = record['source']
    if not record['verification']['passed'] or source['host_sha'] != state['commit'] or source['guest_sha'] != state['commit'] or source['dirty'] or not source['mounted']:
        raise ProofError('The topology did not verify the exact committed source mount.')
    return record


def harness_json(guest, user, state, node, argv):
    raw = user_call(guest, user, ['env', '-C', state['worktree'], 'bin/e2e-topology', 'exec', state['issue'], node,
                                 '--argv=' + json.dumps(argv), '--timeout=60', '--json'], timeout=75, public=True)
    response = json.loads(raw)
    if response.get('state') != 'executed' or response.get('exit_code') != 0:
        raise ProofError('Topology readiness command did not succeed on ' + node)
    return json.loads(response['stdout'])


def bootstrap(guest, control, state, bundle):
    user = control['node']['user']
    home = '/home/' + user
    worktree = home + '/swamp-proof/' + state['commit']
    private = home + '/.local/share/swamp-proof'
    # Credential content stays VM-local. No read crosses the operator transport.
    info = user_call(guest, user, ['stat', '-c', '%a:%U', CLUB_FILE]).decode().strip()
    if info != '600:' + user:
        raise ProofError('Normal provision did not deliver the private proof club file to its configured user.')
    user_call(guest, user, ['test', '-s', CLUB_FILE])
    guest.call(['install', '-d', '-m', '700', '-o', user, '-g', user, home + '/swamp-proof', private])
    user_call(guest, user, ['sh', '-c', 'umask 077; cat > "$1"', '--', private + '/source.bundle'], bundle, timeout=120)
    user_call(guest, user, ['git', 'clone', '--no-checkout', private + '/source.bundle', worktree], timeout=120)
    user_call(guest, user, ['git', '-C', worktree, 'checkout', '-b', 'task-' + str(state['vm']['group_id']) + '-proof', state['commit']])
    # Repository bootstrap and the existing topology host setup own all installation.
    user_call(guest, user, ['env', '-C', worktree, 'bin/bootstrap', '--skip-checks'], timeout=900, public=True)
    user_call(guest, user, ['env', '-C', worktree + '/apps/agent', 'cargo', 'build', '--release', '--locked'], timeout=900, public=True)
    guest.call(['env', '-C', worktree, 'bin/e2e-topology-host-setup'], timeout=300, public=True)
    raw = user_call(guest, user, ['env', '-C', worktree, 'ORBIT_E2E_SWAMP_CLUB_TOKEN_FILE=' + CLUB_FILE,
                                'bin/e2e-topology', 'acquire', state['issue'], worktree,
                                '--nodes=' + ','.join(state['nodes']), '--json'], timeout=1800, public=True)
    acquired = json.loads(raw)
    record = acquisition_record(acquired, state)
    state.update(worktree=worktree, user=user, topology_record=record)
    sandbox = harness_json(guest, user, state, 'gateway', ['sudo', '-n', '/usr/local/bin/converge-swamp.py', 'verify'])
    gateway = harness_json(guest, user, state, 'operator', ['orbit', 'gateway:status', '--json'])
    if sandbox.get('ready') is not True or sandbox.get('worker') != 'orbit-node-209' or gateway.get('release_sha') != state['commit'] or not gateway.get('release'):
        raise ProofError('Topology Gateway release or isolated native worker is unconfirmed.')
    state.update(gateway_install='adopt', gateway_status=gateway, sandbox_verified=sandbox, ready=True, ready_at=utc())
    return state


def up(gateway, project=None, name='app-dev', path=None, lane=None):
    path = path or state_path()
    topology = topology_for(name)
    if path.is_file():
        old = load_state(path)
        if old.get('vm', {}).get('group_id') and not old['vm'].get('destroyed'):
            # Keep the exact allocation until normal cancellation confirms destruction.
            raise ProofError('This lane already owns a proof group. Use run, receipt or down before another up.')
    commit, bundle = bundle_source()
    project = proof_project(gateway, project)
    config = gateway.request('GET', f'projects/{project}')
    exclusions = gateway.request('GET', f'projects/{project}/excluded-nodes')
    excluded = {row['id'] for row in exclusions}
    if project != 62 or config['slug'] != 'swamp-proof' or 9 not in excluded or 13 in excluded or config.get('task_workspace_routed') or config.get('review_and_merge'):
        raise ProofError('Project62/swamp-proof must exclude Beast, allow Shark, and disable routing and merge.')
    lane = lane or ROOT.name
    if not lane or len(lane) > 80 or any(ord(char) < 32 for char in lane):
        raise ProofError('Invalid proof lane title.')
    title = f'topology {topology["name"]}@{topology["version"]} {lane}'
    state = {'topology': topology['name'], 'topology_version': topology['version'], 'nodes': topology['nodes'],
             'commit': commit, 'started_at': utc(), 'ready': False, 'vm': {}, 'nonce': secrets.token_hex(8), 'receipts': []}
    save = lambda: save_state(state, path)
    save()
    try:
        control = provision(gateway, project, title, 'topology', state['nonce'], state['vm'], save, nodes=state['nodes'])
        if topology['seed'] is not None and topology['seed'] != state['vm']['seed']:
            raise ProofError('Normal allocation base-image fingerprint differs from the preset seed.')
        host = gateway.request('GET', 'nodes/13')
        if host['name'] != 'shark' or host['status'] != 'active':
            raise ProofError('Normal provision host is not active Shark.')
        state['operator'] = f'{host["user"]}@{host["wireguard_ip"]}'
        state['issue'] = 'TASK-' + str(state['vm']['group_id'])
        save()
        bootstrap(owned_guest(state), control, state, bundle)
        save()
        return state
    except BaseException:
        try:
            destroy(gateway, state['vm'], state['nonce'])
        except (ProofError, OSError, ValueError, KeyError) as error:
            state['cleanup_error'] = str(error) if isinstance(error, ProofError) else 'Proof cleanup unconfirmed.'
        save()
        raise


def down(gateway, path=None):
    path = path or state_path()
    state = load_state(path)
    if not state['vm'].get('destroyed'):
        try:
            destroy(gateway, state['vm'], state['nonce'])
        finally:
            save_state(state, path)
    state.update(ready=False, destroyed=state['vm'].get('destroyed') is True, destroyed_at=utc())
    for receipt_path in state.get('receipts', []):
        receipt_path = Path(receipt_path)
        receipt = json.loads(receipt_path.read_text())
        receipt.update(destroyed=state['destroyed'], destroyed_at=state['destroyed_at'], vm=dict(state['vm']))
        save_state(receipt, receipt_path)
    save_state(state, path)
    return state


def run(state, kind, path=None, variant=None):
    if not state.get('ready') or state['vm'].get('destroyed'):
        raise ProofError('Use up to acquire a ready proof topology first.')
    # The held sandbox and every fixture must name exactly this committed source.
    from swamp_proof import fixture_for
    fixture_for(kind, variant=variant)
    if command(['git', '-C', str(ROOT), 'rev-parse', 'HEAD']).decode().strip() != state['commit'] or command(['git', '-C', str(ROOT), 'status', '--porcelain']).strip():
        raise ProofError('The held topology serves another source commit. Use down then up for this committed worktree.')
    stamp = datetime.now(UTC).strftime('%Y%m%dT%H%M%S.%fZ')
    destination = Path.home() / 'swamp-execution/evidence/proof' / kind / (stamp + '.json')
    receipt = {'kind': kind, 'proof_pass': False, 'vm': dict(state['vm']), 'swamp_commit': state['commit'], 'started_at': utc()}
    save_state(receipt, destination)
    state['receipts'].append(str(destination))
    save_state(state, path)
    remote = state['worktree'] + '/.e2e/proof/' + stamp + '.json'
    payload = json.dumps({'state': state, 'kind': kind, 'receipt_path': remote, 'variant': variant}).encode()
    try:
        raw = user_call(owned_guest(state), state['user'], ['env', '-C', state['worktree'], 'python3', 'tools/swamp_proof_run.py'],
                        payload, timeout=3600, public=True)
        receipt = json.loads(raw)
    except (ProofError, OSError, ValueError, KeyboardInterrupt) as error:
        receipt['error'] = str(error) if isinstance(error, ProofError) else 'Proof transport interrupted; inspect owned topology and down.'
        # Recover the public admitted identity when the remote runner already persisted it.
        try:
            recovered = user_call(owned_guest(state), state['user'], ['cat', remote])
            receipt.update(json.loads(recovered), proof_pass=False, transport_interrupted=True)
        except (ProofError, OSError, ValueError):
            pass
    finally:
        save_state(receipt, destination)
    return destination, receipt


def cli(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--gateway-config', type=Path, default=Path(os.environ.get('ORBIT_HOME', Path.home() / '.orbit')) / 'config.json')
    sub = parser.add_subparsers(dest='action', required=True)
    start = sub.add_parser('up', help='Provision one owned Task VM and compose its isolated topology.')
    start.add_argument('--topology', default='app-dev', help='Versioned preset or a JSON array of Task Nodes.')
    start.add_argument('--lane', default=ROOT.name)
    execute_parser = sub.add_parser('run', help='Admit real CLI requests in the held topology.')
    execute_parser.add_argument('kind', nargs='+')
    execute_parser.add_argument('--variant')
    sub.add_parser('receipt', help='Print the latest receipt path.')
    sub.add_parser('down', help='Cancel the owned group and confirm VM destruction.')
    args = parser.parse_args(argv)
    def interrupted(_signal, _frame):
        raise KeyboardInterrupt
    for number in (signal.SIGTERM, signal.SIGHUP, signal.SIGINT):
        signal.signal(number, interrupted)
    path = state_path()
    path.parent.mkdir(parents=True, exist_ok=True)
    try:
        with path.with_suffix('.lock').open('a') as lock:
            try:
                fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
            except BlockingIOError:
                raise ProofError('Another proof command owns this lane session.') from None
            if args.action == 'up':
                state = up(Gateway(args.gateway_config), name=args.topology, lane=args.lane)
                print(json.dumps(state))
            elif args.action == 'down':
                print(json.dumps(down(Gateway(args.gateway_config))))
            elif args.action == 'receipt':
                receipts = load_state().get('receipts', [])
                if not receipts:
                    raise ProofError('This session has no proof receipt.')
                print(receipts[-1])
            else:
                state = load_state()
                from swamp_proof import fixture_variants
                if args.variant is not None and len(args.kind) != 1:
                    raise ProofError('--variant selects one kind.')
                for kind in args.kind:
                    for variant, _fixture in fixture_variants(kind, args.variant):
                        destination, result = run(state, kind, variant=variant)
                        print(destination)
                        if not result.get('proof_pass'):
                            print(result.get('error', 'Proof failed; inspect its receipt and use down after diagnosis.'), file=sys.stderr)
                            return 1
        return 0
    except (ProofError, OSError, ValueError, KeyError, subprocess.TimeoutExpired, KeyboardInterrupt) as error:
        print(str(error) if isinstance(error, ProofError) else 'Proof configuration or transport unavailable; owned state is retained.', file=sys.stderr)
        return 2
