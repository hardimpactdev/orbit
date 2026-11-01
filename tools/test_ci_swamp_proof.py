"""F10 tooling: frozen bindings, complete cleanup and truthful proof receipts."""
import copy
import tempfile
import unittest
from datetime import UTC, datetime
from pathlib import Path
from unittest.mock import patch

import swamp_proof as proof
import swamp_proof_guest as guest


class BindingsTest(unittest.TestCase):
    def test_frozen_bindings_preserve_types_and_fixed_time(self):
        context = {'proof.operation_id': 42, 'proof.start_tag': 'proof-1', 'proof.target.host_keys': ['ssh-ed25519 public'],
                   'proof.target.port': 22}
        value = {'id': '${proof.operation_id}', 'keys': '${proof.target.host_keys}', 'port': '${proof.target.port}',
                 'tag': 'owned-${proof.start_tag}', 'deadline': '${proof.now+300}'}
        self.assertEqual(proof.bind(value, context, datetime(2026, 11, 1, tzinfo=UTC)), {
            'id': 42, 'keys': ['ssh-ed25519 public'], 'port': 22, 'tag': 'owned-proof-1', 'deadline': '2026-11-01T00:05:00Z'})

    def test_unknown_malformed_and_embedded_structured_bindings_refuse(self):
        for value in ('${proof.target.password}', '${env.HOME}', '${proof.target.port', 'ssh ${proof.target.host_keys}'):
            with self.subTest(value=value), self.assertRaises(proof.ProofError):
                proof.bind(value, {'proof.target.host_keys': []}, datetime.now(UTC))

    def test_fixture_file_cannot_escape_its_kind_folder(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            (root / 'kind').mkdir()
            (root / 'secret').write_text('not a fixture')
            (root / 'kind/escape').symlink_to(root / 'secret')
            for path in ('../secret', 'escape', str(root / 'secret')):
                with self.subTest(path=path), self.assertRaises(proof.ProofError):
                    proof.relative_file(root / 'kind', path)


class FakeGateway:
    def __init__(self):
        self.calls = []
        self.groups = {}
        self.operations = {}
        self.fail_second = False
        self.cancel_failure = None
        self.host = 13
        self.confirm_destroyed = True
        self.exclusions = [{'id': 9}]

    def request(self, method, path, body=None, key=None):
        self.calls.append((method, path, copy.deepcopy(body), key))
        if method == 'GET' and path == 'projects/60':
            return {'id': 60, 'slug': 'swamp-proof', 'task_workspace_routed': False, 'review_and_merge': False}
        if path == 'projects/60/excluded-nodes':
            return self.exclusions
        if method == 'POST' and path == 'task-groups':
            group = len(self.groups) + 100
            self.groups[group] = {'id': group, 'status': 'todo', 'taskable_id': None, 'vm_power': None}
            return copy.deepcopy(self.groups[group])
        if path.startswith('nodes/'):
            node = int(path.split('/')[1])
            if node == 13:
                return {'id': 13, 'status': 'active', 'name': 'shark'}
            return {'id': node, 'status': 'active', 'name': f'tvm-{node - 1000}', 'user': 'orbit'}
        if path.endswith('/provision'):
            group = int(path.split('/')[1])
            if self.fail_second and group == 101:
                raise proof.ProofError('tasks.capacity_unavailable')
            self.groups[group].update(status='running', taskable_id=group + 2000, vm_power='running')
            operation = {'id': group + 500, 'kind': 'tasks.provision', 'target_type': 'task_group', 'target_id': group,
                         'status': 'succeeded', 'result': {'task_vm_id': group, 'node_id': group + 1000, 'instance_id': group + 2000},
                         'input': {'host_node_id': self.host, 'provider': 'incus', 'vm': {'project': 'orbit-tasks'},
                                   'create': {'network': 'orbittask0'}}}
            return operation
        if path.endswith('/cancel'):
            group = int(path.split('/')[1])
            if self.cancel_failure == group:
                raise proof.ProofError('tasks.target_busy')
            self.groups[group].update(status='cancelled', taskable_id=None, vm_power='destroyed' if self.confirm_destroyed else None)
            return {'id': group + 600, 'kind': 'tasks.cancel', 'target_type': 'task_group', 'target_id': group, 'status': 'succeeded'}
        if method == 'GET' and path.startswith('task-groups/'):
            return copy.deepcopy(self.groups[int(path.split('/')[1])])
        raise AssertionError(f'Unexpected request {method} {path}')


class LifecycleTest(unittest.TestCase):
    def setUp(self):
        self.fixture = {'kind': 'firewall.inspect', 'workflow': 'operation-firewall-inspect', 'budget_seconds': 60,
                        'parity': {'cases': ['legacy-firewall-snapshot']}, 'expect': {'status': 'succeeded'}}

    def run_proof(self, gateway, execute=None):
        receipt, saved = {}, []
        execute = execute or (lambda *_: {'status': 'succeeded', 'native_run_id': 'native-id',
                                          'expect_pass': True, 'budget_pass': True, 'parity': {'passed': True, 'failed': [], 'cases': ['legacy-firewall-snapshot']}})
        passed = proof.run(gateway, 60, self.fixture, execute, receipt, lambda: saved.append(copy.deepcopy(receipt)))
        return passed, receipt, saved

    def test_success_provisions_one_owned_vm_and_container_and_proves_destroyed(self):
        gateway = FakeGateway()
        passed, receipt, saved = self.run_proof(gateway)
        self.assertTrue(passed)
        self.assertEqual(receipt['control_vm']['task_vm_id'], 100)
        self.assertEqual(receipt['target_vm']['task_vm_id'], 100)
        self.assertEqual(receipt['target_vm']['type'], 'incus-container')
        self.assertTrue(receipt['destroyed'])
        self.assertEqual([path for method, path, *_ in gateway.calls if method == 'POST'], [
            'task-groups', 'task-groups/100/provision', 'task-groups/100/cancel'])
        self.assertEqual(saved[0]['control_vm'], {'group_id': 100})

    def test_provision_failure_still_cancels_created_group_without_mutation_retry(self):
        gateway = FakeGateway()
        original = gateway.request
        def refuse(method, path, body=None, key=None):
            if path.endswith('/provision'):
                raise proof.ProofError('tasks.capacity_unavailable')
            return original(method, path, body, key)
        gateway.request = refuse
        passed, receipt, _ = self.run_proof(gateway)
        self.assertFalse(passed)
        self.assertEqual(receipt['error'], 'tasks.capacity_unavailable')
        self.assertTrue(receipt['destroyed'])
        self.assertEqual(gateway.groups[100]['status'], 'cancelled')

    def test_primary_failure_survives_when_cleanup_refuses(self):
        gateway = FakeGateway()
        gateway.cancel_failure = 100
        def fail(*_):
            raise proof.ProofError('Native parity failed.')
        passed, receipt, _ = self.run_proof(gateway, fail)
        self.assertFalse(passed)
        self.assertEqual(receipt['error'], 'Native parity failed.')
        self.assertEqual(receipt['control_vm']['cleanup_error'], 'tasks.target_busy')
        self.assertFalse(receipt['destroyed'])

    def test_interrupt_also_destroys_owned_vm(self):
        def interrupted(*_):
            raise KeyboardInterrupt
        passed, receipt, _ = self.run_proof(FakeGateway(), interrupted)
        self.assertFalse(passed)
        self.assertTrue(receipt['destroyed'])

    def test_native_success_with_failed_parity_or_budget_is_not_proof_success(self):
        for flag in ('parity', 'budget_pass', 'expect_pass'):
            result = {'status': 'succeeded', 'expect_pass': True, 'budget_pass': True, 'parity': {'passed': True}}
            result[flag] = {'passed': False} if flag == 'parity' else False
            with self.subTest(flag=flag):
                passed, receipt, _ = self.run_proof(FakeGateway(), lambda *_, result=result: result)
                self.assertFalse(passed)
                self.assertFalse(receipt['proof_pass'])
                self.assertTrue(receipt['destroyed'])

    def test_wrong_placement_never_runs_kind_and_retains_cleanup(self):
        gateway = FakeGateway()
        gateway.host = 9
        with patch.object(proof, 'provision', wraps=proof.provision) as provision:
            passed, receipt, _ = self.run_proof(gateway, lambda *_: self.fail('Kind must not run on Beast'))
        self.assertFalse(passed)
        self.assertTrue(receipt['destroyed'])
        self.assertEqual(provision.call_count, 1)
        self.assertIn('placement', receipt['error'])

    def test_absent_provider_confirmation_is_not_a_successful_cleanup(self):
        gateway = FakeGateway()
        gateway.confirm_destroyed = False
        passed, receipt, _ = self.run_proof(gateway)
        self.assertFalse(passed)
        self.assertFalse(receipt['destroyed'])
        self.assertTrue('cleanup_error' in receipt['control_vm'])

    def test_unexcluded_project_refuses_before_creating_groups(self):
        gateway = FakeGateway()
        gateway.exclusions = []
        passed, receipt, _ = self.run_proof(gateway)
        self.assertFalse(passed)
        self.assertFalse(gateway.groups)
        self.assertFalse(receipt['destroyed'])

    def test_foreign_lifecycle_result_is_not_accepted(self):
        foreign = {'id': 7, 'kind': 'tasks.provision', 'target_type': 'task_group', 'target_id': 8, 'status': 'succeeded'}
        with self.assertRaisesRegex(proof.ProofError, 'identity'):
            proof.wait_operation(FakeGateway(), foreign, 'tasks.provision', 100)


if __name__ == '__main__':
    unittest.main()


class ReceiptTest(unittest.TestCase):
    def setUp(self):
        self.fixture = {'budget_seconds': 10, 'queue_timeout_seconds': None,
                        'expect': {'status': 'succeeded', 'outputs': {'job': 'effect', 'step': 'read', 'match': {'ready': True}}},
                        'phases': [{'name': 'read', 'job': 'effect', 'step': 'read', 'budget_seconds': 5}],
                        'parity': {'cases': ['legacy-read-only', 'legacy-exact-result']}}
        self.history = {'id': 'native-exact-root', 'status': 'succeeded', 'startedAt': '2026-11-01T00:00:00Z',
                        'completedAt': '2026-11-01T00:00:04Z', 'jobs': [{'name': 'effect', 'steps': [{
                            'name': 'read', 'status': 'succeeded', 'startedAt': '2026-11-01T00:00:01Z',
                            'completedAt': '2026-11-01T00:00:03Z', 'outputs': {'ready': True, 'other': 'public'}}]}]}

    def test_native_timestamps_and_explicit_queue_default_are_recorded(self):
        result = proof.evaluate(self.history, self.fixture, 0)
        self.assertEqual(result['total_seconds'], 4)
        self.assertEqual(result['phases'][0]['seconds'], 2)
        self.assertTrue(result['expect_pass'])
        self.assertTrue(result['budget_pass'])
        self.assertTrue(result['parity']['passed'])
        self.assertEqual(result['queue_timeout_note'], 'inherits serve default 600')

    def test_phase_overrun_and_parity_failure_cannot_hide_behind_native_success(self):
        self.history['jobs'][0]['steps'][0]['completedAt'] = '2026-11-01T00:00:08Z'
        result = proof.evaluate(self.history, self.fixture, 1)
        self.assertEqual(result['status'], 'succeeded')
        self.assertFalse(result['budget_pass'])
        self.assertFalse(result['parity']['passed'])
        self.assertEqual(result['parity']['failed'], self.fixture['parity']['cases'])

    def test_missing_phase_or_ambiguous_phase_is_unproved(self):
        for steps in ([], self.history['jobs'][0]['steps'] * 2):
            with self.subTest(steps=steps), self.assertRaises(proof.ProofError):
                history = copy.deepcopy(self.history)
                history['jobs'][0]['steps'] = steps
                proof.evaluate(history, self.fixture, 0)

    def test_wrong_output_type_is_not_a_match(self):
        self.history['jobs'][0]['steps'][0]['outputs']['ready'] = 1
        self.assertFalse(proof.evaluate(self.history, self.fixture, 0)['expect_pass'])

    def test_epoch_binding_preserves_unix_integer(self):
        self.assertEqual(proof.bind('${proof.epoch+300}', {}, datetime(2026, 11, 1, tzinfo=UTC)), 1793491500)


class ParityOutputsTest(unittest.TestCase):
    def test_selected_terminal_outputs_are_unwrapped_and_other_steps_are_excluded(self):
        history = {'jobs': [{'name': 'effect', 'steps': [
            {'name': 'private-adjacent', 'outputs': {'must_not_enter_parity': True}},
            {'name': 'result', 'outputs': {'stdout': '{"ready":true}', 'exitCode': 0}}]}]}
        fixture = {'expect': {'outputs': {'job': 'effect', 'step': 'result', 'match': {}}}}
        self.assertEqual(guest.parity_outputs(history, fixture), {'stdout': '{"ready":true}', 'exitCode': 0})
        self.assertNotIn('effect', guest.parity_outputs(history, fixture))

    def test_no_terminal_selector_refuses_instead_of_returning_all_history(self):
        with self.assertRaises(proof.ProofError):
            guest.parity_outputs({'jobs': []}, {'expect': {'status': 'succeeded'}})


class ProjectAndTopologyTest(unittest.TestCase):
    def test_default_project_resolves_the_frozen_slug_and_never_a_display_name(self):
        gateway = unittest.mock.Mock()
        gateway.request.return_value = [{'id': 60, 'slug': 'task-vm-lifecycle-canary', 'name': 'Swamp Proof'},
                                        {'id': 62, 'slug': 'swamp-proof', 'name': 'Swamp Proof'}]
        self.assertEqual(proof.proof_project(gateway), 62)
        gateway.request.assert_called_once_with('GET', 'projects')
        gateway.request.return_value = []
        with self.assertRaises(proof.ProofError):
            proof.proof_project(gateway)

    def test_container_image_seam_pins_alias_type_fingerprint_and_topology_version(self):
        value = proof.topology_for('app-dev')
        self.assertEqual(value['version'], 1)
        self.assertEqual(value['image'], {'alias': 'orbit-proof-app-dev-v1', 'type': 'container', 'fingerprint': None})
        for bad in ({'alias': 'latest', 'type': 'container', 'fingerprint': None},
                    {'alias': 'orbit-proof-app-dev-v1', 'type': 'virtual-machine', 'fingerprint': None},
                    {'alias': 'orbit-proof-app-dev-v1', 'type': 'container', 'fingerprint': 'short'}):
            with tempfile.TemporaryDirectory() as tmp:
                root = Path(tmp)
                folder = root / 'swamp/proof/topologies/app-dev'
                folder.mkdir(parents=True)
                (folder / 'setup.sh').write_text('true')
                (folder / 'topology.json').write_text(__import__('json').dumps(dict(value, image=bad)))
                with self.subTest(image=bad), self.assertRaises(proof.ProofError):
                    proof.topology_for('app-dev', root)
