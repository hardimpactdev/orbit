"""The Phpactor symbol index of a Laravel default before its seed (swamp/ansible/instance_php_index.py): Phpactor finds its index by
itself, an unchanged tree starts no Phpactor, a changed one runs Phpactor's incremental build again, a fingerprint stays pending until
the seed holds the index, a failed or late build fails with its reason and records nothing, and a runtime without phpactor skips."""
import json
import os
import shutil
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
        # The runtime folder as the recipe assembles it: the `phpactor` part and its `bin/` link, with the Project's PHP on PATH.
        self.runtime = tempfile.mkdtemp()
        self.bin = os.path.join(self.runtime, 'bin')
        os.makedirs(self.bin)
        os.makedirs(os.path.join(self.runtime, 'phpactor'))
        os.makedirs(os.path.join(self.app, 'app/Models'))
        os.makedirs(os.path.join(self.app, '.git'))
        Path(self.app, 'artisan').write_text('<?php\n')
        Path(self.app, 'app/Models/User.php').write_text('<?php class User {}\n')
        phpactor = Path(self.runtime, 'phpactor/phpactor.phar')
        phpactor.write_text(PHPACTOR)
        phpactor.chmod(0o755)
        os.symlink('../phpactor/phpactor.phar', os.path.join(self.bin, 'phpactor'))
        # PATH holds only the runtime's bin/ and the tools the program and the stand-in run, so a `php` of the host (a CI runner has
        # /usr/bin/php) never stands in for the Project's.
        self.tools = tempfile.mkdtemp()
        for tool in ('timeout', 'nice', 'ionice', 'mkdir', 'date', 'sleep'):
            os.symlink(shutil.which(tool), os.path.join(self.tools, tool))
        self.path = f"{self.bin}:{self.tools}"
        os.symlink(sys.executable, os.path.join(self.bin, 'php'))

    def run_index(self, seconds=60, **environment):
        # As the phase runs it: a clean environment with the deploy steps' HOME and PATH, at the application's folder.
        env = {'HOME': self.home, 'PATH': self.path, **environment}
        return subprocess.run([sys.executable, '-I', '-c', PROGRAM.read_text(), str(seconds), self.runtime], cwd=self.app, env=env,
                              capture_output=True, text=True, check=False, timeout=30)

    def seeded(self):
        # The seed phase, once its snapshot is taken.
        env = {'HOME': self.home, 'PATH': self.path}
        subprocess.run([sys.executable, '-I', '-c', PROGRAM.read_text(), 'seeded'], cwd=self.app, env=env, check=True, timeout=30)

    def run_and_seed(self, **environment):
        result = self.run_index(**environment)
        self.seeded()
        return result

    def calls(self):
        path = Path(self.home, 'calls')
        return path.read_text().splitlines() if path.exists() else []

    def test_the_first_build_runs_phpactor_at_the_application_folder_with_no_index_setting_of_its_own(self):
        result = self.run_index()

        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(json.loads(result.stdout)['index'], 'updated')
        # Phpactor's own discovery: the working folder is the project root, and no option names an index path or a config.
        self.assertEqual(self.calls(), [f"{self.app} index:build --no-interaction --no-ansi"])
        self.assertTrue(Path(self.home, '.cache/orbit-php-index.pending.json').exists())
        # Nothing lands in the Project's tree.
        self.assertEqual(sorted(os.listdir(self.app)), ['.git', 'app', 'artisan'])

    def test_an_unchanged_tree_starts_no_phpactor_on_a_repeat_refresh(self):
        self.run_and_seed()
        result = self.run_index()

        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(json.loads(result.stdout), {'index': 'unchanged'})
        self.assertEqual(len(self.calls()), 1)

    def test_a_fingerprint_whose_seed_was_not_taken_stays_pending_and_the_retry_builds_again(self):
        # The index built, then the snapshot failed: the seed phase never said `seeded`.
        self.assertEqual(json.loads(self.run_index().stdout)['index'], 'updated')
        self.assertFalse(Path(self.home, '.cache/orbit-php-index.json').exists())

        # The retry reports a changed index, so its seed phase retakes the seed; once taken, the next run is unchanged.
        self.assertEqual(json.loads(self.run_and_seed().stdout)['index'], 'updated')
        self.assertEqual(len(self.calls()), 2)
        self.assertFalse(Path(self.home, '.cache/orbit-php-index.pending.json').exists())
        self.assertEqual(json.loads(self.run_index().stdout), {'index': 'unchanged'})

    def test_a_changed_added_or_removed_php_file_runs_the_incremental_build_again_without_a_reset(self):
        self.run_and_seed()
        for change in (lambda: Path(self.app, 'app/Models/User.php').write_text('<?php class User { public $id; }\n'),
                       lambda: Path(self.app, 'app/Models/Post.php').write_text('<?php class Post {}\n'),
                       lambda: os.remove(os.path.join(self.app, 'app/Models/Post.php'))):
            time.sleep(0.01)
            change()
            result = self.run_and_seed()
            self.assertEqual(json.loads(result.stdout)['index'], 'updated', result.stderr)

        self.assertEqual(len(self.calls()), 4)
        self.assertTrue(all('--reset' not in call for call in self.calls()))

    def test_a_file_that_phpactor_does_not_read_and_the_git_folder_leave_the_index_alone(self):
        self.run_and_seed()
        time.sleep(0.01)
        Path(self.app, 'app/Models/notes.md').write_text('notes\n')
        Path(self.app, '.git/HEAD').write_text('ref: refs/heads/main\n')
        # A new file changes its folder's ctime: the folder counts, so this one run builds once more, then the tree is settled.
        self.run_and_seed()
        result = self.run_index()

        self.assertEqual(json.loads(result.stdout), {'index': 'unchanged'})

    def test_a_removed_index_is_built_again_though_the_tree_did_not_change(self):
        self.run_and_seed()
        os.remove(os.path.join(self.home, '.cache/phpactor/index/app-123456/timestamp.v3'))
        result = self.run_index()

        self.assertEqual(json.loads(result.stdout)['index'], 'updated')
        self.assertEqual(len(self.calls()), 2)

    def test_an_application_without_vendor_is_indexed_from_its_source_and_a_later_composer_install_reindexes(self):
        # As a default whose deploy steps never ran Composer: no vendor/, no autoloader. Nothing requires them.
        self.assertFalse(os.path.exists(os.path.join(self.app, 'vendor')))
        self.assertEqual(json.loads(self.run_and_seed().stdout)['index'], 'updated')
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
        self.assertFalse(Path(self.home, '.cache/orbit-php-index.pending.json').exists())
        # The next run tries again.
        self.assertEqual(json.loads(self.run_index().stdout)['index'], 'updated')

    def test_a_build_past_its_bound_is_stopped_and_fails(self):
        started = time.monotonic()
        result = self.run_index(seconds=1, SLEEP='20')

        self.assertEqual(result.returncode, 1)
        self.assertIn('Phpactor did not finish its index within 1 s', result.stderr)
        self.assertLess(time.monotonic() - started, 15)
        self.assertFalse(Path(self.home, '.cache/orbit-php-index.json').exists())
        self.assertFalse(Path(self.home, '.cache/orbit-php-index.pending.json').exists())

    def test_a_runtime_that_predates_phpactor_skips_with_its_reason_and_records_nothing(self):
        # A Node not converged since the release: neither the part nor its link. The deployment goes on, and the skip says why.
        os.remove(os.path.join(self.bin, 'phpactor'))
        shutil.rmtree(os.path.join(self.runtime, 'phpactor'))
        result = self.run_index()

        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(json.loads(result.stdout), {'index': 'skipped', 'reason': "The Node's runtime folder has no phpactor yet: converge the Node."})
        self.assertFalse(os.path.exists(os.path.join(self.home, '.cache')))

    def test_a_converged_runtime_whose_phpactor_is_not_executable_or_missing_fails(self):
        program = os.path.join(self.bin, 'phpactor')
        os.chmod(os.path.join(self.runtime, 'phpactor/phpactor.phar'), 0o644)
        result = self.run_index()
        self.assertEqual(result.returncode, 1, result.stdout)
        self.assertIn(f'The runtime folder ships phpactor, but {program} is missing or not executable', result.stderr)

        # The part is there and its link is gone.
        os.remove(program)
        result = self.run_index()
        self.assertEqual(result.returncode, 1, result.stdout)
        self.assertIn('is missing or not executable', result.stderr)
        self.assertEqual(self.calls(), [])
        self.assertFalse(os.path.exists(os.path.join(self.home, '.cache')))

    def test_a_missing_php_fails(self):
        os.remove(os.path.join(self.bin, 'php'))
        result = self.run_index()

        self.assertEqual(result.returncode, 1)
        self.assertIn('php must be on PATH', result.stderr)

    def test_a_missing_php_fails_also_on_a_runtime_that_predates_phpactor(self):
        os.remove(os.path.join(self.bin, 'php'))
        os.remove(os.path.join(self.bin, 'phpactor'))
        shutil.rmtree(os.path.join(self.runtime, 'phpactor'))
        result = self.run_index()

        self.assertEqual(result.returncode, 1, result.stdout)
        self.assertIn('php must be on PATH', result.stderr)


if __name__ == '__main__':
    unittest.main()
