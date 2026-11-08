"""Recorded-read capabilities use the existing step engine; malformed/missing data must never become a pass."""
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path
from unittest.mock import Mock, patch

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
import fleet
import runner
import spec
import steps
from fakes import FakeFleet


class RecordedReadsTest(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory()
        self.addCleanup(self.directory.cleanup)
        self.run = runner.Run(FakeFleet(), spec.Spec('node:show', 'node/show', 'app-dev', ['gateway', 'app-prod'], [], []), self.directory.name)

    def test_structured_values_preserve_null_and_compare_complete_assignments(self):
        roles = [{'id': 9, 'role': 'database', 'status': 'failed', 'failed_step': 'converge:database', 'error_code': 'node.role_failed'}]
        document = {'nodes': [{'id': 2, 'assignments': roles}], 'agent': None}
        wanted = [{'path': 'nodes[id=2].assignments', 'equals': roles}, {'path': 'agent', 'equals': None}, {'path': 'skipped', 'absent': True}]
        self.assertEqual(steps.check_json(document, wanted), [])
        document['nodes'][0]['assignments'][0]['status'] = 'active'
        # The expectation is not a reference to the actual mutable object.
        self.assertTrue(steps.check_json(document, [{'path': 'nodes[id=2].assignments[0].status', 'equals': 'failed'}]))
        self.assertTrue(steps.check_json({}, [{'path': 'agent', 'equals': None}]))
        self.assertTrue(steps.check_json({'skipped': None}, [{'path': 'skipped', 'absent': True}]))

    def test_array_index_and_unique_id_selection_refuse_ambiguous_or_missing_rows(self):
        self.assertEqual(steps.json_value({'assignments': [{'status': 'removing'}]}, 'assignments[0].status'), 'removing')
        for rows in ([], [{'id': 2}, {'id': 2}]):
            with self.assertRaises(steps.Infra):
                steps.json_value({'nodes': rows}, 'nodes[id=2]')
        with self.assertRaises(steps.Infra):
            steps.json_value({'assignments': []}, 'assignments[4]')

    def test_cli_saves_typed_projection_and_asserts_json(self):
        roles = [{'id': 8, 'role': 'app-dev', 'status': 'provisioning'}]
        self.run.cli = Mock(return_value={'rc': 0, 'json': {'assignments': roles}, 'stdout': '{}', 'stderr': ''})
        self.assertEqual(steps.cli(self.run, {'run': ['node:show', '2'], 'save': {'assignments': 'recorded-roles'},
                                            'expect': {'exit': 0, 'json': [{'path': 'assignments[0].status', 'equals': 'provisioning'}]}}), [])
        self.assertEqual(self.run.resolve('${recorded-roles}'), roles)
        self.assertTrue(steps.cli(self.run, {'run': ['node:show', '2'], 'expect': {'json': [{'path': 'assignments', 'equals': []}]}}))

    def test_node_record_baseline_detects_writes_to_each_table(self):
        baseline = {'nodes': [{'id': 1}], 'node_roles': [], 'node_access': [], 'clusters': []}
        with patch.object(steps, 'recorded_guest', return_value=baseline) as guest:
            self.run.baseline = {'node_records': steps.snapshot(self.run, 'node_records')}
            self.assertEqual(steps.unchanged(self.run, {'since': 'act', 'what': ['node_records']}), [])
            for table in baseline:
                guest.return_value = {**baseline, table: [{'changed': True}]}
                self.assertTrue(steps.unchanged(self.run, {'since': 'act', 'what': ['node_records']}), table)

    def test_recorded_request_binds_caller_and_preserves_clock_boundary(self):
        response = {'status': 200, 'body': {'data': {'agent': {'state': 'online', 'since': None}}}}
        with patch.object(steps, 'recorded_guest', return_value=response) as guest:
            self.assertEqual(steps.recorded_request(self.run, {'node': 9, 'caller': 'gateway', 'agent': 'online', 'age': 15,
                                                              'expect': [{'path': 'body.data.agent.state', 'equals': 'online'}]}), [])
            self.assertEqual(guest.call_args.args[1], {'mode': 'request', 'node': 9, 'caller': self.run.fleet.nodes['gateway']['node_id'], 'agent': 'online', 'age': 15})
            self.assertTrue((Path(self.directory.name) / 'recorded-request.json').is_file())

    def test_guest_failure_or_non_json_output_is_infrastructure_not_success(self):
        self.run.fleet.sh = Mock(return_value=subprocess.CompletedProcess([], 1, '', 'audit refused outbound contact'))
        with self.assertRaisesRegex(steps.Infra, 'audit refused'):
            steps.recorded_guest(self.run, {'mode': 'request'})
        command = self.run.fleet.sh.call_args.args[1]
        for function in ('curl_exec', 'stream_socket_client', 'proc_open', 'shell_exec'):
            self.assertIn(function, command)
        self.run.fleet.sh.return_value = subprocess.CompletedProcess([], 0, 'not JSON', '')
        with self.assertRaisesRegex(steps.Infra, 'no JSON'):
            steps.recorded_guest(self.run, {'mode': 'request'})

    def test_show_cases_plan_and_bind_fixtures_and_saved_projections(self):
        root = Path(__file__).resolve().parents[4]
        show = spec.load(root / 'swamp/proof/node/show.cases.yaml', runner.BATCHES)
        self.assertEqual(len(show.cases), 25)
        self.assertEqual(show.baseline, ['operations', 'node_records'])
        self.assertTrue(all(case.lock == 'all' for case in show.cases))
        for case in show.cases:
            self.assertTrue(any(step.name == 'cli' and step.args['run'][0] == 'node:show' for stage in case.stages for step in stage.act))

    def test_retained_case_compares_assignments_without_replacing_public_role_names(self):
        root = Path(__file__).resolve().parents[4]
        show = spec.load(root / 'swamp/proof/node/show.cases.yaml', runner.BATCHES)
        retained = next(case for case in show.cases if case.id == 'retained-recorded-detail')
        assignments = [{'id': 8, 'role': 'database', 'status': 'failed', 'failed_step': 'converge:Check the role', 'error_code': 'node.role_failed'}]
        self.run.names.update({'retained.id': 9, 'recorded-roles': assignments})
        node = {'id': 9, 'roles': ['database'], 'assignments': assignments}
        documents = {'node:show': node, 'node:list': {'nodes': [node]}, 'node:role:list': {'assignments': assignments}}
        compared = set()
        for stage in retained.stages:
            for step in stage.act:
                if step.name != 'cli' or not any(item.get('equals') == '${recorded-roles}' for item in step.args.get('expect', {}).get('json', [])):
                    continue
                command = step.args['run'][0]
                with self.subTest(command=command), patch.object(steps, 'operation_ids', return_value=set()):
                    self.run.cli = Mock(return_value={'rc': 0, 'json': documents[command], 'stdout': '{}', 'stderr': ''})
                    self.assertEqual(steps.cli(self.run, self.run.resolve(step.args)), [])
                compared.add(command)
        self.assertEqual(compared, set(documents))

    def test_json_flag_precedes_option_terminator(self):
        with patch.object(fleet.subprocess, 'run', return_value=subprocess.CompletedProcess([], 1, '{}', '')) as call:
            fleet.Fleet({}).cli(['node:show', '--', '-1'])
            self.assertEqual(call.call_args.args[0][-4:], ['node:show', '--json', '--', '-1'])


if __name__ == '__main__':
    unittest.main()
