"""The runner commands that the workflow `proof-fleet-verify` calls: the plan lines and the batch setup."""
import contextlib
import io
import json
import sys
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

import yaml

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
sys.path.insert(0, str(Path(__file__).resolve().parent))
import fleet as fleet_module
import runner
import spec
import steps
from fakes import FakeFleet

PROOF = Path(__file__).resolve().parents[2]
CASES = Path(__file__).resolve().parent / 'fixtures' / 'allow.cases.yaml'
BATCHES = PROOF / 'batches.yaml'


def call(*argv):
    """(exit code, stdout lines) of one runner command."""
    out = io.StringIO()
    with contextlib.redirect_stdout(out):
        code = runner.main(list(argv))
    return code, out.getvalue().splitlines()


class PlanLinesTest(unittest.TestCase):
    def test_one_line_a_batch_in_batch_order_with_the_setup_flag_and_the_cases_in_file_order(self):
        code, lines = call('plan', '--lines', '--file', str(CASES), '--batches', str(BATCHES))
        self.assertEqual(code, 0)
        self.assertEqual(len(lines), 2)
        first, second = (line.split(' ') for line in lines)
        self.assertEqual(first[:3], ['fresh-fleet', '0', 'new-restricted-allow'])
        self.assertEqual(second, ['dual-stack', '1', 'family-ipv6'])

    def test_every_case_of_the_file_is_in_exactly_one_line(self):
        _, lines = call('plan', '--lines', '--file', str(CASES), '--batches', str(BATCHES))
        planned = [case for line in lines for case in line.split(' ')[2:]]
        loaded = [case.id for case in spec.load(CASES, BATCHES).cases]
        self.assertEqual(sorted(planned), sorted(loaded))
        self.assertEqual(len(planned), len(set(planned)))

    def test_a_case_id_has_no_space_so_the_workflow_can_split_the_line(self):
        _, lines = call('plan', '--lines', '--file', str(CASES), '--batches', str(BATCHES))
        self.assertTrue(all(' ' not in case.id for case in spec.load(CASES, BATCHES).cases))
        self.assertTrue(all(len(line.split(' ', 2)) == 3 for line in lines))

    def test_the_default_plan_output_is_unchanged(self):
        _, lines = call('plan', '--file', str(CASES), '--batches', str(BATCHES))
        self.assertTrue(lines[0].startswith('firewall:allow  '))
        self.assertTrue(any(line.startswith('batch fresh-fleet:') for line in lines))


class SetupTest(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory()
        self.addCleanup(self.directory.cleanup)
        prep = patch.object(fleet_module, 'prepare', return_value=FakeFleet())
        prep.start()
        self.addCleanup(prep.stop)
        patcher = patch.object(fleet_module, 'from_environment', lambda: FakeFleet())
        patcher.start()
        self.addCleanup(patcher.stop)

    def setup_batch(self, batch):
        return call('setup', batch, '--file', str(CASES), '--batches', str(BATCHES), '--evidence', self.directory.name)

    def test_a_batch_with_no_setup_steps_is_ok(self):
        code, lines = self.setup_batch('fresh-fleet')
        self.assertEqual(code, 0)
        self.assertEqual(json.loads(lines[0])['setup'], 'ok')

    def test_the_ipv6_setup_of_the_dual_stack_batch_is_ok(self):
        code, lines = self.setup_batch('dual-stack')
        self.assertEqual((code, json.loads(lines[0])['setup']), (0, 'ok'))

    def test_a_setup_step_that_is_not_built_fails_the_batch_setup(self):
        with patch.dict(steps.FUNCTIONS, {'ipv6_addresses': steps.unbuilt('ipv6_addresses')}):
            code, lines = self.setup_batch('dual-stack')
        self.assertEqual(code, 1)
        line = json.loads(lines[0])
        self.assertEqual((line['batch'], line['setup']), ('dual-stack', 'failed'))
        self.assertIn('not built', ' '.join(line['failed']))
        self.assertIn('"setup": "failed"', lines[0])  # the workflow's guards match this text

    def test_an_unknown_batch_is_refused(self):
        code, _ = self.setup_batch('nope')
        self.assertEqual(code, 3)

    def test_preparation_does_not_consume_the_batch_setup_budget(self):
        clock = [1000]
        def prepare():
            clock[0] += 121
            return FakeFleet()
        with patch.object(fleet_module, 'prepare', side_effect=prepare), \
                patch.object(runner.time, 'time', side_effect=lambda: clock[0]), \
                patch.object(runner, 'run_steps') as run_steps:
            code, lines = self.setup_batch('dual-stack')
        self.assertEqual(code, 0, lines)
        self.assertEqual(run_steps.call_args.args[-1], 1241)

    def test_failed_preparation_never_discovers_or_runs_setup(self):
        with patch.object(fleet_module, 'prepare', side_effect=fleet_module.FleetError('preparation expired')), \
                patch.object(fleet_module, 'from_environment') as discover:
            code, lines = self.setup_batch('dual-stack')
        self.assertEqual(code, 1)
        self.assertIn('preparation expired', lines[0])
        discover.assert_not_called()


class MandatoryPreparationTest(unittest.TestCase):
    def test_old_workflow_batch_call_prepares_with_or_without_setup_steps(self):
        # These are the released workflow's existing argv: no setup call and no new input are required.
        for batch in ('fresh-fleet', 'dual-stack'):
            events = []
            def prepare():
                events.append('prepare')
                return FakeFleet()
            def run_case(case, *args):
                events.append(case.id)
                return {'id': case.id, 'verdict': 'passed', 'cause': None, 'secs': 0, 'failed': []}
            with self.subTest(batch=batch), patch.object(fleet_module, 'prepare', side_effect=prepare), \
                    patch.object(runner, 'run_case', side_effect=run_case):
                code, lines = call('batch', batch, '--file', str(CASES), '--batches', str(BATCHES))
            self.assertEqual(code, 0, lines)
            self.assertEqual(events[0], 'prepare')
            self.assertEqual(events.count('prepare'), 1)
            self.assertGreater(len(events), 1)

    def test_setup_success_does_not_let_the_batch_bypass_preparation(self):
        with tempfile.TemporaryDirectory() as directory, \
                patch.object(fleet_module, 'prepare', return_value=FakeFleet()) as prepare, \
                patch.object(runner, 'run_case') as run_case:
            code, _ = call('setup', 'fresh-fleet', '--file', str(CASES), '--evidence', directory)
            self.assertEqual(code, 0)
            prepare.side_effect = fleet_module.FleetError('observer download failed')
            code, lines = call('batch', 'fresh-fleet', '--file', str(CASES), '--evidence', directory)
        self.assertEqual(prepare.call_count, 2)
        self.assertEqual(code, 1)
        self.assertIn('observer download failed', lines[0])
        run_case.assert_not_called()

    def test_failed_preparation_blocks_every_selected_case_in_every_batch(self):
        for batch in ('fresh-fleet', 'dual-stack'):
            for error in ('observer download failed', 'observer checksum refused', 'new report missing'):
                with self.subTest(batch=batch, error=error), \
                        patch.object(fleet_module, 'prepare', side_effect=fleet_module.FleetError(error)), \
                        patch.object(runner, 'run_case') as run_case:
                    code, lines = call('batch', batch, '--file', str(CASES), '--batches', str(BATCHES))
                results = [json.loads(line) for line in lines]
                expected = {case.id for case in spec.load(CASES, BATCHES).cases if case.batch == batch}
                self.assertEqual(code, 1)
                self.assertEqual({line['id'] for line in results}, expected)
                self.assertEqual({(line['verdict'], line['cause']) for line in results}, {('failed', 'infrastructure')})
                self.assertTrue(all(error in line['failed'] for line in results))
                run_case.assert_not_called()

    def test_direct_case_cannot_skip_preparation(self):
        with patch.object(fleet_module, 'prepare', side_effect=fleet_module.FleetError('new report missing')) as prepare, \
                patch.object(runner, 'run_case') as run_case:
            code, lines = call('case', 'new-restricted-allow', '--file', str(CASES), '--batches', str(BATCHES))
        self.assertEqual(code, 1)
        self.assertEqual(json.loads(lines[0])['cause'], 'infrastructure')
        prepare.assert_called_once_with()
        run_case.assert_not_called()


class FleetPreparationTest(unittest.TestCase):
    def test_candidate_collections_and_grants_are_prepared_before_the_discovery_cli(self):
        calls = []
        with patch.object(fleet_module.Fleet, 'must', side_effect=lambda argv, why, **kwargs: calls.append(argv)), \
                patch.object(fleet_module, 'discover', side_effect=lambda: calls.append('discover') or 'fleet'):
            fleet_module.prepare_observers()

        self.assertEqual(calls[0], ['incus', 'exec', 'nested-gateway', '--', 'timeout', '180', 'env',
                                   'ANSIBLE_CONFIG=/home/orbit/orbit/swamp/ansible/ansible.cfg',
                                   '/opt/orbit/ansible/venv/bin/ansible-playbook', '-i', 'localhost,', '-e', '@/opt/orbit/bootstrap/control.yml',
                                   '/home/orbit/orbit/swamp/ansible/proof-gateway.yml'])
        self.assertTrue(calls[1][0].endswith('/ansible-galaxy'))
        self.assertIn('nested-gateway,nested-app-dev,nested-app-prod,', calls[2])
        self.assertTrue(calls[2][-1].endswith('/swamp/ansible/proof-observers.yml'))
        self.assertEqual(json.loads(calls[2][calls[2].index('-e') + 1])['proof_observer_release'],
                         json.loads((fleet_module.CHECKOUT / 'apps/gateway/resources/agent/frozen-manifest.json').read_bytes()))
        self.assertEqual(calls[3], 'discover')

    def test_gateway_preparation_deadlines_allow_readiness_then_authenticated_reload(self):
        tasks = yaml.safe_load((PROOF.parent / 'ansible/tasks/gateway-swamp-grants.yml').read_text())
        wait = next(task['ansible.builtin.wait_for']['timeout'] for task in tasks if 'ansible.builtin.wait_for' in task)
        reload = next(task['ansible.builtin.command']['argv'] for task in tasks if task.get('register') == 'current_control_reload')
        with patch.object(fleet_module.subprocess, 'run') as run, patch.object(fleet_module, 'discover'):
            run.return_value.returncode = 0
            fleet_module.prepare_observers()
        gateway = run.call_args_list[0]
        argv = gateway.args[0]
        command_budget = int(argv[argv.index('timeout') + 1])
        self.assertGreaterEqual(command_budget, wait + int(reload[1]) + 30)
        self.assertGreaterEqual(gateway.kwargs['timeout'], command_budget + 10)

    def test_preparation_has_one_hard_budget_including_discovery(self):
        done = type('Done', (), {'stdout': '{"app-dev": {"node_id": 1}}'})()
        with patch.object(fleet_module.Fleet, 'must', return_value=done) as must:
            fleet = fleet_module.prepare()
        self.assertEqual(fleet.nodes, {'app-dev': {'node_id': 1}})
        self.assertEqual(must.call_args.args[0][:2], ['timeout', '330'])
        self.assertEqual(must.call_args.kwargs['timeout'], 340)

    def test_missing_or_invalid_candidate_pin_fails_before_preparation(self):
        with tempfile.TemporaryDirectory() as directory, patch.object(fleet_module, 'CHECKOUT', Path(directory)):
            pin = Path(directory) / 'apps/gateway/resources/agent/frozen-manifest.json'
            pin.parent.mkdir(parents=True)
            for content in (None, 'invalid json'):
                with self.subTest(content=content), patch.object(fleet_module.Fleet, 'must') as must:
                    if content is not None:
                        pin.write_text(content)
                    with self.assertRaises((OSError, ValueError)):
                        fleet_module.prepare_observers()
                    must.assert_not_called()

    def test_failed_observer_preparation_stops_before_any_discovery_or_case(self):
        with patch.object(fleet_module.Fleet, 'must', side_effect=[None, None, fleet_module.FleetError('observer checksum refused')]), \
                patch.object(fleet_module, 'discover') as discover:
            with self.assertRaisesRegex(fleet_module.FleetError, 'observer checksum refused'):
                fleet_module.prepare_observers()
            discover.assert_not_called()

    def test_failed_grant_preparation_stops_before_any_discovery_or_case(self):
        with patch.object(fleet_module.Fleet, 'must', side_effect=fleet_module.FleetError('grant reload refused')), \
                patch.object(fleet_module, 'discover') as discover:
            with self.assertRaisesRegex(fleet_module.FleetError, 'grant reload refused'):
                fleet_module.prepare_observers()
            discover.assert_not_called()


if __name__ == '__main__':
    unittest.main()
