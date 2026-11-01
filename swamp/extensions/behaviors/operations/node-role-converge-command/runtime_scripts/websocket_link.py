"""Publish or restore only this run's canonical Reverb release link."""
import json
import os
import re
import sys
from pathlib import Path

ROLE_INPUT = None


def publish(data):
    path, release = Path(data['install_path']), Path(data['release'])
    if release.parent != Path(str(path) + '.releases') or re.fullmatch(r'release\.[a-f0-9]{32}', release.name) is None:
        raise ValueError('websocket.runtime_ownership_drift')
    if os.path.lexists(path) and not path.is_symlink():
        raise ValueError('websocket.runtime_ownership_drift')
    target = data['previous'] if data['restore'] else str(release)
    if data['restore'] and path.is_symlink() and os.readlink(path) not in (str(release), target):
        raise ValueError('websocket.runtime_ownership_drift')
    temporary = path.with_name('.orbit-role-link-' + release.name)
    try:
        if target is None:
            path.unlink(missing_ok=True)
        else:
            if os.path.lexists(temporary):
                temporary.unlink()
            os.symlink(target, temporary)
            os.replace(temporary, path)
    finally:
        temporary.unlink(missing_ok=True)
    return {'ready': True, 'changed': True}


if __name__ == '__main__':
    try:
        print(json.dumps(publish(ROLE_INPUT)))
    except (ValueError, OSError, KeyError):
        sys.exit(1)
