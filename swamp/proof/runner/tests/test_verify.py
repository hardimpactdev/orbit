"""The runner commands that the workflow `proof-fleet-verify` calls: the plan lines and the batch setup."""
import contextlib
import io
import json
import sys
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

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
        def prepare(_):
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


class FleetPreparationTest(unittest.TestCase):
    def test_candidate_collections_and_grants_are_prepared_before_the_discovery_cli(self):
        calls = []
        with patch.object(fleet_module.Fleet, 'must', side_effect=lambda argv, why, **kwargs: calls.append(argv)), \
                patch.object(fleet_module, 'discover', side_effect=lambda: calls.append('discover') or 'fleet'):
            fleet_module.prepare_observers((fleet_module.CHECKOUT / 'apps/gateway/resources/agent/frozen-manifest.json').read_text())

        self.assertEqual(calls[0], ['incus', 'exec', 'nested-gateway', '--', 'timeout', '110', 'env',
                                   'ANSIBLE_CONFIG=/home/orbit/orbit/swamp/ansible/ansible.cfg',
                                   '/opt/orbit/ansible/venv/bin/ansible-playbook', '-i', 'localhost,', '-e', '@/opt/orbit/bootstrap/control.yml',
                                   '/home/orbit/orbit/swamp/ansible/proof-gateway.yml'])
        self.assertTrue(calls[1][0].endswith('/ansible-galaxy'))
        self.assertIn('nested-gateway,nested-app-dev,nested-app-prod,', calls[2])
        self.assertTrue(calls[2][-1].endswith('/swamp/ansible/proof-observers.yml'))
        self.assertEqual(calls[3], 'discover')

    def test_preparation_has_one_hard_budget_including_discovery(self):
        done = type('Done', (), {'stdout': '{"app-dev": {"node_id": 1}}'})()
        with patch.object(fleet_module.Fleet, 'must', return_value=done) as must:
            fleet = fleet_module.prepare('admitted manifest')
        self.assertEqual(fleet.nodes, {'app-dev': {'node_id': 1}})
        self.assertEqual(must.call_args.args[0][:2], ['timeout', '330'])
        self.assertEqual(must.call_args.args[0][-1], 'admitted manifest')
        self.assertEqual(must.call_args.kwargs['timeout'], 340)

    def test_unadmitted_or_different_pin_fails_before_running_any_candidate_code(self):
        for release in (None, '{}'):
            with self.subTest(release=release), patch.object(fleet_module.Fleet, 'must') as must:
                with self.assertRaises(fleet_module.FleetError):
                    fleet_module.prepare_observers(release)
                must.assert_not_called()

    def test_failed_observer_preparation_stops_before_any_discovery_or_case(self):
        with patch.object(fleet_module.Fleet, 'must', side_effect=[None, None, fleet_module.FleetError('observer checksum refused')]), \
                patch.object(fleet_module, 'discover') as discover:
            with self.assertRaisesRegex(fleet_module.FleetError, 'observer checksum refused'):
                fleet_module.prepare_observers((fleet_module.CHECKOUT / 'apps/gateway/resources/agent/frozen-manifest.json').read_text())
            discover.assert_not_called()

    def test_failed_grant_preparation_stops_before_any_discovery_or_case(self):
        with patch.object(fleet_module.Fleet, 'must', side_effect=fleet_module.FleetError('grant reload refused')), \
                patch.object(fleet_module, 'discover') as discover:
            with self.assertRaisesRegex(fleet_module.FleetError, 'grant reload refused'):
                fleet_module.prepare_observers((fleet_module.CHECKOUT / 'apps/gateway/resources/agent/frozen-manifest.json').read_text())
            discover.assert_not_called()


if __name__ == '__main__':
    unittest.main()
