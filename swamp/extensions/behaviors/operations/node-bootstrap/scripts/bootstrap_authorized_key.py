"""Add the managed public key while retaining the account's other keys."""

import json
import os
import pwd
from pathlib import Path

BOOTSTRAP_INPUT = None


def authorize(data):
    account = pwd.getpwnam(data['user'])
    directory = Path(account.pw_dir) / '.ssh'
    if not directory.exists():
        directory.mkdir(mode=0o700)
        os.chown(directory, account.pw_uid, account.pw_gid)
    target = directory / 'authorized_keys'
    with target.open('a+b') as stream:
        os.fchmod(stream.fileno(), 0o600)
        os.fchown(stream.fileno(), account.pw_uid, account.pw_gid)
        stream.seek(0)
        prior = stream.read()
        key = data['public_key'].encode()
        changed = key not in prior.splitlines()
        if changed:
            stream.write((b'\n' if prior and not prior.endswith(b'\n') else b'') + key + b'\n')
            stream.flush()
            os.fsync(stream.fileno())
    return {'authorized': True, 'changed': changed}


if __name__ == '__main__':
    print(json.dumps(authorize(BOOTSTRAP_INPUT)))
