# Program: tools.read-vp-project
# Uses: lib/program.py
# Outcomes: read invalid
# Timeout: 60
# Input: {"path": Project application directory}
# Result: read: {"package": manifest, ".node-version": text or null, ".nvmrc": text or null,
#   "package_manager_hint": manager or null, "locks": lockfile text, "npmrc": config text}. invalid: no data.
# Reads version requirements as data only; never runs Project scripts or package-manager hooks.
import json
import os
import pathlib
import re
import stat


def read_file(root, name, limit=1048576):
    try:
        fd = os.open(root / name, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    except FileNotFoundError:
        return None
    with os.fdopen(fd) as stream:
        info = os.fstat(stream.fileno())
        if not stat.S_ISREG(info.st_mode) or info.st_size > limit:
            raise ValueError('Invalid Project environment file')
        value = stream.read(limit + 1)
        if len(value) > limit:
            raise ValueError('Invalid Project environment file')
        return value


def main(payload):
    root = pathlib.Path(payload['path'])
    try:
        package = read_file(root, 'package.json')
        manifest = json.loads(package) if package is not None else {}
        if not isinstance(manifest, dict):
            return 'invalid', {}
        uses_vp = any(
            isinstance(manifest.get(key), dict) and any(
                name == 'vite-plus' or (isinstance(spec, str) and spec.startswith('npm:vite-plus@'))
                for name, spec in manifest[key].items())
            for key in ('dependencies', 'devDependencies')
        )
        markers = [
            ('pnpm', 'pnpm-workspace.yaml'), ('pnpm', 'pnpm-lock.yaml'),
            ('yarn', 'yarn.lock'), ('yarn', '.yarnrc.yml'), ('npm', 'package-lock.json'),
            ('bun', 'bun.lock'), ('bun', 'bun.lockb'),
        ]
        hint = next((family for family, name in markers if (root / name).is_file()), None)
        locks = {name: text for name in ('pnpm-lock.yaml', 'package-lock.json', 'yarn.lock')
                 if uses_vp and (text := read_file(root, name, 16777216)) is not None}
        workspaces = {}
        for text in locks.values():
            for link in re.findall(r'version: [\"\']?link:([^\s\"\']+)', text):
                directory = (root / link).resolve()
                if not directory.is_relative_to(root.resolve()):
                    raise ValueError('Workspace runtime leaves the Project')
                contents = read_file(directory, 'package.json')
                workspace = json.loads(contents) if contents else {}
                if workspace.get('name') == 'vite-plus':
                    workspaces['link:' + link] = workspace.get('version')
        return 'read', {
            'package': manifest,
            '.node-version': read_file(root, '.node-version') if uses_vp else None,
            '.nvmrc': read_file(root, '.nvmrc') if uses_vp else None,
            'package_manager_hint': hint,
            'locks': locks,
            'workspaces': workspaces,
            'npmrc': ((read_file(pathlib.Path.home(), '.npmrc') or '') + '\n' +
                      (read_file(root, '.npmrc') or '')) if uses_vp else '',
        }
    except (OSError, ValueError, UnicodeError):
        return 'invalid', {}


run_program(main)
