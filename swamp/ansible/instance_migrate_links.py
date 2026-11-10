"""Read-only symlink plan for instance:migrate, shared by preflight and file copy."""

import json
import os
import sys
from pathlib import Path


def plan(checkout):
    root = Path(os.path.abspath(checkout))
    resolved_root = root.resolve(strict=True)
    rewrites = []
    refused = []

    def unreadable(error):
        raise error

    for directory, folders, files in os.walk(root, followlinks=False, onerror=unreadable):
        for name in sorted(folders + files):
            link = Path(directory) / name
            if not link.is_symlink() or name == '.git':
                continue
            target = os.readlink(link)
            named = Path(os.path.abspath(link.parent / target))
            reason = None
            try:
                resolved = link.resolve(strict=True)
            except (OSError, RuntimeError):
                reason = 'broken'
            else:
                if not named.is_relative_to(root) or not resolved.is_relative_to(resolved_root):
                    reason = 'outside checkout'
            path = str(link.relative_to(root))
            if reason:
                refused.append({'path': path, 'target': target, 'reason': reason})
            elif os.path.isabs(target):
                destination = root / resolved.relative_to(resolved_root)
                rewrites.append({'path': path, 'target': os.path.relpath(destination, link.parent)})
        folders[:] = sorted(name for name in folders if name not in {'.git', 'vendor', 'node_modules'})

    return {'rewrites': rewrites, 'refused': refused}


if __name__ == '__main__':
    print(json.dumps(plan(sys.argv[1])))
