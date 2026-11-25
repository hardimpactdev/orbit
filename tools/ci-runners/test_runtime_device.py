"""The runner container's read-only runtime folder and its FrankenPHP path, with a fake incus."""
import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]
SOURCE = Path(__file__).with_name('setup.sh').read_text()
RECIPE = json.loads((ROOT / 'apps/gateway/resources/machine-software/core.yaml').read_text())
FAKE = '''#!/usr/bin/env bash
set -euo pipefail
printf '%s\\n' "$*" >> "$FAKE_ROOT/calls"
case "$1 $2" in
  "config device")
    case "$3" in
      get) test -f "$FAKE_ROOT/device" ;;
      add) touch "$FAKE_ROOT/device" ;;
      set) ;;
    esac ;;
  "exec "*) [ "${FAKE_FRANKENPHP:-yes}" = yes ] ;;
esac
'''


class RuntimeDeviceTest(unittest.TestCase):
    def run_setup(self, root, command, **env):
        # Every function and setting of setup.sh, without its dispatch; then one command.
        program = SOURCE.rsplit('\ncase "${1:-}" in', 1)[0] + f'\n{command}\n'
        return subprocess.run(['bash', '-c', program, str(ROOT / 'tools/ci-runners/setup.sh')], capture_output=True, text=True,
                              timeout=30, cwd=ROOT, env={**os.environ, 'PATH': f'{root}:{os.environ["PATH"]}', 'FAKE_ROOT': str(root), **env})

    def test_frankenphp_path_comes_from_the_recipe_ci_php_version(self):
        version = RECIPE['php']['ci']
        self.assertIn(version, RECIPE['instances']['php']['versions'])
        result = self.run_setup(Path('/nonexistent'), 'echo "$FRANKENPHP"')
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(result.stdout.strip(), f'/opt/orbit-runtime/current/php/{version}/bin/frankenphp')

    def test_adds_a_read_only_unshifted_device_once_and_resets_its_keys_on_a_rerun(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            (root / 'incus').write_text(FAKE)
            (root / 'incus').chmod(0o755)
            for _ in range(2):
                result = self.run_setup(root, 'runtime_device ci-1')
                self.assertEqual(result.returncode, 0, result.stderr)
            calls = (root / 'calls').read_text().splitlines()
            adds = [call for call in calls if 'device add' in call]
            self.assertEqual(adds, ['config device add ci-1 orbit-runtime disk source=/opt/orbit-runtime path=/opt/orbit-runtime '
                                    'readonly=true --project orbit-ci'])
            sets = [call.split()[5] for call in calls if 'device set' in call]
            self.assertEqual(sets, ['source=/opt/orbit-runtime', 'path=/opt/orbit-runtime', 'readonly=true'])
            # Incus refuses shift on a restricted source path, the only kind of host disk the orbit-ci project admits.
            self.assertFalse(any('shift' in call for call in calls))
            self.assertTrue(any(call.startswith('exec ci-1 --project orbit-ci -- test -x /opt/orbit-runtime/current/php/') for call in calls))

    def test_a_runtime_folder_without_frankenphp_fails_by_name(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            (root / 'incus').write_text(FAKE)
            (root / 'incus').chmod(0o755)
            result = self.run_setup(root, 'runtime_device ci-1', FAKE_FRANKENPHP='no')
            self.assertNotEqual(result.returncode, 0)
            self.assertIn('bin/frankenphp is missing in the runtime folder', result.stderr)

    def test_container_mounts_the_runtime_and_the_hook_writes_its_environment(self):
        body = SOURCE.split('\ncontainer() {', 1)[1].split('\n}\n', 1)[0]
        self.assertLess(body.index('runtime_device "$name"'), body.index('hook "$name"'))
        hook = SOURCE.split('\nhook() {', 1)[1].split('\n}\n', 1)[0]
        self.assertIn('echo "ORBIT_FRANKENPHP=$2" >> "$env_file"', hook)
        self.assertIn('/^ORBIT_FRANKENPHP=/d', hook)
        self.assertIn('\' hook "$SWAMP_BINARY" "$FRANKENPHP"', hook)
        self.assertIn('  runtime_device | container |', SOURCE)


if __name__ == '__main__':
    unittest.main()
