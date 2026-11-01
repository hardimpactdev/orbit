"""The shared setup phase preserves ordered private commands and stored budgets."""
import hashlib
import importlib.util
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

SCRIPT = Path(__file__).parent / 'create/setup.py'
spec = importlib.util.spec_from_file_location('setup_phase', SCRIPT)
phase = importlib.util.module_from_spec(spec)
spec.loader.exec_module(phase)


class SetupPhase(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.path = Path(self.temp.name)

    def step(self, name, command, seconds=3):
        return {'name': name, 'command': command, 'sha256': hashlib.sha256(command.encode()).hexdigest(),
                'directory': str(self.path), 'instance_id': 17, 'instance_name': 'proof',
                'seed_path': '/captured/default', 'seed_commit': 'a' * 40, 'timeout_seconds': seconds}

    def test_order_and_captured_seed(self):
        result = phase.setup({'steps': [
            self.step('first', 'test "$ORBIT_SEED_PATH" = /captured/default; printf first > order'),
            self.step('second', 'test "$(cat order)" = first; printf second >> order'),
        ]})
        self.assertEqual(result, {'ready': True, 'completed': ['first', 'second']})
        self.assertEqual((self.path / 'order').read_text(), 'firstsecond')

    def test_all_identities_are_checked_before_the_first_command(self):
        second = self.step('second', 'true')
        second['sha256'] = '0' * 64
        with self.assertRaisesRegex(ValueError, 'instance.setup_identity_changed'):
            phase.setup({'steps': [self.step('first', 'touch before'), second]})
        self.assertFalse((self.path / 'before').exists())

    def test_failure_stops_later_commands_and_fresh_invocation_converges(self):
        steps = [self.step('gate', 'test -f allowed'), self.step('next', 'touch after')]
        self.assertEqual(phase.setup({'steps': steps}), {'ready': False, 'completed': [],
                         'failed_command': 'gate', 'error_code': 'instance.setup_step_failed'})
        self.assertFalse((self.path / 'after').exists())
        (self.path / 'allowed').touch()
        self.assertTrue(phase.setup({'steps': steps})['ready'])
        self.assertTrue((self.path / 'after').exists())

    def test_timeout_is_bounded_and_stops_later_commands(self):
        result = phase.setup({'steps': [self.step('slow', 'sleep 10; touch late', 1),
                                        self.step('next', 'touch after')]})
        self.assertEqual(result['error_code'], 'operation.timed_out')
        self.assertEqual(result['failed_command'], 'slow')
        self.assertFalse((self.path / 'late').exists())
        self.assertFalse((self.path / 'after').exists())

    def test_cli_does_not_publish_private_program_output(self):
        import json
        result = subprocess.run([sys.executable, '-B', str(SCRIPT)], input=json.dumps({'steps': [
            self.step('private', 'printf private-output; printf private-error >&2')]}),
            capture_output=True, text=True, check=True)
        self.assertNotIn('private-output', result.stdout)
        self.assertNotIn('private-error', result.stderr)
        self.assertTrue(json.loads(result.stdout)['ready'])

    def test_positive_integer_budgets_only_and_empty_list_is_noop(self):
        for value in [0, -1, True, 1.5, 541]:
            with self.assertRaisesRegex(ValueError, 'instance.setup_identity_changed'):
                phase.setup({'steps': [self.step('bad', 'touch after', value)]})
        self.assertFalse((self.path / 'after').exists())
        self.assertEqual(phase.setup({'steps': []}), {'ready': True, 'completed': []})


if __name__ == '__main__':
    unittest.main()
