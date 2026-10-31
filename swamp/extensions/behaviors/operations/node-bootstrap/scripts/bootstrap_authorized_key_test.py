"""Tracked account key policy preserves other keys and performs one append."""
import importlib.util
import os
import tempfile
import unittest
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import patch


class AuthorizedKey(unittest.TestCase):
    def test_existing_keys_are_preserved_and_repeated_publication_is_a_noop(self):
        spec = importlib.util.spec_from_file_location('policy', Path(__file__).with_name('bootstrap_authorized_key.py'))
        policy = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(policy)
        with tempfile.TemporaryDirectory(dir=os.environ.get('TMPDIR')) as directory:
            home = Path(directory)
            (home / '.ssh').mkdir(mode=0o750)
            keys = home / '.ssh/authorized_keys'
            keys.write_text('ssh-ed25519 PRIOR')
            account = SimpleNamespace(pw_dir=directory, pw_uid=os.getuid(), pw_gid=os.getgid())
            with patch.object(policy.pwd, 'getpwnam', return_value=account):
                data = {'user': 'orbit', 'public_key': 'ssh-ed25519 APPROVED'}
                self.assertEqual(policy.authorize(data), {'authorized': True, 'changed': True})
                self.assertEqual(policy.authorize(data), {'authorized': True, 'changed': False})
            self.assertEqual(keys.read_text(), 'ssh-ed25519 PRIOR\nssh-ed25519 APPROVED\n')
            self.assertEqual(keys.stat().st_mode & 0o777, 0o600)
            self.assertEqual((home / '.ssh').stat().st_mode & 0o777, 0o750)
