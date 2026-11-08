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
        patcher = patch.object(fleet_module, 'from_environment', lambda facts=None: FakeFleet())
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


if __name__ == '__main__':
    unittest.main()
