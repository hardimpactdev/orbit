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
if mode not in ('run', 'probe') or not re.fullmatch(r'[a-f0-9]{32}', operation):
    raise ValueError('Invalid task operation identity')
root = os.path.expanduser('~/.local/state/orbit/task-workspace-operations')
os.makedirs(root, mode=0o700, exist_ok=True)
path = os.path.join(root, operation)
unit = 'orbit-workspace-operation-' + operation + '.service'
description = 'Orbit workspace operation ' + binding
lock = os.open(path + '.lock', os.O_CREAT | os.O_RDWR, 0o600)
with open('/proc/sys/kernel/random/boot_id') as source:
    boot = source.read().strip()


def write(state, exit_code=None):
    data = {'operation': operation, 'binding': binding, 'state': state, 'boot': boot}
    if exit_code is not None:
        data['exit_code'] = exit_code
    temporary = path + '.' + str(os.getpid())
    with open(temporary, 'w') as output:
        os.chmod(temporary, 0o600)
        json.dump(data, output)
        output.flush()
        os.fsync(output.fileno())
    os.replace(temporary, path)
    directory = os.open(root, os.O_RDONLY | os.O_DIRECTORY)
    try:
        os.fsync(directory)
    finally:
        os.close(directory)
    return data


def contained_completion(data):
    # A different boot, missing unit, or unreadable cgroup is uncertainty, never termination proof.
    if data.get('boot') != boot:
        return None
    properties = ['LoadState', 'ActiveState', 'SubState', 'ControlGroup', 'Description', 'ExecMainCode', 'ExecMainStatus']
    result = subprocess.run(['systemctl', 'show', unit, '--no-pager',
                             '--property=' + ','.join(properties)], capture_output=True, text=True)
    if result.returncode != 0:
        return None
    state = dict(line.split('=', 1) for line in result.stdout.splitlines() if '=' in line)
    if state.get('LoadState') != 'loaded' or state.get('Description') != description:
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
            # A terminal retained unit whose cgroup was removed has no remaining descendants.
            pass
        except OSError:
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
child = subprocess.Popen(arguments + ['--'] + sys.argv[5:], stdin=sys.stdin.buffer)
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
