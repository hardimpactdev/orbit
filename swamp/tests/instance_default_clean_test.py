"""A default's clean after its deployment's checkout (swamp/ansible/instance_default_clean.py): untracked files that Git does not ignore
go, the `.env` and ignored files stay, tracked files are never touched, and it prints names only."""
import json
import os
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
SOURCE = ROOT / 'swamp/ansible/instance_default_clean.py'


def git(workspace, *args):
    return subprocess.run(['git', '-c', 'user.name=t', '-c', 'user.email=t@t', *args], cwd=workspace, check=True, capture_output=True, text=True)


class DefaultCleanTest(unittest.TestCase):
    def setUp(self):
        self.workspace = tempfile.mkdtemp()
        git(self.workspace, 'init', '--quiet')
        Path(self.workspace, '.gitignore').write_text('vendor/\n')
        Path(self.workspace, 'app.php').write_text('<?php\n')
        git(self.workspace, 'add', '.')
        git(self.workspace, 'commit', '--quiet', '-m', 'start')

    def clean(self):
        done = subprocess.run([sys.executable, '-I', '-c', SOURCE.read_text(), self.workspace, self.workspace + '/.env'],
                              cwd=self.workspace, capture_output=True, text=True, check=True, env=dict(os.environ, HOME=self.workspace))
        return json.loads(done.stdout)

    def test_untracked_files_go_and_the_env_ignored_and_tracked_files_stay(self):
        for name, text in {'.env': 'APP_KEY=x\n', 'vendor/kept.php': 'x', 'leftover.txt': 'y',
                           'stale/e2e/lib/a.php': 'z'}.items():
            Path(self.workspace, name).parent.mkdir(parents=True, exist_ok=True)
            Path(self.workspace, name).write_text(text)
        Path(self.workspace, 'app.php').write_text('<?php // edited\n')

        result = self.clean()

        self.assertEqual(result, {'removed_count': 2, 'removed': ['leftover.txt', 'stale/']})
        self.assertTrue(Path(self.workspace, '.env').is_file())
        self.assertTrue(Path(self.workspace, 'vendor/kept.php').is_file())
        self.assertEqual(Path(self.workspace, 'app.php').read_text(), '<?php // edited\n')
        self.assertFalse(Path(self.workspace, 'stale').exists())
        self.assertIn('/.env', Path(self.workspace, '.git/info/exclude').read_text().splitlines())

    def test_a_clean_tree_reports_nothing(self):
        self.assertEqual(self.clean(), {'removed_count': 0, 'removed': []})


if __name__ == '__main__':
    unittest.main()
