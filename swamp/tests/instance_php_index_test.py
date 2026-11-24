"""The Phpactor symbol index of a Laravel default before its seed (swamp/ansible/instance_php_index.py): Phpactor finds its index by
itself, an unchanged tree starts no Phpactor, a changed one runs Phpactor's incremental build again, and a failed or late build fails
with its reason and records nothing."""
import json
import os
import subprocess
import sys
import tempfile
import time
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
PROGRAM = ROOT / 'swamp/ansible/instance_php_index.py'

# A stand-in for the runtime folder's `phpactor`: it records its arguments and working folder, and writes the index's timestamp file
# where Phpactor keeps it, under the home's cache. FAIL makes it fail; SLEEP makes it outlast the bound.
PHPACTOR = """#!/bin/sh
printf '%s %s\\n' "$(pwd)" "$*" >> "$HOME/calls"
[ -n "${FAIL:-}" ] && { echo "Phpactor: cannot parse vendor/broken.php"; exit 3; }
[ -n "${SLEEP:-}" ] && sleep "$SLEEP"
mkdir -p "$HOME/.cache/phpactor/index/app-123456"
date +%s > "$HOME/.cache/phpactor/index/app-123456/timestamp.v3"
echo Done
"""


class PhpIndexTest(unittest.TestCase):
    def setUp(self):
        self.home = tempfile.mkdtemp()
        self.app = os.path.join(tempfile.mkdtemp(), 'app')
        self.bin = tempfile.mkdtemp()
        os.makedirs(os.path.join(self.app, 'app/Models'))
        os.makedirs(os.path.join(self.app, '.git'))
        Path(self.app, 'artisan').write_text('<?php\n')
        Path(self.app, 'app/Models/User.php').write_text('<?php class User {}\n')
        phpactor = Path(self.bin, 'phpactor')
        phpactor.write_text(PHPACTOR)
        phpactor.chmod(0o755)
        os.symlink(sys.executable, os.path.join(self.bin, 'php'))

    def run_index(self, seconds=60, **environment):
        # As the phase runs it: a clean environment with the deploy steps' HOME and PATH, at the application's folder.
        env = {'HOME': self.home, 'PATH': f"{self.bin}:/usr/bin:/bin", **environment}
        return subprocess.run([sys.executable, '-I', '-c', PROGRAM.read_text(), str(seconds)], cwd=self.app, env=env,
                              capture_output=True, text=True, check=False, timeout=30)

    def calls(self):
        path = Path(self.home, 'calls')
        return path.read_text().splitlines() if path.exists() else []

    def test_the_first_build_runs_phpactor_at_the_application_folder_with_no_index_setting_of_its_own(self):
        result = self.run_index()

        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(json.loads(result.stdout)['index'], 'updated')
        # Phpactor's own discovery: the working folder is the project root, and no option names an index path or a config.
        self.assertEqual(self.calls(), [f"{self.app} index:build --no-interaction --no-ansi"])
        self.assertTrue(Path(self.home, '.cache/orbit-php-index.json').exists())
        # Nothing lands in the Project's tree.
        self.assertEqual(sorted(os.listdir(self.app)), ['.git', 'app', 'artisan'])

    def test_an_unchanged_tree_starts_no_phpactor_on_a_repeat_refresh(self):
        self.run_index()
        result = self.run_index()

        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(json.loads(result.stdout), {'index': 'unchanged'})
        self.assertEqual(len(self.calls()), 1)

    def test_a_changed_added_or_removed_php_file_runs_the_incremental_build_again_without_a_reset(self):
        self.run_index()
        for change in (lambda: Path(self.app, 'app/Models/User.php').write_text('<?php class User { public $id; }\n'),
                       lambda: Path(self.app, 'app/Models/Post.php').write_text('<?php class Post {}\n'),
                       lambda: os.remove(os.path.join(self.app, 'app/Models/Post.php'))):
            time.sleep(0.01)
            change()
            result = self.run_index()
            self.assertEqual(json.loads(result.stdout)['index'], 'updated', result.stderr)

        self.assertEqual(len(self.calls()), 4)
        self.assertTrue(all('--reset' not in call for call in self.calls()))

    def test_a_file_that_phpactor_does_not_read_and_the_git_folder_leave_the_index_alone(self):
        self.run_index()
        time.sleep(0.01)
        Path(self.app, 'app/Models/notes.md').write_text('notes\n')
        Path(self.app, '.git/HEAD').write_text('ref: refs/heads/main\n')
        # A new file changes its folder's ctime: the folder counts, so this one run builds once more, then the tree is settled.
        self.run_index()
        result = self.run_index()

        self.assertEqual(json.loads(result.stdout), {'index': 'unchanged'})

    def test_a_removed_index_is_built_again_though_the_tree_did_not_change(self):
        self.run_index()
        os.remove(os.path.join(self.home, '.cache/phpactor/index/app-123456/timestamp.v3'))
        result = self.run_index()

        self.assertEqual(json.loads(result.stdout)['index'], 'updated')
        self.assertEqual(len(self.calls()), 2)

    def test_an_application_without_vendor_is_indexed_from_its_source_and_a_later_composer_install_reindexes(self):
        # As a default whose deploy steps never ran Composer: no vendor/, no autoloader. Nothing requires them.
        self.assertFalse(os.path.exists(os.path.join(self.app, 'vendor')))
        self.assertEqual(json.loads(self.run_index().stdout)['index'], 'updated')
        time.sleep(0.01)
        os.makedirs(os.path.join(self.app, 'vendor/laravel/framework/src'))
        Path(self.app, 'vendor/autoload.php').write_text('<?php\n')
        Path(self.app, 'vendor/laravel/framework/src/Application.php').write_text('<?php class Application {}\n')

        self.assertEqual(json.loads(self.run_index().stdout)['index'], 'updated')
        self.assertEqual(len(self.calls()), 2)
        self.assertTrue(all('--reset' not in call for call in self.calls()))

    def test_a_failed_build_fails_with_phpactor_s_reason_and_records_nothing(self):
        result = self.run_index(FAIL='1')

        self.assertEqual(result.returncode, 1)
        self.assertIn('phpactor index:build exited 3: Phpactor: cannot parse vendor/broken.php', result.stderr)
        self.assertFalse(Path(self.home, '.cache/orbit-php-index.json').exists())
        # The next run tries again.
        self.assertEqual(json.loads(self.run_index().stdout)['index'], 'updated')

    def test_a_build_past_its_bound_is_stopped_and_fails(self):
        started = time.monotonic()
        result = self.run_index(seconds=1, SLEEP='20')

        self.assertEqual(result.returncode, 1)
        self.assertIn('Phpactor did not finish its index within 1 s', result.stderr)
        self.assertLess(time.monotonic() - started, 15)
        self.assertFalse(Path(self.home, '.cache/orbit-php-index.json').exists())

    def test_a_missing_phpactor_fails(self):
        os.remove(os.path.join(self.bin, 'phpactor'))
        result = self.run_index()

        self.assertEqual(result.returncode, 1)
        self.assertIn('phpactor and php must be on PATH', result.stderr)


if __name__ == '__main__':
    unittest.main()
