"""Stage one private Project manifest snapshot for pure requirement selection on209."""
import json
import os
import pwd
import re
import sys
from pathlib import Path


def small(directory, name, limit=16777216):
    path = directory / name
    if path.is_symlink():
        raise ValueError('tool.project_invalid')
    if not path.exists():
        return None
    descriptor = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    with os.fdopen(descriptor, 'rb') as stream:
        if not path.is_file() or os.fstat(stream.fileno()).st_size > limit:
            raise ValueError('tool.project_invalid')
        data = stream.read(limit + 1)
        if len(data) > limit:
            raise ValueError('tool.project_invalid')
        return data.decode()


def capture(value):
    root = Path(value['application'])
    if root.is_symlink() or not root.is_dir():
        raise ValueError('tool.project_invalid')
    package = json.loads(small(root, 'package.json', 1048576) or '{}')
    locks = {name: text for name in ('pnpm-lock.yaml', 'package-lock.json', 'yarn.lock') if (text := small(root, name)) is not None}
    workspaces = {}
    dependencies = {**package.get('dependencies', {}), **package.get('devDependencies', {})}
    relevant = any(name == 'vite-plus' or isinstance(spec, str) and spec.startswith('npm:vite-plus@') for name, spec in dependencies.items())
    for text in locks.values() if relevant else []:
        for link in re.findall(r'version: ["\x27]?link:([^\s"\x27]+)', text):
            directory = (root / link).resolve()
            if not directory.is_relative_to(root.resolve()) or not directory.is_dir():
                continue
            workspace = json.loads(small(directory, 'package.json', 1048576) or '{}')
            if workspace.get('name') == 'vite-plus':
                workspaces['link:' + link] = workspace.get('version')
    hint = next((manager for manager, file in [('pnpm', 'pnpm-workspace.yaml'), ('pnpm', 'pnpm-lock.yaml'), ('yarn', 'yarn.lock'), ('yarn', '.yarnrc.yml'), ('npm', 'package-lock.json'), ('bun', 'bun.lock'), ('bun', 'bun.lockb')] if (root / file).is_file()), None)
    account = pwd.getpwuid(os.geteuid())
    home = Path(account.pw_dir)
    candidates = [Path('/opt/orbit/vite-plus'), home / '.vite-plus', home / '.local/share/vite-plus']
    scope = candidates[-1]
    for candidate in candidates if relevant else []:
        if candidate.is_symlink() or candidate.exists() and (not candidate.is_dir() or candidate.stat().st_uid != os.geteuid()):
            raise ValueError('tool.vp_scope_conflict')
        if (candidate / 'bin/vp').is_file():
            scope = candidate
            break
    material = {'store': str(scope), 'manager_required': not (scope / 'bin/vp').is_file(), 'package': package, 'locks': locks, 'workspaces': workspaces, 'package_manager_hint': hint, '.node-version': small(root, '.node-version'), '.nvmrc': small(root, '.nvmrc')}
    destination = Path(value['destination'])
    destination.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
    descriptor = os.open(destination, os.O_WRONLY | os.O_CREAT | os.O_TRUNC | os.O_NOFOLLOW, 0o600)
    with os.fdopen(descriptor, 'w') as stream:
        json.dump(material, stream)
    return {'ready': True}


if __name__ == '__main__':
    try:
        print(json.dumps(capture(json.load(sys.stdin))))
    except (ValueError, OSError, KeyError, TypeError):
        print('{"ready":false,"error_code":"tool.project_invalid"}')
        sys.exit(1)
