"""The held topology moves forward only for fixture or harness-driver changes."""
import sys
import unittest
from pathlib import Path
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parent))
import swamp_proof


class Refresh(unittest.TestCase):
    def run_refresh(self, changed):
        replies = {('status', '--porcelain'): '', ('rev-parse', 'HEAD'): 'new', ('diff', '--name-only', 'old', 'new'): '\n'.join(changed)}
        calls = []

        class Guest:
            def call(self, argv, input=None, timeout=120):
                calls.append(argv)
                return ''

        state = {'commit': 'old'}
        with patch.object(swamp_proof, 'git', side_effect=lambda *argv: replies[argv]), \
                patch.object(swamp_proof, 'save'), \
                patch.object(swamp_proof.subprocess, 'run', side_effect=lambda argv, **_: Path(argv[5]).write_bytes(b'bundle')):
            swamp_proof.refresh(Guest(), state)
        return state, calls

    def test_fixture_and_driver_changes_restage_in_place(self):
        state, calls = self.run_refresh(['swamp/proof/firewall.allow.json', 'tools/swamp-proof-guest/gateway.php'])
        self.assertEqual(state['commit'], 'new')
        self.assertEqual(state['refreshed'][0]['from'], 'old')
        self.assertIn(['runuser', '-u', 'orbit', '--', 'git', '-C', swamp_proof.SOURCE, 'checkout', '-q', '--detach', 'new'], calls)

    def test_gateway_swamp_or_setup_changes_need_a_new_up(self):
        for path in ('apps/gateway/app/Models/Node.php', 'swamp/workflows/workflow-operation-firewall-direct.yaml',
                     'tools/swamp-proof-guest/up.py'):
            with self.subTest(path=path), self.assertRaisesRegex(swamp_proof.ProofError, 'Use down, then up'):
                self.run_refresh([path])


if __name__ == '__main__':
    unittest.main()
