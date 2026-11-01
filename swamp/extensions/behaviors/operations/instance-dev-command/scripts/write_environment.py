"""Publish the admitted private environment as the managed account; stdout has keys only."""
import json
import os
import sys
import tempfile
from pathlib import Path


def validate_target(value, filename='.env'):
    directory = Path(value['directory'])
    if filename not in ('.env', '.env.testing'):
        raise ValueError('env.configuration_invalid')
    target = directory / filename
    if directory.is_symlink() or not directory.is_dir() or directory.stat().st_uid != os.geteuid():
        raise ValueError('instance.source_ownership_mismatch')
    if target.is_symlink() or target.exists() and (not target.is_file() or target.stat().st_uid != os.geteuid()):
        raise ValueError('env.sync_failed')
    return directory, target


def publish(value, filename='.env'):
    directory, target = validate_target(value, filename)
    content = value['content'].encode()
    if len(content) > 131072:
        raise ValueError('env.configuration_invalid')
    descriptor, temporary = tempfile.mkstemp(prefix='.env.orbit-', dir=directory)
    try:
        with os.fdopen(descriptor, 'wb') as stream:
            stream.write(content)
            stream.flush()
            os.fsync(stream.fileno())
        os.chmod(temporary, 0o600)
        os.replace(temporary, target)
    finally:
        Path(temporary).unlink(missing_ok=True)
    return {'ready': True, 'keys': value['keys']}


if __name__ == '__main__':
    try:
        print(json.dumps(publish(json.load(sys.stdin))))
    except (ValueError, OSError, KeyError, TypeError):
        print('{"ready":false,"error_code":"env.sync_failed"}')
        sys.exit(1)
