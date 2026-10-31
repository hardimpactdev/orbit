"""Local public pin file publication: no network or worker effects."""
import importlib.util
import os
import runpy
import stat
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

SCRIPT = Path(__file__).resolve().parents[1] / 'extensions/behaviors/operations/node-worker-assets/scripts/write_known_hosts.py'
spec = importlib.util.spec_from_file_location('known_hosts_writer', SCRIPT)
writer = importlib.util.module_from_spec(spec)
spec.loader.exec_module(writer)


class KnownHosts(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.home = Path(self.temp.name)
        self.file = self.home / '.ssh/known_hosts'
        self.content = '10.44.0.9 ssh-ed25519 public-fixture-key\n'

    def test_atomic_private_mode_and_idempotent_repeat(self):
        self.assertTrue(writer.publish(self.home, self.content)['changed'])
        self.assertEqual(self.file.read_text(), self.content)
        self.assertEqual(stat.S_IMODE(self.file.stat().st_mode), 0o600)
        self.assertEqual(stat.S_IMODE(self.file.parent.stat().st_mode), 0o700)
        before = self.file.stat().st_mtime_ns
        self.assertFalse(writer.publish(self.home, self.content)['changed'])
        self.assertEqual(self.file.stat().st_mtime_ns, before)

    def test_failed_replace_preserves_existing_file_and_cleans_staging(self):
        writer.publish(self.home, 'previous\n')
        with patch.object(writer.os, 'replace', side_effect=OSError('fixture refusal')), self.assertRaises(OSError):
            writer.publish(self.home, self.content)
        self.assertEqual(self.file.read_text(), 'previous\n')
        self.assertEqual(list(self.file.parent.iterdir()), [self.file])

    def test_replaces_symlink_without_writing_referent(self):
        referent = self.home / 'unrelated'
        referent.write_text('preserved')
        self.file.parent.mkdir()
        self.file.symlink_to(referent)
        writer.publish(self.home, self.content)
        self.assertFalse(self.file.is_symlink())
        self.assertEqual(referent.read_text(), 'preserved')
        self.assertEqual(self.file.read_text(), self.content)

    def test_main_uses_account_home_instead_of_worker_home_override(self):
        account = type('Account', (), {'pw_dir': str(self.home)})()
        with patch('pwd.getpwuid', return_value=account), patch.dict(os.environ, {
            'HOME': str(self.home / 'wrong-home'), 'ORBIT_KNOWN_HOSTS_CONTENT': self.content,
        }), patch('builtins.print'):
            runpy.run_path(str(SCRIPT), run_name='__main__')
        self.assertEqual(self.file.read_text(), self.content)
        self.assertFalse((self.home / 'wrong-home').exists())


if __name__ == '__main__':
    unittest.main()
