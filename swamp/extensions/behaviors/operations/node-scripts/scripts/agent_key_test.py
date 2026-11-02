"""agent_key.py keeps the old key until the agent runs with the new one; the key never leaves the Node."""
import hashlib
import importlib.util
import tempfile
import unittest
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import patch

spec = importlib.util.spec_from_file_location('agent_key', Path(__file__).with_name('agent_key.py'))
key = importlib.util.module_from_spec(spec)
spec.loader.exec_module(key)


class AgentKey(unittest.TestCase):
    def setUp(self):
        directory = Path(tempfile.mkdtemp()) / 'agent'
        self.patches = [patch.object(key, 'DIRECTORY', directory), patch.object(key, 'SECRET', directory / 'secret'),
                        patch.object(key, 'PREVIOUS', directory / 'secret.previous')]
        for p in self.patches:
            p.start()
            self.addCleanup(p.stop)
        directory.mkdir(mode=0o700)
        key.SECRET.write_bytes(b'old-key')

    def active(self, code=0):
        return patch.object(key.subprocess, 'run', return_value=SimpleNamespace(returncode=code))

    def test_rekey_keeps_the_old_key_and_returns_only_the_verifier(self):
        verifier = key.rekey()['verifier_hash']
        new = key.SECRET.read_bytes()
        self.assertEqual(len(new), 64)
        self.assertEqual(hashlib.sha256(new).hexdigest(), verifier)
        self.assertEqual(key.PREVIOUS.read_bytes(), b'old-key')
        self.assertEqual(key.SECRET.stat().st_mode & 0o777, 0o600)
        with self.active():
            self.assertEqual(key.check(verifier + '\n'), {'rekeyed': True, 'restarted': True, 'verifier_hash': verifier})
        self.assertFalse(key.PREVIOUS.exists())

    def test_an_inactive_agent_or_wrong_verifier_fails_and_restore_puts_the_old_key_back(self):
        verifier = key.rekey()['verifier_hash']
        with self.active(3), self.assertRaises(SystemExit):
            key.check(verifier)
        with self.active(), self.assertRaises(SystemExit):
            key.check('0' * 64)
        with self.active():
            key.restore()
        self.assertEqual(key.SECRET.read_bytes(), b'old-key')


if __name__ == '__main__':
    unittest.main()
