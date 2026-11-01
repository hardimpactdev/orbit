"""Write the admitted sandbox club token to its fixed VM-local private file."""
import json
import os
import pwd
import sys
import tempfile
from pathlib import Path

DESTINATION = Path('/home/orbit/.config/swamp/club-api-key')


def store(material, user, destination=DESTINATION):
    if not material or len(material) > 16384 or b'\0' in material:
        raise ValueError('Invalid sandbox credential material.')
    account = pwd.getpwnam(user)
    config = destination.parent.parent
    if not config.exists():
        config.mkdir(mode=0o700)
        os.chown(config, account.pw_uid, account.pw_gid)
    destination.parent.mkdir(parents=True, exist_ok=True, mode=0o700)
    destination.parent.chmod(0o700)
    os.chown(destination.parent, account.pw_uid, account.pw_gid)
    pending = None
    try:
        with tempfile.NamedTemporaryFile(dir=destination.parent, delete=False) as stream:
            pending = Path(stream.name)
            os.fchmod(stream.fileno(), 0o600)
            os.fchown(stream.fileno(), account.pw_uid, account.pw_gid)
            stream.write(material)
            stream.flush()
            os.fsync(stream.fileno())
        pending.replace(destination)
    finally:
        if pending is not None:
            pending.unlink(missing_ok=True)


def main():
    try:
        if os.geteuid() != 0 or len(sys.argv) != 2 or sys.argv[1] != 'orbit':
            raise ValueError('Sandbox credential delivery requires the orbit account and root.')
        store(sys.stdin.buffer.read(16385), sys.argv[1])
    except (ValueError, KeyError, OSError):
        print(json.dumps({'error_code': 'tasks.proof_credential_write_failed'}))
        return 1
    print(json.dumps({'stored': True}))
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
