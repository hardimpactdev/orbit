"""Execute the actual inline cleanup actions against disposable filesystem state."""
import json
import os
import pwd
import shutil
import subprocess
import sys
import tempfile
import unittest
import uuid
from pathlib import Path

import yaml

ROOT = Path(__file__).resolve().parents[1]
WORKFLOW = yaml.safe_load((ROOT / 'workflows/workflow-operation-database-cleanup.yaml').read_text())
SCRIPTS = {step['name']: step['task']['inputs']['run'] for step in WORKFLOW['jobs'][0]['steps'] if step['task'].get('behaviorType') == 'command/shell'}


def run_action(name, env, user=None):
    result = subprocess.run(['/bin/sh'], check=False, input=SCRIPTS[name], env={'PATH': '/usr/bin:/bin', **env},
                            user=user, text=True, capture_output=True, timeout=15)
    if result.stderr:
        raise AssertionError(result.stderr)
    return result.returncode, json.loads(result.stdout)


class CloneCleanup(unittest.TestCase):
    def setUp(self):
        self.user = pwd.getpwnam('swamp') if os.geteuid() == 0 else pwd.getpwuid(os.geteuid())
        self.directory = Path('/tmp/orbit-sqlite-clone-' + uuid.uuid4().hex)
        self.directory.mkdir(mode=0o700)
        self.snapshot = self.directory / 'snapshot.sqlite'
        self.snapshot.write_text('private-disposable-snapshot')
        self.snapshot.chmod(0o600)
        if os.geteuid() == 0:
            for path in (self.directory, self.snapshot):
                os.chown(path, self.user.pw_uid, self.user.pw_gid)
        self.addCleanup(lambda: self.directory.unlink() if self.directory.is_symlink()
                        else shutil.rmtree(self.directory, ignore_errors=True))

    def run_cleanup(self, directory=None):
        return run_action('clone', {'ORBIT_CLEANUP_DIRECTORY': str(directory or self.directory)},
                          user=self.user.pw_uid)

    def test_owned_snapshot_and_missing_directory_are_clean(self):
        self.assertEqual(self.run_cleanup(), (0, {'backend': 'absent'}))
        self.assertFalse(self.directory.exists())
        self.assertEqual(self.run_cleanup(), (0, {'backend': 'absent'}))

    def test_public_directory_is_untouched(self):
        self.directory.chmod(0o755)
        self.assertEqual(self.run_cleanup(), (1, {'error_code': 'database.cleanup_owner_unavailable'}))
        self.assertTrue(self.snapshot.exists())

    def test_directory_symlink_is_refused(self):
        shutil.rmtree(self.directory)
        self.directory.symlink_to('/tmp', target_is_directory=True)
        self.assertEqual(self.run_cleanup(), (1, {'error_code': 'database.cleanup_owner_unavailable'}))

    def test_unrelated_contents_are_refused_before_any_unlink(self):
        (self.directory / 'keep').write_text('keep')
        self.assertEqual(self.run_cleanup(), (1, {'error_code': 'database.sqlite_cleanup_refused'}))
        self.assertTrue(self.snapshot.exists())

    def test_snapshot_symlink_or_hardlink_is_refused(self):
        foreign = self.directory / 'foreign'
        self.snapshot.rename(foreign)
        self.snapshot.symlink_to(foreign)
        self.assertNotEqual(self.run_cleanup()[0], 0)
        self.assertEqual(foreign.read_text(), 'private-disposable-snapshot')
        self.snapshot.unlink()
        foreign.rename(self.snapshot)
        link = Path(tempfile.mktemp(prefix='cleanup-hardlink-'))
        os.link(self.snapshot, link)
        self.addCleanup(link.unlink)
        self.assertNotEqual(self.run_cleanup()[0], 0)
        self.assertTrue(self.snapshot.exists())

    def test_parent_traversal_is_refused(self):
        self.assertNotEqual(self.run_cleanup('/tmp/orbit-sqlite-clone-../foreign')[0], 0)
        self.assertTrue(self.snapshot.exists())

    def test_result_step_cannot_hide_missing_or_unbounded_outcomes(self):
        for value in ['', '{}', '{"backend":"absent","private":"not-public"}', 'private-diagnostic']:
            self.assertEqual(run_action('result', {'ORBIT_CLEANUP_RESULT': value}),
                             (1, {'error_code': 'database.sqlite_cleanup_failed'}))
        self.assertEqual(run_action('result', {'ORBIT_CLEANUP_RESULT': '{"backend":"absent"}'}),
                         (0, {'backend': 'absent'}))

    def test_all_shell_bodies_pass_shellcheck(self):
        if shutil.which('shellcheck') is None:
            self.skipTest('Shellcheck runs on the host.')
        for script in SCRIPTS.values():
            import re
            script = re.sub(r'\$\{\{.*?\}\}', '/native-cache/script.py', script)
            result = subprocess.run(['shellcheck', '-s', 'sh', '-'], check=False, input=script,
                                    text=True, capture_output=True, timeout=10)
            self.assertEqual(result.returncode, 0, result.stdout + result.stderr)


@unittest.skipUnless(os.geteuid() == 0 and os.environ.get('ORBIT_DISPOSABLE_SQLITE_TEST') == '1', 'Root seed custody runs only in a disposable container.')
class SeedCleanup(unittest.TestCase):
    def setUp(self):
        self.operation = uuid.uuid4().hex * 2
        self.user = pwd.getpwnam('swamp')
        self.directory = Path('/var/lib/orbit/app-instance-sqlite-seeds/source-21-target-42-' + self.operation)
        self.directory.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
        self.directory.mkdir(mode=0o700)
        self.snapshot = Path('/tmp/orbit-sqlite-' + self.operation + '.sqlite')
        self.snapshot.write_text('private-disposable-seed')
        self.snapshot.chmod(0o600)
        os.chown(self.snapshot, self.user.pw_uid, self.user.pw_gid)
        self.state = {'operation': self.operation, 'base': '/home/swamp/app',
                      'source_path': '/home/swamp/app/db.sqlite', 'snapshot_path': str(self.snapshot),
                      'inode': self.snapshot.stat().st_ino}
        self.save()
        self.env = dict(zip(['ORBIT_CLEANUP_OPERATION', 'ORBIT_CLEANUP_TARGET', 'ORBIT_CLEANUP_BASE',
                             'ORBIT_CLEANUP_SOURCE', 'ORBIT_CLEANUP_SNAPSHOT', 'ORBIT_CLEANUP_STATE',
                             'ORBIT_CLEANUP_OWNER', 'ORBIT_CLEANUP_TRANSPORT'],
                            [self.operation, '42', self.state['base'], self.state['source_path'],
                             str(self.snapshot), str(self.directory), 'swamp', 'swamp']))
        self.addCleanup(lambda: shutil.rmtree(self.directory, ignore_errors=True))
        self.addCleanup(lambda: self.snapshot.unlink(missing_ok=True))

    def save(self):
        selected = self.directory / 'state.json'
        selected.write_text(json.dumps(self.state))
        selected.chmod(0o600)

    def run_cleanup(self):
        script = ROOT / 'extensions/behaviors/operations/database/sqlite-cleanup/scripts/source_cleanup.py'
        result = subprocess.run([sys.executable, '-E', '-s', str(script)], check=False, env={'PATH': '/usr/bin:/bin', **self.env},
                                text=True, capture_output=True, timeout=10)
        self.assertEqual(result.stderr, '')
        return result.returncode, json.loads(result.stdout)

    def test_bound_seed_scratch_is_removed_and_absence_converges(self):
        self.assertEqual(self.run_cleanup(), (0, {'backend': 'absent'}))
        self.assertFalse(self.snapshot.exists())
        self.assertFalse(self.directory.exists())
        self.assertEqual(self.run_cleanup(), (0, {'backend': 'absent'}))

    def test_foreign_identity_is_untouched(self):
        self.state['operation'] = 'f' * 64
        self.save()
        self.assertNotEqual(self.run_cleanup()[0], 0)
        self.assertTrue(self.snapshot.exists())

    def test_replaced_snapshot_is_untouched(self):
        self.state['inode'] += 1
        self.save()
        self.assertNotEqual(self.run_cleanup()[0], 0)
        self.assertTrue(self.snapshot.exists())

    def test_public_or_symlink_state_is_untouched(self):
        (self.directory / 'state.json').chmod(0o644)
        self.assertNotEqual(self.run_cleanup()[0], 0)
        self.assertTrue(self.snapshot.exists())
        (self.directory / 'state.json').unlink()
        (self.directory / 'state.json').symlink_to(self.snapshot)
        self.assertNotEqual(self.run_cleanup()[0], 0)
        self.assertTrue(self.snapshot.exists())

    def test_missing_state_cannot_remove_unbound_snapshot(self):
        shutil.rmtree(self.directory)
        self.assertNotEqual(self.run_cleanup()[0], 0)
        self.assertTrue(self.snapshot.exists())

    def test_target_and_source_paths_are_bound(self):
        for field, value in [('ORBIT_CLEANUP_TARGET', '43'), ('ORBIT_CLEANUP_SOURCE', '/home/foreign/db.sqlite'),
                             ('ORBIT_CLEANUP_SNAPSHOT', '/tmp/foreign.sqlite'), ('ORBIT_CLEANUP_OWNER', 'root')]:
            with self.subTest(field=field):
                saved = self.env[field]
                self.env[field] = value
                self.assertNotEqual(self.run_cleanup()[0], 0)
                self.env[field] = saved
                self.assertTrue(self.snapshot.exists())


if __name__ == '__main__':
    unittest.main()
