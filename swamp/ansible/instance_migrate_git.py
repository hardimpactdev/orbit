"""Carry local Git refs, stash history and logical index entries without copying a Git directory."""

import argparse
import base64
import hashlib
import json
import os
import subprocess
import sys
import tempfile
from pathlib import Path


class GitStateError(Exception):
    def __init__(self, code):
        super().__init__(code)
        self.code = code


def git(root, *args, data=None, index=None, output=None, stdin=None):
    environment = {**os.environ, 'GIT_OPTIONAL_LOCKS': '0', 'GIT_NO_LAZY_FETCH': '1',
                   'GIT_NO_REPLACE_OBJECTS': '1', 'GIT_CONFIG_NOSYSTEM': '1', 'GIT_CONFIG_GLOBAL': '/dev/null'}
    for name in ('GIT_DIR', 'GIT_WORK_TREE', 'GIT_COMMON_DIR', 'GIT_INDEX_FILE', 'GIT_OBJECT_DIRECTORY', 'GIT_ALTERNATE_OBJECT_DIRECTORIES'):
        environment.pop(name, None)
    if index is not None:
        environment['GIT_INDEX_FILE'] = str(index)
    command = ['git', '-c', 'safe.directory=*', '-c', 'core.hooksPath=/dev/null',
               '-c', 'core.fsmonitor=false', '-c', 'gc.auto=0', '-c', 'maintenance.auto=false',
               '-c', 'user.name=Orbit', '-c', 'user.email=orbit@localhost', '-C', str(root), *args]
    result = subprocess.run(command, input=data, stdin=stdin, stdout=output or subprocess.PIPE,
                            stderr=subprocess.PIPE, env=environment, check=False)
    if result.returncode != 0:
        raise GitStateError('instance.migrate_git_state_mismatch')
    return result.stdout


def optional_ref(root, name):
    result = git(root, 'for-each-ref', '--format=%(refname)%00%(objectname)', name)
    for line in result.splitlines():
        ref, oid = line.split(b'\0')
        if os.fsdecode(ref) == name:
            return oid.decode('ascii')
    return None


def refs(root):
    return {os.fsdecode(name): oid.decode('ascii')
            for name, oid in (line.split(b'\0') for line in
                              git(root, 'for-each-ref', '--format=%(refname)%00%(objectname)',
                                  'refs/heads/').splitlines())}


def git_path(root, name):
    return Path(os.fsdecode(git(root, 'rev-parse', '--path-format=absolute', '--git-path', name).rstrip(b'\n')))


def encoded(value):
    return base64.b64encode(value).decode('ascii')


def decoded(value):
    return base64.b64decode(value, validate=True)


def index_entries(raw):
    entries = []
    for record in raw.split(b'\0'):
        if record:
            header, path = record.split(b'\t', 1)
            mode, oid, stage = header.split()
            entries.append((mode, oid, stage, path))
    return entries


def statuses(root):
    raw = git(root, '-c', 'status.renames=true', 'status', '--porcelain=v1', '-z',
              '--untracked-files=all', '--ignore-submodules=none')
    records = iter(raw.split(b'\0'))
    result = []
    for record in records:
        if not record:
            continue
        old = next(records) if record[:1] in (b'R', b'C') or record[1:2] in (b'R', b'C') else None
        result.append((record[:2], record[3:], old))
    return raw, result


def guards(root):
    identity(root)
    links = {path for mode, _oid, _stage, path in index_entries(git(root, 'ls-files', '--stage', '-z'))
             if mode == b'160000'}
    for record in git(root, 'ls-tree', '-rz', '--full-tree', 'HEAD').split(b'\0'):
        if record:
            header, name = record.split(b'\t', 1)
            if header.startswith(b'160000 '):
                links.add(name)
    _raw, rows = statuses(root)
    tracked = git(root, 'status', '--porcelain=v1', '--untracked-files=no',
                  '--ignore-submodules=none').decode('utf-8', 'replace').splitlines()
    modules = [os.fsdecode(path) for status, path, old in rows
               if status != b'??' and (path in links or old in links or path == b'.gitmodules')]
    return {'tracked': tracked, 'submodules': modules}


def file_hash(root, name):
    path = root / os.fsdecode(name)
    if not os.path.lexists(path):
        return {'kind': 'deleted'}
    if path.is_symlink():
        return {'kind': 'symlink', 'sha256': hashlib.sha256(os.fsencode(os.readlink(path))).hexdigest()}
    if not path.resolve().is_relative_to(root.resolve()):
        raise GitStateError('instance.migrate_symlink_unsupported')
    if not path.is_file():
        return {'kind': 'directory'}
    digest = hashlib.sha256()
    with path.open('rb') as content:
        for chunk in iter(lambda: content.read(1024 * 1024), b''):
            digest.update(chunk)
    return {'kind': 'file', 'sha256': digest.hexdigest()}


def snapshot(root):
    index = git(root, 'ls-files', '--stage', '-z')
    head = git(root, 'rev-parse', '--verify', 'HEAD').strip().decode('ascii')
    symbolic = git(root, 'rev-parse', '--symbolic-full-name', 'HEAD').rstrip(b'\n')
    log = git_path(root, 'logs/refs/stash')
    # Git flags or filters can hide working bytes from diff/status. Hash every
    # tracked path so an uncopied edit can never pass the comparison silently.
    changed = {path for mode, _oid, _stage, path in index_entries(index) if mode != b'160000'}
    for record in git(root, 'ls-tree', '-rz', '--full-tree', 'HEAD').split(b'\0'):
        if record:
            mode, name = record.split(b'\t', 1)
            if not mode.startswith(b'160000 '):
                changed.add(name)
    status, _rows = statuses(root)
    return {'format': git(root, 'rev-parse', '--show-object-format').strip().decode('ascii'),
            'branches': refs(root), 'head': head,
            'head_ref': None if symbolic == b'HEAD' else os.fsdecode(symbolic),
            'stash_tip': optional_ref(root, 'refs/stash'),
            'stash_log': encoded(log.read_bytes()) if log.exists() else None,
            'index': encoded(index), 'status': encoded(status),
            'tracked': {encoded(name): file_hash(root, name) for name in sorted(changed)}}


def identity(root):
    if Path(os.fsdecode(git(root, 'rev-parse', '--show-toplevel').rstrip(b'\n'))).resolve() != root.resolve():
        raise GitStateError('instance.migrate_retirement_blocked')
    return {name: os.fsdecode(git(root, 'rev-parse', *args).rstrip(b'\n'))
            for name, args in [('top', ['--show-toplevel']), ('git_dir', ['--absolute-git-dir']),
                               ('common_dir', ['--path-format=absolute', '--git-common-dir'])]} | {
        'worktrees': encoded(git(root, 'worktree', 'list', '--porcelain', '-z'))}


def fsck(root):
    try:
        git(root, 'fsck', '--full', '--strict')
    except GitStateError as error:
        raise GitStateError('instance.migrate_git_objects_incomplete') from error


def atomic_file(path, body, mode=0o600):
    path.parent.mkdir(parents=True, exist_ok=True)
    descriptor, temporary = tempfile.mkstemp(prefix='.orbit-git-', dir=path.parent)
    try:
        with os.fdopen(descriptor, 'wb') as output:
            os.fchmod(output.fileno(), mode)
            output.write(body)
        os.replace(temporary, path)
    finally:
        Path(temporary).unlink(missing_ok=True)


def check_source(root, manifest):
    saved = json.loads(Path(manifest).read_bytes())
    try:
        same = identity(root) == saved['identity'] and snapshot(root) == saved['state']
    except (OSError, GitStateError):
        same = False
    if not same:
        raise GitStateError('instance.migrate_git_source_changed')
    return saved


def export(root, prefix, branch):
    if guards(root)['submodules']:
        raise GitStateError('instance.migrate_retirement_blocked')
    fsck(root)
    state = snapshot(root)
    if state['head_ref'] != 'refs/heads/' + branch:
        raise GitStateError('instance.migrate_branch_changed')
    source_identity = identity(root)
    roots = {state['head'], *state['branches'].values()}
    if state['stash_tip']:
        roots.add(state['stash_tip'])
    if state['stash_log'] is not None:
        for line in decoded(state['stash_log']).splitlines():
            old, new, _actor = line.split(b' ', 2)
            roots.update(oid.decode('ascii') for oid in (old, new) if set(oid) != {48})
    roots.update(oid.decode('ascii') for mode, oid, _stage, _path in index_entries(decoded(state['index']))
                 if mode != b'160000' and set(oid) != {48})
    pack = Path(str(prefix) + '.git.pack')
    manifest = Path(str(prefix) + '.git.json')
    descriptor, temporary = tempfile.mkstemp(prefix='.orbit-git-', dir=pack.parent)
    try:
        with os.fdopen(descriptor, 'wb') as output:
            git(root, 'pack-objects', '--revs', '--stdout',
                data=''.join(oid + '\n' for oid in sorted(roots)).encode('ascii'), output=output)
        if source_identity != identity(root) or state != snapshot(root):
            raise GitStateError('instance.migrate_git_source_changed')
        os.replace(temporary, pack)
        atomic_file(manifest, json.dumps({'identity': source_identity, 'state': state}).encode('utf-8'))
    finally:
        Path(temporary).unlink(missing_ok=True)
    return {'verified': True}


def import_state(root, manifest, pack):
    expected = json.loads(Path(manifest).read_bytes())['state']
    if snapshot(root) == expected:
        fsck(root)
        return {'verified': True, 'changed': False}
    if git(root, 'rev-parse', '--show-object-format').strip().decode('ascii') != expected['format']:
        raise GitStateError('instance.migrate_git_state_mismatch')
    # No ref names are installed until every supplied object has passed Git's own checks.
    with Path(pack).open('rb') as source:
        git(root, 'index-pack', '--stdin', '--strict', stdin=source)
    fsck(root)
    actual = refs(root)
    if set(actual) - set(expected['branches']):
        raise GitStateError('instance.migrate_git_state_mismatch')
    updates = ['start']
    wanted = {**expected['branches'], 'refs/stash': expected['stash_tip']}
    for name, oid in wanted.items():
        old = optional_ref(root, name)
        if oid == old:
            if oid is not None:
                updates.append(f'verify {name} {oid}')
        elif oid is None:
            updates.append(f'delete {name} {old}')
        elif old is None:
            updates.append(f'create {name} {oid}')
        else:
            updates.append(f'update {name} {oid} {old}')
    updates += ['prepare', 'commit']
    git(root, 'update-ref', '--stdin', data=('\n'.join(updates) + '\n').encode('utf-8', 'surrogateescape'))
    if expected['head_ref'] is None:
        git(root, 'update-ref', '--no-deref', 'HEAD', expected['head'])
    else:
        git(root, 'symbolic-ref', 'HEAD', expected['head_ref'])
    # Rebuild a portable index, including staged blobs that no branch or stash references.
    with tempfile.TemporaryDirectory(prefix='.orbit-git-', dir=git_path(root, 'index').parent) as directory:
        index = Path(directory) / 'index'
        git(root, 'read-tree', '--empty', index=index)
        git(root, 'update-index', '-z', '--index-info', data=decoded(expected['index']), index=index)
        if git(root, 'ls-files', '--stage', '-z', index=index) != decoded(expected['index']):
            raise GitStateError('instance.migrate_git_state_mismatch')
        os.chmod(index, 0o660)
        os.replace(index, git_path(root, 'index'))
    # A fetch only preserves the tip. Restore every original raw reflog record,
    # oldest first, retaining duplicate messages, identities, timestamps and timezones.
    log = git_path(root, 'logs/refs/stash')
    if expected['stash_log'] is None:
        log.unlink(missing_ok=True)
    else:
        atomic_file(log, decoded(expected['stash_log']), 0o660)
    if snapshot(root) != expected:
        raise GitStateError('instance.migrate_git_state_mismatch')
    fsck(root)
    return {'verified': True, 'changed': True}


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('root', type=Path)
    parser.add_argument('mode', choices=['export', 'import', 'check-source', 'guards'])
    parser.add_argument('manifest', nargs='?')
    parser.add_argument('pack', nargs='?')
    parser.add_argument('--branch')
    args = parser.parse_args()
    try:
        if args.mode == 'export':
            result = export(args.root, args.manifest, args.branch)
        elif args.mode == 'import':
            result = import_state(args.root, args.manifest, args.pack)
        elif args.mode == 'check-source':
            check_source(args.root, args.manifest)
            result = {'verified': True}
        else:
            result = guards(args.root)
        print(json.dumps(result))
        return 0
    except (GitStateError, OSError, ValueError, KeyError, TypeError) as error:
        code = error.code if isinstance(error, GitStateError) else 'instance.migrate_git_state_mismatch'
        print(json.dumps({'error_code': code}))
        return 1


if __name__ == '__main__':
    sys.exit(main())
