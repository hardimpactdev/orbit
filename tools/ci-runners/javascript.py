#!/usr/bin/env python3
"""Install the runner's JavaScript tools through the source-built native agent."""
import json
from pathlib import Path
import runpy
import subprocess


ROOT = Path(__file__).resolve().parents[2]


def install(root=ROOT):
    resources = root / 'apps/gateway/resources/machine-software'
    document = json.loads((resources / 'recipe.json').read_text())
    payload = {
        'user': 'runner',
        'snapshot': document['ubuntu']['snapshot'],
        'architecture': subprocess.check_output(['dpkg', '--print-architecture'], text=True).strip(),
        'tools': {name: document['tools'][name] for name in ('node', 'vp', 'vp-cli', 'pnpm')},
        'vp_lock': (resources / 'vite-plus-package-lock.json').read_text(),
    }
    agent = runpy.run_path(str(root / 'tools/agent_command.py'))
    result, _log = agent['run'](root / 'apps/agent/target/release/orbit-agent',
                              'recipe.install-javascript', payload, 900)
    if type(result.get('changed')) is not bool or not isinstance(result.get('retired'), list):
        raise RuntimeError('recipe.install-javascript returned an invalid result')


if __name__ == '__main__':
    install()
