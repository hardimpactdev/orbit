"""Shared light-Node shell transport; no local action branch or credential issuance."""
import argparse
import base64
import hashlib
import json
import os
import re
import selectors
import shlex
import signal
import stat
import subprocess
import sys
import time
from pathlib import Path

ROOT = Path('/etc/orbit/node-proxy')
CONTRACT = Path(__file__).with_name('proxy_contract.json')
REMOTE = Path(__file__).with_name('proxy_remote.py')
MAX_FRAME = 1024 * 1024
MAX_SCRIPT = 256 * 1024
MAX_OUTPUT = 64 * 1024 * 1024
SAFE_ENV = {'PATH': '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin', 'LANG': 'C'}
# Public fixed loader only. All shipped source, action text and values use SSH stdin.
LOADER = ('import sys; n=int(sys.stdin.buffer.readline(32)); '
          'assert 0<n<=65536; '
          'exec(compile(sys.stdin.buffer.read(n),"<orbit-proxy-supervisor>","exec"))')

# This fixed read-only program returns no file content. The identity directory is
# root0700, so the unprivileged proxy must use its existing sudo permission.
IDENTITY_CHECK = """import os, stat, tomllib
for path, directory in [('/etc/orbit/agent', True), ('/etc/orbit/agent/config.toml', False)]:
    info = os.lstat(path)
    if (info.st_uid != 0 or info.st_gid != 0 or info.st_mode & 0o022
            or not (stat.S_ISDIR(info.st_mode) if directory else stat.S_ISREG(info.st_mode))):
        raise SystemExit(1)
with open('/etc/orbit/agent/config.toml', 'rb') as stream:
    raw = stream.read(65537)
if len(raw) > 65536 or tomllib.loads(raw.decode()).get('swamp_worker') != {'node_id': 209, 'user': 'orbit'}:
    raise SystemExit(1)
print('orbit-node-209')
"""


class Refused(Exception):
    """A fixed public refusal code; never includes request bytes."""


def custody(path, mode=None, directory=False):
    info = os.lstat(path)
    kind = stat.S_ISDIR if directory else stat.S_ISREG
    if info.st_uid != 0 or info.st_gid != 0 or info.st_mode & 0o022 or not kind(info.st_mode):
        raise Refused('node.command.custody_refused')
    if mode is not None and stat.S_IMODE(info.st_mode) != mode:
        raise Refused('node.command.custody_refused')
    return info


def bounded_file(path, limit):
    with open(path, 'rb') as stream:
        value = stream.read(limit + 1)
    if len(value) > limit:
        raise Refused('node.command.metadata_oversized')
    return value


def placement(node):
    contract = json.loads(CONTRACT.read_text())
    cgroup = bounded_file('/proc/self/cgroup', 65536).decode()
    match = re.search(contract['cgroup_pattern'], cgroup, re.MULTILINE)
    if match is None or match.group(1) not in ('1', '18') or int(match.group(1)) != node:
        raise Refused('node.command.proxy_placement_required')
    for path in (Path('/etc'), Path('/etc/orbit'), Path('/etc/orbit/agent'), ROOT):
        custody(path, directory=True)
    identity = subprocess.run(['/usr/bin/sudo', '-n', '--', '/usr/bin/python3', '-I', '-c', IDENTITY_CHECK],
                              stdin=subprocess.DEVNULL, capture_output=True, timeout=8, env=SAFE_ENV,
                              check=False)
    if identity.returncode != 0 or identity.stdout != b'orbit-node-209\n':
        raise Refused('node.command.proxy_host_refused')
    selected = ROOT / (str(node) + '.json')
    custody(selected, 0o644)
    value = json.loads(bounded_file(selected, 4096))
    target = contract['targets'][str(node)]
    if value != {key: target[key] for key in ('node_id', 'host', 'user')}:
        raise Refused('node.command.proxy_selection_refused')
    custody(ROOT / 'id_ed25519', 0o600)
    custody(ROOT / 'known_hosts', 0o644)
    records = []
    for item in contract['targets'].values():
        wire = base64.b64decode(item['host_key'].split()[1], validate=True)
        fingerprint = 'SHA256:' + base64.b64encode(hashlib.sha256(wire).digest()).decode().rstrip('=')
        if fingerprint != item['host_fingerprint']:
            raise Refused('node.command.pinned_key_refused')
        records.append(item['host'] + ' ' + item['host_key'])
    if bounded_file(ROOT / 'known_hosts', 16384).decode().splitlines() != records:
        raise Refused('node.command.pinned_key_refused')
    return target


def request(args, target):
    action = sys.stdin.buffer.read(MAX_SCRIPT + 1)
    if not action or len(action) > MAX_SCRIPT or b'\0' in action:
        raise Refused('node.command.script_refused')
    names = args.env + args.secret_env
    if len(names) != len(set(names)) or len(names) > 64:
        raise Refused('node.command.environment_refused')
    forwarded = {}
    reserved = {'PATH', 'HOME', 'USER', 'LOGNAME', 'SHELL', 'ENV', 'BASH_ENV', 'SHELLOPTS', 'IFS', 'LANG'}
    for name in names:
        if (not re.fullmatch(r'ORBIT_[A-Z0-9_]{1,58}', name) or name in reserved
                or name.startswith(('SWAMP_', 'LD_', 'DYLD_', 'PYTHON')) or name not in os.environ):
            raise Refused('node.command.environment_refused')
        value = os.environ[name]
        if '\0' in value or len(value.encode()) > MAX_SCRIPT:
            raise Refused('node.command.environment_refused')
        forwarded[name] = value
    body = json.dumps({'schema_version': 1, 'node_id': args.node_id, 'host': target['host'],
                       'user': target['user'], 'script': action.decode(), 'env': forwarded,
                       'timeout_ms': args.timeout_ms}, separators=(',', ':')).encode()
    if len(body) > MAX_FRAME:
        raise Refused('node.command.input_oversized')
    program = REMOTE.read_bytes()
    if not 0 < len(program) <= 65536:
        raise Refused('node.command.supervisor_refused')
    frame = str(len(program)).encode() + b'\n' + program + str(len(body)).encode() + b'\n' + body
    return frame, [forwarded[name].encode() for name in args.secret_env if forwarded[name]]


def launch(target, timeout_ms):
    options = [
        'BatchMode=yes', 'IdentitiesOnly=yes', 'IdentityAgent=none', 'ForwardAgent=no',
        'UpdateHostKeys=no', 'VerifyHostKeyDNS=no', 'HostKeyAlgorithms=ssh-ed25519',
        'ClearAllForwardings=yes', 'StrictHostKeyChecking=yes',
        'UserKnownHostsFile=' + str(ROOT / 'known_hosts'), 'GlobalKnownHostsFile=/dev/null',
        'ConnectTimeout=8', 'ConnectionAttempts=1', 'ServerAliveInterval=10',
        'ServerAliveCountMax=2', 'LogLevel=ERROR',
    ]
    argv = ['/usr/bin/sudo', '-n', '--', '/usr/bin/ssh', '-F', '/dev/null', '-T']
    for option in options:
        argv.extend(['-o', option])
    remote = ['/usr/bin/sudo', '-n', '--', '/usr/bin/timeout', '--signal=TERM',
              '--kill-after=5s', str(timeout_ms / 1000) + 's', '/usr/bin/python3', '-I', '-c', LOADER]
    return argv + ['-i', str(ROOT / 'id_ed25519'), target['user'] + '@' + target['host'], shlex.join(remote)]


def stop(process):
    if process.stdin and not process.stdin.closed:
        process.stdin.close()  # Remote control EOF terminates the remote action group.
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
    process.wait(timeout=3)


def exchange(argv, frame, limit, timeout_ms):
    process = subprocess.Popen(argv, stdin=subprocess.PIPE, stdout=subprocess.PIPE,
                               stderr=subprocess.PIPE, env=SAFE_ENV, start_new_session=True)
    selector = selectors.DefaultSelector()
    outputs = {process.stdout: bytearray(), process.stderr: bytearray()}
    offset = total = 0
    deadline = time.monotonic() + timeout_ms / 1000 + 8
    try:
        for stream in (process.stdin, process.stdout, process.stderr):
            os.set_blocking(stream.fileno(), False)
            selector.register(stream, selectors.EVENT_WRITE if stream is process.stdin else selectors.EVENT_READ)
        # This is bounded full-duplex process I/O, not workflow polling/retry.
        while selector.get_map():
            remaining = deadline - time.monotonic()
            if remaining <= 0:
                raise Refused('node.command.deadline_exceeded')
            events = selector.select(remaining)
            if not events:
                raise Refused('node.command.deadline_exceeded')
            for key, _mask in events:
                stream = key.fileobj
                if stream is process.stdin:
                    try:
                        written = os.write(stream.fileno(), frame[offset:offset + 65536])
                    except BrokenPipeError:
                        selector.unregister(stream)
                        continue
                    offset += written
                    if offset == len(frame):
                        # Keep SSH stdin open as a cancellation/control channel.
                        selector.unregister(stream)
                else:
                    block = os.read(stream.fileno(), 8192)
                    if not block:
                        selector.unregister(stream)
                    else:
                        total += len(block)
                        if total > limit:
                            raise Refused('node.command.output_oversized')
                        outputs[stream].extend(block)
        code = process.wait(timeout=max(.01, deadline - time.monotonic()))
        if offset != len(frame):
            raise Refused('node.command.channel_uncertain')
        return code, bytes(outputs[process.stdout]), bytes(outputs[process.stderr])
    finally:
        selector.close()
        stop(process)
        for stream in (process.stdout, process.stderr):
            stream.close()


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--node-id', type=int, required=True, choices=(1, 18))
    parser.add_argument('--env', action='append', default=[])
    parser.add_argument('--secret-env', action='append', default=[])
    parser.add_argument('--timeout-ms', type=int, default=60000)
    parser.add_argument('--max-output-bytes', type=int, default=65536)
    args = parser.parse_args()
    if not 1 <= args.timeout_ms <= 3600000 or not 1 <= args.max_output_bytes <= MAX_OUTPUT:
        raise Refused('node.command.limits_refused')
    target = placement(args.node_id)
    frame, values = request(args, target)
    code, stdout, stderr = exchange(launch(target, args.timeout_ms), frame,
                                  args.max_output_bytes, args.timeout_ms)
    for value in sorted(values, key=len, reverse=True):
        stdout, stderr = stdout.replace(value, b'[redacted]'), stderr.replace(value, b'[redacted]')
    if len(stdout) + len(stderr) > args.max_output_bytes:
        raise Refused('node.command.output_oversized')
    sys.stdout.buffer.write(stdout)
    sys.stderr.buffer.write(stderr)
    return code


def interrupted(_signum, _frame):
    raise Refused('node.command.cancelled')


if __name__ == '__main__':
    signal.signal(signal.SIGTERM, interrupted)
    signal.signal(signal.SIGINT, interrupted)
    try:
        sys.exit(main())
    except Refused as error:
        print(str(error), file=sys.stderr)
        sys.exit(1)
    except (OSError, ValueError, KeyError, TypeError, subprocess.SubprocessError):
        print('node.command.channel_uncertain', file=sys.stderr)
        sys.exit(1)
