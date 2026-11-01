"""The swamp role's worker units.

Operators place each worker's tokens; this never mints, reads or moves them.
"""

import json
import os
import pwd
import re
import stat
import subprocess
import sys

ROOT = '/etc/orbit/swamp-role-worker'
UNIT = '/etc/systemd/system/orbit-swamp-worker@.service'
BINARY = '/usr/local/bin/swamp'
IDENTITY_SOURCE = '/etc/orbit/node-proxy/id_ed25519'
IDENTITY = ROOT + '/ssh-identity'
HOME = '/var/lib/orbit-swamp-worker/%i'


def done(value):
    print(json.dumps(value))
    sys.exit(0)


def systemctl(*args):
    return subprocess.run(['/usr/bin/systemctl', *args], capture_output=True, text=True, check=False).returncode == 0


def publish(path, content, mode, gid=0):
    try:
        current = os.stat(path)
        with open(path, 'rb') as handle:
            if handle.read() == content and stat.S_IMODE(current.st_mode) == mode and current.st_gid == gid:
                return False
    except FileNotFoundError:
        pass
    os.makedirs(os.path.dirname(path), mode=0o755, exist_ok=True)
    temporary = path + '.orbit-new'
    fd = os.open(temporary, os.O_WRONLY | os.O_CREAT | os.O_TRUNC | os.O_NOFOLLOW, 0o600)
    with os.fdopen(fd, 'wb') as handle:
        handle.write(content)
    os.chown(temporary, 0, gid)
    os.chmod(temporary, mode)
    os.replace(temporary, path)
    return True


def unit(data):
    return (
        '[Unit]\nDescription=Orbit swamp role worker %i\nAfter=network-online.target\n'
        'Wants=network-online.target\nStartLimitIntervalSec=0\n\n'
        '[Service]\nType=exec\nUser=' + data['user'] + '\nStateDirectory=orbit-swamp-worker/%i\n'
        'WorkingDirectory=' + HOME + '\n'
        'Environment=HOME=' + HOME + ' SWAMP_HOME=' + HOME + '/home SWAMP_CONFIG_DIR=' + HOME + '/config'
        ' SWAMP_NO_UPDATE_CHECK=1 SWAMP_NO_TELEMETRY=1\n'
        'LoadCredential=enrollment:' + ROOT + '/%i/enrollment.token\n'
        'LoadCredential=server:' + ROOT + '/%i/server.token\n'
        'ExecStart=' + BINARY + ' worker connect ws://127.0.0.1:9418 --token-file=%d/enrollment'
        ' --server-token-file=%d/server --cache-dir=' + HOME + '/cache'
        ' --concurrency ' + str(data['concurrency']) + ' --label role=swamp --label node=' + str(data['node_id']) + '\n'
        'Restart=on-failure\nRestartSec=15\nKillMode=mixed\nTimeoutStopSec=infinity\nUMask=0077\n'
        'StandardOutput=journal\nStandardError=journal\n\n'
        '[Install]\nWantedBy=multi-user.target\n'
    ).encode()


def converge(data):
    if not os.path.isfile(BINARY) or not os.access(BINARY, os.X_OK):
        done({'error_code': 'swamp.worker_binary_missing'})
    for worker in data['workers']:
        for name in ['enrollment.token', 'server.token']:
            try:
                token = os.lstat(ROOT + '/' + worker + '/' + name)
            except FileNotFoundError:
                done({'error_code': 'swamp.worker_credentials_missing'})
            if not stat.S_ISREG(token.st_mode) or token.st_uid != 0 or token.st_mode & 0o077:
                done({'error_code': 'swamp.worker_credentials_unsafe'})
    try:
        with open(IDENTITY_SOURCE, 'rb') as handle:
            identity = handle.read()
    except FileNotFoundError:
        done({'error_code': 'swamp.ssh_identity_missing'})
    changed = publish(IDENTITY, identity, 0o440, pwd.getpwnam(data['user']).pw_gid)
    unit_changed = publish(UNIT, unit(data), 0o644)
    if unit_changed and not systemctl('daemon-reload'):
        done({'error_code': 'swamp.worker_unit_failed'})
    for worker in data['workers']:
        name = 'orbit-swamp-worker@' + worker + '.service'
        if not systemctl('enable', name):
            done({'error_code': 'swamp.worker_unit_failed'})
        # A restart drains in-flight dispatches first, so the step that runs this keeps its worker until it ends.
        if not systemctl('restart' if unit_changed else 'start', '--no-block', name):
            done({'error_code': 'swamp.worker_unit_failed'})
    done({'completed': True, 'changed': changed or unit_changed})


def remove(data):
    for worker in data['workers']:
        systemctl('disable', '--now', '--no-block', 'orbit-swamp-worker@' + worker + '.service')
    for path in [UNIT, IDENTITY]:
        try:
            os.remove(path)
        except FileNotFoundError:
            pass
    if data['purge_data']:
        for worker in data['workers']:
            for name in ['enrollment.token', 'server.token']:
                try:
                    os.remove(ROOT + '/' + worker + '/' + name)
                except FileNotFoundError:
                    pass
    systemctl('daemon-reload')
    done({'completed': True, 'changed': True})


if os.geteuid() != 0:
    sys.exit(1)
message = json.loads(sys.stdin.read(65537))
if not all(re.fullmatch('[a-z]', worker) for worker in message['data']['workers']):
    sys.exit(1)
(converge if message['operation'] == 'converge' else remove)(message['data'])
