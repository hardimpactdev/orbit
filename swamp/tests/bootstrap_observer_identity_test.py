"""The tracked observer identity keeps private bytes out of every action result."""

import hashlib
import importlib.util
import json
import os
import tempfile
import unittest
from pathlib import Path

SOURCE = (
    Path(__file__).resolve().parents[1]
    / "extensions/behaviors/operations/node-bootstrap/scripts/bootstrap_observer_identity.py"
)
spec = importlib.util.spec_from_file_location("observer_identity", SOURCE)
identity = importlib.util.module_from_spec(spec)
spec.loader.exec_module(identity)


class ObserverIdentity(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(dir=os.environ.get("TMPDIR"))
        self.addCleanup(self.temp.cleanup)
        identity.SECRET = Path(self.temp.name) / "agent/secret"

    def test_first_identity_is_stable_and_only_the_digest_leaves_the_action(self):
        first = identity.prepare(None)
        secret = identity.SECRET.read_text()
        self.assertEqual(0o600, identity.SECRET.stat().st_mode & 0o777)
        self.assertEqual(
            hashlib.sha256(secret.encode()).hexdigest(), first["observer_digest"]
        )
        self.assertNotIn(secret, json.dumps(first))
        self.assertEqual(first, identity.prepare(first["observer_digest"]))
        self.assertEqual(first, identity.prepare(None))
        self.assertEqual(secret, identity.SECRET.read_text())

    def test_mismatch_or_missing_recorded_identity_never_rotates_or_creates(self):
        with self.assertRaises(ValueError):
            identity.prepare("a" * 64)
        self.assertFalse(identity.SECRET.exists())
        identity.prepare(None)
        before = identity.SECRET.read_bytes()
        with self.assertRaises(ValueError):
            identity.prepare("a" * 64)
        self.assertEqual(before, identity.SECRET.read_bytes())

    def test_linked_identity_is_refused_without_changing_its_target(self):
        target = Path(self.temp.name) / "foreign"
        target.write_text("retained")
        identity.SECRET.parent.mkdir()
        identity.SECRET.symlink_to(target)
        with self.assertRaises(ValueError):
            identity.prepare(None)
        self.assertEqual("retained", target.read_text())


if __name__ == "__main__":
    unittest.main()
