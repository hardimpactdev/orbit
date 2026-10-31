"""Download the pinned observer and publish it after checksum verification."""

import hashlib
import json
import os
import tempfile
import urllib.request
from pathlib import Path

BOOTSTRAP_INPUT = None
TARGET = Path('/usr/local/bin/orbit-agent')


def install(data):
    if TARGET.is_file():
        with TARGET.open('rb') as stream:
            if hashlib.file_digest(stream, 'sha256').hexdigest() == data['sha256']:
                return {'installed': True, 'changed': False, 'sha256': data['sha256']}
    fd, temporary = tempfile.mkstemp(prefix='.orbit-observer-', dir=TARGET.parent)
    try:
        digest = hashlib.sha256()
        with os.fdopen(fd, 'wb') as output, urllib.request.urlopen(data['url'], timeout=60) as response:
            while chunk := response.read(65536):
                output.write(chunk)
                digest.update(chunk)
            output.flush()
            os.fsync(output.fileno())
            os.fchmod(output.fileno(), 0o755)
        if digest.hexdigest() != data['sha256']:
            raise ValueError('node.agent_checksum_mismatch')
        os.replace(temporary, TARGET)
    finally:
        Path(temporary).unlink(missing_ok=True)
    return {'installed': True, 'changed': True, 'sha256': data['sha256']}


if __name__ == '__main__':
    print(json.dumps(install(BOOTSTRAP_INPUT)))
