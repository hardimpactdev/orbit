"""Install the one public Orbit certificate in an admitted image guest."""
import json
import os
import re
import ssl
import stat
import subprocess
import sys
import tempfile
from pathlib import Path

ANCHOR = Path('/usr/local/share/ca-certificates/orbit-root.crt')
ROOT_UID = 0
ROOT_GID = 0
FAILURE = 'task_vm.image_build_failed'


def certificate_bytes(value):
    if type(value) is not dict or set(value) != {'certificate'}:
        raise ValueError()
    certificate = value['certificate']
    if type(certificate) is not str:
        raise ValueError()
    content = certificate.encode('utf-8')
    if len(content) > 65536 or re.fullmatch(
        r'\s*-----BEGIN CERTIFICATE-----\s+[A-Za-z0-9+/=\r\n]+\s*-----END CERTIFICATE-----\s*',
        certificate,
    ) is None:
        raise ValueError()
    ssl.SSLContext(ssl.PROTOCOL_TLS_CLIENT).load_verify_locations(cadata=certificate)
    return content


def safe_paths():
    for parent in reversed(ANCHOR.parents):
        metadata = parent.lstat()
        if (not stat.S_ISDIR(metadata.st_mode) or metadata.st_uid != ROOT_UID
                or metadata.st_mode & 0o022):
            raise ValueError()
    try:
        metadata = ANCHOR.lstat()
    except FileNotFoundError:
        return
    if not stat.S_ISREG(metadata.st_mode) or metadata.st_uid != ROOT_UID:
        raise ValueError()


def update_trust():
    subprocess.run(
        ['/usr/sbin/update-ca-certificates'], stdin=subprocess.DEVNULL,
        stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, check=True, timeout=45,
    )


def trust_image_ca(value):
    content = certificate_bytes(value)
    safe_paths()
    descriptor, temporary = tempfile.mkstemp(prefix='.orbit-image-ca-', dir=ANCHOR.parent)
    try:
        with os.fdopen(descriptor, 'wb') as staged:
            staged.write(content)
            os.fchmod(staged.fileno(), 0o644)
            os.fchown(staged.fileno(), ROOT_UID, ROOT_GID)
            staged.flush()
            os.fsync(staged.fileno())
        os.replace(temporary, ANCHOR)
    finally:
        try:
            os.unlink(temporary)
        except FileNotFoundError:
            pass
    update_trust()
    return {'ready': True}


def main():
    try:
        result = trust_image_ca(json.load(sys.stdin))
        status = 0
    except (OSError, ValueError, TypeError, KeyError, subprocess.SubprocessError):
        result = {'error_code': FAILURE}
        status = 1
    print(json.dumps(result))
    return status


if __name__ == '__main__':
    sys.exit(main())
