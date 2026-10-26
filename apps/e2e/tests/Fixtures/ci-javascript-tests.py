"""CI uses recipe pins and refuses to remove another package with Ubuntu Node."""
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest
from unittest.mock import patch, Mock

ROOT = Path(__file__).resolve().parents[4]
SPEC = importlib.util.spec_from_file_location('ci_javascript', ROOT / 'tools/ci-runners/javascript.py')
installer = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(installer)


class CiJavascriptTest(unittest.TestCase):
    def test_installs_and_verifies_the_current_recipe_with_its_lock(self):
        document = json.loads((ROOT / 'apps/gateway/resources/machine-software/recipe.json').read_text())
        selected = {name: document['tools'][name] for name in ('node', 'vp', 'vp-cli', 'pnpm')}
        command = Mock(return_value=({'changed': True, 'retired': ['nodejs']}, ''))
        with patch.object(installer.runpy, 'run_path', return_value={'run': command}), \
             patch.object(installer.subprocess, 'check_output', return_value='amd64\n'):
            installer.install(ROOT)
        command.assert_called_once_with(ROOT / 'apps/agent/target/release/orbit-agent',
            'recipe.install-javascript', {
                'user': 'runner', 'snapshot': document['ubuntu']['snapshot'], 'architecture': 'amd64',
                'tools': selected,
                'vp_lock': (ROOT / 'apps/gateway/resources/machine-software/vite-plus-package-lock.json').read_text(),
            }, 900)

    def test_rejects_an_invalid_native_result(self):
        for result in [{}, {'changed': 1, 'retired': []}, {'changed': True, 'retired': 'nodejs'}]:
            with self.subTest(result=result), \
                 patch.object(installer.runpy, 'run_path', return_value={'run': Mock(return_value=(result, ''))}), \
                 patch.object(installer.subprocess, 'check_output', return_value='amd64\n'), \
                 self.assertRaisesRegex(RuntimeError, 'invalid result'):
                installer.install(ROOT)

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


if __name__ == "__main__":
    unittest.main()
