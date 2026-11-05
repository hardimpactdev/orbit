"""The runner commands that the workflow `proof-fleet-verify` calls: the plan lines, the restore of base and the batch setup."""
import contextlib
import io
import json
import os
import sys
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
sys.path.insert(0, str(Path(__file__).resolve().parents[2] / 'reset'))
sys.path.insert(0, str(Path(__file__).resolve().parent))
import fleet as fleet_module
import phases
import runner
import spec
from fakes import FakeFleet

PROOF = Path(__file__).resolve().parents[2]
CASES = PROOF / 'firewall' / 'allow.cases.yaml'
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


class RestoreTest(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory()
        self.addCleanup(self.directory.cleanup)
        self.request = Path(self.directory.name) / 'request.json'
        self.request.write_text(json.dumps({'group': '7'}))

    def test_a_restore_prints_one_ok_line_and_exits_0(self):
        with patch.object(phases, 'execute', return_value={'snapshot': 'base'}) as restore:
            code, lines = call('restore', '--group', '7', '--request', str(self.request))
        self.assertEqual(code, 0)
        restore.assert_called_once_with({'group': '7'}, 'base-restore')
        self.assertEqual(len(lines), 1)
        self.assertEqual(json.loads(lines[0])['restore'], 'ok')

    def test_a_restore_that_fails_prints_a_failed_line_with_the_reason_and_is_not_retried(self):
        with patch.object(phases, 'execute', side_effect=RuntimeError('The tunnel did not come back.')) as restore:
            code, lines = call('restore', '--group', '7', '--request', str(self.request))
        self.assertEqual(code, 1)
        self.assertEqual(restore.call_count, 1)
        line = json.loads(lines[0])
        self.assertEqual(line['restore'], 'failed')
        self.assertIn('The tunnel did not come back.', line['error'])
        self.assertIn('"restore": "failed"', lines[0])  # the workflow's guards match this text

    def test_a_group_with_no_reset_request_is_a_failed_restore(self):
        with patch.object(runner, 'latest_request', side_effect=fleet_module.FleetError('no reset request for group 9')):
            code, lines = call('restore', '--group', '9')
        self.assertEqual(code, 1)
        self.assertIn('no reset request for group 9', json.loads(lines[0])['error'])

    def test_the_request_is_the_newest_generation_of_the_group(self):
        root = Path(self.directory.name)
        for number, generation in enumerate(('old', 'new')):
            path = root / '.orbit-artifacts' / '7' / 'reset' / generation
            path.mkdir(parents=True)
            (path / 'request.json').write_text('{}')
            os.utime(path / 'request.json', (1000 + number, 1000 + number))
        self.assertEqual(runner.latest_request('7', root).parent.name, 'new')
        with self.assertRaises(fleet_module.FleetError):
            runner.latest_request('8', root)


class SetupTest(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory()
        self.addCleanup(self.directory.cleanup)
        patcher = patch.object(fleet_module, 'from_environment', lambda: FakeFleet())
        patcher.start()
        self.addCleanup(patcher.stop)

    def setup_batch(self, batch):
        return call('setup', batch, '--file', str(CASES), '--batches', str(BATCHES), '--evidence', self.directory.name)

    def test_a_batch_with_no_setup_steps_is_ok(self):
        code, lines = self.setup_batch('fresh-fleet')
        self.assertEqual(code, 0)
        self.assertEqual(json.loads(lines[0])['setup'], 'ok')

    def test_a_setup_step_that_is_not_built_fails_the_batch_setup(self):
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
