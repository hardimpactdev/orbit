"""Observe one admitted source as the managed account; no filesystem mutation."""
import base64
import grp
import json
import os
import pwd
import subprocess
import sys
from pathlib import Path


def observe(value):
    member = value['member']
    path = Path(member['checkout_path'])
    if value.get('retained') is not None:
        captured = next(row for row in value['retained']['members'] if row['instance_id'] == member['instance_id'])
        if path.exists() or path.is_symlink():
            info = path.lstat()
            if path.is_symlink() or not path.is_dir() or str(info.st_dev)+':'+str(info.st_ino) != captured['source_identity'] or info.st_uid != os.geteuid():
                raise ValueError('instance.removal_conflict')
        return {'instance_id':member['instance_id'],'origin_required':path.exists() and bool(captured['source_commit'])}
    account = pwd.getpwuid(os.geteuid())
    if account.pw_name != member['user'] or path.is_symlink() or path.resolve() != path:
        raise ValueError('instance.source_ownership_mismatch')
    def git(arguments, check=True):
        result = subprocess.run(['/usr/bin/git', '-c', 'core.hooksPath=/dev/null', '-c', 'core.fsmonitor=false', '-C', str(path), *arguments], stdin=subprocess.DEVNULL, capture_output=True, check=False, env={'HOME': account.pw_dir, 'PATH': '/usr/bin:/bin', 'LC_ALL': 'C', 'GIT_OPTIONAL_LOCKS': '0'})
        if check and result.returncode:
            raise ValueError('instance.source_git_failed')
        return result.stdout.rstrip(b'\n')
    if not path.exists():
        if not value['force'] and not member['failed_creation']:
            raise ValueError('instance.remove_refused')
        facts = {'top': str(path), 'common': str(path / '.git'), 'origin': value['repository'], 'branch': member['branch'] or '', 'commit': '', 'dirty': '', 'source_identity': 'absent', 'worktrees': 'worktree '+str(path)+'\0\0'}
    else:
        info = path.stat()
        if not path.is_dir() or info.st_uid != account.pw_uid:
            raise ValueError('instance.source_ownership_mismatch')
        identity = str(info.st_dev)+':'+str(info.st_ino)
        unresolved = member['failed_creation'] and member['starting_commit'] is None
        gitdir = path / '.git'
        if gitdir.is_file():
            raw = gitdir.read_text().strip()
            if not raw.startswith('gitdir: '):
                raise ValueError('instance.source_identity_invalid')
            gitdir = (path / raw[8:]).resolve()
        marker = gitdir / 'orbit-source-prepare'
        if unresolved and (marker.is_symlink() or not marker.is_file() or marker.read_text().strip() != str(member['source_prepare_id'])+':'+identity):
            raise ValueError('instance.source_ownership_mismatch')
        if unresolved and not (gitdir / 'HEAD').is_file():
            facts = {'top': str(path), 'common': str(path / '.git'), 'origin': value['repository'], 'branch': member['branch'] or '', 'commit': '', 'dirty': '', 'source_identity': identity, 'worktrees': 'worktree '+str(path)+'\0\0'}
        else:
            top = git(['rev-parse', '--show-toplevel']).decode()
            common = git(['rev-parse', '--path-format=absolute', '--git-common-dir']).decode()
            commit = git(['rev-parse', '--verify', 'HEAD'], not unresolved).decode() if (gitdir / 'HEAD').is_file() else ''
            if unresolved and not (len(commit) in (40, 64) and all(character in '0123456789abcdef' for character in commit)):
                commit = ''
            origin = value['repository']  # A separate canonical origin action supplies the observed, credential-safe value.
            facts = {'top': top, 'common': common, 'origin': origin, 'branch': git(['symbolic-ref', '--quiet', '--short', 'HEAD'],False).decode(), 'commit': commit, 'dirty': '1' if git(['status', '--porcelain', '--untracked-files=all']) else '0', 'source_identity': identity, 'worktrees': git(['worktree','list','--porcelain','-z']).decode()}
    return {'instance_id': member['instance_id'], 'origin_required': facts['source_identity'] != 'absent' and (gitdir / 'HEAD').is_file(), 'account': {'user': account.pw_name, 'group': grp.getgrgid(account.pw_gid).gr_name, 'home': account.pw_dir}, 'source': {key: base64.b64encode(item.encode()).decode() for key,item in facts.items()}}


if __name__ == '__main__':
    try:
        print(json.dumps(observe(json.load(sys.stdin))))
    except (ValueError, OSError, KeyError, UnicodeError, subprocess.SubprocessError):
        print('{"error_code":"instance.source_identity_invalid"}')
        sys.exit(1)
