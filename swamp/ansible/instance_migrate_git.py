"""Carry local Git refs, stash history and logical index entries without copying a Git directory."""

import argparse
import atexit
import base64
import hashlib
import json
import os
import shutil
import stat
import subprocess
import sys
import tempfile
from pathlib import Path


class GitStateError(Exception):
    def __init__(self, code):
        super().__init__(code)
        self.code = code


# Work commands never read the checkout's config, info attributes or hooks.
# Only metadata discovery/check-attr/worktree-list use the original repository;
# those plumbing reads do not invoke filters, diff/merge drivers or transports.
_REPOSITORIES = {}
_VIEWS = {}


def git_environment():
    return {**{key: value for key, value in os.environ.items() if not key.startswith('GIT_')},
            'GIT_OPTIONAL_LOCKS': '0', 'GIT_NO_LAZY_FETCH': '1', 'GIT_NO_REPLACE_OBJECTS': '1',
            'GIT_CONFIG_NOSYSTEM': '1', 'GIT_CONFIG_SYSTEM': '/dev/null', 'GIT_CONFIG_GLOBAL': '/dev/null',
            'GIT_ATTR_NOSYSTEM': '1', 'GIT_TERMINAL_PROMPT': '0'}


def native_git(root, *args, **options):
    # These fixed overrides also apply to the three metadata-only discovery verbs.
    command = ['/usr/bin/git', '-c', 'safe.directory=*', '-c', 'core.hooksPath=/dev/null',
               '-c', 'core.fsmonitor=false',
               '-c', 'protocol.allow=never', '-c', 'core.alternateRefsCommand=',
               '-c', 'credential.helper=', '-c', 'core.pager=', '--no-pager', *args]
    result = subprocess.run(command, stdout=options.pop('output', None) or subprocess.PIPE,
                            stderr=subprocess.PIPE, env=options.pop('environment', git_environment()),
                            check=False, **options)
    if result.returncode != 0:
        raise GitStateError('instance.migrate_git_state_mismatch')
    return result.stdout


def repository(root, refresh=False):
    root = Path(root)
    if refresh or root not in _REPOSITORIES:
        values = native_git(root, '-C', str(root), 'rev-parse', '--path-format=absolute',
                            '--show-toplevel', '--absolute-git-dir', '--git-common-dir',
                            '--show-object-format').splitlines()
        top, private, common, format_name = map(os.fsdecode, values)
        if Path(top).resolve() != root.resolve():
            raise GitStateError('instance.migrate_retirement_blocked')
        _REPOSITORIES[root] = {'top': top, 'git_dir': str(Path(private).resolve()),
                               'common_dir': str(Path(common).resolve()), 'format': format_name}
    return _REPOSITORIES[root]


def git_view(root):
    info = repository(root)
    key = (str(root), info['git_dir'], info['common_dir'], info['format'])
    if key not in _VIEWS:
        temporary = tempfile.TemporaryDirectory(prefix='orbit-git-view-')
        atexit.register(temporary.cleanup)
        view = Path(temporary.name)
        private, common = Path(info['git_dir']), Path(info['common_dir'])
        (view / 'index').symlink_to(private / 'index')
        (view / 'objects').symlink_to(common / 'objects')
        (view / 'refs').mkdir()
        (view / 'info').mkdir()
        # No include, driver, alias, editor, pager, SSH, credential or alternate-ref
        # command from a source config can enter this view.
        config = '[core]\nrepositoryformatversion = 0\nbare = false\nlogAllRefUpdates = false\n'
        if info['format'] == 'sha256':
            config = config.replace('version = 0', 'version = 1') + '[extensions]\nobjectFormat = sha256\n'
        (view / 'config').write_text(config)
        _VIEWS[key] = (view, temporary)
    view = _VIEWS[key][0]
    (view / 'HEAD').write_bytes((Path(info['git_dir']) / 'HEAD').read_bytes())
    # Git fsck requires real ref files, not links. Only data is copied; config,
    # hooks and info attributes are deliberately absent from this private view.
    shutil.rmtree(view / 'refs')
    shutil.copytree(Path(info['common_dir']) / 'refs', view / 'refs')
    for name in ('packed-refs', 'shallow'):
        source = Path(info['common_dir']) / name
        if source.exists():
            (view / name).write_bytes(source.read_bytes())
        else:
            (view / name).unlink(missing_ok=True)
    stash_log = Path(info['common_dir']) / 'logs/refs/stash'
    copied_log = view / 'logs/refs/stash'
    if stash_log.exists():
        copied_log.parent.mkdir(parents=True, exist_ok=True)
        copied_log.write_bytes(stash_log.read_bytes())
    else:
        copied_log.unlink(missing_ok=True)
    return view


def git(root, *args, data=None, index=None, output=None, stdin=None):
    root = Path(root)
    if args[0] == 'update-ref':
        # The only native write is Git's atomic ref transaction. It cannot apply
        # filters, merge/diff drivers or transports, and hooks stay disabled.
        return native_git(root, '-C', str(root), *args, input=data, stdin=stdin, output=output)
    environment = git_environment()
    if index is not None:
        environment['GIT_INDEX_FILE'] = str(index)
    if args[0] == 'status':
        args = (*args, '--ignore-submodules=all')
    elif args[0] == 'diff':
        args = (args[0], '--no-ext-diff', '--no-textconv', '--ignore-submodules=all', *args[1:])
    return native_git(root, '-c', 'core.attributesFile=/dev/null', '--git-dir', str(git_view(root)), '--work-tree', str(root), *args,
                      input=data, stdin=stdin, output=output, environment=environment)


def optional_ref(root, name):
    result = git(root, 'for-each-ref', '--format=%(refname)%00%(objectname)', name)
    for line in result.splitlines():
        ref, oid = line.split(b'\0')
        if os.fsdecode(ref) == name:
            return oid.decode('ascii')
    return None


def refs(root, stash=False):
    return {os.fsdecode(name): oid.decode('ascii')
            for name, oid in (line.split(b'\0') for line in
                              git(root, 'for-each-ref', '--format=%(refname)%00%(objectname)',
                                  *(['refs/heads/', 'refs/stash'] if stash else ['refs/heads/'])).splitlines())}


def git_path(root, name):
    info = repository(root)
    directory = info['git_dir'] if name in ('HEAD', 'index') else info['common_dir']
    return Path(directory) / name


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


def gitlinks(root, index):
    links = {path: oid for mode, oid, _stage, path in index_entries(index) if mode == b'160000'}
    committed = {}
    names = set()
    for record in git(root, 'ls-tree', '-rz', '--full-tree', 'HEAD').split(b'\0'):
        if record:
            header, name = record.split(b'\t', 1)
            mode, _kind, oid = header.split()
            if mode == b'160000':
                committed[name] = oid
            else:
                names.add(name)
    return links, committed, names


def guards(root):
    identity(root)
    index = git(root, 'ls-files', '--stage', '-z')
    links, committed, head_names = gitlinks(root, index)
    _raw, rows = statuses(root)
    modules = [os.fsdecode(path) for status, path, old in rows
               if status != b'??' and (path in links or old in links or path == b'.gitmodules')]
    for path in links.keys() | committed.keys():
        child = root / os.fsdecode(path)
        changed = links.get(path) != committed.get(path)
        if (child / '.git').exists():
            try:
                changed |= git(child, 'rev-parse', 'HEAD').strip() != links.get(path)
                changed |= bool(statuses(child)[0]) or bool(guards(child)['submodules'])
            except (OSError, GitStateError):
                changed = True
        if changed:
            modules.append(os.fsdecode(path))
    names = sorted(head_names | {path for mode, _oid, _stage, path in index_entries(index) if mode != b'160000'})
    paths = b''.join(path + b'\0' for path in names)
    # Check both the effective worktree and the base checkout's attributes.
    # A staged removal of attributes cannot expose a smudge driver while the
    # container first checks out HEAD.
    attributes = native_git(root, '-C', str(root), 'check-attr', '-z', '--stdin', 'filter', input=paths).split(b'\0')
    base_attributes = git(root, 'check-attr', '--source=HEAD', '-z', '--stdin', 'filter', data=paths).split(b'\0')
    filters = sorted({os.fsdecode(rows[i]) for rows in (attributes, base_attributes)
                      for i in range(0, len(rows) - 1, 3) if rows[i + 2] not in (b'unspecified', b'unset')})
    visible = set(git(root, 'diff', '--cached', '--no-renames', '--name-only', '-z',
                      '--ita-visible-in-index', 'HEAD').split(b'\0'))
    invisible = set(git(root, 'diff', '--cached', '--no-renames', '--name-only', '-z',
                        '--ita-invisible-in-index', 'HEAD').split(b'\0'))
    return {'tracked': [os.fsdecode(status + b' ' + (old + b' -> ' if old else b'') + path)
                        for status, path, old in rows if status != b'??'],
            'submodules': sorted(set(modules)), 'filters': filters,
            'intent_to_add': [os.fsdecode(path) for path in sorted(visible - invisible) if path]}


def carried_paths(root, excluded):
    """Match the archive: root Git data and tracked submodule contents stay out; sockets have no portable bytes."""
    def unreadable(error):
        raise error

    for directory, folders, files in os.walk(root, followlinks=False, onerror=unreadable):
        parent = Path(directory)
        for name in sorted(folders + files):
            path = parent / name
            relative = os.fsencode(path.relative_to(root))
            if (parent == root and name == '.git') or any(relative == prefix or relative.startswith(prefix + b'/') for prefix in excluded):
                if name in folders:
                    folders.remove(name)
                continue
            if stat.S_ISSOCK(path.lstat().st_mode):
                continue
            yield relative
            if path.is_symlink() and name in folders:
                folders.remove(name)


def file_hash(root, name):
    path = root / os.fsdecode(name)
    if not os.path.lexists(path):
        return {'kind': 'deleted'}
    if path.is_symlink():
        return {'kind': 'symlink', 'sha256': hashlib.sha256(os.fsencode(os.readlink(path))).hexdigest()}
    if not path.resolve().is_relative_to(root.resolve()):
        raise GitStateError('instance.migrate_symlink_unsupported')
    if path.is_dir():
        return {'kind': 'directory'}
    if not path.is_file():
        metadata = path.lstat()
        return {'kind': 'special', 'type': stat.S_IFMT(metadata.st_mode), 'device': metadata.st_rdev}
    digest = hashlib.sha256()
    with path.open('rb') as content:
        for chunk in iter(lambda: content.read(1024 * 1024), b''):
            digest.update(chunk)
    return {'kind': 'file', 'sha256': digest.hexdigest()}


def snapshot(root, files=True):
    index = git(root, 'ls-files', '--stage', '-z')
    head = git(root, 'rev-parse', '--verify', 'HEAD').strip().decode('ascii')
    symbolic = git(root, 'rev-parse', '--symbolic-full-name', 'HEAD').rstrip(b'\n')
    log = git_path(root, 'logs/refs/stash')
    changed = set()
    if files:
        links, committed, _names = gitlinks(root, index)
        changed.update(carried_paths(root, links.keys() | committed.keys()))
        # Missing tracked paths remain observable too.
        changed.update(path for mode, _oid, _stage, path in index_entries(index) if mode != b'160000')
    status, _rows = statuses(root)
    tips = refs(root, stash=True)
    stash_tip = tips.pop('refs/stash', None)
    return {'format': repository(root)['format'],
            'branches': {name: oid for name, oid in tips.items() if name.startswith('refs/heads/')}, 'head': head,
            'head_ref': None if symbolic == b'HEAD' else os.fsdecode(symbolic),
            'stash_tip': stash_tip,
            'stash_log': encoded(log.read_bytes()) if log.exists() else None,
            'index': encoded(index), 'status': encoded(status),
            'files': {encoded(name): file_hash(root, name) for name in sorted(changed)} if files else None}


def identity(root):
    info = repository(root, refresh=True)
    return {name: info[name] for name in ('top', 'git_dir', 'common_dir')} | {
        'worktrees': encoded(native_git(root, '-C', str(root), 'worktree', 'list', '--porcelain', '-z'))}


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
    guard = guards(root)
    if guard['filters']:
        raise GitStateError('instance.migrate_git_filter_unsupported')
    if guard['intent_to_add']:
        raise GitStateError('instance.migrate_git_intent_to_add')
    if guard['submodules']:
        raise GitStateError('instance.migrate_retirement_blocked')
    fsck(root)
    state = snapshot(root, files=False)
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
        complete = snapshot(root)
        if source_identity != identity(root) or state != {**complete, 'files': None}:
            raise GitStateError('instance.migrate_git_source_changed')
        os.replace(temporary, pack)
        atomic_file(manifest, json.dumps({'identity': source_identity, 'state': complete}).encode('utf-8'))
    finally:
        Path(temporary).unlink(missing_ok=True)
    return {'verified': True}


def import_state(root, manifest, pack):
    expected = json.loads(Path(manifest).read_bytes())['state']
    if snapshot(root, files=False) == {**expected, 'files': None}:
        if snapshot(root) != expected:
            raise GitStateError('instance.migrate_git_state_mismatch')
        fsck(root)
        return {'verified': True, 'changed': False}
    if repository(root)['format'] != expected['format']:
        raise GitStateError('instance.migrate_git_state_mismatch')
    # No ref names are installed until every supplied object has passed Git's own checks.
    with Path(pack).open('rb') as source:
        git(root, 'index-pack', '--stdin', '--strict', stdin=source)
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
    head = (expected['head_ref'] and 'ref: ' + expected['head_ref']) or expected['head']
    atomic_file(git_path(root, 'HEAD'), (head + '\n').encode('utf-8', 'surrogateescape'), 0o660)
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
