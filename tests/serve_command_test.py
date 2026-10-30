import importlib.util
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('serve_command', ROOT / 'bin/serve-command.py')
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)


class ServeCommandTest(unittest.TestCase):
    def test_changes_only_required_flags_and_env_without_adding_webhook(self):
        original = ['/usr/bin/env', 'OTHER=value', '/bin/swamp', 'serve', '--port', '9418', '--dispatch-env-allow=,', '--max-concurrent-scheduled-runs', '32', '--trusted-hosts', 'swamp.orbit']
        expected = ['/usr/bin/env', 'SWAMP_REMOTE_ONLY=1', 'OTHER=value', '/bin/swamp', 'serve', '--port', '9418', '--dispatch-env-allow=,', '--trusted-hosts', 'swamp.orbit', '--max-concurrent-scheduled-runs', '1', '--remote-only', '--hot-reload']
        self.assertEqual(module.serve_command(original), expected)
        self.assertEqual(module.serve_command(expected), expected)
        self.assertNotIn('--max-concurrent-webhook-runs', expected)

    def test_preserves_existing_webhook_and_replaces_conflicting_boolean_flags(self):
        result = module.serve_command(['/bin/swamp', 'serve', '--remote-only=false', '--hot-reload=false', '--max-concurrent-webhook-runs', '4'])
        self.assertEqual(result, ['/usr/bin/env', 'SWAMP_REMOTE_ONLY=1', '/bin/swamp', 'serve', '--max-concurrent-webhook-runs', '4', '--max-concurrent-scheduled-runs', '1', '--remote-only', '--hot-reload'])

    def test_replaces_existing_remote_env_without_touching_other_values(self):
        result = module.serve_command(['/usr/bin/env', 'SWAMP_REMOTE_ONLY=0', 'OTHER=a=b', '/bin/swamp', 'serve'])
        self.assertEqual(result[:5], ['/usr/bin/env', 'SWAMP_REMOTE_ONLY=1', 'OTHER=a=b', '/bin/swamp', 'serve'])

    def test_refuses_malformed_input_before_any_runtime_action(self):
        for value in [[], 'serve', ['/bin/swamp', 'worker'], ['/bin/swamp', 'serve', '--max-concurrent-scheduled-runs', '--remote-only']]:
            with self.assertRaises(ValueError):
                module.serve_command(value)


if __name__ == '__main__':
    unittest.main()
