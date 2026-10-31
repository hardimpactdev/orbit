"""Read the candidate account and observer without invoking agent actions."""

import grp
import hashlib
import json
import os
import pwd
import subprocess
from pathlib import Path

BOOTSTRAP_INPUT = None


def verify(data):
    account = pwd.getpwuid(os.getuid())
    if account.pw_name != data['user']:
        raise ValueError('node.bootstrap_verification_failed')
    with Path('/usr/local/bin/orbit-agent').open('rb') as stream:
        if hashlib.file_digest(stream, 'sha256').hexdigest() != data['sha256']:
            raise ValueError('node.agent_checksum_mismatch')
    subprocess.run(['/usr/bin/sudo', '-n', 'true'], stdin=subprocess.DEVNULL,
                   stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, check=True)
    return {'bootstrap_verified': True, 'account': {'user': account.pw_name,
            'group': grp.getgrgid(account.pw_gid).gr_name, 'home': account.pw_dir}}


if __name__ == '__main__':
    print(json.dumps(verify(BOOTSTRAP_INPUT)))
