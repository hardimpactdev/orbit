#!/usr/bin/python
"""Read-only Node-pin preflight; source order follows Vite+ v1.4.0 runtime.rs."""
import json
import os
import re
import shlex
from pathlib import Path

DOCUMENTATION = r"""
module: orbit_migrate_node
short_description: Refuse unavailable Node pins before migration downtime
version_added: "1.0.0"
description: Read the same shared Vite+ home the container mounts, without running Project code or resolving versions online.
options:
  checkout:
    description: Source checkout boundary.
    type: path
    required: true
  application:
    description: Source application directory.
    type: path
    required: true
  processes:
    description: Admitted container Process units, including working directories.
    type: list
    elements: dict
    default: []
  runtime_home:
    description: The shared current runtime Vite+ home mounted by containers.
    type: path
    default: /opt/orbit-runtime/current/vite-plus-home
author: Orbit
"""

EXACT = re.compile(r'v?(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\Z')


def local_pin(directory):
    """Nearest directory wins; native pin, development requirement, engines, nvmrc."""
    native = directory / '.node-version'
    if native.exists():
        lines = native.read_text().splitlines()
        if lines and lines[0].strip():
            return native, lines[0].strip()
    manifest = directory / 'package.json'
    if manifest.exists():
        package = json.loads(manifest.read_text())
        runtime = package.get('devEngines', {}).get('runtime', [])
        for entry in runtime if isinstance(runtime, list) else [runtime]:
            if isinstance(entry, dict) and entry.get('name') == 'node' and entry.get('version'):
                return manifest, entry['version'], 'devEngines.runtime'
        version = package.get('engines', {}).get('node')
        if version:
            return manifest, version, 'engines.node'
    nvmrc = directory / '.nvmrc'
    if nvmrc.exists():
        declarations = [line.split('#', 1)[0].strip() for line in nvmrc.read_text().splitlines()]
        declarations = [line for line in declarations if line and '=' not in line]
        if declarations:
            return nvmrc, declarations[0] if len(declarations) == 1 else '[ambiguous pin]'
    return None


def inspect_node_versions(checkout, application, processes=(), runtime_home='/opt/orbit-runtime/current/vite-plus-home'):
    """Return one public refusal, or None. Never execute vp or contact a registry."""
    checkout = Path(checkout).resolve()
    directories = {checkout, Path(application).resolve()}
    for process in processes:
        for match in re.finditer(r'^WorkingDirectory=(.+)$', process.get('content', ''), re.MULTILINE):
            words = shlex.split(match[1])
            if len(words) == 1 and (words[0] == '/srv/workspace' or words[0].startswith('/srv/workspace/')):
                directories.add(checkout / Path(words[0]).relative_to('/srv/workspace'))
    available = sorted(path.name for path in (Path(runtime_home) / 'js_runtime/node').glob('*')
                       if EXACT.fullmatch(path.name) and os.access(path / 'bin/node', os.X_OK))
    for directory in sorted(directories):
        if not directory.is_relative_to(checkout):
            continue
        while True:
            try:
                pin = local_pin(directory)
            except (OSError, ValueError, AttributeError, TypeError):
                pin = (directory, '[unreadable pin]')
            if pin is not None:
                path, pinned, *field = pin
                source = str(path.relative_to(checkout)) + (':' + field[0] if field else '')
                pinned = pinned.strip() if isinstance(pinned, str) else '[unsupported pin]'
                if EXACT.fullmatch(pinned) and pinned.removeprefix('v') in available:
                    break
                details = {'pin_source': source, 'pinned': pinned, 'available': available}
                reason = 'is unavailable' if EXACT.fullmatch(pinned) else 'cannot be proved available offline'
                return {'details': details,
                        'message': f"{source} pins Node {pinned}, which {reason}. "
                                   f"The container provides Node {', '.join(available) or 'none'}. "
                                   "Orbit will provide pinned versions in a later release."}
            if directory == checkout:
                break
            directory = directory.parent
    return None


def main():
    from ansible.module_utils.basic import AnsibleModule
    module = AnsibleModule(argument_spec={
        'checkout': {'type': 'path', 'required': True},
        'application': {'type': 'path', 'required': True},
        'processes': {'type': 'list', 'elements': 'dict', 'default': []},
        'runtime_home': {'type': 'path', 'default': '/opt/orbit-runtime/current/vite-plus-home'},
    }, supports_check_mode=True)
    module.exit_json(changed=False, refusal=inspect_node_versions(**module.params))


if __name__ == '__main__':
    main()
