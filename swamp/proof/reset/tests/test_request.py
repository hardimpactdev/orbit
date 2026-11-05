import json
import sys
import tempfile
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
import request

GENERATION = 'a' * 32


class RequestTest(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory()
        self.addCleanup(self.directory.cleanup)
        self.home = Path(self.directory.name) / 'home'
        self.candidate = Path(self.directory.name) / 'candidate'
        (self.home / '.config/swamp').mkdir(parents=True)
        self.candidate.mkdir()
        key = self.home / '.config/swamp/club-api-key'
        key.write_text('key')
        key.chmod(0o600)

    def test_writes_the_owned_request_a_phase_reads(self):
        path = request.write_request('7', GENERATION, self.candidate, self.home)

        self.assertEqual(self.candidate / '.orbit-artifacts/7/reset' / GENERATION / 'request.json', path)
        value = json.loads(path.read_text())
        self.assertEqual({'group': '7', 'candidate': str(self.candidate)}, json.loads((self.home / '.local/state/orbit-proof-7/owner.json').read_text()))
        self.assertEqual(('7', 'TASK-7', GENERATION, 'ready', True), (value['group'], value['issue'], value['generation'], value['mode'], value['from_ready']))
        self.assertEqual(str(self.home / '.config/swamp/club-api-key'), value['key_file'])

    def test_refuses_a_proof_root_of_another_candidate(self):
        request.write_request('7', GENERATION, self.candidate, self.home)
        with self.assertRaisesRegex(RuntimeError, 'different candidate'):
            request.write_request('7', 'b' * 32, self.candidate / 'other', self.home)

    def test_refuses_a_key_other_users_can_read(self):
        (self.home / '.config/swamp/club-api-key').chmod(0o644)
        with self.assertRaisesRegex(RuntimeError, 'private proof club key'):
            request.write_request('7', GENERATION, self.candidate, self.home)

    def test_takes_a_group_and_a_generation_only(self):
        self.assertEqual(2, request.main(['request.py', '7']))
        self.assertEqual(2, request.main(['request.py', '7', 'not-a-generation']))


if __name__ == '__main__':
    unittest.main()
