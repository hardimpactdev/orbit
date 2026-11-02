"""The swamp role's runtime: serve, its workers and the Gateway's grants.

Operators place each worker's tokens; this never mints, reads or moves them. It never restarts a running serve:
a changed serve unit waits for an operator restart in a quiet window.

`python3 -I swamp_workers.py grants <repo>` renders the grants only; the release Swamp load step runs it as the
serve account after each checkout, then reloads the policy.
"""

import json
import os
import pwd
import re
import socket
import stat
import subprocess
import sys

ROOT = '/etc/orbit/swamp-role-worker'
UNIT = '/etc/systemd/system/orbit-swamp-worker@.service'
SERVE_UNIT = '/etc/systemd/system/orbit-swamp-serve.service'
BINARY = '/usr/local/bin/swamp'
IDENTITY_SOURCE = '/etc/orbit/node-proxy/id_ed25519'
IDENTITY = ROOT + '/ssh-identity'
HOME = '/var/lib/orbit-swamp-worker/%i'
PORT = 9418
CHECKOUT = '/apps/orbit-monorepo-staging/checkout/swamp'
GRANTS_MANIFEST = 'config/gateway-workflow-grants.json'
GRANTS_FILE = 'grants/orbit-gateway.yaml'


def done(value):
    print(json.dumps(value))
    sys.exit(0)


def systemctl(*args):
    return subprocess.run(['/usr/bin/systemctl', *args], capture_output=True, text=True, check=False).returncode == 0


def publish(path, content, mode, gid=0, uid=0):
    try:
        current = os.stat(path)
        with open(path, 'rb') as handle:
            if (handle.read() == content and stat.S_IMODE(current.st_mode) == mode and current.st_gid == gid
                    and current.st_uid == uid):
                return False
    except FileNotFoundError:
        pass
    if not os.path.isdir(os.path.dirname(path)):
        os.makedirs(os.path.dirname(path), mode=0o755)
        if os.geteuid() == 0:
            os.chown(os.path.dirname(path), uid, gid)
    temporary = path + '.orbit-new'
    fd = os.open(temporary, os.O_WRONLY | os.O_CREAT | os.O_TRUNC | os.O_NOFOLLOW, 0o600)
    with os.fdopen(fd, 'wb') as handle:
        handle.write(content)
    if os.geteuid() == 0:
        os.chown(temporary, uid, gid)
    os.chmod(temporary, mode)
    os.replace(temporary, path)
    return True


def grants(repo):
    """The manifest's grants for its principal, in serve's grants file format. JSON is valid YAML."""
    with open(os.path.join(repo, GRANTS_MANIFEST)) as handle:
        manifest = json.load(handle)
    result = [{'subject': manifest['principal'], 'effect': 'allow', 'actions': entry['actions'],
               'resource': 'vault:' + entry['vault'], 'condition': 'key.matches(' + json.dumps(entry['key_pattern']) + ')'}
              for entry in manifest['vaults']]
    result += [{'subject': manifest['principal'], 'effect': 'allow', 'actions': entry['actions'],
                'resource': 'workflow:' + entry['workflow']} for entry in manifest['workflows']]
    return (json.dumps({'grants': result}, indent=2) + '\n').encode()


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
        'ExecStart=' + BINARY + ' worker connect ws://127.0.0.1:' + str(PORT) + ' --token-file=%d/enrollment'
        ' --server-token-file=%d/server --cache-dir=' + HOME + '/cache'
        ' --concurrency ' + str(data['concurrency']) + ' --label role=swamp --label node=' + str(data['node_id']) + '\n'
        'Restart=on-failure\nRestartSec=15\nKillMode=mixed\nTimeoutStopSec=infinity\nUMask=0077\n'
        'StandardOutput=journal\nStandardError=journal\n\n'
        '[Install]\nWantedBy=multi-user.target\n'
    ).encode()


def serve_unit(data, home):
    repo = home + CHECKOUT
    config = home + '/.config/swamp'
    return (
        '[Unit]\nDescription=Orbit swamp role serve\nAfter=network-online.target\nWants=network-online.target\n'
        'RequiresMountsFor=' + repo + '\n\n'
        '[Service]\nType=simple\nUser=' + data['user'] + '\nWorkingDirectory=' + repo + '\n'
        'Environment=HOME=' + home + ' PATH=' + home + '/.local/bin:/usr/local/bin:/usr/bin:/bin'
        ' SWAMP_HOME=' + home + '/.swamp SWAMP_CONFIG_DIR=' + config + ' SWAMP_API_KEY_FILE=' + config + '/api-key'
        ' SWAMP_SERVER_TOKEN_FILE=' + config + '/serve-driver-token SWAMP_REMOTE_ONLY=1 NODE_USE_SYSTEM_CA=1'
        ' SWAMP_NO_UPDATE_CHECK=1 SWAMP_NO_TELEMETRY=1\n'
        'ExecStart=' + home + '/.local/bin/swamp serve --repo-dir ' + repo + ' --port ' + str(PORT) + ' --host 127.0.0.1'
        ' --auth-mode token --admins user:nckrtl --club-api-key-file ' + config + '/api-key'
        ' --trusted-hosts swamp.orbit --trust-proxy --dispatch-env-allow=,'
        ' --max-concurrent-scheduled-runs ' + str(data['scheduled_runs']) + ' --remote-only --hot-reload\n'
        'Restart=on-failure\nRestartSec=2\n\n'
        '[Install]\nWantedBy=multi-user.target\n'
    ).encode()


def serving():
    try:
        socket.create_connection(('127.0.0.1', PORT), timeout=2).close()
        return True
    except OSError:
        return False


def reload_grants(account, home):
    config = home + '/.config/swamp'
    result = subprocess.run(
        ['/usr/sbin/runuser', '-u', account.pw_name, '--', '/usr/bin/env', 'HOME=' + home, 'SWAMP_HOME=' + home + '/.swamp',
         'SWAMP_CONFIG_DIR=' + config, 'SWAMP_API_KEY_FILE=' + config + '/api-key', 'SWAMP_NO_UPDATE_CHECK=1',
         'SWAMP_NO_TELEMETRY=1', home + '/.local/bin/swamp', 'access', 'reload', '--server', 'ws://127.0.0.1:' + str(PORT),
         '--token-file', config + '/serve-driver-token', '--json'],
        capture_output=True, text=True, check=False, timeout=90)
    return result.returncode == 0


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
    account = pwd.getpwnam(data['user'])
    home = account.pw_dir
    if not os.path.isfile(home + CHECKOUT + '/' + GRANTS_MANIFEST):
        done({'error_code': 'swamp.serve_checkout_missing'})
    changed = publish(IDENTITY, identity, 0o440, account.pw_gid)
    changed = publish(home + CHECKOUT + '/' + GRANTS_FILE, grants(home + CHECKOUT), 0o640, account.pw_gid,
                      account.pw_uid) or changed
    serve_changed = publish(SERVE_UNIT, serve_unit(data, home), 0o644)
    unit_changed = publish(UNIT, unit(data), 0o644)
    if (serve_changed or unit_changed) and not systemctl('daemon-reload'):
        done({'error_code': 'swamp.worker_unit_failed'})
    # Start serve only where nothing serves its port. A running serve keeps running.
    if systemctl('is-active', '--quiet', 'orbit-swamp-serve.service'):
        if not systemctl('enable', 'orbit-swamp-serve.service'):
            done({'error_code': 'swamp.serve_unit_failed'})
    elif not serving() and not systemctl('enable', '--now', 'orbit-swamp-serve.service'):
        done({'error_code': 'swamp.serve_unit_failed'})
    if serving() and not reload_grants(account, home):
        done({'error_code': 'swamp.grants_reload_failed'})
    for worker in data['workers']:
        name = 'orbit-swamp-worker@' + worker + '.service'
        if not systemctl('enable', name):
            done({'error_code': 'swamp.worker_unit_failed'})
        # A restart drains in-flight dispatches first, so the step that runs this keeps its worker until it ends.
        if not systemctl('restart' if unit_changed else 'start', '--no-block', name):
            done({'error_code': 'swamp.worker_unit_failed'})
    done({'completed': True, 'changed': changed or serve_changed or unit_changed})


def remove(data):
    for worker in data['workers']:
        systemctl('disable', '--now', '--no-block', 'orbit-swamp-worker@' + worker + '.service')
    systemctl('disable', '--now', '--no-block', 'orbit-swamp-serve.service')
    for path in [UNIT, SERVE_UNIT, IDENTITY]:
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


if len(sys.argv) == 3 and sys.argv[1] == 'grants':
    publish(os.path.join(sys.argv[2], GRANTS_FILE), grants(sys.argv[2]), 0o640, os.getgid(), os.getuid())
    sys.exit(0)
if os.geteuid() != 0:
    sys.exit(1)
message = json.loads(sys.stdin.read(65537))
if not all(re.fullmatch('[a-z]', worker) for worker in message['data']['workers']):
    sys.exit(1)
(converge if message['operation'] == 'converge' else remove)(message['data'])
