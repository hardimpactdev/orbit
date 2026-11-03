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
        state, calls = self.run_refresh(['swamp/proof/firewall.allow.json', 'tools/swamp-proof-guest/gateway.php', 'tools/test_swamp_proof_guest.py'])
        self.assertEqual(state['commit'], 'new')
        self.assertEqual(state['refreshed'][0]['from'], 'old')
        self.assertIn(['runuser', '-u', 'orbit', '--', 'git', '-C', swamp_proof.SOURCE, 'checkout', '-q', '--detach', 'new'], calls)

    def test_gateway_swamp_or_setup_changes_need_a_new_up(self):
        for path in ('apps/gateway/app/Models/Node.php', 'swamp/workflows/workflow-operation-firewall-direct.yaml',
                     'tools/swamp-proof-guest/up.py'):
            with self.subTest(path=path), self.assertRaisesRegex(swamp_proof.ProofError, 'Use down, then up'):
                self.run_refresh([path])


class Down(unittest.TestCase):
    """down cancels once, then trusts only the Gateway's group record."""

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

    def test_one_cancel_takes_the_group_down(self):
        state, posts = self.run_down(['succeeded'])
        self.assertEqual(posts, ['swamp-proof:n:cancel'])
        self.assertTrue(state['vm']['destroyed'])

    def test_a_failed_cancel_stops_without_marking_the_vm_destroyed(self):
        with self.assertRaisesRegex(swamp_proof.ProofError, 'ended failed'):
            self.run_down(['failed'])

    def test_a_cached_failed_cancel_is_followed_by_a_new_one_under_the_next_key(self):
        state, posts = self.run_down(['succeeded'], state={'nonce': 'n', 'vm': {'group_id': 7, 'task_vm_id': 3, 'cancel_operation_id': 90}})
        self.assertEqual(posts, ['swamp-proof:n:cancel:1'])
        self.assertTrue(state['vm']['destroyed'])

    def test_the_gateway_group_record_must_show_the_vm_gone(self):
        with self.assertRaisesRegex(swamp_proof.ProofError, 'did not destroy'):
            self.run_down(['succeeded'], group={'status': 'cancelled', 'vm_power': 'running'})


class Reuse(unittest.TestCase):
    """Consecutive kinds share one up; a clean fixture restages fresh Nodes only after an earlier run."""

    def run_kinds(self, fixtures):
        calls = []

        class Guest:
            def __init__(self, *_):
                pass

            def call(self, argv, input=None, timeout=120):
                calls.append(argv)
                if argv[2].endswith('up.py'):
                    return '{"ready": true, "commit": "c", "timings": []}'
                return '{"proof_pass": true}'

        state = {'ready': True, 'vm': {'name': 'tvm-1'}, 'guest_host': 'h', 'topology': 'minimal', 'topology_version': 6,
                 'commit': 'c', 'receipts': [], 'timings': [], 'ready_epoch': 1, 'last_up_started_epoch': 1, 'up_started_epoch': 1,
                 'up_started_at': 'now'}
        with patch.object(swamp_proof, 'Guest', Guest), patch.object(swamp_proof, 'refresh'), patch.object(swamp_proof, 'save'), \
                patch.object(Path, 'read_text', side_effect=lambda *_: fixtures.pop(0)):
            for _ in range(len(fixtures)):
                swamp_proof.run(state, 'kind')
        return [argv for argv in calls if argv[2].endswith('up.py')]

    def test_kinds_share_one_up(self):
        self.assertEqual(self.run_kinds(['{}', '{}', '{}']), [])

    def test_a_clean_fixture_restages_fresh_nodes_only_after_an_earlier_run(self):
        ups = self.run_kinds(['{"clean": true}', '{}', '{"clean": true}'])
        self.assertEqual(len(ups), 1)
        self.assertEqual(ups[0][-1], '--fresh')


class Provision(unittest.TestCase):
    def run_provision(self, admitted=None, image='seed'):
        operation = {'id': 12, 'input': admitted or {'host_node_id': 9, 'provider': 'incus', 'image_fingerprint': 'seed'},
                     'result': {'task_vm_id': 3, 'node_id': 10, 'image': {'fingerprint': image}}}

        class Gateway:
            def request(self, method, path, body=None, key=None):
                if method == 'POST':
                    return operation
                return {'id': 10, 'name': 'tvm-3'} if path == 'nodes/10' else {'user': 'orbit', 'wireguard_ip': '10.44.0.9'}

            def wait(self, admitted_operation, kind, group):
                return admitted_operation

        state = {'nonce': 'n', 'vm': {'group_id': 7, 'provision_operation_id': 12}}
        with patch.object(swamp_proof, 'save'), patch.object(swamp_proof, 'Guest') as guest:
            swamp_proof.provision(Gateway(), state)
        return state, guest

    def test_compact_provision_replies_keep_the_existing_vm(self):
        state, guest = self.run_provision()
        self.assertEqual(state['vm']['task_vm_id'], 3)
        self.assertEqual(state['vm']['node_id'], 10)
        self.assertEqual(state['vm']['seed'], 'seed')
        guest.assert_called_once_with('orbit@10.44.0.9', 'tvm-3')

    def test_provision_refuses_a_foreign_host_provider_or_image(self):
        for admitted, image in (
            ({'host_node_id': 17, 'provider': 'incus', 'image_fingerprint': 'seed'}, 'seed'),
            ({'host_node_id': 9, 'provider': 'upcloud', 'image_fingerprint': 'seed'}, 'seed'),
            ({'host_node_id': 9, 'provider': 'incus'}, None),
            ({'host_node_id': 9, 'provider': 'incus', 'image_fingerprint': 'seed'}, 'other'),
        ):
            with self.subTest(admitted=admitted, image=image), self.assertRaises(swamp_proof.ProofError):
                self.run_provision(admitted, image)


class Lifecycle(unittest.TestCase):
    """The proof-reaper schedule needs each group's lane in its title and a fresh guest heartbeat."""

    def test_the_group_title_names_this_machine_and_session_as_its_lane(self):
        bodies = []

        class Gateway:
            def request(self, method, path, body=None, key=None):
                bodies.append(body)
                return {'id': 41, 'user': 'orbit', 'wireguard_ip': '10.44.0.9'}

        state = {'topology': 'minimal', 'topology_version': 8, 'vm': {'task_vm_id': 1, 'host_node_id': 9, 'name': 'tvm-5'}}
        with patch.object(swamp_proof, 'SESSION', Path('/tmp/sessions/lane-a.json')), patch.object(swamp_proof, 'save'), \
                patch.object(swamp_proof.socket, 'gethostname', return_value='beast'):
            swamp_proof.provision(Gateway(), state)
        self.assertEqual(bodies[0]['title'], 'swamp-proof beast:lane-a minimal@8')
        self.assertEqual(state['vm']['group_id'], 41)

    def test_up_and_run_touch_the_heartbeat_at_their_start_and_end(self):
        calls = []

        class Guest:
            def __init__(self, *_):
                pass

            def call(self, argv, input=None, timeout=120):
                calls.append(argv)
                return '{"ready": true, "commit": "c", "timings": []}' if argv[2].endswith('up.py') else '{"proof_pass": true}'

        touch = ['runuser', '-u', 'orbit', '--', 'touch', swamp_proof.HEARTBEAT]
        state = {'topology': 'minimal', 'vm': {}, 'receipts': []}
        with patch.object(swamp_proof, 'git', return_value=''), patch.object(swamp_proof, 'save'), \
                patch.object(swamp_proof.subprocess, 'run', side_effect=lambda argv, **_: type('R', (), {'stdout': '' if 'status' in argv else 'c'})()), \
                patch.object(swamp_proof, 'topology', return_value={'name': 'minimal', 'version': 8}), \
                patch.object(swamp_proof.SESSION.__class__, 'is_file', return_value=False), \
                patch.object(swamp_proof, 'provision', return_value=Guest()), patch.object(swamp_proof, 'stage', return_value=1.0):
            state = swamp_proof.up(object())
            self.assertEqual([calls[0], calls[-1]], [touch, touch])
            calls.clear()
            state.update(guest_host='h', vm={'name': 'tvm-5'}, timings=[])
            with patch.object(swamp_proof, 'Guest', Guest), patch.object(swamp_proof, 'refresh'), \
                    patch.object(Path, 'read_text', return_value='{}'):
                swamp_proof.run(state, 'kind')
        self.assertEqual([calls[0], calls[-1]], [touch, touch])
        self.assertTrue(calls[1][2].endswith('run.py'))
        self.assertFalse(swamp_proof.HEARTBEAT.startswith('/home/orbit/swamp-proof/'), 'stage replaces that tree')


class Reap(unittest.TestCase):
    """up and run take down other sessions that are idle for an hour or older than eight hours."""

    def test_only_expired_unlocked_sessions_go_down(self):
        import fcntl
        import json
        import tempfile
        now = 100_000.0
        with tempfile.TemporaryDirectory() as folder:
            folder = Path(folder)
            sessions = {
                'mine': {'vm': {'group_id': 1}, 'up_started_epoch': now - 99_000},
                'idle': {'vm': {'group_id': 2}, 'up_started_epoch': now - 4000, 'ready_epoch': now - 3700},
                'busy': {'vm': {'group_id': 3}, 'up_started_epoch': now - 4000, 'last_run_epoch': now - 60},
                'old': {'vm': {'group_id': 4}, 'up_started_epoch': now - 30_000, 'last_run_epoch': now - 60},
                'gone': {'vm': {'group_id': 5, 'destroyed': True}, 'up_started_epoch': now - 99_000},
                'held': {'vm': {'group_id': 6}, 'up_started_epoch': now - 99_000},
            }
            for name, state in sessions.items():
                (folder / f'{name}.json').write_text(json.dumps(state))
            downed = []
            with (folder / 'held.lock').open('a') as held, \
                    patch.object(swamp_proof, 'SESSION', folder / 'mine.json'), \
                    patch.object(swamp_proof, 'down', side_effect=lambda gateway, state, path: downed.append(path.stem)):
                fcntl.flock(held, fcntl.LOCK_EX | fcntl.LOCK_NB)
                self.assertEqual(swamp_proof.reap(object(), now), ['idle', 'old'])
            self.assertEqual(downed, ['idle', 'old'])

    def test_a_failed_takedown_is_reported_and_the_rest_continue(self):
        import json
        import tempfile
        with tempfile.TemporaryDirectory() as folder:
            folder = Path(folder)
            for name in ('a', 'b'):
                (folder / f'{name}.json').write_text(json.dumps({'vm': {'group_id': 1}, 'up_started_epoch': 0}))

            def down(gateway, state, path):
                if path.stem == 'a':
                    raise swamp_proof.ProofError('cancel ended failed')

            with patch.object(swamp_proof, 'SESSION', folder / 'mine.json'), patch.object(swamp_proof, 'down', side_effect=down):
                self.assertEqual(swamp_proof.reap(object(), 100_000.0), ['b'])


if __name__ == '__main__':
    unittest.main()
