"""F13 regression coverage: real request boundaries, native identity and owned cleanup."""
import copy
import io
import json
import os
import tempfile
import unittest
from contextlib import redirect_stderr
from datetime import UTC, datetime, timedelta
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import Mock, patch

import swamp_proof as proof
import swamp_proof_requests as requests
import swamp_proof_run as runner
import swamp_proof_topology as sessions


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
        if method == 'GET' and path == 'projects':
            return [{'id': 62, 'slug': 'swamp-proof'}]
        if method == 'GET' and path == 'projects/62':
            return {'id': 62, 'slug': 'swamp-proof', 'task_workspace_routed': False, 'review_and_merge': False}
        if path == 'projects/62/excluded-nodes':
            return self.exclusions
        if method == 'POST' and path == 'task-groups':
            group = len(self.groups) + 100
            self.groups[group] = {'id': group, 'status': 'todo', 'taskable_id': None, 'vm_power': None}
            return copy.deepcopy(self.groups[group])
        if path.startswith('nodes/'):
            node = int(path.split('/')[1])
            if node == 13:
                return {'id': 13, 'status': 'active', 'name': 'shark', 'user': 'orbit', 'wireguard_ip': '10.44.0.13'}
            return {'id': node, 'status': 'active', 'name': f'tvm-{node - 1000}', 'user': 'orbit'}
        if path.endswith('/provision'):
            group = int(path.split('/')[1])
            if self.fail_second and group == 101:
                raise proof.ProofError('tasks.capacity_unavailable')
            self.groups[group].update(status='running', taskable_id=group + 2000, vm_power='running')
            operation = {'id': group + 500, 'kind': 'tasks.provision', 'target_type': 'task_group', 'target_id': group,
                         'status': 'succeeded', 'result': {'task_vm_id': group, 'node_id': group + 1000, 'instance_id': group + 2000},
                         'input': {'host_node_id': self.host, 'provider': 'incus', 'vm': {'project': 'orbit-tasks'},
                                   'create': {'network': 'orbittask0'}, 'image_fingerprint': 'a' * 64}}
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


class ExpectedInputTest(unittest.TestCase):
    def test_recursive_subset_reports_each_path_and_compares_arrays_whole(self):
        actual = {'native_registry': {'request': '4ba84968-1919-40b2-b919-f0ced3951770', 'revision': 4},
                  'unchecked': True, 'list': [1, 2, 3]}
        self.assertEqual(proof.input_mismatches(actual, {'native_registry': {'request': '${match.uuid}'}}), [])
        mismatches = proof.input_mismatches(actual, {'native_registry': {'revision': 5}, 'list': [1, 2], 'missing': '${match.present}'})
        self.assertEqual([item['path'] for item in mismatches], ['$["native_registry"]["revision"]', '$["list"]', '$["missing"]'])
        self.assertTrue(mismatches[-1]['missing'])

    def test_generated_matchers_preserve_types_and_presence_is_not_truthiness(self):
        cases = [('${match.uuid}', '4ba84968-1919-40b2-b919-f0ced3951770', 'not-a-uuid'),
                 ('${match.positive_int}', 7, True), ('${match.sha256}', 'a' * 64, 'a' * 63),
                 ('${match.nonempty_string}', 'value', '')]
        for matcher, good, bad in cases:
            with self.subTest(matcher=matcher):
                self.assertEqual(proof.input_mismatches(good, matcher), [])
                self.assertEqual(len(proof.input_mismatches(bad, matcher)), 1)
        self.assertEqual(proof.input_mismatches(None, '${match.present}'), [])


class RequestContractTest(unittest.TestCase):
    def test_prepare_captures_real_flat_cli_result_and_preserves_argument_types(self):
        fixture = {'prepare': [{'argv': ['orbit', 'project:create', 'proof-${proof.run}', '--json'], 'capture': {'project': '$.id'}},
                               {'argv': ['orbit', 'project:show', '${capture.project}', '--json'], 'capture': {'branch': '$.default_branch'}}]}
        call = Mock(side_effect=[{'id': 17}, {'default_branch': 'main'}])
        context = requests.prepare(fixture, {'proof.run': 'abc'}, call)
        self.assertEqual(call.call_args_list[1].args[0], ['orbit', 'project:show', '17', '--json'])
        self.assertEqual(context['capture.branch'], 'main')
        self.assertEqual(requests.json_path({'rows': [{'id': 3}]}, '$.rows[0].id'), 3)
        for bad in ('$.absent', '$..id', '$[x]', 'id'):
            with self.subTest(path=bad), self.assertRaises(proof.ProofError):
                requests.json_path({}, bad)

    def test_f13_fixture_accepts_hook_objects_and_rejects_legacy_vault_setup(self):
        fixture = proof.fixture_for('firewall.inspect')
        self.assertEqual(fixture['before_request'], [{'argv': ['python3', '-I', 'setup.py']}])
        for hook in (['sh', 'x'], {'argv': ['sh', 'x'], 'timeout_seconds': 301}, {'argv': ['sh', 'x'], 'timeout_seconds': True}):
            invalid = dict(fixture, before_request=[hook])
            with self.subTest(hook=hook), self.assertRaises(proof.ProofError):
                requests.validate_commands(invalid)
        self.assertEqual(proof.bind('${proof.epoch+300}', {}, datetime(2026, 11, 1, tzinfo=UTC)), 1793491500)
        with self.assertRaises(proof.ProofError):
            proof.bind('${proof.target.host}', {'proof.target.host': 'production'}, datetime.now(UTC))

    def test_topology_uses_existing_catalog_seed_and_node_prerequisites(self):
        self.assertEqual(proof.topology_for('app-dev'), {'name': 'app-dev', 'version': 1, 'nodes': ['app-dev'], 'seed': None})
        self.assertEqual(proof.topology_for('["app-dev","app-prod+ingress"]')['nodes'], ['app-dev', 'app-prod+ingress'])
        for value in (['app-prod-2'], ['app-dev', 'app-dev'], ['gateway'], ['app-dev+unknown'], 'app-dev+ingress'):
            with self.subTest(value=value), self.assertRaises(proof.ProofError):
                proof.topology_for(value)

    def test_variants_resolve_in_filename_order_and_cannot_mix_with_single_file(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            folder = root / 'swamp/proof/node.role_add'
            folder.mkdir(parents=True)
            for name in ('websocket', 'database'):
                (folder / (name + '.json')).write_text('{}')
            with patch.object(proof, 'fixture_for', return_value={}) as loader:
                self.assertEqual([name for name, _ in proof.fixture_variants('node.role_add', root=root)], ['database', 'websocket'])
                self.assertEqual(loader.call_args_list[0].args, ('node.role_add', root, 'database'))
                self.assertEqual(proof.fixture_variants('node.role_add', 'websocket', root), [('websocket', {})])
            (root / 'swamp/proof/node.role_add.json').write_text('{}')
            with self.assertRaises(proof.ProofError):
                proof.fixture_variants('node.role_add', root=root)


class LifecycleTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.path = Path(self.tmp.name) / 'session.json'

    def up(self, gateway, bootstrap=None):
        with patch.object(sessions, 'bundle_source', return_value=('c' * 40, b'bundle')), \
             patch.object(sessions, 'owned_guest'), \
             patch.object(sessions, 'bootstrap', side_effect=bootstrap or (lambda *_: None)):
            return sessions.up(gateway, path=self.path)

    def test_capacity_refusal_still_cancels_created_group_once(self):
        gateway = FakeGateway()
        original = gateway.request
        def refuse(method, path, body=None, key=None):
            if path.endswith('/provision'):
                raise proof.ProofError('tasks.capacity_unavailable')
            return original(method, path, body, key)
        gateway.request = refuse
        with self.assertRaisesRegex(proof.ProofError, 'capacity'):
            self.up(gateway)
        state = sessions.load_state(self.path)
        self.assertTrue(state['vm']['destroyed'])
        self.assertEqual([path for method, path, *_ in gateway.calls if method == 'POST'], ['task-groups', 'task-groups/100/cancel'])

    def test_bootstrap_failure_or_interrupt_keeps_primary_failure_and_cleans_its_vm(self):
        for error in (proof.ProofError('missing club file'), KeyboardInterrupt()):
            gateway = FakeGateway()
            self.path.unlink(missing_ok=True)
            with self.subTest(error=error), self.assertRaises(type(error)):
                self.up(gateway, Mock(side_effect=error))
            self.assertTrue(sessions.load_state(self.path)['vm']['destroyed'])
            self.assertEqual(gateway.groups[100]['status'], 'cancelled')

    def test_failed_cleanup_retains_exact_group_and_blocks_duplicate_up(self):
        gateway = FakeGateway()
        gateway.cancel_failure = 100
        with self.assertRaisesRegex(proof.ProofError, 'primary'):
            self.up(gateway, Mock(side_effect=proof.ProofError('primary')))
        state = sessions.load_state(self.path)
        self.assertEqual(state['vm']['group_id'], 100)
        self.assertEqual(state['cleanup_error'], 'tasks.target_busy')
        with self.assertRaisesRegex(proof.ProofError, 'already owns'):
            self.up(gateway)
        self.assertEqual(len(gateway.groups), 1)

    def test_wrong_placement_never_bootstraps_and_still_cancels(self):
        gateway = FakeGateway()
        gateway.host = 9
        action = Mock()
        with self.assertRaisesRegex(proof.ProofError, 'placement'):
            self.up(gateway, action)
        action.assert_not_called()
        self.assertTrue(sessions.load_state(self.path)['vm']['destroyed'])

    def test_no_provider_confirmation_does_not_claim_destroyed(self):
        gateway = FakeGateway()
        gateway.confirm_destroyed = False
        with self.assertRaises(proof.ProofError):
            self.up(gateway, Mock(side_effect=proof.ProofError('primary')))
        state = sessions.load_state(self.path)
        self.assertFalse(state['vm'].get('destroyed', False))
        self.assertIn('cleanup_error', state)

    def test_unexcluded_project_refuses_before_allocation(self):
        gateway = FakeGateway()
        gateway.exclusions = []
        with self.assertRaises(proof.ProofError):
            self.up(gateway)
        self.assertFalse(gateway.groups)

    def test_lifecycle_identity_mismatch_refuses(self):
        foreign = {'id': 7, 'kind': 'tasks.provision', 'target_type': 'task_group', 'target_id': 8, 'status': 'succeeded'}
        with self.assertRaisesRegex(proof.ProofError, 'identity'):
            proof.wait_operation(FakeGateway(), foreign, 'tasks.provision', 100)

    def test_persistent_lifetime_uses_last_run_mtime_and_absolute_ceiling(self):
        created = datetime(2026, 11, 1, tzinfo=UTC)
        group = {'title': 'topology app-dev@1 lane25', 'created_at': created.isoformat()}
        self.assertFalse(sessions.expired(group, now=created + timedelta(minutes=59)))
        self.assertTrue(sessions.expired(group, now=created + timedelta(minutes=60)))
        self.assertFalse(sessions.expired(group, last_run=(created + timedelta(hours=2)).timestamp(), now=created + timedelta(hours=2, minutes=59)))
        self.assertTrue(sessions.expired(group, last_run=(created + timedelta(hours=7, minutes=59)).timestamp(), now=created + timedelta(hours=8)))
        group['title'] = 'proof firewall.inspect app-dev@1'
        self.assertTrue(sessions.expired(group, now=created + timedelta(minutes=45)))


class NativeIdentityTest(unittest.TestCase):
    def test_exact_root_search_never_selects_latest_or_an_ambiguous_run(self):
        top = Mock()
        operation = {'id': 42}
        fixture = {'workflow': 'operation-firewall-inspect', 'kind': 'firewall.inspect'}
        row = {'runId': 'root', 'workflowName': fixture['workflow'], 'inputs': {'operation_id': 42}}
        history = {'id': 'root', 'workflowName': fixture['workflow'], 'inputs': {'operation_id': 42, 'kind': fixture['kind']}}
        top.history.side_effect = [{'results': [row]}, history]
        self.assertEqual(runner.exact_history(top, operation, fixture), history)
        self.assertEqual(top.history.call_args_list[0].args, ('search', '--workflow', fixture['workflow'], '--input', 'operation_id=42'))
        for rows in ([], [row, row], [dict(row, inputs={'operation_id': '42'})]):
            top.history.side_effect = None
            top.history.return_value = {'results': rows}
            with self.subTest(rows=rows), self.assertRaises(proof.ProofError):
                runner.exact_history(top, operation, fixture)
        top.history.side_effect = [{'results': [row]}, dict(history, inputs={'operation_id': 43, 'kind': fixture['kind']})]
        with self.assertRaises(proof.ProofError):
            runner.exact_history(top, operation, fixture)

    def test_selected_outputs_are_unwrapped_and_adjacent_steps_excluded(self):
        history = {'jobs': [{'name': 'effect', 'steps': [
            {'name': 'adjacent', 'outputs': {'other': True}},
            {'name': 'result', 'outputs': {'stdout': '{"ready":true}', 'exitCode': 0}}]}]}
        fixture = {'expect': {'outputs': {'job': 'effect', 'step': 'result', 'match': {}}}}
        self.assertEqual(runner.parity_outputs(history, fixture), {'stdout': '{"ready":true}', 'exitCode': 0})
        with self.assertRaises(proof.ProofError):
            runner.parity_outputs(history, {'expect': {}})

    def test_terminal_failed_cli_response_preserves_admitted_operation(self):
        operation = {'id': 42, 'kind': 'firewall.inspect', 'status': 'failed'}
        response = {'state': 'executed', 'exit_code': 1, 'stdout': json.dumps(operation), 'stderr': 'full native error'}
        execute = Mock(return_value=SimpleNamespace(returncode=1, stdout=json.dumps(response), stderr=''))
        top = runner.Topology({'worktree': '/tmp/proof', 'issue': 'TASK-42'}, execute)
        with redirect_stderr(io.StringIO()) as errors:
            self.assertEqual(top.request(['orbit', 'firewall:inspect', '--json']), operation)
        self.assertIn('full native error', errors.getvalue())
        with redirect_stderr(io.StringIO()), self.assertRaises(proof.ProofError):
            top.cli(['orbit', 'firewall:allow', '--json'])

    def test_hook_environment_has_only_current_proof_names(self):
        top = SimpleNamespace(prefix=['/tmp/a b/bin/e2e-topology', 'exec', 'TASK-42'])
        with patch.dict(os.environ, {'PROOF_TARGET_SSH': 'forbidden', 'PROOF_RUN_ID': 'old', 'ORBIT_GATEWAY_URL': 'production', 'SWAMP_SERVER_URL': 'production'}):
            before = runner.hook_env(top, 'run', Path('/tmp/kind'))
            parity = runner.hook_env(top, 'run', Path('/tmp/kind'), 42, Path('/tmp/outputs.json'))
        self.assertEqual({key for key in before if key.startswith('PROOF_')}, {'PROOF_EXEC', 'PROOF_RUN', 'PROOF_KIND_DIR'})
        self.assertEqual({key for key in parity if key.startswith('PROOF_')}, {'PROOF_EXEC', 'PROOF_RUN', 'PROOF_KIND_DIR', 'PROOF_OPERATION_ID', 'PROOF_OUTPUTS'})
        self.assertNotIn('ORBIT_GATEWAY_URL', parity)
        self.assertNotIn('SWAMP_SERVER_URL', parity)


class RequestSequenceTest(unittest.TestCase):
    def setUp(self):
        self.fixture = {'kind': 'firewall.inspect', 'workflow': 'operation-firewall-inspect', 'topology': 'app-dev',
                        'budget_seconds': 10, 'queue_timeout_seconds': None,
                        'prepare': [{'argv': ['orbit', 'project:create', '--json'], 'capture': {'project_id': '$.id'}}],
                        'before_request': [{'argv': ['python3', '-I', 'observer.py', '${capture.project_id}'], 'timeout_seconds': 23}],
                        'request': {'argv': ['orbit', 'firewall:inspect', '--node=${topology.app-dev.node_id}', '--json'], 'operation': '$.id'},
                        'envelope': {'node_id': '${topology.app-dev.node_id}', 'generated': '${match.uuid}'},
                        'expect': {'status': 'succeeded', 'outputs': {'job': 'effect', 'step': 'result', 'match': {'ready': True}}},
                        'phases': [{'name': 'inspect', 'job': 'effect', 'step': 'result', 'budget_seconds': 2}],
                        'parity': {'command': ['python3', 'parity.py'], 'cases': ['legacy-inspect']}}
        self.state = {'worktree': str(proof.ROOT), 'issue': 'TASK-42', 'commit': 'c' * 40, 'nodes': ['app-dev'],
                      'vm': {'group_id': 42, 'seed': 'a' * 64}, 'topology': 'app-dev', 'topology_version': 1}
        self.operation = {'id': 73, 'kind': 'firewall.inspect', 'status': 'succeeded', 'input': {'node_id': 7, 'generated': '4ba84968-1919-40b2-b919-f0ced3951770'}}
        self.history = {'id': 'exact-root', 'workflowName': 'operation-firewall-inspect', 'inputs': {'operation_id': 73, 'kind': 'firewall.inspect'},
                        'status': 'succeeded', 'startedAt': '2026-11-01T00:00:00Z', 'completedAt': '2026-11-01T00:00:01Z',
                        'jobs': [{'name': 'effect', 'steps': [{'name': 'result', 'status': 'succeeded', 'startedAt': '2026-11-01T00:00:00Z',
                                                            'completedAt': '2026-11-01T00:00:01Z', 'outputs': {'ready': True}}]}]}

    def execute(self, *, hook_exit=0, parity_exit=0):
        events = []
        top = Mock()
        top.root = proof.ROOT
        top.prefix = [str(proof.ROOT / 'bin/e2e-topology'), 'exec', 'TASK-42']
        top.context.return_value = {'topology.app-dev.node_id': 7, 'proof.run': 'run'}
        def cli(argv):
            if argv[1] == 'project:create':
                events.append('prepare')
                return {'id': 17}
            events.append('follow')
            return self.operation
        def admitted(argv, **kwargs):
            events.append('request')
            self.assertIn('--node=7', argv)
            return self.operation
        def public_command(argv, **kwargs):
            if argv[0] == 'sudo':
                return SimpleNamespace(returncode=0, stdout='', stderr='')
            if argv[2:3] == ['observer.py']:
                events.append('before_request')
                self.assertEqual(kwargs['cwd'], proof.ROOT / 'swamp/proof/firewall.inspect')
                self.assertEqual(kwargs['timeout'], 23)
                self.assertEqual(argv[-1], '17')
                self.assertNotIn('PROOF_OPERATION_ID', kwargs['env'])
                return SimpleNamespace(returncode=hook_exit, stdout='observer ready', stderr='')
            events.append('parity')
            self.assertEqual(json.loads(Path(kwargs['env']['PROOF_OUTPUTS']).read_text()), {'ready': True})
            self.assertEqual(kwargs['env']['PROOF_OPERATION_ID'], '73')
            return SimpleNamespace(returncode=parity_exit, stdout='', stderr='')
        def history(*args):
            events.append('history')
            return {'results': [{'runId': 'exact-root', 'workflowName': 'operation-firewall-inspect', 'inputs': {'operation_id': 73}}]} if args[0] == 'search' else self.history
        top.cli.side_effect = cli
        top.request.side_effect = admitted
        top.execute.side_effect = public_command
        top.history.side_effect = history
        receipt, snapshots = {}, []
        with patch.object(runner, 'fixture_for', return_value=self.fixture), patch.object(runner, 'pins', return_value={'version': 'pinned'}), redirect_stderr(io.StringIO()):
            runner.run_kind(self.state, 'firewall.inspect', receipt, lambda: snapshots.append(copy.deepcopy(receipt)), topology=top)
        return receipt, events, snapshots

    def test_full_request_sequence_requires_intent_parity_native_and_budget_agreement(self):
        result, events, snapshots = self.execute()
        self.assertTrue(result['proof_pass'])
        self.assertEqual(events, ['prepare', 'before_request', 'request', 'follow', 'history', 'history', 'parity'])
        self.assertEqual(result['actual_input'], self.operation['input'])
        self.assertEqual(result['native_run_id'], 'exact-root')
        self.assertTrue(any(row.get('operation_id') == 73 and 'operation' not in row for row in snapshots))
        self.assertEqual(result['seed'], 'a' * 64)

    def test_failed_before_request_never_admits_and_retains_failure_receipt(self):
        result, events, _ = self.execute(hook_exit=1)
        self.assertFalse(result['proof_pass'])
        self.assertNotIn('request', events)
        self.assertNotIn('operation_id', result)
        self.assertIn('before_request', result['error'])

    def test_input_mismatch_or_failed_parity_cannot_pass_with_native_success(self):
        result, _, _ = self.execute(parity_exit=1)
        self.assertFalse(result['proof_pass'])
        self.assertEqual(result['parity']['failed'], ['legacy-inspect'])
        self.operation['input']['node_id'] = 8
        result, _, _ = self.execute()
        self.assertFalse(result['proof_pass'])
        self.assertEqual(result['input_mismatches'][0]['path'], '$["node_id"]')

    def test_missing_phase_keeps_exact_root_receipt_and_fails(self):
        self.fixture['phases'][0]['step'] = 'missing'
        result, _, _ = self.execute()
        self.assertFalse(result['proof_pass'])
        self.assertEqual(result['operation_id'], 73)
        self.assertEqual(result['native_run_id'], 'exact-root')
        self.assertIn('exact fixture job/step', result['error'])


class ArtisanSelectionTest(unittest.TestCase):
    def test_scheduler_request_selects_only_one_new_exact_target_after_request_time(self):
        top = Mock()
        fixture = {'kind': 'task_vm.sweep_host', 'budget_seconds': 300, 'queue_timeout_seconds': 10, 'request': {'artisan': ['schedule:test', '--name=sweep'],
                   'select': {'kind': 'task_vm.sweep_host', 'target_type': 'node', 'target_id': '${capture.host}', 'count': 1}}}
        now = datetime(2026, 11, 1, tzinfo=UTC)
        old = {'id': 1, 'kind': fixture['kind'], 'target_type': 'node', 'target_id': 7, 'created_at': now.isoformat()}
        new = dict(old, id=2)
        top.node.side_effect = [now.isoformat(), 'Scheduled entry ran']
        top.cli.side_effect = [{'operations': [old]}, {'operations': [old, dict(new, id=3, target_id=8), new]}]
        self.assertEqual(runner.admit(top, fixture, {'capture.host': 7}, now), 2)
        self.assertEqual(top.cli.call_args_list[0].args[0], ['orbit', 'operation:list', '--kind=task_vm.sweep_host', '--json'])
        for rows in ([], [new, dict(new, id=3)]):
            top.reset_mock()
            top.node.side_effect = [now.isoformat(), 'Scheduled entry ran']
            top.cli.side_effect = [{'operations': [old]}, {'operations': rows}]
            with self.subTest(rows=rows), self.assertRaises(proof.ProofError):
                runner.admit(top, fixture, {'capture.host': 7}, now)
            self.assertEqual(top.node.call_count, 2)


class ArrayMatchersTest(unittest.TestCase):
    def test_runtime_uuid_matcher_keeps_whole_array_length_order_and_exact_row_keys(self):
        uuid = '4ba84968-1919-40b2-b919-f0ced3951770'
        expected = {'native_registry': {'runtimes': [{'process_id': '${match.uuid}', 'port': 8790}]}}
        actual = {'native_registry': {'unchecked': True, 'runtimes': [{'process_id': uuid, 'port': 8790}]}}
        self.assertEqual(proof.input_mismatches(actual, expected), [])
        for rows in ([], [{'process_id': uuid, 'port': 8790}] * 2,
                     [{'process_id': 'invalid', 'port': 8790}], [{'process_id': uuid, 'port': 8791}],
                     [{'process_id': uuid, 'port': 8790, 'extra': True}]):
            with self.subTest(rows=rows):
                self.assertTrue(proof.input_mismatches({'native_registry': {'runtimes': rows}}, expected))
        mismatch = proof.input_mismatches({'native_registry': {'runtimes': [{'process_id': 'invalid', 'port': 8790}]}}, expected)
        self.assertEqual(mismatch[0]['path'], '$["native_registry"]["runtimes"][0]["process_id"]')


class PilotAdmissionTest(unittest.TestCase):
    def test_pilot_matches_the_canonical_ssh_target_alias_not_the_gateway_node_name(self):
        fixture = proof.fixture_for('firewall.inspect')
        actual = {'node_id': 7, 'intent': {'ssh': {'target': {'name': 'target', 'host': '10.44.0.2', 'user': 'orbit', 'port': 22, 'host_keys': ['public']}}, 'expected': []}}
        context = {'topology.app-dev.node_id': 7, 'topology.app-dev.name': 'generated-app-dev-name', 'topology.app-dev.wireguard_ip': '10.44.0.2'}
        self.assertEqual(requests.compare_input({'input': actual}, fixture, context)['input_mismatches'], [])


class PilotParityTest(unittest.TestCase):
    def test_owned_rule_uses_public_rule_name_while_preserving_its_canonical_comment(self):
        import runpy
        with tempfile.TemporaryDirectory() as tmp:
            outputs = Path(tmp) / 'outputs.json'
            snapshot = {'backend': 'active', 'comparison': {'live': [{'name': 'proof-owned', 'comment': 'orbit:node:7:firewall:proof-owned', 'match': 'exact'}], 'missing': []}, 'live': [{'comment': 'proof-ssh'}]}
            outputs.write_text(json.dumps({'stdout': json.dumps(snapshot)}))
            with patch.dict(os.environ, {'PROOF_OUTPUTS': str(outputs), 'PROOF_EXEC': 'bin/e2e-topology exec TASK-42'}), patch('subprocess.run') as call:
                runpy.run_path(str(proof.ROOT / 'swamp/proof/firewall.inspect/parity.py'))
            self.assertEqual(call.call_args.args[0][3], 'app-dev')


class TopologyRoleSubsetTest(unittest.TestCase):
    def test_richer_topology_supplies_bare_node_and_required_role_subsets(self):
        self.assertTrue(runner.supplies(['app-dev+ingress', 'app-prod'], ['app-dev']))
        self.assertTrue(runner.supplies(['app-dev+ingress'], ['app-dev+ingress']))
        self.assertFalse(runner.supplies(['app-dev'], ['app-dev+ingress']))
        self.assertFalse(runner.supplies(['app-prod+ingress'], ['app-dev']))


class ArtisanFailureRecoveryTest(unittest.TestCase):
    def test_admitted_image_build_survives_command_timeout_without_repeating_request(self):
        import subprocess
        top = Mock()
        fixture = {'kind': 'task_vm.image_build', 'budget_seconds': 900, 'queue_timeout_seconds': 10,
                   'request': {'artisan': ['task-vms:build-image'], 'select': {'kind': 'task_vm.image_build', 'count': 1}}}
        now = datetime(2026, 11, 1, tzinfo=UTC)
        operation = {'id': 73, 'kind': fixture['kind'], 'created_at': now.isoformat()}
        top.node.side_effect = [now.isoformat(), subprocess.TimeoutExpired(['php', 'artisan'], 1030)]
        top.cli.side_effect = [{'operations': []}, {'operations': [operation]}]
        self.assertEqual(runner.admit(top, fixture, {}, now), 73)
        self.assertEqual(top.node.call_count, 2)
        self.assertEqual(top.node.call_args.kwargs['timeout'], 1030)
        self.assertTrue(top.node.call_args.kwargs['allow_failure'])

    def test_nonzero_artisan_execution_preserves_public_stderr_and_permits_selection(self):
        response = {'state': 'executed', 'exit_code': 1, 'stdout': 'Build admitted and failed', 'stderr': 'complete native failure'}
        execute = Mock(return_value=SimpleNamespace(returncode=1, stdout=json.dumps(response), stderr=''))
        top = runner.Topology({'worktree': '/tmp/proof', 'issue': 'TASK-42'}, execute)
        with redirect_stderr(io.StringIO()) as errors:
            result = top.node('gateway', ['php', 'artisan', 'task-vms:build-image'], raw_output=True, allow_failure=True)
        self.assertEqual(result, response['stdout'])
        self.assertIn(response['stderr'], errors.getvalue())


if __name__ == '__main__':
    unittest.main()
