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
        self.seed = proof.topology_for('app-dev')['seed']['base_fingerprint']
        self.resources = {'cpus': 4, 'memory': '8GiB', 'disk': '40GiB', 'topology': True}
        self.confirm_destroyed = True
        self.exclusions = [{'project_id': 62, 'project_slug': 'swamp-proof', 'node_id': 9, 'node_name': 'beast', 'development_instance_count': 0}]

    def request(self, method, path, body=None, key=None):
        self.calls.append((method, path, copy.deepcopy(body), key))
        if method == 'GET' and path == 'projects':
            return [{'id': 62, 'slug': 'swamp-proof'}]
        if method == 'GET' and path == 'projects/62':
            return {'id': 62, 'slug': 'swamp-proof', 'task_workspace_routed': False, 'review_and_merge': False}
        if path == 'projects/62/excluded-nodes':
            return self.exclusions
        if method == 'POST' and path == 'task-groups':
            if any('topology' in task for task in body['tasks']):
                raise proof.ProofError('tasks.orchestration_unsupported')
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
                                   'create': {'network': 'orbittask0', **self.resources}, 'image_fingerprint': self.seed}}
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
        self.fixture = {'budget_seconds': 10, 'queue_timeout_seconds': 1,
                        'expect': {'status': 'succeeded', 'outputs': {'job': 'effect', 'step': 'read', 'match': {'ready': True}}},
                        'phases': [{'name': 'read', 'job': 'effect', 'step': 'read', 'budget_seconds': 5}],
                        'parity': {'cases': ['legacy-read-only', 'legacy-exact-result']}}
        self.history = {'id': 'native-exact-root', 'status': 'succeeded', 'startedAt': '2026-11-01T00:00:00Z',
                        'completedAt': '2026-11-01T00:00:04Z', 'jobs': [{'name': 'effect', 'steps': [{
                            'name': 'read', 'status': 'succeeded', 'startedAt': '2026-11-01T00:00:01Z',
                            'completedAt': '2026-11-01T00:00:03Z', 'outputs': {'ready': True, 'other': 'public'}}]}]}

    def test_native_timestamps_and_explicit_queue_policy_are_recorded(self):
        result = proof.evaluate(self.history, self.fixture, 0)
        self.assertEqual(result['total_seconds'], 4)
        self.assertEqual(result['phases'][0]['seconds'], 2)
        self.assertTrue(result['expect_pass'])
        self.assertTrue(result['budget_pass'])
        self.assertTrue(result['parity']['passed'])
        self.assertEqual(result['queue_timeout_note'], 'explicit workflow policy')

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


class ChildPhaseTest(unittest.TestCase):
    def setUp(self):
        self.root = {'id': 'root', 'workflowId': 'root-flow', 'workflowName': 'dispatcher', 'status': 'succeeded',
                     'startedAt': '2026-11-01T00:00:00Z', 'completedAt': '2026-11-01T00:00:10Z',
                     'jobs': [{'name': 'dispatch', 'steps': [{'name': 'child', 'status': 'succeeded', 'outputs': {}}]}]}
        self.middle = dict(copy.deepcopy(self.root), id='middle', workflowId='middle-flow', workflowName='intermediate',
                           parentRun={'runId': 'root', 'workflowId': 'root-flow', 'workflowName': 'dispatcher', 'stepName': 'child'})
        self.child = dict(copy.deepcopy(self.root), id='effect', workflowId='effect-flow', workflowName='node-role-converge',
                          parentRun={'runId': 'middle', 'workflowId': 'middle-flow', 'workflowName': 'intermediate', 'stepName': 'child'},
                          jobs=[{'name': 'converge', 'steps': [{'name': 'apply', 'status': 'succeeded', 'startedAt': '2026-11-01T00:00:01Z',
                                                             'completedAt': '2026-11-01T00:00:05Z'}]}])
        self.fixture = {'phases': [{'name': 'role', 'workflow': 'node-role-converge', 'job': 'converge', 'step': 'apply', 'budget_seconds': 3}],
                        'budget_seconds': 10, 'queue_timeout_seconds': 2, 'expect': {'status': 'succeeded'}, 'parity': {'cases': ['role']}}

    def resolve(self, children=None, changed=None):
        children = children if children is not None else [self.child, self.middle]
        rows = [dict(runId=row['id'], **{key: row[key] for key in ('workflowId', 'workflowName')}) for row in children]
        top = Mock()
        by_id = {row['id']: row for row in [self.middle, self.child, *children]}
        by_id['another-root'] = {**self.root, 'id': 'another-root', 'workflowId': 'middle-flow', 'workflowName': 'intermediate'}
        def history(*argv):
            if argv[0] == 'search':
                self.assertEqual(argv, ('search', '--workflow', 'node-role-converge'))
                self.assertTrue(all('parentRun' not in row for row in rows))
                return {'results': rows}
            return (changed or {}).get(argv[1], by_id[argv[1]])
        top.history.side_effect = history
        return runner.phase_histories(top, self.root, self.fixture)

    def test_nested_child_uses_its_own_native_timing_and_records_full_linked_histories(self):
        unrelated = dict(self.child, id='unrelated', parentRun={**self.child['parentRun'], 'runId': 'another-root'})
        selected, histories = self.resolve([unrelated, self.child, self.middle])
        self.assertEqual([row['id'] for row in histories], ['middle', 'effect'])
        result = proof.evaluate(self.root, self.fixture, 0, selected)
        self.assertEqual(result['total_seconds'], 10)
        self.assertEqual(result['phases'][0]['seconds'], 4)
        self.assertEqual(result['phases'][0]['native_run_id'], 'effect')
        self.assertEqual(result['phases'][0]['workflow'], 'node-role-converge')
        self.assertFalse(result['budget_pass'])
        self.fixture['phases'][0]['budget_seconds'] = 4
        self.assertTrue(proof.evaluate(self.root, self.fixture, 0, selected)['budget_pass'])

    def test_absent_or_multiple_linked_runs_never_select_latest_or_an_unrelated_run(self):
        duplicate = dict(self.child, id='second')
        for rows in ([], [self.child, duplicate, self.middle]):
            with self.subTest(rows=rows), self.assertRaisesRegex(proof.ProofError, 'exactly one linked'):
                self.resolve(rows)

    def test_rechecked_child_identity_parent_and_terminal_state_must_match(self):
        for change in ({'id': 'foreign'}, {'workflowId': 'foreign'}, {'workflowName': 'foreign'},
                       {'parentRun': {**self.child['parentRun'], 'workflowId': 'foreign'}}, {'status': 'running'}):
            with self.subTest(change=change), self.assertRaises(proof.ProofError):
                self.resolve(changed={'effect': {**self.child, **change}})

    def test_cycles_and_conflicting_parent_identity_are_refused(self):
        for parent in ({**self.middle['parentRun'], 'workflowId': 'wrong'},
                       {'runId': 'effect', 'workflowId': 'effect-flow', 'workflowName': 'node-role-converge', 'stepName': 'apply'}):
            with self.subTest(parent=parent), self.assertRaises(proof.ProofError):
                self.resolve([self.child, {**self.middle, 'parentRun': parent}])

    def test_parent_step_and_optional_forward_link_must_identify_this_child(self):
        for steps in ([], self.root['jobs'][0]['steps'] * 2,
                      [{'name': 'child', 'nestedRun': {'runId': 'foreign', 'workflowId': 'middle-flow', 'workflowName': 'intermediate'}}]):
            with self.subTest(steps=steps), self.assertRaises(proof.ProofError):
                self.root['jobs'][0]['steps'] = steps
                self.resolve()

    def test_child_missing_phase_does_not_use_root_step_with_same_name(self):
        selected, _ = self.resolve()
        self.fixture['phases'][0]['step'] = 'missing'
        with self.assertRaisesRegex(proof.ProofError, 'exact fixture job/step'):
            proof.evaluate(self.root, self.fixture, 0, selected)
        with self.assertRaisesRegex(proof.ProofError, 'linked phase workflow'):
            proof.evaluate(self.root, self.fixture, 0)

    def test_fixture_phase_accepts_only_nonempty_optional_workflow(self):
        fixture = proof.fixture_for('firewall.inspect')
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            (root / 'swamp/proof').mkdir(parents=True)
            (root / 'swamp/workflows').symlink_to(proof.ROOT / 'swamp/workflows')
            (root / 'swamp/proof/topologies').symlink_to(proof.ROOT / 'swamp/proof/topologies')
            (root / 'apps').symlink_to(proof.ROOT / 'apps')
            path = root / 'swamp/proof/firewall.inspect.json'
            for value in ('node-role-converge', '', None, 42):
                fixture['phases'][0]['workflow'] = value
                path.write_text(json.dumps(fixture))
                if value == 'node-role-converge':
                    self.assertEqual(proof.fixture_for('firewall.inspect', root)['phases'][0]['workflow'], value)
                else:
                    with self.subTest(value=value), self.assertRaises(proof.ProofError):
                        proof.fixture_for('firewall.inspect', root)


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
    def test_fixture_without_explicit_positive_dispatch_budget_is_refused(self):
        original = json.loads((proof.ROOT / 'swamp/proof/firewall.inspect.json').read_text())
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            folder = root / 'swamp/proof'
            folder.mkdir(parents=True)
            for value in (None, 0, True):
                fixture = dict(original, queue_timeout_seconds=value)
                (folder / 'firewall.inspect.json').write_text(json.dumps(fixture))
                with self.subTest(value=value), self.assertRaisesRegex(proof.ProofError, 'queue_timeout_seconds'):
                    proof.fixture_for('firewall.inspect', root)

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
        self.assertEqual(fixture['before_request'], [{'argv': ['python3', '-I', 'swamp/proof/firewall.inspect/setup.py']}])
        for hook in (['sh', 'x'], {'argv': ['sh', 'x'], 'timeout_seconds': 301}, {'argv': ['sh', 'x'], 'timeout_seconds': True}):
            invalid = dict(fixture, before_request=[hook])
            with self.subTest(hook=hook), self.assertRaises(proof.ProofError):
                requests.validate_commands(invalid)
        self.assertEqual(proof.bind('${proof.epoch+300}', {}, datetime(2026, 11, 1, tzinfo=UTC)), 1793491500)
        with self.assertRaises(proof.ProofError):
            proof.bind('${proof.target.host}', {'proof.target.host': 'production'}, datetime.now(UTC))

    def test_topology_uses_existing_catalog_seed_and_node_prerequisites(self):
        topology = proof.topology_for('app-dev')
        self.assertEqual(topology['nodes'], ['app-dev'])
        self.assertEqual(topology['seed']['build_id'], 147)
        self.assertEqual(topology['seed']['base_fingerprint'], '3aaa1160b86e5e1b6a0d244fef2845dd1243997fead7c3baf1d86ff9e2017bd1')
        self.assertEqual(proof.topology_for('app-prod')['nodes'], ['app-prod'])
        self.assertEqual(proof.topology_for('app-prod-2')['nodes'], ['app-prod', 'app-prod-2'])
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

    def test_outer_group_never_declares_the_nested_topology(self):
        gateway = FakeGateway()
        state = self.up(gateway)
        body = next(body for method, path, body, _key in gateway.calls if method == 'POST' and path == 'task-groups')
        self.assertEqual(body['project_id'], 62)
        self.assertEqual(body['orchestration'], 'external')
        self.assertEqual(state['nodes'], ['app-dev'])
        self.assertEqual(state['seed_provenance'], proof.topology_for('app-dev')['seed'])
        self.assertNotIn('topology', body['tasks'][0])

    def test_different_promoted_seed_refuses_before_acquisition_and_cancels_owned_group(self):
        gateway = FakeGateway()
        gateway.seed = 'b' * 64
        action = Mock()
        with self.assertRaisesRegex(proof.ProofError, 'differs from the preset seed'):
            self.up(gateway, action)
        action.assert_not_called()
        state = sessions.load_state(self.path)
        self.assertEqual(state['vm']['seed'], 'b' * 64)
        self.assertEqual(state['seed_provenance'], proof.topology_for('app-dev')['seed'])
        self.assertTrue(state['vm']['destroyed'])

    def test_multi_node_shape_refuses_before_allocating_or_recording_a_session(self):
        gateway = FakeGateway()
        with self.assertRaisesRegex(proof.ProofError, 'large-VM size'):
            sessions.up(gateway, name='["app-dev","app-prod"]', path=self.path)
        self.assertEqual(gateway.calls, [])
        self.assertFalse(self.path.exists())

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

    def test_unconfigured_medium_vm_refuses_before_nested_acquisition(self):
        gateway = FakeGateway()
        gateway.resources = {'cpus': 2, 'memory': '4GiB', 'disk': '20GiB', 'topology': False}
        action = Mock()
        with self.assertRaisesRegex(proof.ProofError, 'configured medium VM'):
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
                        'budget_seconds': 10, 'queue_timeout_seconds': 1,
                        'prepare': [{'argv': ['orbit', 'project:create', '--json'], 'capture': {'project_id': '$.id'}}],
                        'before_request': [{'argv': ['python3', '-I', 'observer.py', '${capture.project_id}'], 'timeout_seconds': 23}],
                        'request': {'argv': ['orbit', 'firewall:inspect', '--node=${topology.app-dev.node_id}', '--json'], 'operation': '$.id'},
                        'envelope': {'node_id': '${topology.app-dev.node_id}', 'generated': '${match.uuid}'},
                        'expect': {'status': 'succeeded', 'outputs': {'job': 'effect', 'step': 'result', 'match': {'ready': True}}},
                        'phases': [{'name': 'inspect', 'job': 'effect', 'step': 'result', 'budget_seconds': 2}],
                        'parity': {'command': ['python3', 'parity.py'], 'cases': ['legacy-inspect']}}
        self.state = {'worktree': str(proof.ROOT), 'issue': 'TASK-42', 'commit': 'c' * 40, 'nodes': ['app-dev'],
                      'vm': {'group_id': 42, 'seed': 'a' * 64}, 'topology': 'app-dev', 'topology_version': 1,
                      'seed_provenance': proof.topology_for('app-dev')['seed']}
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
                self.assertEqual(kwargs['cwd'], proof.ROOT)
                self.assertEqual(kwargs['timeout'], 23)
                self.assertEqual(argv[-1], '17')
                self.assertNotIn('PROOF_OPERATION_ID', kwargs['env'])
                return SimpleNamespace(returncode=hook_exit, stdout='observer ready', stderr='')
            events.append('parity')
            if self.fixture.get('secrets'):
                self.parity_secret_file = Path(kwargs['env']['PROOF_SECRETS'])
                self.assertEqual(json.loads(self.parity_secret_file.read_text()), {'password': self.api_secret})
                self.assertEqual(self.parity_secret_file.stat().st_mode & 0o777, 0o600)
            else:
                self.assertNotIn('PROOF_SECRETS', kwargs['env'])
            self.assertEqual(kwargs['cwd'], proof.ROOT)
            self.assertEqual(json.loads(Path(kwargs['env']['PROOF_OUTPUTS']).read_text()), {'ready': True})
            self.assertEqual(kwargs['env']['PROOF_OPERATION_ID'], '73')
            return SimpleNamespace(returncode=parity_exit, stdout='', stderr='')
        def history(*args):
            events.append('history')
            return {'results': [{'runId': 'exact-root', 'workflowName': 'operation-firewall-inspect', 'inputs': {'operation_id': 73}}]} if args[0] == 'search' else self.history
        top.cli.side_effect = cli
        def api_node(name, argv, **kwargs):
            events.append('request')
            self.assertEqual(name, 'operator')
            self.assertEqual(argv[0:2], ['php', '/home/orbit/orbit/tools/swamp-proof-request.php'])
            self.api_secret = json.loads(kwargs['stdin'])['password']
            self.assertNotIn(self.api_secret, json.dumps(argv))
            return {'data': {'operation': {'id': 73}}}
        top.node.side_effect = api_node
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
        self.assertEqual(result['seed_provenance'], self.state['seed_provenance'])
        self.assertEqual(result['enrollment'], 'fixture-set')
        self.assertEqual(result['topology_ca'], 'fixture-set')
        self.assertEqual(result['fixture_repositories'], 'separate-caddy')
        self.assertEqual(result['github_token_path'], 'not_exercised')

    def test_mounted_source_refusal_and_acquisition_limits_survive_in_the_kind_receipt(self):
        refusal = {'error_code': 'gateway.release_web_build_unavailable', 'step': 'web',
                   'message': 'GitHub Actions artifact read unavailable.'}
        self.state.update(gateway_install='mounted_source', release_runner_exercised=False,
                          adopt_refusal=refusal, limitations=[refusal])
        self.fixture['limitations'] = ['fixture enrollment only']
        result, _, _ = self.execute()
        self.assertTrue(result['proof_pass'])
        self.assertEqual(result['gateway_install'], 'mounted_source')
        self.assertIs(result['release_runner_exercised'], False)
        self.assertEqual(result['adopt_refusal'], refusal)
        self.assertEqual(result['limitations'], [refusal, 'fixture enrollment only'])

    def test_private_api_request_preserves_operation_identity_and_scoped_parity_material(self):
        self.fixture.update(secrets=[{'name': 'password', 'value': 'generate:hex32'}], limitations=['fixture enrollment only'])
        self.fixture['request'] = {'api': {'method': 'POST', 'path': '/api/v1/proof', 'body': {'password': '${secret.password}'}},
                                   'operation': '$.data.operation.id'}
        for parity_exit in (0, 1):
            result, events, snapshots = self.execute(parity_exit=parity_exit)
            self.assertEqual(result['operation_id'], 73)
            self.assertEqual(result['proof_pass'], parity_exit == 0)
            self.assertEqual(result['limitations'], ['fixture enrollment only'])
            self.assertEqual(result['workload_roles'], 'recorded_not_converged')
            self.assertEqual(result['workload_roles_reason'], sessions.WORKLOAD_ROLES['workload_roles_reason'])
            self.assertEqual(result['request']['body']['password'], '[REDACTED:password]')
            self.assertNotIn(self.api_secret, json.dumps(snapshots))
            self.assertFalse(self.parity_secret_file.exists())
            self.assertEqual(events.count('request'), 1)

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
    def test_runtime_uuid_matcher_keeps_array_length_order_and_recursive_subset_rows(self):
        uuid = '4ba84968-1919-40b2-b919-f0ced3951770'
        expected = {'native_registry': {'runtimes': [{'process_id': '${match.uuid}', 'port': 8790}]}}
        actual = {'native_registry': {'unchecked': True, 'runtimes': [{'process_id': uuid, 'port': 8790}]}}
        self.assertEqual(proof.input_mismatches(actual, expected), [])
        actual['native_registry']['runtimes'][0]['unchecked'] = True
        self.assertEqual(proof.input_mismatches(actual, expected), [])
        for rows in ([], [{'process_id': uuid, 'port': 8790}] * 2,
                     [{'process_id': 'invalid', 'port': 8790}], [{'process_id': uuid, 'port': 8791}]):
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


class RepositoryBindingTest(unittest.TestCase):
    def test_committed_variant_payload_binds_public_local_url_before_prepare(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            (root / 'swamp/proof/instance.create/source/repository').mkdir(parents=True)
            receipt = {'url': 'https://repositories.orbit/run/instance.create-source.git', 'branch': 'main', 'commit': 'a' * 40}
            top = SimpleNamespace(root=root, node=Mock(return_value=receipt))
            context = {}
            self.assertEqual(runner.bind_repository(top, {}, context, 'instance.create', 'run', 'source'), receipt)
            self.assertEqual(context['proof.repo.url'], receipt['url'])
            self.assertEqual(top.node.call_args.args, ('gateway', ['sudo', '-n', 'python3', '-I', '/home/orbit/orbit/tools/swamp_proof_repository.py', 'instance.create', 'run', 'repositories.orbit', 'source']))

    def test_missing_payload_refuses_without_node_effect_or_admission(self):
        with tempfile.TemporaryDirectory() as tmp:
            top = SimpleNamespace(root=Path(tmp), node=Mock())
            fixture = {'prepare': [{'argv': ['orbit', 'project:create', '${proof.repo.url}']}]}
            with self.assertRaisesRegex(proof.ProofError, 'committed repository payload'):
                runner.bind_repository(top, fixture, {}, 'instance.create', 'run')
            self.assertIsNone(runner.bind_repository(top, {}, {}, 'firewall.inspect', 'run'))
            top.node.assert_not_called()


class AcquisitionReadinessTest(unittest.TestCase):
    def test_bootstrap_never_marks_ready_without_current_release_and_isolated_worker(self):
        state = {'issue': 'TASK-42', 'commit': 'c' * 40, 'vm': {'group_id': 42}, 'nodes': ['app-dev']}
        record = {'source': {'host_sha': state['commit'], 'guest_sha': state['commit'], 'dirty': False, 'mounted': True}, 'verification': {'passed': True}}
        acquired = {'state': 'acquired', 'issue': state['issue'], 'topology': record}
        sandbox = {'ready': True, 'worker': 'orbit-node-209'}
        gateway = {'release_sha': state['commit'], 'release': 'proof-release'}
        for wrong in (None, 'worker', 'ready', 'release_sha', 'release', 'command'):
            instance = copy.deepcopy(state)
            observed_sandbox, observed_gateway = dict(sandbox), dict(gateway)
            if wrong in ('worker', 'ready'):
                observed_sandbox[wrong] = False
            elif wrong is not None and wrong != 'command':
                observed_gateway[wrong] = None
            def command(_guest, _user, argv, *args, **kwargs):
                if argv[0] == 'stat':
                    return b'600:orbit'
                return json.dumps(acquired).encode() if 'acquire' in argv else b''
            observations = proof.ProofError('Readiness command failed') if wrong == 'command' else [observed_sandbox, observed_gateway]
            with self.subTest(wrong=wrong), patch.object(sessions, 'user_call', side_effect=command), patch.object(sessions, 'harness_json', side_effect=observations):
                if wrong is None:
                    sessions.bootstrap(Mock(), {'node': {'user': 'orbit'}}, instance, b'bundle')
                    self.assertTrue(instance['ready'])
                    self.assertEqual(instance['gateway_install'], 'adopt')
                    self.assertEqual(instance['workload_roles'], 'recorded_not_converged')
                    self.assertTrue(instance['workload_roles_reason'])
                else:
                    with self.assertRaises(proof.ProofError):
                        sessions.bootstrap(Mock(), {'node': {'user': 'orbit'}}, instance, b'bundle')
                    self.assertFalse(instance.get('ready', False))

    def test_mounted_source_requires_the_exact_web_refusal_and_both_live_readiness_reads(self):
        state = {'issue': 'TASK-42', 'commit': 'c' * 40, 'vm': {'group_id': 42}, 'nodes': ['app-dev']}
        refusal = {'error_code': 'gateway.release_web_build_unavailable', 'step': 'web',
                   'message': 'GitHub Actions artifact read unavailable.'}
        record = {'source': {'host_sha': state['commit'], 'guest_sha': state['commit'], 'dirty': False, 'mounted': True},
                  'verification': {'passed': True}}
        acquired = {'state': 'acquired', 'issue': state['issue'], 'topology': record,
                    'gateway_install': 'mounted_source', 'release_runner_exercised': False,
                    'adopt_refusal': refusal, 'limitations': [refusal]}
        for wrong in (None, 'code', 'step', 'message', 'missing_refusal', 'runner_true', 'runner_zero', 'runner_missing',
                      'install', 'worker', 'ready', 'sandbox_command', 'gateway_command', 'host_sha', 'guest_sha',
                      'dirty', 'unmounted', 'verification'):
            instance, observed = copy.deepcopy(state), copy.deepcopy(acquired)
            sandbox = {'ready': True, 'worker': 'orbit-node-209'}
            # Real status succeeds, but there is deliberately no adopted release.
            gateway = {'release': None, 'release_sha': None}
            if wrong in ('code', 'step', 'message'):
                field = {'code': 'error_code', 'step': 'step', 'message': 'message'}[wrong]
                observed['adopt_refusal'][field] = None
            elif wrong == 'missing_refusal':
                del observed['adopt_refusal']
            elif wrong in ('runner_true', 'runner_zero'):
                observed['release_runner_exercised'] = True if wrong == 'runner_true' else 0
            elif wrong == 'runner_missing':
                del observed['release_runner_exercised']
            elif wrong == 'install':
                observed['gateway_install'] = 'adopt'
            elif wrong in ('worker', 'ready'):
                sandbox[wrong] = False
            elif wrong in ('host_sha', 'guest_sha'):
                observed['topology']['source'][wrong] = 'd' * 40
            elif wrong == 'dirty':
                observed['topology']['source']['dirty'] = True
            elif wrong == 'unmounted':
                observed['topology']['source']['mounted'] = False
            elif wrong == 'verification':
                observed['topology']['verification']['passed'] = False
            def command(_guest, _user, argv, *args, observed=observed, **kwargs):
                if argv[0] == 'stat':
                    return b'600:orbit'
                return json.dumps(observed).encode() if 'acquire' in argv else b''
            observations = [sandbox, gateway]
            if wrong == 'sandbox_command':
                observations[0] = proof.ProofError('Native verify failed')
            elif wrong == 'gateway_command':
                observations[1] = proof.ProofError('Gateway access refused')
            with self.subTest(wrong=wrong), patch.object(sessions, 'user_call', side_effect=command), \
                 patch.object(sessions, 'harness_json', side_effect=observations) as readiness:
                if wrong is None:
                    sessions.bootstrap(Mock(), {'node': {'user': 'orbit'}}, instance, b'bundle')
                    self.assertTrue(instance['ready'])
                    self.assertEqual(instance['gateway_install'], 'mounted_source')
                    self.assertIs(instance['release_runner_exercised'], False)
                    self.assertEqual(instance['adopt_refusal'], refusal)
                    self.assertEqual(instance['limitations'], [refusal])
                    self.assertEqual(instance['gateway_status'], gateway)
                    self.assertEqual([call.args[3] for call in readiness.call_args_list], ['gateway', 'operator'])
                else:
                    with self.assertRaises(proof.ProofError):
                        sessions.bootstrap(Mock(), {'node': {'user': 'orbit'}}, instance, b'bundle')
                    self.assertFalse(instance.get('ready', False))

    def test_generic_acquired_status_cannot_claim_a_verified_exact_source(self):
        state = {'issue': 'TASK-42', 'commit': 'c' * 40}
        record = {'source': {'host_sha': state['commit'], 'guest_sha': state['commit'], 'dirty': False, 'mounted': True}, 'verification': {'passed': True}}
        acquired = {'state': 'acquired', 'issue': 'TASK-42', 'topology': record}
        self.assertEqual(sessions.acquisition_record(acquired, state), record)
        for field, value in (('host_sha', 'd' * 40), ('guest_sha', 'd' * 40), ('dirty', True), ('mounted', False)):
            bad = copy.deepcopy(acquired)
            bad['topology']['source'][field] = value
            with self.subTest(field=field), self.assertRaises(proof.ProofError):
                sessions.acquisition_record(bad, state)
        acquired['topology']['verification']['passed'] = False
        with self.assertRaises(proof.ProofError):
            sessions.acquisition_record(acquired, state)


if __name__ == '__main__':
    unittest.main()
