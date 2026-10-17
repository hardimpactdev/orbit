"""Private task-lease SSH receipts. No payload, credentials, or command output are stored."""
import fcntl
import json
import os
import re
import signal
import subprocess
import sys
import time

mode, operation, binding, detached = sys.argv[1:5]
if mode not in ('run', 'probe', 'seal', 'payload') or not re.fullmatch(r'[a-f0-9]{32}', operation) or not re.fullmatch(r'[a-f0-9]{64}', binding):
    raise ValueError('Invalid task operation identity')
root = os.path.expanduser('~/.local/state/orbit/task-workspace-operations')
os.makedirs(root, mode=0o700, exist_ok=True)
path = os.path.join(root, operation)
unit = 'orbit-workspace-operation-' + operation + '.service'
description = 'Orbit workspace operation ' + binding
lock = os.open(path + '.lock', os.O_CREAT | os.O_RDWR, 0o600)
gate = os.open(os.path.join(root, 'binding-' + binding + '.lock'), os.O_CREAT | os.O_RDWR, 0o600)
sealed = os.path.join(root, 'binding-' + binding + '.sealed')
with open('/proc/sys/kernel/random/boot_id') as source:
    boot = source.read().strip()


def atomic_write(destination, data):
    temporary = destination + '.' + str(os.getpid())
    with open(temporary, 'w') as output:
        os.chmod(temporary, 0o600)
        json.dump(data, output)
        output.flush()
        os.fsync(output.fileno())
    os.replace(temporary, destination)
    directory = os.open(root, os.O_RDONLY | os.O_DIRECTORY)
    try:
        os.fsync(directory)
    finally:
        os.close(directory)
    return data


def write(state, exit_code=None, outcome_unknown=False):
    data = {'operation': operation, 'binding': binding, 'state': state, 'boot': boot}
    if state == 'finished':
        data['exit_code'] = exit_code
    if outcome_unknown:
        data['outcome_unknown'] = True
    return atomic_write(path, data)


def unit_state(unit_name):
    properties = ['LoadState', 'ActiveState', 'SubState', 'ControlGroup', 'Description', 'ExecMainCode', 'ExecMainStatus']
    result = subprocess.run(['systemctl', 'show', unit_name, '--no-pager',
                             '--property=' + ','.join(properties)], capture_output=True, text=True)
    if result.returncode != 0:
        return None
    return dict(line.split('=', 1) for line in result.stdout.splitlines() if '=' in line)


def empty_terminal(unit_name, expected_description=None, state=None):
    state = unit_state(unit_name) if state is None else state
    if state is None:
        return None
    if state.get('LoadState') != 'loaded' or (expected_description is not None and state.get('Description') != expected_description):
        return None
    if not ((state.get('ActiveState') == 'active' and state.get('SubState') == 'exited')
            or state.get('ActiveState') in ('inactive', 'failed')):
        return None
    group = state.get('ControlGroup')
    if group:
        if not group.startswith('/') or '..' in group.split('/'):
            return None
        try:
            with open('/sys/fs/cgroup' + group + '/cgroup.events') as source:
                events = dict(line.split() for line in source)
            if events.get('populated') != '0':
                return None
        except FileNotFoundError:
            pass
        except OSError:
            return None
    return state


def all_managed_units_terminal(operations, check_runs):
    result = subprocess.run(['systemctl', 'list-units', '--all', '--no-pager', '--output=json',
                             'orbit-workspace-operation-*', 'orbit-task-check-*'], capture_output=True, text=True)
    if result.returncode != 0:
        return False
    try:
        units = json.loads(result.stdout)
        if not isinstance(units, list):
            return False
        for item in units:
            if not isinstance(item, dict) or not isinstance(item.get('unit'), str):
                return False
            name = item['unit']
            state = unit_state(name)
            if state is None or state.get('LoadState') != 'loaded':
                return False
            operation_match = re.fullmatch(r'orbit-workspace-operation-([a-f0-9]{32})\.service', name)
            check_match = re.fullmatch(r'orbit-task-check-([a-f0-9]{32})\.service', name)
            if operation_match:
                identity = operation_match[1]
                unit_description = state.get('Description', '')
                match = re.fullmatch(r'Orbit workspace operation ([a-f0-9]{64})', unit_description)
                if match is None or (identity in operations and unit_description != description):
                    return False
                relevant = match[1] == binding
                expected_description = description
            elif check_match:
                identity = check_match[1]
                expected_description = 'Orbit task check ' + identity
                if state.get('Description') != expected_description:
                    return False
                relevant = identity in check_runs
            else:
                return False
            if relevant and empty_terminal(name, expected_description, state) is None:
                return False
        return True
    except (ValueError, OSError):
        return False


if mode == 'payload':
    # This admission runs INSIDE the unit. A queued systemd-run cannot bypass a later seal.
    # Once admitted the unit/cgroup itself proves liveness until every descendant stops.
    with os.fdopen(gate) as admission:
        fcntl.flock(admission, fcntl.LOCK_EX)
        if os.path.exists(sealed):
            sys.exit(75)
    os.execvp(sys.argv[5], sys.argv[5:])

if mode == 'seal':
    operations = json.loads(sys.argv[5])
    check_runs = json.loads(sys.argv[6]) if len(sys.argv) > 6 else []
    for identities in (operations, check_runs):
        if not isinstance(identities, list) or not all(isinstance(item, str) and re.fullmatch(r'[a-f0-9]{32}', item) for item in identities):
            raise ValueError('Invalid recovery operation or check identities')
    with os.fdopen(gate) as admission:
        fcntl.flock(admission, fcntl.LOCK_EX)
        atomic_write(sealed, {'binding': binding, 'boot': boot})
        locks = []
        try:
            for identity in operations:
                handle = os.open(os.path.join(root, identity + '.lock'), os.O_CREAT | os.O_RDWR, 0o600)
                locks.append(handle)
                try:
                    fcntl.flock(handle, fcntl.LOCK_EX | fcntl.LOCK_NB)
                except BlockingIOError:
                    print(json.dumps({'binding': binding, 'state': 'running'}))
                    sys.exit(0)
            if not all_managed_units_terminal(operations, check_runs):
                print(json.dumps({'binding': binding, 'state': 'running'}))
                sys.exit(0)
            for identity in operations:
                destination = os.path.join(root, identity)
                try:
                    with open(destination) as source:
                        receipt = json.load(source)
                except (OSError, ValueError):
                    receipt = None
                if receipt is not None and (receipt.get('binding') != binding or receipt.get('operation') != identity):
                    raise ValueError('Recovery operation ownership differs')
                if receipt is None or receipt.get('state') not in ('finished', 'not_started'):
                    atomic_write(destination, {'operation': identity, 'binding': binding, 'state': 'finished',
                                               'boot': boot, 'exit_code': None, 'outcome_unknown': True})
            print(json.dumps({'binding': binding, 'state': 'sealed', 'boot': boot, 'operations': len(operations), 'check_runs': len(check_runs)}))
        finally:
            for handle in locks:
                os.close(handle)
    sys.exit(0)


def contained_completion(data):
    # This helper handles the current boot only; probe separately settles an old boot as unknown.
    if data.get('boot') != boot:
        return None
    state = empty_terminal(unit, description)
    if state is None:
        return None
    try:
        code = int(state['ExecMainStatus'])
        main_code = int(state['ExecMainCode'])
    except (KeyError, ValueError):
        return None
    return code if main_code == 1 else 128 + code


def retire_unit():
    # The durable receipt precedes cleanup. Never stop an unconfirmed active operation.
    subprocess.run(['sudo', '-n', 'systemctl', 'stop', unit], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)


try:
    fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
except BlockingIOError:
    if mode == 'probe':
        print(json.dumps({'operation': operation, 'binding': binding, 'state': 'running'}))
        sys.exit(0)
    sys.exit(75)

data = None
if os.path.exists(path):
    with open(path) as source:
        data = json.load(source)
    if data.get('operation') != operation or data.get('binding') != binding:
        raise ValueError('Task operation ownership differs')
if mode == 'probe':
    if data is None:
        # Admission and no-start fencing use the same lock; a late original cannot start.
        data = write('not_started')
    elif data.get('state') == 'running' and data.get('boot') and data['boot'] != boot:
        # Boot identity proves old processes ended, not their success or resulting tree.
        data = write('finished', outcome_unknown=True)
    elif data.get('state') == 'running':
        code = contained_completion(data)
        if code is not None:
            data = write('finished', code)
            retire_unit()
    print(json.dumps(data))
    sys.exit(0)
if data is not None:
    sys.exit(75)

signal.signal(signal.SIGHUP, signal.SIG_IGN)
with os.fdopen(gate) as admission:
    fcntl.flock(admission, fcntl.LOCK_EX)
    if os.path.exists(sealed):
        write('not_started')
        sys.exit(75)
try:
    prerequisite = subprocess.run(['sudo', '-n', 'systemctl', 'show', '--property=Version'],
                                  capture_output=True, text=True)
    version = re.search(r'^Version=(\d+)', prerequisite.stdout, re.MULTILINE)
    if prerequisite.returncode != 0 or not version or int(version.group(1)) < 250 or not os.path.exists('/sys/fs/cgroup/cgroup.controllers'):
        write('not_started')
        sys.exit(78)
except OSError:
    write('not_started')
    sys.exit(78)
data = write('running')

# The system manager is a managed Linux prerequisite. The service drops back to the SSH UID/GID.
# RemainAfterExit retains authoritative completion if this client dies before writing its receipt.
arguments = ['sudo', '-n', 'systemd-run', '--quiet', '--pipe', '--unit=' + unit,
             '--uid=' + str(os.getuid()), '--gid=' + str(os.getgid()),
             '--property=Type=exec', '--property=ExitType=cgroup', '--property=RemainAfterExit=yes',
             '--property=KillMode=control-group', '--property=WorkingDirectory=' + os.getcwd(),
             '--description=' + description]
for name in ('HOME', 'PATH', 'LANG', 'LC_ALL', 'TMPDIR'):
    if name in os.environ:
        arguments.append('--setenv=' + name + '=' + os.environ[name])
# __file__ does not exist for -c. Reuse the exact running program, never an environment override.
with open('/proc/self/cmdline', 'rb') as source:
    invocation = source.read().split(b'\0')
program = invocation[invocation.index(b'-c') + 1].decode()
payload = ['python3', '-c', program, 'payload', operation, binding, '0', *sys.argv[5:]]
child = subprocess.Popen(arguments + ['--'] + payload, stdin=sys.stdin.buffer)
while True:
    code = contained_completion(data)
    if code is not None:
        write('finished', code)
        retire_unit()
        child.wait()
        sys.exit(code)
    if child.poll() is not None:
        # Failed launch/supervision is not a successful no-start proof.
        sys.exit(child.returncode if child.returncode > 0 else 75)
    time.sleep(0.1)
