<?php

declare(strict_types=1);

namespace App\Infrastructure\Instances;

final class DependencyFilesProgram
{
    /** @var list<string> */
    public const array FILES = ['composer.json', 'composer.lock', 'package.json', 'npm-shrinkwrap.json', 'package-lock.json', 'pnpm-lock.yaml', 'bun.lock', 'bun.lockb', 'yarn.lock', 'pnpm-workspace.yaml', '.yarnrc.yml', '.pnpmfile.cjs', 'pnpmfile.cjs', 'bunfig.toml', 'yarn.config.cjs'];

    public static function render(): string
    {
        return <<<'PYTHON'
import os, sys, stat, json, hashlib, base64
FILES = ['composer.json', 'composer.lock', 'package.json', 'npm-shrinkwrap.json', 'package-lock.json', 'pnpm-lock.yaml', 'bun.lock', 'bun.lockb', 'yarn.lock', 'pnpm-workspace.yaml', '.yarnrc.yml', '.pnpmfile.cjs', 'pnpmfile.cjs', 'bunfig.toml', 'yarn.config.cjs']
CONTENT = set(FILES)
class Refusal(Exception):
    pass
def fail(code):
    raise Refusal('dependencies.' + code)
def signature(s):
    return [s.st_dev, s.st_ino, s.st_mode, s.st_size, s.st_mtime_ns, s.st_ctime_ns]
def directory(path):
    if not path.startswith('/') or path == '/' or os.path.normpath(path) != path or os.path.realpath(path) != path:
        fail('unsafe_source')
    fd = os.open('/', os.O_RDONLY | os.O_DIRECTORY)
    try:
        for component in path.split('/')[1:]:
            next_fd = os.open(component, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW, dir_fd=fd)
            os.close(fd)
            fd = next_fd
        return fd
    except BaseException:
        os.close(fd)
        raise

def source(environment, path, application):
    fd = directory(path)
    if environment == 'development':
        try:
            root = path + application
            app_fd = directory(root)
            return root, None, app_fd, [os.fstat(fd).st_dev, os.fstat(fd).st_ino, os.fstat(app_fd).st_dev, os.fstat(app_fd).st_ino]
        finally:
            os.close(fd)
    try:
        try:
            pointer = os.stat('current', dir_fd=fd, follow_symlinks=False)
        except FileNotFoundError:
            fail('source_unavailable')
        if not stat.S_ISLNK(pointer.st_mode):
            fail('unsafe_source')
        target = os.readlink('current', dir_fd=fd)
        root = os.path.normpath(os.path.join(path, target))
        prefix = path + '/releases/'
        name = root.removeprefix(prefix)
        if not root.startswith(prefix) or '/' in name or not name or len(name) > 128 or not all(c.isascii() and (c.isalnum() or c in '._-') for c in name) or not name[0].isalnum():
            fail('unsafe_source')
        release_fd = directory(root)
        identity = [os.fstat(fd).st_dev, os.fstat(fd).st_ino, signature(pointer), target, os.fstat(release_fd).st_dev, os.fstat(release_fd).st_ino]
        try:
            root += application
            app_fd = directory(root)
            identity += [os.fstat(app_fd).st_dev, os.fstat(app_fd).st_ino]
            return root, name, app_fd, identity
        finally:
            os.close(release_fd)
    finally:
        os.close(fd)

def read_file(fd, name):
    try:
        before = os.stat(name, dir_fd=fd, follow_symlinks=False)
    except FileNotFoundError:
        return {'content': None, 'hash': None, 'error': None, 'state': None}
    except OSError:
        return {'content': None, 'hash': None, 'error': 'dependencies.unreadable_source', 'state': None}
    entry = {'content': None, 'hash': None, 'error': None, 'state': signature(before)}
    if not stat.S_ISREG(before.st_mode):
        entry['error'] = 'dependencies.unsafe_source'
        return entry
    limit = 8 * 1024 * 1024 if name.endswith(('.lock', '.lockb')) or name in ('package-lock.json', 'npm-shrinkwrap.json', 'pnpm-lock.yaml') else 1024 * 1024
    if name in CONTENT and before.st_size > limit:
        entry['error'] = 'dependencies.source_too_large'
        return entry
    try:
        file_fd = os.open(name, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK, dir_fd=fd)
        with os.fdopen(file_fd, 'rb') as handle:
            if signature(os.fstat(handle.fileno())) != signature(before):
                fail('source_changed')
            data = handle.read(limit + 1) if name in CONTENT else b''
            if signature(os.fstat(handle.fileno())) != signature(before):
                fail('source_changed')
        if len(data) > limit:
            fail('source_changed')
        if signature(os.stat(name, dir_fd=fd, follow_symlinks=False)) != signature(before):
            fail('source_changed')
        entry['content'] = base64.b64encode(data).decode('ascii')
        entry['hash'] = hashlib.sha256(data).hexdigest()
    except OSError:
        entry['error'] = 'dependencies.unreadable_source'
    return entry

def collect_files(fd):
    files = {name: read_file(fd, name) for name in FILES}
    again = {name: read_file(fd, name) for name in FILES}
    if files != again:
        fail('source_changed')
    return files

def discover(root, fd):
    projects = {}
    enabled = False
    for parent in ('apps', 'packages'):
        try:
            info = os.stat(parent, dir_fd=fd, follow_symlinks=False)
        except FileNotFoundError:
            continue
        enabled = True
        if not stat.S_ISDIR(info.st_mode):
            fail('unsafe_source')
        parent_fd = os.open(parent, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW, dir_fd=fd)
        try:
            for name in sorted(os.listdir(parent_fd)):
                info = os.stat(name, dir_fd=parent_fd, follow_symlinks=False)
                if not stat.S_ISDIR(info.st_mode):
                    continue
                child_fd = os.open(name, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW, dir_fd=parent_fd)
                try:
                    names = set(os.listdir(child_fd))
                    if 'composer.json' not in names and not ('package.json' in names and names.intersection({'package-lock.json', 'npm-shrinkwrap.json', 'pnpm-lock.yaml', 'bun.lock'})):
                        continue
                    relative = parent + '/' + name
                    try:
                        files = collect_files(child_fd)
                    except (Refusal, OSError) as error:
                        code = str(error) if isinstance(error, Refusal) else 'dependencies.unreadable_source'
                        files = {key: {'content': None, 'hash': None, 'error': code, 'state': None} for key in FILES}
                    projects[relative] = {'root': root + '/' + relative, 'files': files, 'source_identity': [os.fstat(child_fd).st_dev, os.fstat(child_fd).st_ino]}
                finally:
                    os.close(child_fd)
        finally:
            os.close(parent_fd)
    return projects if enabled else None

try:
    args = sys.argv[1:]
    monorepo = args[-1:] == ['--monorepo']
    if monorepo:
        args = args[:-1]
    environment, path, *suffix = args
    application = suffix[0] if len(suffix) == 1 else ''
    if len(suffix) > 1 or (application and (not application.startswith('/') or any(p in ('', '.', '..') for p in application[1:].split('/')))):
        fail('unsafe_source')
    if environment not in ('development', 'production'):
        fail('unsafe_source')
    root, reference, fd, identity = source(environment, path, application)
    try:
        projects = discover(root, fd) if monorepo else None
        files = collect_files(fd) if projects is None else {name: {'content': None, 'hash': None, 'error': None, 'state': None} for name in FILES}
        if monorepo and projects != discover(root, fd):
            fail('source_changed')
        sources = [files] + [p['files'] for p in (projects or {}).values()]
        if sum(v['state'][3] for source_files in sources for v in source_files.values() if v['hash'] is not None) > 32 * 1024 * 1024:
            fail('source_too_large')
        next_root, next_reference, next_fd, next_identity = source(environment, path, application)
        os.close(next_fd)
        if (root, reference, identity) != (next_root, next_reference, next_identity):
            fail('source_changed')
        digest = hashlib.sha256(json.dumps([identity, files, projects], sort_keys=True).encode()).hexdigest()
        for source_files in sources:
            for entry in source_files.values():
                del entry['state']
        receipt = {'root': root, 'reference': reference, 'identity': digest, 'files': files}
        if monorepo:
            receipt['sub_projects'] = projects
            for project in (projects or {}).values():
                project['reference'] = reference
                project['identity'] = digest
        print(json.dumps(receipt))
    finally:
        os.close(fd)
except Refusal as error:
    print(json.dumps({'error': str(error)}))
except (OSError, ValueError):
    print(json.dumps({'error': 'dependencies.unreadable_source'}))
PYTHON;
    }
}
