"""Verify the source: capture each member's owner, origin and inode as the managed account; no writes.

argv[1] is the public destroy intent as JSON. A retained capture from an earlier run is checked against the
Node and reused, so a rerun after quarantine keeps the original identity. Prints {"source_inspection": ...}
or {"error_code": ...} and exits 1 on a refusal.
"""
import grp
import hashlib
import json
import os
import pwd
import re
import subprocess
import sys
from pathlib import Path
from urllib.parse import urlsplit

COMMIT = re.compile(r'(?:[a-f0-9]{40}|[a-f0-9]{64})?')


class Refused(Exception):
    pass


def check(ok, code='instance.source_identity_invalid'):
    if not ok:
        raise Refused(code)


def digest(value):
    return hashlib.sha256(json.dumps(value, separators=(',', ':'), ensure_ascii=False).encode()).hexdigest()


def repository_identity(origin):
    if origin.startswith('git@'):
        match = re.fullmatch(r'git@([^:/\s]+):(\S+)', origin)
        check(match is not None)
        host, path = match[1], match[2]
    else:
        parsed = urlsplit(origin)
        check(parsed.scheme in ('https', 'ssh') and parsed.hostname and not parsed.password and not parsed.query
              and not parsed.fragment and parsed.username in (None, 'git'))
        host, path = parsed.hostname, parsed.path
    path = re.sub(r'\.git$', '', path.strip('/')).rstrip('/')
    check(path != '')
    return host.lower() + '/' + path


def git(path, home, *arguments, required=True):
    result = subprocess.run(['/usr/bin/git', '-c', 'core.hooksPath=/dev/null', '-c', 'core.fsmonitor=false', '-C', str(path), *arguments],
                            stdin=subprocess.DEVNULL, capture_output=True, check=False, timeout=60,
                            env={'HOME': home, 'PATH': '/usr/bin:/bin', 'LC_ALL': 'C', 'GIT_OPTIONAL_LOCKS': '0'})
    check(result.returncode == 0 or not required, 'instance.source_git_failed')
    return result.stdout.decode().rstrip('\n') if result.returncode == 0 else ''


def worktree_paths(inventory):
    check(inventory.endswith('\0\0'))
    paths = set()
    for entry in inventory[:-2].split('\0\0'):
        fields = entry.split('\0')
        check(fields[0].startswith('worktree '))
        if not any(field == 'prunable' or field.startswith('prunable ') for field in fields):
            paths.add(fields[0][9:])
    return sorted(paths)


def retained(intent, member):
    captured = next((row for row in intent['source_capture']['members'] if row['instance_id'] == member['instance_id']), None)
    check(captured is not None, 'instance.removal_conflict')
    path = Path(member['checkout_path'])
    if path.exists() or path.is_symlink():
        info = path.lstat()
        check(not path.is_symlink() and path.is_dir() and f'{info.st_dev}:{info.st_ino}' == captured['source_identity']
              and info.st_uid == os.geteuid(), 'instance.removal_conflict')


def capture(intent, member, admitted):
    account = pwd.getpwuid(os.geteuid())
    check(member['user'] == intent['node_user'] == account.pw_name, 'instance.source_ownership_mismatch')
    path, root, home = Path(member['checkout_path']), member['source_root'], account.pw_dir
    check(str(path).startswith(root + '/') and str(path.parent.parent) == root, 'instance.checkout_path_unsafe')
    overlap = [p for p in intent['protected_source_roots'] if p == root or p.startswith(root + '/') or root.startswith(p + '/')]
    check(home != root and not home.startswith(root + '/') and not overlap and not root.startswith(home + '/.'), 'instance.checkout_path_unsafe')
    check(not path.is_symlink() and (not path.exists() or path.resolve() == path), 'instance.source_ownership_mismatch')
    absent_allowed = intent['force'] or member['failed_creation']
    unresolved = member['failed_creation'] and member['starting_commit'] is None
    origin, branch, commit, dirty, has_head = intent['repository'], member['branch'] or '', '', '', False
    top, common, inventory = str(path), str(path / '.git'), f'worktree {path}\0\0'
    if not path.exists():
        check(absent_allowed, 'instance.remove_refused')
        identity = 'absent'
    else:
        info = path.stat()
        check(path.is_dir() and info.st_uid == account.pw_uid, 'instance.source_ownership_mismatch')
        identity = f'{info.st_dev}:{info.st_ino}'
        gitdir = path / '.git'
        if gitdir.is_file():
            raw = gitdir.read_text().strip()
            check(raw.startswith('gitdir: '))
            gitdir = (path / raw[8:]).resolve()
        if unresolved:
            marker = gitdir / 'orbit-source-prepare'
            check(not marker.is_symlink() and marker.is_file()
                  and marker.read_text().strip() == f"{member['source_prepare_id']}:{identity}", 'instance.source_ownership_mismatch')
        has_head = (gitdir / 'HEAD').is_file()
        if has_head:
            top = git(path, home, 'rev-parse', '--show-toplevel')
            common = git(path, home, 'rev-parse', '--path-format=absolute', '--git-common-dir')
            commit = git(path, home, 'rev-parse', '--verify', 'HEAD', required=not unresolved)
            if unresolved and COMMIT.fullmatch(commit) is None:
                commit = ''
            branch = git(path, home, 'symbolic-ref', '--quiet', '--short', 'HEAD', required=False)
            dirty = '1' if git(path, home, 'status', '--porcelain', '--untracked-files=all') else '0'
            inventory = git(path, home, 'worktree', 'list', '--porcelain', '-z') or inventory
            origin = git(path, home, 'config', '--get', 'remote.origin.url', required=False) or origin
    check(top == str(path) and COMMIT.fullmatch(commit) is not None)
    check(member['source_layout'] != 'checkout' or common == top + '/.git')
    check(member['source_layout'] != 'worktree' or identity == 'absent' or common != top + '/.git')
    check(repository_identity(origin) == member['repository_identity'])
    check(unresolved or (branch or None) == member['branch'], 'instance.source_branch_mismatch')
    check(intent['force'] or member['failed_creation'] or member['starting_commit'] is None or dirty != '1', 'instance.unsafe_content')
    check(not (member['failed_creation'] and member['stored_status'] == 'reserved' and identity != 'absent'
               and (member['source_prepare_id'] is None or member['registration_request_id'] is not None)), 'instance.remove_refused')
    paths = worktree_paths(inventory)
    check(top in paths)
    check(member['source_layout'] != 'checkout' or all(p in admitted for p in paths), 'instance.remove_refused')
    common_path = str(Path(common).parent)
    source = {'instance_id': member['instance_id'], 'layout': member['source_layout'], 'repository_identity': member['repository_identity'],
              'checkout_path': top, 'root': root, 'branch': member['branch'], 'starting_commit': commit,
              'common_repository_path': common_path, 'source_identity': identity, 'linked_worktree_paths': paths}
    return {'instance_id': member['instance_id'], 'user': account.pw_name, 'group': grp.getgrgid(account.pw_gid).gr_name,
            'source_root': root, 'source_digest': digest(source), 'source_commit': commit, 'common_repository_path': common_path,
            'source_identity': identity, 'linked_worktree_paths': paths, 'worktree_inventory': inventory, 'origin': origin}


def verify(intent):
    if intent['source_capture'] is not None:
        for member in intent['members']:
            retained(intent, member)
        return {'source_inspection': intent['source_capture']}
    admitted = {member['checkout_path'] for member in intent['members']}
    members = [capture(intent, member, admitted) for member in intent['members']]
    return {'source_inspection': {'members': members, 'inventory_digest': digest(members)}}


if __name__ == '__main__':
    try:
        print(json.dumps(verify(json.loads(sys.argv[1]))))
    except Refused as refusal:
        print(json.dumps({'error_code': str(refusal)}))
        sys.exit(1)
    except (ValueError, OSError, KeyError, TypeError, UnicodeError, subprocess.SubprocessError):
        print(json.dumps({'error_code': 'instance.source_identity_invalid'}))
        sys.exit(1)
