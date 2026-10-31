"""Publish the Gateway's public Node pins for the executing worker account."""

import hashlib
import json
import os
import pwd
import stat
import tempfile
from pathlib import Path


def publish(home: Path, content: str) -> dict:
    directory = home / '.ssh'
    directory.mkdir(mode=0o700, exist_ok=True)
    destination = directory / 'known_hosts'
    digest = hashlib.sha256(content.encode()).hexdigest()
    if (destination.is_file() and not destination.is_symlink()
            and stat.S_IMODE(destination.stat().st_mode) == 0o600
            and destination.read_text() == content):
        return {'known_hosts_ready': True, 'digest': digest, 'changed': False, 'path': str(destination)}

    descriptor, staged = tempfile.mkstemp(prefix='.known_hosts-', dir=directory)
    try:
        with os.fdopen(descriptor, 'w', encoding='utf-8', newline='') as stream:
            stream.write(content)
            stream.flush()
            os.fchmod(stream.fileno(), 0o600)
            os.fsync(stream.fileno())
        os.replace(staged, destination)
    finally:
        Path(staged).unlink(missing_ok=True)
    return {'known_hosts_ready': True, 'digest': digest, 'changed': True, 'path': str(destination)}


if __name__ == '__main__':
    account_home = Path(pwd.getpwuid(os.geteuid()).pw_dir)
    print(json.dumps(publish(account_home, os.environ['ORBIT_KNOWN_HOSTS_CONTENT'])))
