"""Exercise binary retention and the exact drained-worker cache command on disk."""
import os
import shlex
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

import yaml

ANSIBLE = Path(__file__).resolve().parents[1] / 'ansible'
TASKS = ANSIBLE / 'tasks/swamp-binary-retention.yml'
PLAYBOOK = Path(sys.executable).parent / 'ansible-playbook'


class SwampRetentionTest(unittest.TestCase):
    def run_retention(self, folder, current, selected, previous=True):
        tasks = yaml.safe_load(TASKS.read_text())
        # A private proc fixture supplies the same exe link and maps format,
        # without requiring CI to read other users' process maps.
        command = next(task for task in tasks if task['name'] == 'Read Swamp binary folders still used by processes')
        command['ansible.builtin.command']['argv'][2] = command['ansible.builtin.command']['argv'][2].replace(
            "pathlib.Path('/proc')", f'pathlib.Path({str(folder / "proc")!r})')
        variables = {'rt': {'swamp': {'binary': {'path': str(folder / current / 'swamp')}}},
                     'swamp_executable': {'stat': {'exists': True, 'islnk': True, 'lnk_source': str(folder / selected / 'swamp')}}}
        if not previous:
            (folder / 'previous').unlink(missing_ok=True)
        (folder / 'play.yml').write_text(yaml.safe_dump([{'name': 'Retain Swamp', 'hosts': 'localhost',
                                                         'gather_facts': False, 'vars': variables, 'tasks': tasks}]))
        result = subprocess.run([str(PLAYBOOK), '-i', 'localhost,', '-c', 'local', str(folder / 'play.yml')],
                                check=False, capture_output=True, text=True, timeout=90,
                                env={**os.environ, 'ANSIBLE_CONFIG': str(ANSIBLE / 'ansible.cfg'),
                                     'ANSIBLE_PYTHON_INTERPRETER': sys.executable, 'ANSIBLE_FORCE_COLOR': '0'})
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        return result.stdout

    def test_selection_previous_mapped_and_unknown_folders_survive_and_repeat_is_unchanged(self):
        with tempfile.TemporaryDirectory() as name:
            root = Path(name)
            current, previous, mapped, expired = [f'202611{i:02}.000000.0-sha.abcdef12' for i in (17, 16, 15, 14)]
            for version in (current, previous, mapped, expired, 'replaced-old', 'tasks', 'operator-notes'):
                (root / version).mkdir()
                (root / version / 'swamp').write_text('owned executable fixture')
            (root / 'previous').symlink_to(root / previous / 'swamp')
            proc = root / 'proc/123'
            proc.mkdir(parents=True)
            (proc / 'exe').symlink_to(root / mapped / 'swamp')
            (proc / 'maps').write_text(f'0000-1000 r-xp 0000 00:00 1 {root / mapped / "swamp"}\n')
            self.run_retention(root, current, current)
            self.assertTrue(all((root / version).exists() for version in (current, previous, mapped, 'tasks', 'operator-notes')))
            self.assertFalse((root / expired).exists())
            self.assertFalse((root / 'replaced-old').exists())
            self.assertIn('changed=0', self.run_retention(root, current, current))
            # After a pin switch the actual former selection replaces the old
            # rollback pointer; the unrelated mapped executable still survives.
            newest = '20261118.000000.0-sha.abcdef12'
            (root / newest).mkdir()
            (root / newest / 'swamp').write_text('new executable fixture')
            self.run_retention(root, newest, current)
            self.assertEqual((root / 'previous').resolve(), root / current / 'swamp')
            self.assertFalse((root / previous).exists())
            self.assertTrue((root / mapped).exists())

    def test_an_unknown_previous_selection_never_authorizes_deletion(self):
        with tempfile.TemporaryDirectory() as name:
            root = Path(name)
            (root / 'proc').mkdir()
            current = '20261117.000000.0-sha.abcdef12'
            for version in (current, 'replaced-unknown'):
                (root / version).mkdir()
                (root / version / 'swamp').write_text('fixture')
            self.run_retention(root, current, current, previous=False)
            self.assertTrue((root / 'replaced-unknown/swamp').exists())
            (root / 'previous').symlink_to(root / 'missing/swamp')
            self.run_retention(root, current, current)
            self.assertTrue((root / 'replaced-unknown/swamp').exists())

    def test_drained_start_removes_only_downloaded_bundles_and_keeps_identity(self):
        tasks = yaml.safe_load((ANSIBLE / 'tasks/swamp-housekeeping.yml').read_text())
        dropin = next(task['ansible.builtin.copy']['content'] for task in tasks
                      if task['name'] == 'Bound worker bundle storage at the drained start boundary')
        command = next(line.removeprefix('ExecStartPre=') for line in dropin.splitlines() if line.startswith('ExecStartPre='))
        with tempfile.TemporaryDirectory() as name:
            root = Path(name)
            worker = root / 'a'
            (worker / 'cache/bundles/fingerprint/files').mkdir(parents=True)
            (worker / 'cache/bundles/fingerprint/files/play.yml').write_text('old asset')
            (worker / 'cache/machine-id').write_text('stable-machine')
            (worker / 'config').mkdir()
            (worker / 'config/token').write_text('fixture')
            argv = shlex.split(command.replace('/var/lib/orbit-swamp-worker/%i', str(worker)))
            subprocess.run(argv, check=True)
            self.assertFalse((worker / 'cache/bundles').exists())
            self.assertEqual((worker / 'cache/machine-id').read_text(), 'stable-machine')
            self.assertTrue((worker / 'config/token').exists())
            subprocess.run(argv, check=True)  # An empty or new worker also starts.


if __name__ == '__main__':
    unittest.main()
