# Program: tools.read-vp-project
# Uses: lib/program.py
# Outcomes: read invalid
# Timeout: 60
# Input: {"path": Project application directory}
# Result: read: {"package": manifest, ".node-version": text or null, ".nvmrc": text or null,
#   "package_manager_hint": manager or null}. invalid: no data.
# Reads version requirements as data only; never runs Project scripts or package-manager hooks.
import json
import os
import pathlib
import stat


def read_file(root, name):
    try:
        fd = os.open(root / name, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    except FileNotFoundError:
        return None
    with os.fdopen(fd) as stream:
        info = os.fstat(stream.fileno())
        if not stat.S_ISREG(info.st_mode) or info.st_size > 1048576:
            raise ValueError('Invalid Project environment file')
        value = stream.read(1048577)
        if len(value) > 1048576:
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
            isinstance(manifest.get(key), dict) and 'vite-plus' in manifest[key]
            for key in ('dependencies', 'devDependencies')
        )
        markers = [
            ('pnpm', 'pnpm-workspace.yaml'), ('pnpm', 'pnpm-lock.yaml'),
            ('yarn', 'yarn.lock'), ('yarn', '.yarnrc.yml'), ('npm', 'package-lock.json'),
            ('bun', 'bun.lock'), ('bun', 'bun.lockb'),
        ]
        hint = next((family for family, name in markers if (root / name).is_file()), None)
        return 'read', {
            'package': manifest,
            '.node-version': read_file(root, '.node-version') if uses_vp else None,
            '.nvmrc': read_file(root, '.nvmrc') if uses_vp else None,
            'package_manager_hint': hint,
        }
    except (OSError, ValueError, UnicodeError):
        return 'invalid', {}


run_program(main)
