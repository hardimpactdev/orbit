"""Test the private file boundary without writing the host's fixed /etc destination."""
import importlib.util
import os
import pwd
import tempfile
import unittest
from pathlib import Path

spec = importlib.util.spec_from_file_location('proof_club', Path(__file__).with_name('proof_club.py'))
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)
store = module.store


class PrivateClubFileTest(unittest.TestCase):
    def test_private_replacement_is_user_owned_and_never_follows_previous_file(self):
        user = pwd.getpwuid(os.getuid()).pw_name
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            destination = root / 'private/club-api-key'
            public = root / 'public'
            public.write_bytes(b'untouched')
            destination.parent.mkdir()
            destination.symlink_to(public)
            store(b'throwaway-test-material', user, destination)
            self.assertEqual(public.read_bytes(), b'untouched')
            self.assertFalse(destination.is_symlink())
            self.assertEqual(destination.read_bytes(), b'throwaway-test-material')
            self.assertEqual(destination.stat().st_mode & 0o777, 0o600)
            self.assertEqual(destination.stat().st_uid, os.getuid())
            self.assertEqual(list(destination.parent.iterdir()), [destination])

    def test_invalid_material_creates_no_file_or_directory(self):
        for material in (b'', b'\0', b'x' * 16385):
            with tempfile.TemporaryDirectory() as tmp:
                destination = Path(tmp) / 'private/club-api-key'
                with self.assertRaises(ValueError):
                    store(material, pwd.getpwuid(os.getuid()).pw_name, destination)
                self.assertFalse(destination.parent.exists())


if __name__ == '__main__':
    unittest.main()
