"""Execute the maintenance runbook against an isolated Incus command boundary."""
import json
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest
from unittest.mock import patch

DOC = (Path(__file__).resolve().parents[4] / 'docs/reference/compute-drivers.md').read_text()
SCRIPT = DOC.split('```python\n# incus-upgrade-autostart\n')[1].split('```')[0]


class IncusUpgradeRunbookTest(unittest.TestCase):
    def setUp(self):
        work = tempfile.TemporaryDirectory()
        self.addCleanup(work.cleanup)
        self.manifest = Path(work.name) / 'autostart.json'
        self.instances = [
            {'project': project, 'name': name, 'config': config,
             'expanded_config': {'boot.autostart': effective}}
            for project, name, config, effective in [
                ('orbit-ci', 'runner', {}, 'true'),
                ('default', 'vm', {'boot.autostart': 'true'}, 'true'),
                ('other', 'guest', {'boot.autostart': '1'}, '1'),
                ('orbit-ci', 'disabled', {'boot.autostart': 'false'}, 'false'),
            ]
        ]

    def execute(self, action, *selection, fail=False):
        with patch.object(sys, 'argv', ['script', action, str(self.manifest), *selection]), \
             patch('subprocess.check_output', return_value=json.dumps(self.instances)), \
             patch('subprocess.check_call') as call:
            if fail:
                call.side_effect = subprocess.CalledProcessError(1, 'incus')
            exec(compile(SCRIPT, 'runbook', 'exec'), {})
            return [item.args[0] for item in call.call_args_list]

    def test_hold_disables_effective_autostart_in_every_project_without_changing_profiles(self):
        calls = self.execute('hold')
        self.assertEqual(calls, [
            ['incus', '--project', project, 'config', 'set', name, 'boot.autostart=false']
            for project, name in [('orbit-ci', 'runner'), ('default', 'vm'), ('other', 'guest'), ('orbit-ci', 'disabled')]
        ])
        self.assertEqual(json.loads(self.manifest.read_text())[0]['value'], None)

    def test_restore_one_runner_then_all_preserves_inheritance_and_explicit_values(self):
        self.execute('hold')
        self.assertEqual(self.execute('restore', 'orbit-ci/runner'), [
            ['incus', '--project', 'orbit-ci', 'config', 'unset', 'runner', 'boot.autostart']])
        self.assertEqual(self.execute('restore')[1:], [
            ['incus', '--project', 'default', 'config', 'set', 'vm', 'boot.autostart=true'],
            ['incus', '--project', 'other', 'config', 'set', 'guest', 'boot.autostart=1'],
            ['incus', '--project', 'orbit-ci', 'config', 'set', 'disabled', 'boot.autostart=false']])

    def test_failed_mutation_retains_original_manifest_and_refuses_overwrite(self):
        with self.assertRaises(subprocess.CalledProcessError):
            self.execute('hold', fail=True)
        original = self.manifest.read_text()
        with self.assertRaises(FileExistsError):
            self.execute('hold')
        self.assertEqual(self.manifest.read_text(), original)
        self.assertEqual(len(self.execute('restore')), 4)

    def test_unknown_restore_selection_refuses_before_mutation(self):
        self.execute('hold')
        with self.assertRaises(SystemExit):
            self.execute('restore', 'orbit-ci/missing')

    def test_backup_skips_absent_agent_but_requires_server_packages(self):
        block = DOC.split('```bash\nset -euo pipefail\nbackup=/root/rollback')[1]
        loop = 'for package in incus incus-base incus-client incus-agent; do' + block.split(
            'for package in incus incus-base incus-client incus-agent; do')[1].split('\nunits=')[0]
        with tempfile.TemporaryDirectory() as work:
            prefix = '''set -euo pipefail
backup="$1"
mkdir -p "$backup/debs"
dpkg-query() {
  if [ "${@: -1}" = "$missing" ]; then return 1; fi
  if [ "$2" = '-f=${Status}' ]; then printf 'install ok installed'; else printf '6.0.5-8'; fi
}
apt-get() { echo "$*"; }
dpkg-repack() { return 1; }
'''
            for missing, success in [('incus-agent', True), ('incus', False)]:
                result = subprocess.run(['bash', '-c', prefix + loop, 'backup', work],
                                        env={'PATH': '/usr/bin:/bin', 'missing': missing},
                                        capture_output=True, text=True)
                self.assertEqual(result.returncode == 0, success, result.stderr)
                if success:
                    self.assertNotIn('download incus-agent=', result.stdout)


if __name__ == '__main__':
    unittest.main()
