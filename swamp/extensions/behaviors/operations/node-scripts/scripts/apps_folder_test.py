"""apps_folder.py keeps the legacy node.prepare-storage-root contract."""
import importlib.util
import os
import tempfile
import unittest
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import patch

spec = importlib.util.spec_from_file_location('apps_folder', Path(__file__).with_name('apps_folder.py'))
folder = importlib.util.module_from_spec(spec)
spec.loader.exec_module(folder)


class AppsFolder(unittest.TestCase):
    def setUp(self):
        self.base = os.path.realpath(tempfile.mkdtemp())
        self.home = self.base + '/home'
        os.mkdir(self.home, 0o755)
        account = SimpleNamespace(pw_dir=self.home, pw_uid=os.getuid(), pw_gid=os.getgid())
        self.patches = [patch.object(folder.pwd, 'getpwnam', return_value=account),
                        patch.object(folder.grp, 'getgrgid', return_value=SimpleNamespace(gr_name='orbit'))]
        for p in self.patches:
            p.start()

    def tearDown(self):
        for p in self.patches:
            p.stop()

    def run_action(self, action, root, create_missing=True):
        folder.main(action, f'{{"root": {"null" if root is None else repr(root).replace(chr(39), chr(34))}, "user": "orbit", "create_missing": {str(create_missing).lower()}}}')

    def test_creates_missing_parts_0755_and_leaves_existing_folders(self):
        os.mkdir(self.base + '/srv', 0o750)
        self.run_action('create', self.base + '/srv/apps/one')
        self.run_action('check', self.base + '/srv/apps/one')
        self.assertEqual(os.stat(self.base + '/srv/apps/one').st_mode & 0o777, 0o755)
        self.assertEqual(os.stat(self.base + '/srv').st_mode & 0o777, 0o750)

    def test_defaults_to_home_apps(self):
        self.run_action('create', None)
        self.assertTrue(os.path.isdir(self.home + '/apps'))

    def test_refuses_without_create_missing(self):
        with self.assertRaises(SystemExit):
            self.run_action('create', self.base + '/missing', create_missing=False)
        self.assertFalse(os.path.exists(self.base + '/missing'))

    def test_refuses_a_symlinked_part_and_writes_nothing(self):
        os.mkdir(self.base + '/real')
        os.symlink(self.base + '/real', self.base + '/link')
        with self.assertRaises(SystemExit):
            self.run_action('create', self.base + '/link/apps')
        self.assertFalse(os.path.exists(self.base + '/real/apps'))

    def test_refuses_home_its_ancestors_hidden_folders_and_unnormalized_paths(self):
        for root in (self.home, self.base, self.home + '/.cache/apps', self.base + '/a/../b', self.base + '/a/'):
            with self.assertRaises(SystemExit, msg=root):
                self.run_action('create', root)

    def test_check_refuses_a_folder_others_may_write(self):
        os.mkdir(self.base + '/open')
        os.chmod(self.base + '/open', 0o775)
        with self.assertRaises(SystemExit):
            self.run_action('check', self.base + '/open')


if __name__ == '__main__':
    unittest.main()
