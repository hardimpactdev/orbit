"""Scratch filesystem tests for the proof VM club key file."""
import importlib.util
import stat
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

SCRIPT = Path(__file__).with_name('club_key.py')
spec = importlib.util.spec_from_file_location('club_key', SCRIPT)
club = importlib.util.module_from_spec(spec)
spec.loader.exec_module(club)


class ClubKey(unittest.TestCase):
    def setUp(self):
        self.home = tempfile.TemporaryDirectory(prefix='club-key-fixture-')
        self.addCleanup(self.home.cleanup)
        root = Path(self.home.name)
        self.directory = root / '.config/swamp'
        real = Path
        patcher = patch.object(club, 'Path', side_effect=lambda value: root / real(value).relative_to('/home/orbit')
                               if str(value).startswith('/home/orbit/') else real(value))
        patcher.start()
        self.addCleanup(patcher.stop)

    def test_writes_one_private_file_in_a_private_directory(self):
        club.publish('fixture-club-key')
        target = self.directory / 'club-api-key'
        self.assertEqual(target.read_text(), 'fixture-club-key')
        self.assertEqual(stat.S_IMODE(target.stat().st_mode), 0o600)
        self.assertEqual(stat.S_IMODE(self.directory.stat().st_mode), 0o700)
        self.assertEqual([path.name for path in self.directory.iterdir()], ['club-api-key'])

    def test_refuses_an_empty_key_without_writing(self):
        with self.assertRaises(ValueError):
            club.publish('')
        self.assertFalse(self.directory.exists())


if __name__ == '__main__':
    unittest.main()
