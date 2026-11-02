"""node.settings: create or check a Node's apps folder for the managed user (legacy node.prepare-storage-root).

Usage: apps_folder.py create|check '<intent json>' with root (null means <home>/apps), user and create_missing.
create makes each missing part as the managed user and group, mode 0755, and leaves existing folders as they are.
check reads the folder back: a real folder, owned by the managed user and group, that only the owner may write.
A refusal prints its reason on stderr and exits 1; nothing is written after the first refusal.
"""
import grp
import json
import os
import pwd
import stat
import sys


def refuse(reason):
    sys.exit(f'node.settings_root_failed: {reason}')


def inside(path, parent):
    return path.startswith(parent + '/')


def resolve(intent):
    try:
        account = pwd.getpwnam(intent['user'])
    except KeyError:
        refuse('the managed account exists')
    home = account.pw_dir.rstrip('/') or '/'
    root = intent['root'] or home + '/apps'
    parts = root.split('/')[1:]
    if not root.startswith('/') or root == '/' or any(p in ('', '.', '..') for p in parts):
        refuse('the root is an absolute, normalized path')
    if root == home or inside(home, root):
        refuse('the root is not the managed home or an ancestor of it')
    if inside(root, home) and root[len(home) + 1:].startswith('.'):
        refuse('the root is not inside a hidden folder of the managed home')
    return root, account.pw_uid, account.pw_gid, grp.getgrgid(account.pw_gid).gr_name


def real_directory(path):
    info = os.lstat(path)
    return stat.S_ISDIR(info.st_mode) and os.path.realpath(path) == path


def create(root, uid, gid, create_missing):
    current = ''
    for part in root.split('/')[1:]:
        current += '/' + part
        if os.path.lexists(current):
            if not real_directory(current):
                refuse(f'{current} is a real folder, not a symlink or file')
            continue
        if not create_missing:
            refuse(f'{current} exists')
        os.mkdir(current, 0o755)
        os.chown(current, uid, gid)
        os.chmod(current, 0o755)


def check(root, uid, gid):
    if not os.path.lexists(root) or not real_directory(root):
        refuse(f'{root} is a real folder')
    info = os.lstat(root)
    if (info.st_uid, info.st_gid) != (uid, gid):
        refuse(f'the managed user and group own {root}')
    mode = info.st_mode & 0o777
    if mode & 0o700 != 0o700 or mode & 0o022:
        refuse(f'only the managed user may write {root} (mode {mode:o})')


def main(action, raw):
    intent = json.loads(raw)
    root, uid, gid, group = resolve(intent)
    if action == 'create':
        create(root, uid, gid, intent['create_missing'])
        print(json.dumps({'root': root, 'user': intent['user'], 'group': group}))
    else:
        check(root, uid, gid)
        print(json.dumps({'prepared': True, 'root': root}))


if __name__ == '__main__':
    main(sys.argv[1], sys.argv[2])
