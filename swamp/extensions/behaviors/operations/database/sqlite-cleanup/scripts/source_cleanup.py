"""Remove only the source snapshot captured by an admitted SQLite seed."""
import json
import os
import pwd
import re
import stat
import sys
from pathlib import Path


def require(value):
    if not value:
        raise ValueError()


def path(value):
    require(isinstance(value, str) and value.startswith('/') and len(value) > 1
            and not value.endswith('/')
            and not re.search(r'[\x00\r\n]|(?:^|/)\.{1,2}(?:/|$)|//', value))
    return Path(value)


def info(selected):
    try:
        return selected.lstat()
    except FileNotFoundError:
        return None


def walk(selected):
    current = Path('/')
    for part in selected.parts[1:]:
        current /= part
        require(stat.S_ISDIR(current.lstat().st_mode))


def account(name):
    require(re.fullmatch(r'[a-z_][a-z0-9_-]{0,31}', name))
    user = pwd.getpwnam(name)
    require(user.pw_uid != 0)
    return user


def load_state(directory):
    if info(directory) is None:
        return False, None
    walk(directory)
    for selected in (directory.parent, directory):
        item = selected.lstat()
        require(item.st_uid == 0 and stat.S_IMODE(item.st_mode) == 0o700)
    require(all(entry.name == 'state.json' for entry in directory.iterdir()))
    state_file = directory / 'state.json'
    saved = info(state_file)
    if saved is None:
        return True, None
    require(stat.S_ISREG(saved.st_mode) and saved.st_uid == 0
            and saved.st_nlink == 1 and stat.S_IMODE(saved.st_mode) == 0o600)
    fd = os.open(state_file, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    with os.fdopen(fd) as stream:
        opened = os.fstat(stream.fileno())
        require((opened.st_dev, opened.st_ino) == (saved.st_dev, saved.st_ino))
        value = json.load(stream)
    require(isinstance(value, dict))
    return True, value


def cleanup():
    require(os.geteuid() == 0)
    operation = os.environ['ORBIT_CLEANUP_OPERATION']
    require(re.fullmatch(r'[a-f0-9]{64}', operation))
    target = os.environ['ORBIT_CLEANUP_TARGET']
    require(re.fullmatch(r'[1-9][0-9]*', target))
    base = path(os.environ['ORBIT_CLEANUP_BASE'])
    source = path(os.environ['ORBIT_CLEANUP_SOURCE'])
    require(base in source.parents)
    snapshot = path(os.environ['ORBIT_CLEANUP_SNAPSHOT'])
    require(str(snapshot) == '/tmp/orbit-sqlite-' + operation + '.sqlite')
    directory = path(os.environ['ORBIT_CLEANUP_STATE'])
    require(re.fullmatch(r'/var/lib/orbit/app-instance-sqlite-seeds/source-[1-9][0-9]*-target-'
                         + target + '(?:-' + operation + ')?', str(directory)))
    owner = account(os.environ['ORBIT_CLEANUP_OWNER']).pw_uid
    transport = account(os.environ['ORBIT_CLEANUP_TRANSPORT']).pw_uid
    exists, state = load_state(directory)
    if state is None:
        require(info(snapshot) is None)
    else:
        expected = {'operation': operation, 'base': str(base),
                    'source_path': str(source), 'snapshot_path': str(snapshot)}
        require(all(state.get(key) == value for key, value in expected.items()))
        item = info(snapshot)
        if item is not None:
            require(stat.S_ISREG(item.st_mode) and item.st_uid in (owner, transport)
                    and item.st_nlink == 1 and stat.S_IMODE(item.st_mode) == 0o600)
            require('inode' not in state or state['inode'] == item.st_ino)
            snapshot.unlink()
        (directory / 'state.json').unlink()
    if exists:
        directory.rmdir()


if __name__ == '__main__':
    try:
        cleanup()
        print(json.dumps({'backend': 'absent'}))
    except (OSError, ValueError, KeyError, TypeError):
        print(json.dumps({'error_code': 'instance.sqlite_seed_refused'}))
        sys.exit(1)
