"""Validate and publish the managed account's passwordless sudo rule."""

import json
import os
import subprocess
import tempfile
from pathlib import Path

BOOTSTRAP_INPUT = None


def prepare(data):
    user = data['user']
    checked = subprocess.run(['/usr/bin/sudo', '-n', '-u', user, '--', '/usr/bin/sudo', '-n', 'true'],
                             stdin=subprocess.DEVNULL, stdout=subprocess.DEVNULL,
                             stderr=subprocess.DEVNULL, check=False)
    if checked.returncode == 0:
        return {'sudo_prepared': True, 'changed': False}
    directory = Path('/etc/sudoers.d')
    fd, temporary = tempfile.mkstemp(prefix='.orbit-bootstrap-', dir=directory)
    try:
        os.fchmod(fd, 0o440)
        with os.fdopen(fd, 'w') as stream:
            stream.write(user + ' ALL=(ALL) NOPASSWD:ALL\n')
            stream.flush()
            os.fsync(stream.fileno())
        subprocess.run(['/usr/sbin/visudo', '-cf', temporary], stdin=subprocess.DEVNULL,
                       stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, check=True)
        os.replace(temporary, directory / user)
    finally:
        Path(temporary).unlink(missing_ok=True)
    return {'sudo_prepared': True, 'changed': True}


if __name__ == '__main__':
    print(json.dumps(prepare(BOOTSTRAP_INPUT)))
