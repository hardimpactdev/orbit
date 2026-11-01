"""Exercise shipped setup scripts in a disposable tree without privileged effects."""
import os
import runpy
import subprocess
import tempfile
import unittest
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import patch


class SetupScriptsTest(unittest.TestCase):
    def setUp(self):
        temporary = tempfile.TemporaryDirectory(dir=os.environ.get('TMPDIR'))
        self.addCleanup(temporary.cleanup)
        self.root = Path(temporary.name)
        self.account = SimpleNamespace(pw_uid=os.getuid(), pw_gid=os.getgid(), pw_name='orbit', pw_dir=str(self.root / 'account'))

    def load(self, name):
        return runpy.run_path(str(Path(__file__).with_name('tool_scripts') / name))

    def path(self, value):
        return self.root / str(value).lstrip('/') if str(value).startswith(('/opt/', '/home/linuxbrew')) else Path(value)

    def test_composer_recovers_owned_partial_scope_and_preserves_existing_roots(self):
        script = self.load('prepare_composer.py')
        root = self.path('/opt/orbit/composer')
        original_stat = Path.stat
        def stat(path, **kwargs):
            value = original_stat(path, **kwargs)
            return os.stat_result((*value[:4], 0, *value[5:])) if path == root.parent else value
        with patch('pwd.getpwnam', return_value=self.account), patch('os.chown'), patch('subprocess.check_output', return_value='2.9.5'), patch.object(script['pathlib'], 'Path', self.path), patch.object(Path, 'stat', stat):
            self.assertTrue(script['prepare']({'user': 'orbit', 'version': '2.9.5'})['composer_ready'])
            (root / 'composer.json').write_text('{"require":{"owned/package":"*"}}')
            script['prepare']({'user': 'orbit', 'version': '2.9.5'})
            self.assertIn('owned/package', (root / 'composer.json').read_text())
            (root / 'composer.json').unlink()
            (root / 'composer.json').symlink_to(self.root / 'missing')
            with self.assertRaisesRegex(ValueError, 'scope_conflict'):
                script['prepare']({'user': 'orbit', 'version': '2.9.5'})

    def test_composer_pin_mismatch_refuses_before_scope_creation(self):
        script = self.load('prepare_composer.py')
        with patch('pwd.getpwnam', return_value=self.account), patch('subprocess.check_output', return_value='wrong'):
            with self.assertRaisesRegex(ValueError, 'version_mismatch'):
                script['prepare']({'user': 'orbit', 'version': '2.9.5'})
        self.assertFalse(self.path('/opt/orbit/composer').exists())

    def test_homebrew_failure_leaves_no_published_prefix_or_staging_directory(self):
        self.path('/home/linuxbrew').parent.mkdir()
        script = self.load('install_homebrew.py'); script['install'].__globals__['Path'] = self.path
        with patch('pwd.getpwnam', return_value=self.account), patch('os.chown'), patch('subprocess.run', side_effect=subprocess.CalledProcessError(1, 'git')):
            with self.assertRaises(subprocess.CalledProcessError):
                script['install']({'user': 'orbit', 'revision': 'a' * 40, 'version': '7.0.0'})
        self.assertEqual(list(self.path('/home/linuxbrew').iterdir()), [])

    def test_javascript_foreign_launcher_refuses_before_artifact_download(self):
        script = self.load('install_javascript.py'); namespace = script['install'].__globals__
        namespace['ROOT'] = self.root / 'opt'; namespace['BIN'] = self.root / 'bin'; namespace['BIN'].mkdir()
        (namespace['BIN'] / 'node').write_text('foreign launcher')
        with patch('pwd.getpwnam', return_value=self.account), patch('urllib.request.urlopen') as download:
            with self.assertRaisesRegex(ValueError, 'launcher_conflict'):
                script['install']({'user': 'orbit', 'tools': {}})
            download.assert_not_called()
        self.assertEqual((namespace['BIN'] / 'node').read_text(), 'foreign launcher')


if __name__ == '__main__':
    unittest.main()
