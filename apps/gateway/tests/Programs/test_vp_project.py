"""Read Project version requirements as data, with no hooks and no unsafe file reads."""
import json
import os
import tempfile
import unittest
from pathlib import Path

from harness import assemble, execute_source


class VpProjectTest(unittest.TestCase):
    def setUp(self):
        directory = tempfile.TemporaryDirectory(prefix='orbit-vp-project-')
        self.addCleanup(directory.cleanup)
        self.root = Path(directory.name)
        self.manifest = {
            'devDependencies': {'vite-plus': '0.3.3'},
            'packageManager': 'npm@11.12.0',
            'engines': {'node': '24.22.0'},
            'scripts': {'preinstall': f'touch {self.root / "hook-ran"}'},
        }
        self.write_manifest()

    def write_manifest(self):
        (self.root / 'package.json').write_text(json.dumps(self.manifest))

    def inspect(self):
        name = 'tools.read-vp-project'
        completed = execute_source(
            name, assemble(name), json.dumps({'path': str(self.root)}).encode(), timeout=5,
        )
        self.assertEqual(completed.returncode, 0, completed.stderr)
        parsed = json.loads(completed.stdout)
        self.assertFalse((self.root / 'hook-ran').exists())
        return parsed['outcome'], parsed['data']

    def test_reads_pins_and_manager_signal_without_running_hooks(self):
        (self.root / '.node-version').write_text('24.22.0\n')
        (self.root / '.nvmrc').write_text('lts/*\n')
        (self.root / 'package-lock.json').write_text('{}')
        outcome, data = self.inspect()
        self.assertEqual(outcome, 'read')
        self.assertEqual(data, {
            'package': self.manifest, '.node-version': '24.22.0\n', '.nvmrc': 'lts/*\n',
            'package_manager_hint': 'npm', 'locks': {'package-lock.json': '{}'}, 'workspaces': {}, 'npmrc': '\n',
        })

    def test_missing_manifest_is_an_unrelated_project(self):
        (self.root / 'package.json').unlink()
        self.assertEqual(self.inspect(), ('read', {
            'package': {}, '.node-version': None, '.nvmrc': None, 'package_manager_hint': None,
            'locks': {}, 'workspaces': {}, 'npmrc': '',
        }))

    def test_does_not_read_node_pins_for_unrelated_projects(self):
        self.manifest = {'name': 'not-vite'}
        self.write_manifest()
        (self.root / '.node-version').mkdir()
        self.assertEqual(self.inspect()[0], 'read')

    def test_rejects_malformed_or_non_object_manifests(self):
        for contents in ('{', '[]', 'null'):
            with self.subTest(contents=contents):
                (self.root / 'package.json').write_text(contents)
                self.assertEqual(self.inspect(), ('invalid', {}))

    def test_refuses_symlinks_in_manifest_and_node_pins(self):
        for name in ('package.json', '.node-version', '.nvmrc'):
            with self.subTest(name=name):
                file = self.root / name
                if file.exists():
                    file.unlink()
                target = self.root / 'outside'
                target.write_text('secret data')
                file.symlink_to(target)
                self.assertEqual(self.inspect(), ('invalid', {}))
                file.unlink()
                self.write_manifest()

    def test_rejects_nonregular_files_without_blocking(self):
        pin = self.root / '.node-version'
        pin.mkdir()
        self.assertEqual(self.inspect(), ('invalid', {}))
        pin.rmdir()
        os.mkfifo(pin)
        self.assertEqual(self.inspect(), ('invalid', {}))

    def test_rejects_oversized_version_files(self):
        (self.root / '.node-version').write_text('x' * 1048577)
        self.assertEqual(self.inspect(), ('invalid', {}))
