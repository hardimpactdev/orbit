"""Read machine identity over pinned SSH; the private key never enters argv."""
import json
import os
import re
import subprocess
import tempfile
from pathlib import Path

from ssh_common import Refused, intent, main

SSH = '/usr/bin/ssh'


def probe():
    admitted = intent()
    target = admitted['target']
    observed = json.loads(os.environ['ORBIT_BOOTSTRAP_HOST_KEY'])
    if (observed['host_key_fingerprint'] != target['fingerprint']
            or observed['host_type'] not in ['ssh-ed25519', 'ssh-rsa', 'ecdsa-sha2-nistp256',
                                             'ecdsa-sha2-nistp384', 'ecdsa-sha2-nistp521']
            or not re.fullmatch(r'[A-Za-z0-9+/]+={0,2}', observed['public_key'])):
        raise Refused('node.ssh_host_key_mismatch')
    identity = os.environ.pop('ORBIT_BOOTSTRAP_IDENTITY')
    with tempfile.TemporaryDirectory(prefix='orbit-bootstrap-') as directory:
        private = Path(directory) / 'identity'
        known = Path(directory) / 'known_hosts'
        with open(private, 'x', opener=lambda path, flags: os.open(path, flags, 0o600)) as stream:
            stream.write(identity)
        with open(known, 'x', opener=lambda path, flags: os.open(path, flags, 0o600)) as stream:
            stream.write('orbit-bootstrap-target ' + observed['host_type'] + ' ' + observed['public_key'] + '\n')
        result = subprocess.run([
            SSH, '-F', '/dev/null', '-o', 'BatchMode=yes', '-o', 'IdentitiesOnly=yes',
            '-o', 'StrictHostKeyChecking=yes', '-o', 'UserKnownHostsFile=' + str(known),
            '-o', 'GlobalKnownHostsFile=/dev/null', '-o', 'HostKeyAlias=orbit-bootstrap-target',
            '-o', 'ConnectTimeout=10', '-o', 'ConnectionAttempts=1', '-o', 'ControlMaster=no',
            '-o', 'ForwardAgent=no', '-o', 'ClearAllForwardings=yes', '-o', 'LogLevel=ERROR',
            '-i', str(private), '-p', str(target['port']), '--',
            target['user'] + '@' + target['host'], '/usr/bin/uname -sm',
        ], capture_output=True, timeout=20, check=False, env=dict(os.environ))
    if result.returncode:
        if re.search(rb'host key verification failed|REMOTE HOST IDENTIFICATION HAS CHANGED', result.stderr, re.IGNORECASE):
            raise Refused('node.ssh_host_key_mismatch')
        raise Refused('node.bootstrap_channel_uncertain')
    fields = result.stdout.decode('utf-8', errors='replace').strip().split()
    if len(fields) != 2 or fields[0] != ('Linux' if admitted['platform'] == 'linux' else 'Darwin'):
        raise Refused('node.platform_mismatch')
    architecture = {'amd64': 'x86_64', 'aarch64': 'arm64'}.get(fields[1], fields[1])
    if architecture not in ['x86_64', 'arm64'] or (admitted['platform'] == 'linux' and architecture != 'x86_64'):
        raise Refused('node.architecture_unsupported')
    expected = admitted['expected_architecture']
    if expected is not None and architecture != expected:
        raise Refused('node.architecture_mismatch')
    return {'probed': True, 'architecture': architecture, **observed}


if __name__ == '__main__':
    main(probe)
