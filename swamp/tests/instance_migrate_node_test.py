"""Exercise the shipped Node-pin preflight on temporary checkouts and runtime homes."""
import importlib.util
import json
import tempfile
import unittest
from pathlib import Path

MODULE = Path(__file__).resolve().parents[1] / 'ansible/library/orbit_migrate_node.py'
SPEC = importlib.util.spec_from_file_location('orbit_migrate_node', MODULE)
guard = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(guard)


class NodePinTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name) / 'checkout'
        self.root.mkdir()
        self.home = Path(self.temp.name) / 'vite-home'
        binary = self.home / 'js_runtime/node/24.22.1/bin/node'
        binary.parent.mkdir(parents=True)
        binary.write_text('fixture')
        binary.chmod(0o755)

    def inspect(self, files, application=None, processes=()):
        for file, value in files.items():
            path = self.root / file
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_text(json.dumps(value) if isinstance(value, dict) else value)
        return guard.inspect_node_versions(str(self.root), str(application or self.root), processes, str(self.home))

    def test_unavailable_nvmrc_has_named_fields_and_does_not_change_checkout(self):
        self.inspect({'.nvmrc': '22\n'})
        before = {path.name: path.read_bytes() for path in self.root.iterdir()}
        refusal = self.inspect({})
        self.assertEqual(refusal['details'], {'pin_source': '.nvmrc', 'pinned': '22', 'available': ['24.22.1']})
        self.assertIn('.nvmrc pins Node 22', refusal['message'])
        self.assertIn('later release', refusal['message'])
        self.assertEqual({path.name: path.read_bytes() for path in self.root.iterdir()}, before)

    def test_exact_pins_and_no_pin_pass(self):
        for files in [{}, {'package.json': {'packageManager': 'pnpm@10.0.0'}},
                      {'.nvmrc': '24.22.1'}, {'.node-version': '24.22.1'}, {'.node-version': 'v24.22.1'},
                      {'package.json': {'engines': {'node': '24.22.1'}}},
                      {'package.json': {'devEngines': {'runtime': {'name': 'node', 'version': '24.22.1'}}}},
                      {'package.json': {'devEngines': {'runtime': [{'name': 'node', 'version': '24.22.1'}]}}}]:
            with self.subTest(files=files):
                for path in self.root.iterdir():
                    path.unlink()
                self.assertIsNone(self.inspect(files))

    def test_partial_ranges_and_aliases_fail_closed(self):
        for pin in ['24', 'v24', '24.22', '>=20', '^24.0.0', 'lts/*', 'lts', 'latest']:
            with self.subTest(pin=pin):
                for path in self.root.iterdir():
                    path.unlink()
                files = {'package.json': {'engines': {'node': pin}}} if '=' in pin else {'.nvmrc': pin}
                refusal = self.inspect(files)
                self.assertEqual(refusal['details']['pinned'], pin)
                self.assertIn('cannot be proved available offline', refusal['message'])

    def test_vite_source_precedence_and_closest_directory(self):
        files = {'.node-version': '24.22.1', '.nvmrc': '22',
                 'package.json': {'devEngines': {'runtime': {'name': 'node', 'version': '22'}},
                                  'engines': {'node': '22'}}}
        self.assertIsNone(self.inspect(files))
        (self.root / '.node-version').unlink()
        self.assertEqual(self.inspect({})['details']['pin_source'], 'package.json:devEngines.runtime')
        self.assertIsNone(self.inspect({'package.json': {'engines': {'node': '24.22.1'}}}))
        self.inspect({'apps/web/.nvmrc': '22'})
        refusal = self.inspect({}, self.root / 'apps/web')
        self.assertEqual(refusal['details']['pin_source'], 'apps/web/.nvmrc')

    def test_process_working_directory_cannot_hide_a_pin_and_other_managers_are_ignored(self):
        self.assertIsNone(self.inspect({'package.json': {'volta': {'node': '22'}}, '.tool-versions': 'nodejs 22'}))
        self.inspect({'web/.nvmrc': '22'})
        refusal = self.inspect({}, processes=[{'content': '[Service]\nWorkingDirectory=/srv/workspace/web\nExecStart=vp dev\n'}])
        self.assertEqual(refusal['details']['pin_source'], 'web/.nvmrc')

    def test_only_executable_versions_in_the_selected_home_are_available(self):
        (self.home / 'js_runtime/node/24.22.1/bin/node').chmod(0o644)
        refusal = self.inspect({'.node-version': '24.22.1'})
        self.assertEqual(refusal['details']['available'], [])


if __name__ == '__main__':
    unittest.main()
