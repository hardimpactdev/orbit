"""Exercise serve's actual grant parser, not a copy of its regex safety rules."""
import importlib.util
import json
import os
import shutil
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('grant_check', ROOT / 'bin/check-grant-patterns.py')
gate = importlib.util.module_from_spec(spec)
spec.loader.exec_module(gate)


class GrantPatternsNative(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cache = Path(os.environ.get('SWAMP_RUNTIME_CACHE', Path.home() / '.cache/orbit-shape-runtime'))
        cls.binary, cls.version = gate.runtime.pinned_runtime(cache, ROOT)

    def setUp(self):
        temp = tempfile.TemporaryDirectory(prefix='grant-policy-test-')
        self.addCleanup(temp.cleanup)
        self.root = Path(temp.name)
        for name in ('config/gateway-workflow-grants.json', 'config/serve-grants.json'):
            destination = self.root / name
            destination.parent.mkdir(parents=True, exist_ok=True)
            shutil.copyfile(ROOT / name, destination)

    def change(self, name, mutate):
        path = self.root / 'config' / name
        value = json.loads(path.read_text())
        mutate(value)
        path.write_text(json.dumps(value))

    def check(self, inherited=None):
        return gate.validate_grants(self.root, self.binary, self.version, os.environ if inherited is None else inherited)

    def test_all_current_gateway_and_serve_grants_pass_native_startup_check(self):
        report = self.check()
        gateway = json.loads((self.root / 'config/gateway-workflow-grants.json').read_text())
        serve = json.loads((self.root / 'config/serve-grants.json').read_text())
        self.assertEqual(report['grant_files'], 2)
        self.assertEqual(report['grants'], len(gateway['vaults']) + len(gateway['workflows']) + len(serve['grants']))

    def test_optional_group_after_quantifier_in_late_gateway_row_is_refused(self):
        self.change('gateway-workflow-grants.json', lambda p: p['vaults'][-1].update(
            key_pattern='^database-server-[1-9][0-9]*(?:-client-[0-9]+)?$'))
        with self.assertRaisesRegex(ValueError, 'catastrophic backtracking'):
            self.check()

    def test_invalid_pattern_and_complete_cel_conditions_in_serve_grants_are_refused(self):
        for condition, error in [('key.matches("[")', 'regular expression'),
                                 ('key.matches("^safe$") && missing_field == 1', 'CEL')]:
            with self.subTest(condition=condition):
                self.change('serve-grants.json', lambda p: p['grants'][-1].update(condition=condition))
                with self.assertRaisesRegex(ValueError, error):
                    self.check()

    def test_unknown_grant_config_cannot_be_silently_skipped(self):
        (self.root / 'config/extra-grants.json').write_text('{"grants": []}')
        with self.assertRaisesRegex(ValueError, 'complete release-renderer coverage'):
            self.check()

    def test_missing_account_fails_instead_of_reporting_validation_success(self):
        with self.assertRaisesRegex(ValueError, 'auth_gate_blocked'):
            self.check({})


if __name__ == '__main__':
    unittest.main()
