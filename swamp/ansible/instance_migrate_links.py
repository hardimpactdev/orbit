"""Check migration links; rewrite only the exact copied workspace, never the source."""

import argparse
import json
import os
import stat
from collections import deque
from pathlib import Path


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


def resolve(link, root, old_checkout):
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
        if part == '..':
            if not resolved:
                raise UnsupportedLink('outside checkout')
            resolved.pop()
            continue
        candidate = root.joinpath(*resolved, part)
        try:
            mode = candidate.lstat().st_mode
            if stat.S_ISLNK(mode):
                followed += 1
                if followed > 40:
                    raise UnsupportedLink('broken')
                target = Path(os.readlink(candidate))
                if target.is_absolute():
                    parts = inside(target)
                    resolved = []
                else:
                    parts = target.parts
                    # Check the named destination as well as its resolved destination.
                    inside(candidate.parent / target)
                pending.extendleft(reversed(parts))
            else:
                if pending and not stat.S_ISDIR(mode):
                    raise UnsupportedLink('broken')
                resolved.append(part)
        except OSError as error:
            raise UnsupportedLink('broken') from error
    return root.joinpath(*resolved)


def plan(checkout, old_checkout=None, rewrite=False):
    root = Path(os.path.abspath(checkout))
    old_checkout = Path(os.path.abspath(old_checkout or checkout))
    rewrites = []
    refused = []
    for link in checkout_links(root):
        target = os.readlink(link)
        path = str(link.relative_to(root))
        try:
            destination = resolve(link, root, old_checkout)
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
    args = parser.parse_args()
    result = plan(args.checkout, args.old_checkout, args.rewrite)
    # Ansible rejects surrogate escapes even in JSON. Only display text leaves the filesystem process;
    # actual rewrite paths retain their original bytes and never pass through a JSON exec argument.
    public = {group: [{key: os.fsencode(value).decode('utf-8', 'backslashreplace') for key, value in item.items()}
                      for item in items] for group, items in result.items()}
    print(json.dumps(public))
