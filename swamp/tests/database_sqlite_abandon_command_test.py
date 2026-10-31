"""Root custody checks run only in an explicit disposable container."""
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

ROOT = Path(__file__).resolve().parents[1]
SCRIPT = ROOT / 'extensions/behaviors/operations/database/sqlite-cleanup/scripts/target_abandon.py'


@unittest.skipUnless(os.geteuid() == 0 and os.environ.get('ORBIT_DISPOSABLE_SQLITE_TEST') == '1',
                     'Root scratch fixtures require an explicit disposable container.')
class TargetAbandon(unittest.TestCase):
    def setUp(self):
        self.identity = uuid.uuid4().hex * 2
        self.user = pwd.getpwnam('swamp')
        self.home = Path(tempfile.mkdtemp(prefix='sqlite-abandon-target-'))
        self.addCleanup(lambda: shutil.rmtree(self.home, ignore_errors=True))
        self.destination = self.home / 'database.sqlite'
        self.destination.write_text('installed database')
        self.directory = Path('/var/lib/orbit/app-instance-sqlite-seeds/target-42-' + self.identity)
        self.directory.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
        self.directory.mkdir(mode=0o700)
        self.addCleanup(lambda: shutil.rmtree(self.directory, ignore_errors=True))
        self.incoming = Path('/tmp/orbit-sqlite-' + self.identity[:16] + '-' + uuid.uuid4().hex[:16])
        self.candidate = self.home / ('.database.sqlite.orbit-' + uuid.uuid4().hex[:16])
        self.addCleanup(lambda: self.incoming.unlink(missing_ok=True))
        for selected in (self.incoming, self.candidate):
            selected.write_text('attempt scratch')
            selected.chmod(0o600)
            os.chown(selected, self.user.pw_uid, self.user.pw_gid)
        self.state = {'operation': self.identity, 'destination': str(self.destination),
                      'incoming': str(self.incoming), 'incoming_inode': self.incoming.stat().st_ino,
                      'candidate': str(self.candidate), 'candidate_inode': self.candidate.stat().st_ino}
        self.save()
        self.env = {'ORBIT_CLEANUP_OPERATION': self.identity, 'ORBIT_CLEANUP_TARGET': '42',
                    'ORBIT_CLEANUP_BASE': str(self.home), 'ORBIT_CLEANUP_RELATIVE': 'database.sqlite',
                    'ORBIT_CLEANUP_OWNER': 'swamp', 'ORBIT_CLEANUP_TRANSPORT': 'swamp',
                    'ORBIT_CLEANUP_ENVIRONMENT': 'development', 'ORBIT_CLEANUP_STATE': str(self.directory)}

    def save(self):
        selected = self.directory / 'state.json'
        selected.write_text(json.dumps(self.state))
        selected.chmod(0o600)

    def run_cleanup(self):
        result = subprocess.run([sys.executable, '-B', '-E', '-s', str(SCRIPT)], check=False,
                                env=self.env, text=True, capture_output=True, timeout=10)
        self.assertEqual(result.stderr, '')
        return result.returncode, json.loads(result.stdout)

    def test_only_attempt_scratch_is_removed_and_destination_survives(self):
        self.assertEqual(self.run_cleanup(), (0, {'backend': 'absent'}))
        self.assertFalse(self.incoming.exists())
        self.assertFalse(self.candidate.exists())
        self.assertEqual(self.destination.read_text(), 'installed database')
        self.assertEqual(self.run_cleanup(), (0, {'backend': 'absent'}))

    def test_replaced_incoming_refuses_before_removing_the_candidate(self):
        self.state['incoming_inode'] += 1
        self.save()
        self.assertEqual(self.run_cleanup(), (1, {'error_code': 'instance.sqlite_seed_refused'}))
        self.assertTrue(self.candidate.exists())
        self.assertTrue(self.incoming.exists())

    def test_installed_candidate_hardlink_is_unlinked_without_removing_database(self):
        self.destination.unlink()
        os.link(self.candidate, self.destination)
        self.assertEqual(self.run_cleanup(), (0, {'backend': 'absent'}))
        self.assertEqual(self.destination.read_text(), 'attempt scratch')

    def test_foreign_attempt_or_target_is_untouched(self):
        for field in ('operation', 'destination'):
            with self.subTest(field=field):
                saved = self.state[field]
                self.state[field] = 'foreign'
                self.save()
                self.assertNotEqual(self.run_cleanup()[0], 0)
                self.assertTrue(self.candidate.exists())
                self.assertTrue(self.incoming.exists())
                self.state[field] = saved

    def test_symlink_scratch_or_state_is_refused(self):
        self.candidate.unlink()
        self.candidate.symlink_to(self.destination)
        self.assertNotEqual(self.run_cleanup()[0], 0)
        self.assertEqual(self.destination.read_text(), 'installed database')
        self.candidate.unlink()
        self.state.pop('candidate')
        self.state.pop('candidate_inode')
        self.save()
        (self.directory / 'state.json').chmod(0o644)
        self.assertNotEqual(self.run_cleanup()[0], 0)
        self.assertTrue(self.incoming.exists())

    def test_absent_parent_is_already_clean_without_creating_directories(self):
        shutil.rmtree(self.directory)
        self.env['ORBIT_CLEANUP_BASE'] = str(self.home / 'missing' / 'nested')
        self.assertEqual(self.run_cleanup(), (0, {'backend': 'absent'}))
        self.assertFalse((self.home / 'missing').exists())


if __name__ == '__main__':
    unittest.main()
