"""Abandon a seed's scratch without unlinking its installed database."""
import json
import os
import re
import stat
import sys

from source_cleanup import account, info, load_state, path, require, walk


def abandon():
    require(os.geteuid() == 0)
    operation = os.environ['ORBIT_CLEANUP_OPERATION']
    require(re.fullmatch(r'[a-f0-9]{64}', operation))
    target = os.environ['ORBIT_CLEANUP_TARGET']
    require(re.fullmatch(r'[1-9][0-9]*', target))
    base = path(os.environ['ORBIT_CLEANUP_BASE'])
    relative = os.environ['ORBIT_CLEANUP_RELATIVE']
    require(relative and not relative.startswith('/') and not relative.endswith('/')
            and not re.search(r'[\x00\r\n]|(?:^|/)\.{1,2}(?:/|$)|//', relative))
    destination = path(str(base / relative))
    require(base in destination.parents)
    owner = account(os.environ['ORBIT_CLEANUP_OWNER'])
    transport = account(os.environ['ORBIT_CLEANUP_TRANSPORT'])
    environment = os.environ['ORBIT_CLEANUP_ENVIRONMENT']
    require(environment in ('development', 'production'))
    if environment == 'production':
        require(str(base) == owner.pw_dir and relative == 'database.sqlite')
    directory = path(os.environ['ORBIT_CLEANUP_STATE'])
    require(re.fullmatch(r'/var/lib/orbit/app-instance-sqlite-seeds/target-'
                         + target + '(?:-' + operation + ')?', str(directory)))
    exists, state = load_state(directory)
    if not exists:
        return
    selected = []
    if state is not None:
        require(state.get('operation') == operation and state.get('destination') == str(destination))
        for kind in ('candidate', 'incoming'):
            if kind not in state:
                continue
            scratch = path(state[kind])
            if kind == 'incoming':
                require(re.fullmatch('/tmp/orbit-sqlite-' + operation[:16] + r'-[a-f0-9]{16}', str(scratch)))
                uid = transport.pw_uid
            else:
                require(scratch.parent == destination.parent
                        and re.fullmatch(r'\.database\.sqlite\.orbit-[a-f0-9]{16}', scratch.name))
                uid = owner.pw_uid
            item = info(scratch)
            if item is not None:
                walk(scratch.parent)
                require(stat.S_ISREG(item.st_mode) and item.st_uid == uid
                        and stat.S_IMODE(item.st_mode) == 0o600
                        and type(state.get(kind + '_inode')) is int and item.st_ino == state[kind + '_inode'])
                if kind == 'incoming':
                    require(item.st_nlink == 1)
                selected.append(scratch)
        for scratch in selected:
            scratch.unlink()
        (directory / 'state.json').unlink()
    directory.rmdir()


if __name__ == '__main__':
    try:
        abandon()
        print(json.dumps({'backend': 'absent'}))
    except (OSError, ValueError, KeyError, TypeError):
        print(json.dumps({'error_code': 'instance.sqlite_seed_refused'}))
        sys.exit(1)
