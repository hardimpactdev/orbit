"""Validation credentials stay scoped and cannot use ambient runner configuration."""
import importlib.util
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


class ValidateCI(unittest.TestCase):
    def test_required_validation_only_receives_event_scoped_credentials(self):
        source = (ROOT / '.github/workflows/swamp-shape.yml').read_text()
        self.assertEqual(source, (ROOT.parent / '.github/workflows/swamp-shape.yml').read_text())
        shape = source.split('  shape:', 1)[1].split('  validate:', 1)[0]
        validate = source.split('  validate:', 1)[1].split('  tests:', 1)[0]
        tests = source.split('  tests:', 1)[1]
        for name in ('SWAMP_API_KEY', 'SWAMP_SIGNIN_TOKEN'):
            self.assertNotIn(name, shape)
            self.assertNotIn(name, tests)
            self.assertIn('secrets.' + name, validate)
        self.assertNotIn('allow-auth-unavailable', source)
        self.assertNotIn('continue-on-error', validate)
        self.assertNotIn('pull_request_target', source)
        self.assertIn("github.event_name == 'pull_request'", validate)
        self.assertIn("github.ref == 'refs/heads/main'", validate)

    def test_native_environment_is_explicit_and_configuration_is_isolated(self):
        spec = importlib.util.spec_from_file_location('validate', ROOT / 'bin/validate-swamp-workflows.py')
        module = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(module)
        env = module.native_env('/fixture/fresh', {'HOME': '/ambient', 'SWAMP_CONFIG_DIR': '/ambient',
            'SWAMP_API_KEY': 'synthetic-key', 'SWAMP_SIGNIN_TOKEN': 'synthetic-proof', 'UNAPPROVED': 'synthetic'})
        for name in ('HOME', 'XDG_CONFIG_HOME', 'XDG_CACHE_HOME', 'SWAMP_CONFIG_DIR'):
            self.assertEqual(env[name], '/fixture/fresh')
        self.assertEqual(env['SWAMP_API_KEY'], 'synthetic-key')
        self.assertEqual(env['SWAMP_SIGNIN_TOKEN'], 'synthetic-proof')
        self.assertNotIn('UNAPPROVED', env)
        self.assertNotIn('SWAMP_API_KEY', module.native_env('/fresh', {}))


if __name__ == '__main__':
    unittest.main()
