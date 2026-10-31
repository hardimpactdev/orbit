"""Ephemeral remote supervisor: verifies the effect Node, then runs one shell action."""
import json
import os
import pwd
import re
import signal
import subprocess
import sys
import threading

MAX_FRAME = 1024 * 1024


def terminate(process):
    # The shell can exit before a background child. Always clean its group.
    try:
        os.killpg(process.pid, signal.SIGTERM)
    except ProcessLookupError:
        pass
    try:
        process.wait(timeout=3)
    except subprocess.TimeoutExpired:
        pass
    try:
        os.killpg(process.pid, signal.SIGKILL)
    except ProcessLookupError:
        pass
    process.wait(timeout=1)


def main():
    if os.geteuid() != 0:
        raise ValueError('root supervisor required')
    header = sys.stdin.buffer.readline(32)
    if not re.fullmatch(rb'[1-9][0-9]{0,6}\n', header):
        raise ValueError('invalid frame')
    length = int(header)
    if length > MAX_FRAME:
        raise ValueError('invalid frame')
    raw = sys.stdin.buffer.read(length)
    if len(raw) != length:
        raise ValueError('incomplete frame')
    request = json.loads(raw)
    if (set(request) != {'schema_version', 'node_id', 'host', 'user', 'script', 'env', 'timeout_ms', 'interpreter', 'as_root'}
            or request['schema_version'] != 1 or isinstance(request['schema_version'], bool)
            or request['node_id'] not in (1, 18) or isinstance(request['node_id'], bool)
            or request['interpreter'] not in ('sh', 'python3') or not isinstance(request['as_root'], bool)
            or request['user'] != 'orbit' or not isinstance(request['script'], str)
            or not request['script'] or '\0' in request['script'] or len(request['script'].encode()) > 256 * 1024
            or not isinstance(request['timeout_ms'], int) or isinstance(request['timeout_ms'], bool)
            or not 1 <= request['timeout_ms'] <= 3600000 or not isinstance(request['env'], dict)):
        raise ValueError('invalid request')
    addresses = json.loads(subprocess.check_output(['/usr/sbin/ip', '-j', 'address', 'show'], timeout=8))
    if not any(row.get('local') == request['host'] for item in addresses for row in item.get('addr_info', [])):
        raise ValueError('wrong effect Node')
    state = subprocess.run(['/usr/bin/systemctl', 'is-enabled', 'orbit-node-worker.service'],
                           capture_output=True, text=True, timeout=8, check=False)
    pid = subprocess.check_output(['/usr/bin/systemctl', 'show', 'orbit-node-worker.service',
                                   '-p', 'MainPID', '--value'], text=True, timeout=8).strip()
    if state.stdout.strip() != 'masked' or pid != '0':
        raise ValueError('light Node worker is not masked and stopped')
    account = pwd.getpwnam(request['user'])
    if account.pw_uid <= 0 or account.pw_gid <= 0:
        raise ValueError('invalid Node account')
    env = {'PATH': '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
           'HOME': account.pw_dir, 'USER': account.pw_name, 'LOGNAME': account.pw_name, 'LANG': 'C'}
    for name, value in request['env'].items():
        if (not isinstance(name, str) or not re.fullmatch(r'ORBIT_[A-Z0-9_]{1,58}', name)
                or name in env or name in ('SHELL', 'ENV', 'BASH_ENV', 'SHELLOPTS', 'IFS')
                or name.startswith(('SWAMP_', 'LD_', 'DYLD_', 'PYTHON'))
                or not isinstance(value, str) or '\0' in value or len(value.encode()) > 256 * 1024):
            raise ValueError('invalid environment')
        env[name] = value
    argv = ['/usr/bin/python3', '-I', '-'] if request['interpreter'] == 'python3' else ['/bin/sh', '-s']
    process = subprocess.Popen(argv, stdin=subprocess.PIPE, env=env,
                               start_new_session=True, user=0 if request['as_root'] else account.pw_uid,
                               group=0 if request['as_root'] else account.pw_gid,
                               extra_groups=[] if request['as_root'] else os.getgrouplist(account.pw_name, account.pw_gid))
    finished = threading.Event()

    def closed():
        os.read(sys.stdin.fileno(), 1)
        if not finished.is_set():
            terminate(process)

    def stopped(_signum, _frame):
        terminate(process)
        raise SystemExit(124)

    signal.signal(signal.SIGTERM, stopped)
    signal.signal(signal.SIGINT, stopped)
    threading.Thread(target=closed, daemon=True).start()
    try:
        process.stdin.write(request['script'].encode())
        process.stdin.close()
        result = process.wait(timeout=request['timeout_ms'] / 1000)
        return result if result >= 0 else 128 - result
    finally:
        finished.set()
        terminate(process)
        if not process.stdin.closed:
            process.stdin.close()


if __name__ in ('__main__', '__builtin__'):
    try:
        sys.exit(main())
    except (OSError, ValueError, KeyError, TypeError, subprocess.SubprocessError):
        print('node.command.remote_refused', file=sys.stderr)
        sys.exit(1)
