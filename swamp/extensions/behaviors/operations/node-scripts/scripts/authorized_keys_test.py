"""authorized_keys.py keeps every line outside Orbit's block (the Nov 1 lockout)."""
import importlib.util
import os
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

spec = importlib.util.spec_from_file_location('authorized_keys', Path(__file__).with_name('authorized_keys.py'))
keys = importlib.util.module_from_spec(spec)
spec.loader.exec_module(keys)
OPERATORS = ['ssh-ed25519 AAAA nick@mini', '# a comment', 'ssh-rsa BBBB ops@laptop']


class AuthorizedKeys(unittest.TestCase):
    def setUp(self):
        self.home = Path(tempfile.mkdtemp())
        (self.home / '.ssh').mkdir(mode=0o700)
        self.file = self.home / '.ssh/authorized_keys'
        patcher = patch.object(keys.Path, 'home', return_value=self.home)
        patcher.start()
        self.addCleanup(patcher.stop)

    def run_write(self, block):
        return keys.write(self.file, block)

    def test_appends_the_block_and_keeps_operator_keys_in_place(self):
        self.file.write_text('\n'.join(OPERATORS) + '\n')
        os.chmod(self.file, 0o600)
        result = self.run_write(['ssh-ed25519 SWAMP swamp'])
        self.assertTrue(result['changed'])
        self.assertEqual(self.file.read_text().splitlines(), [*OPERATORS, keys.BEGIN, 'ssh-ed25519 SWAMP swamp', keys.END])
        self.assertEqual(keys.verify(self.file, ['ssh-ed25519 SWAMP swamp'], result['outside_digest']), {'ssh_access_ready': True})
        self.assertEqual(self.file.stat().st_mode & 0o777, 0o600)

    def test_replaces_only_the_block_and_an_identical_block_writes_nothing(self):
        self.file.write_text('\n'.join([OPERATORS[0], keys.BEGIN, 'old', keys.END, *OPERATORS[1:]]) + '\n')
        self.run_write(['new', 'ssh-ed25519 SWAMP swamp'])
        self.assertEqual(self.file.read_text().splitlines(), [OPERATORS[0], keys.BEGIN, 'new', 'ssh-ed25519 SWAMP swamp', keys.END, *OPERATORS[1:]])
        stamp = self.file.stat().st_mtime_ns
        self.assertFalse(self.run_write(['new', 'ssh-ed25519 SWAMP swamp'])['changed'])
        self.assertEqual(self.file.stat().st_mtime_ns, stamp)

    def test_a_key_outside_the_block_stays_there(self):
        self.file.write_text('ssh-ed25519 SWAMP swamp\n')
        self.run_write(['ssh-ed25519 SWAMP swamp'])
        self.assertEqual(self.file.read_text().splitlines()[0], 'ssh-ed25519 SWAMP swamp')

    def test_broken_markers_are_refused_before_any_write(self):
        for broken in ([keys.BEGIN, 'x'], [keys.END, 'x', keys.BEGIN], [keys.BEGIN, keys.BEGIN, keys.END]):
            text = '\n'.join(OPERATORS + broken) + '\n'
            self.file.write_text(text)
            with self.assertRaises(SystemExit):
                self.run_write(['ssh-ed25519 SWAMP swamp'])
            self.assertEqual(self.file.read_text(), text)

    def test_verify_refuses_a_lost_operator_key(self):
        self.file.write_text('\n'.join(OPERATORS) + '\n')
        result = self.run_write(['ssh-ed25519 SWAMP swamp'])
        self.file.write_text(f'{keys.BEGIN}\nssh-ed25519 SWAMP swamp\n{keys.END}\n')
        with self.assertRaises(SystemExit):
            keys.verify(self.file, ['ssh-ed25519 SWAMP swamp'], result['outside_digest'])

    def test_creates_the_file_when_missing(self):
        self.run_write(['ssh-ed25519 SWAMP swamp'])
        self.assertEqual(self.file.read_text().splitlines(), [keys.BEGIN, 'ssh-ed25519 SWAMP swamp', keys.END])


if __name__ == '__main__':
    unittest.main()
