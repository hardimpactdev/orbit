"""Prepare this native run's one release directory; Git and Composer are separate steps."""
import grp
import json
import os
import pathlib
import pwd
import sys

ROLE_INPUT = None


def prepare(data):
    release = pathlib.Path(data['release'])
    uid = pwd.getpwnam(data['user']).pw_uid
    gid = grp.getgrnam(data['group']).gr_gid
    parent = release.parent
    if parent.is_symlink() or parent.exists() and not parent.is_dir():
        raise ValueError('websocket.runtime_ownership_drift')
    parent.mkdir(mode=0o755, parents=True, exist_ok=True)
    if release.is_symlink() or release.exists() and (not release.is_dir() or release.stat().st_uid != uid):
        raise ValueError('websocket.runtime_ownership_drift')
    release.mkdir(mode=0o755, exist_ok=True)
    os.chown(release, uid, gid)
    return {'ready': True, 'changed': True}


if __name__ == '__main__':
    try:
        print(json.dumps(prepare(ROLE_INPUT)))
    except (ValueError, OSError, KeyError):
        sys.exit(1)
