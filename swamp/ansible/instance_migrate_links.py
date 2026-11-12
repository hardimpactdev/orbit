"""Plan the migration overlay and links; rewrite only the copied workspace."""

import argparse
import base64
import json
import os
import stat
import subprocess
import sys
import tarfile
from collections import deque
from pathlib import Path


class CopyLayout:
    """The existing Git file overlay plus directories that it would not create."""
    def __init__(self, root):
        self.root = root
        self.files = self.git('diff', '--name-only', '-z', '--diff-filter=d', 'HEAD')
        self.untracked = self.git('ls-files', '-z', '--others')
        self.files += self.untracked
        self.counted = self.git('ls-files', '--others', '--exclude-standard', '-z')
        self.deleted = set(self.git('diff', '--name-only', '-z', '--diff-filter=D', 'HEAD'))
        entries = self.git('ls-tree', '-rz', '--full-tree', 'HEAD')
        self.tracked = set()
        self.gitlinks = set()
        existing = set()
        for entry in entries:
            metadata, name = entry.split(b'\t', 1)
            self.tracked.add(name)
            if metadata.startswith(b'160000 '):
                self.gitlinks.add(name)
                existing.add(name)
            existing.update(self.parents(name))
        self.archived_trees = set()
        for name in self.files:
            existing.update(self.parents(name.rstrip(b'/')))
            path = root / os.fsdecode(name.rstrip(b'/'))
            if stat.S_ISDIR(path.lstat().st_mode):
                self.archived_trees.add(name.rstrip(b'/'))
        self.directories = []
        for directory, folders, _files in os.walk(root, followlinks=False, onerror=self.unreadable):
            parent = Path(directory)
            folders[:] = sorted(
                name for name in folders if name != '.git'
                and os.fsencode((parent / name).relative_to(root)) not in self.gitlinks)
            if parent == root:
                continue
            name = os.fsencode(parent.relative_to(root))
            if name not in existing and not self.in_archived_tree(name):
                self.directories.append((name, stat.S_IMODE(parent.stat().st_mode)))
        self.present_directories = existing | {name for name, _mode in self.directories}
        # These paths come from init --shared=group, the branch fetch and checkout -B.
        # Template files, reflogs and object filenames are deliberately not promised.
        head = self.git('rev-parse', '--symbolic-full-name', 'HEAD')[0].rstrip(b'\n')
        files = {b'.git/HEAD', b'.git/config', b'.git/index', b'.git/FETCH_HEAD'}
        if head.startswith(b'refs/heads/'):
            branch = head.removeprefix(b'refs/heads/')
            files.update({b'.git/' + head, b'.git/refs/remotes/origin/' + branch})
        directories = {b'.git', b'.git/objects', b'.git/objects/info', b'.git/objects/pack'}
        for name in files:
            directories.update(self.parents(name))
        self.git_metadata = {name: stat.S_IFREG for name in files}
        self.git_metadata.update({name: stat.S_IFDIR for name in directories})

    @staticmethod
    def unreadable(error):
        raise error

    @staticmethod
    def parents(name):
        parent = os.path.dirname(name)
        while parent:
            yield parent
            parent = os.path.dirname(parent)

    def git(self, *args):
        result = subprocess.run(
            ['/usr/bin/git', '-c', 'core.hooksPath=/dev/null', '-c', 'core.fsmonitor=false',
             '-c', 'safe.directory=' + str(self.root), '-C', str(self.root), *args],
            env={**os.environ, 'GIT_OPTIONAL_LOCKS': '0'},
            check=True, capture_output=True)
        return [name for name in result.stdout.split(b'\0') if name]

    def in_archived_tree(self, name):
        return any(name == tree or name.startswith(tree + b'/') for tree in self.archived_trees)

    def contains(self, path):
        if path == self.root:
            return True
        name = os.fsencode(path.relative_to(self.root))
        if name in self.deleted:
            return False
        if self.in_archived_tree(name):
            return not stat.S_ISSOCK(path.lstat().st_mode)
        if name == b'.git' or name.startswith(b'.git/'):
            return name in self.git_metadata
        if any(name.startswith(tree + b'/') for tree in self.gitlinks):
            return False
        return name in self.tracked or name in self.files or name in self.present_directories

    def mode(self, path):
        name = os.fsencode(path.relative_to(self.root))
        if name == b'.git' or name.startswith(b'.git/'):
            if name not in self.git_metadata:
                raise UnsupportedLink('broken')
            return self.git_metadata[name]
        if not self.contains(path):
            raise UnsupportedLink('broken')
        return path.lstat().st_mode

    def archive(self, prefix):
        for suffix, values in (('.list', self.files), ('.untracked', self.untracked),
                               ('.counted', self.counted), ('.deleted', sorted(self.deleted))):
            Path(prefix + suffix).write_bytes(b''.join(name + b'\0' for name in values))
        subprocess.run(['tar', '-C', str(self.root), '--null', '--files-from', prefix + '.list',
                        '-cf', prefix + '.tar'], check=True)
        def inspectable(info):
            # Extraction runs as orbit; inspect before restoring even unreadable source modes.
            if info.isdir():
                info.mode |= stat.S_IRWXU
            return info

        with tarfile.open(prefix + '.tar', 'a', encoding='utf-8', errors='surrogateescape') as archive:
            for name, _mode in self.directories:
                archive.add(self.root / os.fsdecode(name), arcname=os.fsdecode(name), recursive=False, filter=inspectable)
        manifest = json.dumps([{'path': os.fsdecode(name), 'mode': mode} for name, mode in self.directories])
        # The Node returns this through Ansible's protected result, never an app-writable container file.
        return {'directories_copied': len(self.directories),
                'directory_modes': base64.b64encode(manifest.encode()).decode('ascii')}


def restore_directories(root, encoded):
    entries = json.loads(base64.b64decode(encoded.strip(), validate=True))
    if not isinstance(entries, list):
        raise TypeError('Invalid directory manifest')
    paths = []
    for entry in entries:
        name, mode = entry['path'], entry['mode']
        parts = name.split('/')
        if os.path.isabs(name) or any(part in {'', '.', '..'} for part in parts):
            raise ValueError('Invalid directory path')
        if type(mode) is not int or not 0 <= mode <= 0o7777:
            raise ValueError('Invalid directory mode')
        paths.append((parts, mode))
    flags = os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW | os.O_CLOEXEC
    root_fd = os.open(root, flags)
    try:
        for parts, mode in sorted(paths, key=lambda item: len(item[0]), reverse=True):
            directory_fd = os.dup(root_fd)
            try:
                for part in parts:
                    child_fd = os.open(part, flags, dir_fd=directory_fd)
                    os.close(directory_fd)
                    directory_fd = child_fd
                os.fchmod(directory_fd, mode)
            finally:
                os.close(directory_fd)
    finally:
        os.close(root_fd)


class UnsupportedLink(Exception):
    pass


def package_links(directory, kind):
    """Inspect package boundary links, not installed package contents or bin shims."""
    for entry in sorted(directory.iterdir()):
        if entry.name.startswith('.') or (kind == 'vendor' and entry.name == 'bin'):
            continue
        if entry.is_symlink():
            yield entry
        elif entry.is_dir() and (kind == 'vendor' or entry.name.startswith('@')):
            yield from (package for package in sorted(entry.iterdir()) if package.is_symlink())


def checkout_links(root):
    def unreadable(error):
        raise error

    for directory, folders, files in os.walk(root, followlinks=False, onerror=unreadable):
        parent = Path(directory)
        for name in sorted(folders + files):
            link = parent / name
            if name != '.git' and link.is_symlink():
                yield link
        dependencies = {'vendor': 'composer.json', 'node_modules': 'package.json'}
        for name, manifest in dependencies.items():
            dependency = parent / name
            if name in folders and not dependency.is_symlink() and (parent / manifest).is_file():
                yield from package_links(dependency, name)
                folders.remove(name)
        folders[:] = sorted(name for name in folders if name != '.git')


def resolve(link, root, old_checkout, layout=None):
    """Resolve links against this tree, translating old absolute prefixes at every hop."""
    def inside(target):
        named = Path(os.path.abspath(target))
        for prefix in (old_checkout, root):
            if target.is_relative_to(prefix) and named.is_relative_to(prefix):
                return target.relative_to(prefix).parts
        raise UnsupportedLink('outside checkout')

    pending = deque(link.relative_to(root).parts)
    resolved = []
    followed = 0
    while pending:
        part = pending.popleft()
        if part == '.':
            continue
        if part == '..':
            if not resolved:
                raise UnsupportedLink('outside checkout')
            resolved.pop()
            continue
        candidate = root.joinpath(*resolved, part)
        try:
            mode = candidate.lstat().st_mode if layout is None else layout.mode(candidate)
            if stat.S_ISLNK(mode):
                followed += 1
                if followed > 40:
                    raise UnsupportedLink('broken')
                raw_target = os.readlink(candidate)
                target = Path(raw_target)
                if target.is_absolute():
                    parts = inside(target)
                    resolved = []
                else:
                    parts = target.parts
                    # Check the named destination as well as its resolved destination.
                    inside(candidate.parent / target)
                # Path drops trailing separators and dots. Retain their directory requirement at this hop.
                if raw_target.endswith(('/', '/.')):
                    parts = (*parts, '.')
                pending.extendleft(reversed(parts))
            else:
                if pending and not stat.S_ISDIR(mode):
                    raise UnsupportedLink('broken')
                resolved.append(part)
        except OSError as error:
            raise UnsupportedLink('broken') from error
    return root.joinpath(*resolved)


def plan(checkout, old_checkout=None, rewrite=False, predict_copy=False):
    root = Path(os.path.abspath(checkout))
    old_checkout = Path(os.path.abspath(old_checkout or checkout))
    layout = CopyLayout(root) if predict_copy else None
    rewrites = []
    refused = []
    for link in checkout_links(root):
        if layout is not None and not layout.contains(link):
            continue
        target = os.readlink(link)
        path = str(link.relative_to(root))
        try:
            destination = resolve(link, root, old_checkout, layout)
        except UnsupportedLink as error:
            refused.append({'path': path, 'target': target, 'reason': str(error)})
        else:
            if os.path.isabs(target):
                rewrites.append({'path': path, 'target': os.path.relpath(destination, link.parent)})
    if rewrite and not refused:
        for item in rewrites:
            link = root / item['path']
            link.unlink()
            link.symlink_to(item['target'])
    return {'rewrites': rewrites, 'refused': refused}


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('checkout')
    parser.add_argument('--old-checkout')
    parser.add_argument('--rewrite', action='store_true')
    modes = parser.add_mutually_exclusive_group()
    modes.add_argument('--predict-copy', action='store_true')
    modes.add_argument('--archive-prefix')
    modes.add_argument('--restore-directories', action='store_true')
    args = parser.parse_args()
    if args.archive_prefix:
        print(json.dumps(CopyLayout(Path(os.path.abspath(args.checkout))).archive(args.archive_prefix)))
    elif args.restore_directories:
        restore_directories(Path(args.checkout), sys.stdin.read())
    else:
        result = plan(args.checkout, args.old_checkout, args.rewrite, args.predict_copy)
        # Display text escapes invalid UTF-8; rewrite and archive paths stay in this filesystem process.
        public = {group: [{key: os.fsencode(value).decode('utf-8', 'backslashreplace') for key, value in item.items()}
                          for item in items] for group, items in result.items()}
        print(json.dumps(public))
