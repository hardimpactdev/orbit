"""Observe the host key and require the immutable admitted fingerprint."""
import base64
import hashlib
import subprocess

from ssh_common import Refused, intent, main

SSH_KEYSCAN = '/usr/bin/ssh-keyscan'
KEY_TYPES = {'ssh-ed25519', 'ssh-rsa', 'ecdsa-sha2-nistp256',
             'ecdsa-sha2-nistp384', 'ecdsa-sha2-nistp521'}


def scan():
    target = intent()['target']
    result = subprocess.run([SSH_KEYSCAN, '-T', '10', '-p', str(target['port']), target['host']],
                            capture_output=True, timeout=15, check=False)
    if result.returncode or len(result.stdout) > 65536:
        raise Refused('node.bootstrap_channel_uncertain')
    for line in result.stdout.decode('utf-8', errors='replace').splitlines():
        fields = line.split()
        if len(fields) != 3 or fields[1] not in KEY_TYPES:
            continue
        try:
            blob = base64.b64decode(fields[2], validate=True)
        except ValueError:
            continue
        fingerprint = 'SHA256:' + base64.b64encode(hashlib.sha256(blob).digest()).decode().rstrip('=')
        if fingerprint == target['fingerprint']:
            return {'host_type': fields[1], 'public_key': fields[2],
                    'host_key_fingerprint': fingerprint}
    raise Refused('node.ssh_host_key_mismatch')


if __name__ == '__main__':
    main(scan)
