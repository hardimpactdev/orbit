"""Write the Gateway-rendered environment as the managed account; stdout holds no environment content or keys."""
import json
import os
import sys
import tempfile
from pathlib import Path


def publish(value):
    directory, checkout = Path(value['directory']), Path(value['checkout'])
    target = directory / '.env'
    if not directory.is_relative_to(checkout) or any(path.is_symlink() for path in (directory, *directory.parents)
                                                      if path == checkout or checkout in path.parents):
        raise ValueError('env.sync_failed')
    if not directory.is_dir() or directory.stat().st_uid != os.geteuid():
        raise ValueError('instance.source_ownership_mismatch')
    if target.is_symlink() or target.exists() and (not target.is_file() or target.stat().st_uid != os.geteuid()):
        raise ValueError('env.sync_failed')
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
    return {'ready': True}


if __name__ == '__main__':
    try:
        print(json.dumps(publish(json.load(sys.stdin))))
    except (ValueError, OSError, KeyError, TypeError):
        print('{"ready":false,"error_code":"env.sync_failed"}')
        sys.exit(1)
