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


class Down(unittest.TestCase):
    """down retries a failed cancel once under a new key, then trusts only the Gateway's group record."""

    def run_down(self, outcomes, state=None, group=None):
        posts = []
        operations = {}

        class Gateway:
            def request(self, method, path, body=None, key=None):
                if method == 'POST':
                    posts.append(key)
                    operation = {'id': 100 + len(posts), 'kind': 'tasks.cancel', 'target_id': 7, 'status': 'running'}
                    operations[operation['id']] = {**operation, 'status': outcomes[len(posts) - 1], 'error_code': 'swamp.start_uncertain'}
                    return operation
                if path.startswith('operations/'):
                    return operations[int(path.split('/')[1])]
                return group or {'status': 'cancelled', 'vm_power': 'destroyed'}

            def wait(self, operation, kind, group_id):
                final = operations[operation['id']]
                if final['status'] != 'succeeded':
                    raise swamp_proof.ProofError(f'{kind} operation {operation["id"]} ended {final["status"]}')
                return final

        state = state or {'nonce': 'n', 'vm': {'group_id': 7, 'task_vm_id': 3}}
        if 'cancel_operation_id' in state['vm']:
            operations[state['vm']['cancel_operation_id']] = {'id': state['vm']['cancel_operation_id'], 'status': 'failed', 'error_code': 'operation.native_failed'}
        with patch.object(swamp_proof, 'save'):
            swamp_proof.down(Gateway(), state)
        return state, posts

    def test_a_failed_cancel_is_retried_once_under_a_new_key(self):
        state, posts = self.run_down(['failed', 'succeeded'])
        self.assertEqual(posts, ['swamp-proof:n:cancel', 'swamp-proof:n:cancel-2'])
        self.assertTrue(state['vm']['destroyed'])

    def test_a_cancel_that_failed_in_an_earlier_down_is_not_replayed(self):
        state, posts = self.run_down(['succeeded'], state={'nonce': 'n', 'vm': {'group_id': 7, 'task_vm_id': 3, 'cancel_operation_id': 50}})
        self.assertEqual(posts, ['swamp-proof:n:cancel-2'])
        self.assertTrue(state['vm']['destroyed'])

    def test_a_second_failure_stops_without_marking_the_vm_destroyed(self):
        with self.assertRaisesRegex(swamp_proof.ProofError, 'ended failed'):
            self.run_down(['failed', 'failed'])

    def test_the_gateway_group_record_must_show_the_vm_gone(self):
        with self.assertRaisesRegex(swamp_proof.ProofError, 'did not destroy'):
            self.run_down(['succeeded'], group={'status': 'cancelled', 'vm_power': 'running'})


if __name__ == '__main__':
    unittest.main()
