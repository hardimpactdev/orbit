#!/usr/bin/env python3
"""Install the runner's JavaScript tools with the shared Node/image recipe installer."""
import importlib.util
import json
import os
from pathlib import Path
import pwd
import subprocess
import tempfile


ROOT = Path(__file__).resolve().parents[2]


def install(root=ROOT):
    resources = root / 'apps/gateway/resources'
    spec = importlib.util.spec_from_file_location('runner_recipe', resources / 'remote/lib/recipe.py')
    recipe = importlib.util.module_from_spec(spec)
    exec(compile((resources / 'remote/lib/artifacts.py').read_text(), 'artifacts.py', 'exec'), recipe.__dict__)
    spec.loader.exec_module(recipe)
    document = json.loads((resources / 'machine-software/recipe.json').read_text())
    tools = {name: document['tools'][name] for name in ('node', 'vp', 'vp-cli', 'pnpm')}
    lock = (resources / 'machine-software/vite-plus-package-lock.json').read_text()
    account = pwd.getpwnam('runner')
    architecture = subprocess.check_output(['dpkg', '--print-architecture'], text=True).strip()
    os.environ['SUDO_USER'] = 'runner'
    with tempfile.TemporaryDirectory(prefix='orbit-ci-javascript-') as directory:
        work = Path(directory)
        downloads = {name: recipe.download(pin, architecture, work / (name + '.archive'))
                     for name, pin in tools.items() if name != 'vp'}
        recipe.install_javascript(tools, downloads, work, account, lock)
    observed = recipe.inspect(tools)
    if any(observed[name] != pin['version'] for name, pin in tools.items()):
        raise RuntimeError('Runner JavaScript tools do not match the recipe')
    recipe.consolidate_node(tools, apply=True)


if __name__ == '__main__':
    install()
