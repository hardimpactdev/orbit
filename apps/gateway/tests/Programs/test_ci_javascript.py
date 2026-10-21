"""CI uses recipe pins and refuses to remove another package with Ubuntu Node."""
import importlib.util
import json
import os
from pathlib import Path
import types
import subprocess
import tempfile
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[4]
SPEC = importlib.util.spec_from_file_location('ci_javascript', ROOT / 'tools/ci-runners/javascript.py')
installer = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(installer)


class CiJavascriptTest(unittest.TestCase):
    def test_installs_and_verifies_the_current_recipe_with_its_lock(self):
        document = json.loads((ROOT / 'apps/gateway/resources/machine-software/recipe.json').read_text())
        selected = {name: document['tools'][name] for name in ('node', 'vp', 'vp-cli', 'pnpm')}
        recipe = types.ModuleType('fake_recipe')
        from unittest.mock import Mock
        recipe.download = Mock(side_effect=lambda pin, architecture, destination: destination)
        recipe.install_javascript = Mock()
        recipe.consolidate_node = Mock()
        recipe.inspect = Mock(return_value={name: pin['version'] for name, pin in selected.items()})
        spec = types.SimpleNamespace(loader=types.SimpleNamespace(exec_module=lambda module: None))
        with patch.dict(os.environ), \
             patch.object(installer.importlib.util, 'spec_from_file_location', return_value=spec), \
             patch.object(installer.importlib.util, 'module_from_spec', return_value=recipe), \
             patch.object(installer.pwd, 'getpwnam', return_value='runner-account'), \
             patch.object(installer.subprocess, 'check_output', return_value='amd64\n'):
            installer.install(ROOT)
        self.assertEqual(recipe.download.call_count, 3)
        args = recipe.install_javascript.call_args.args
        self.assertEqual(args[0], selected)
        self.assertEqual(args[3], 'runner-account')
        self.assertEqual(args[4], (ROOT / 'apps/gateway/resources/machine-software/vite-plus-package-lock.json').read_text())
        recipe.inspect.assert_called_once_with(selected)
        recipe.consolidate_node.assert_called_once_with(selected, apply=True)

    def smoke_check(self, actual):
        source = (ROOT / 'tools/ci-runners/setup.sh').read_text()
        source = source[source.index('# Verify the launcher'):source.index('if [ ! -x /home/runner/actions-runner/config.sh ]')]
        source = source.replace('/root/orbit-php-build', str(ROOT))
        with tempfile.TemporaryDirectory() as directory:
            work = Path(directory)
            private = work / 'private'
            private.mkdir(mode=0o700)
            sudo = work / 'sudo'
            sudo.write_text('#!/bin/sh\n[ "$PWD" = / ] || exit 71\n'
                            '[ "$*" = "-u runner -H /usr/local/bin/node --version" ] || exit 72\n'
                            'printf "%s\\n" "$SMOKE_NODE_VERSION"\n')
            sudo.chmod(0o755)
            return subprocess.run(['bash', '-euo', 'pipefail', '-c', source], cwd=private,
                                  env=dict(os.environ, PATH=directory + ':' + os.environ['PATH'], SMOKE_NODE_VERSION=actual),
                                  capture_output=True, text=True)

    def test_setup_smoke_check_escapes_private_cwd_and_matches_recipe_as_runner(self):
        version = json.loads((ROOT / 'apps/gateway/resources/machine-software/recipe.json').read_text())['tools']['node']['version']
        result = self.smoke_check('v' + version)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(result.stdout.strip(), 'v' + version)

    def test_setup_smoke_check_rejects_wrong_runtime_version(self):
        result = self.smoke_check('v24.22.0')
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('does not match the recipe', result.stderr)
