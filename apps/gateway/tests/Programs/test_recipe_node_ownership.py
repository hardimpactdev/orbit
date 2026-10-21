"""Recipe Node replaces duplicate APT ownership without removing dependencies."""
from pathlib import Path
import subprocess
import tempfile
import unittest
from unittest.mock import patch

from test_recipe_versions import recipe


class NodeOwnershipTest(unittest.TestCase):
    def setUp(self):
        directory = tempfile.TemporaryDirectory()
        self.addCleanup(directory.cleanup)
        self.apt = Path(directory.name)
        (self.apt / 'sources.list.d').mkdir()
        self.source = self.apt / 'sources.list.d/nodesource.sources'
        self.content = b'Types: deb\nURIs: https://deb.nodesource.com/node_24.x\nSuites: nodistro\nComponents: main\nSigned-By: /usr/share/keyrings/nodesource.gpg\n'
        self.source.write_bytes(self.content)
        self.tools = {'node': {'version': '24.22.1'}}
        self.enterContext(patch.object(recipe, 'APT_ETC', self.apt))
        self.enterContext(patch.object(recipe, 'publish', side_effect=lambda path, content: path.write_bytes(content)))

    def test_dry_run_reports_both_owners_without_removal_or_source_writes(self):
        with patch.object(recipe, 'packages', return_value={'nodejs': '24.20.0-1nodesource1'}), \
             patch.object(recipe, 'run') as command:
            self.assertEqual(recipe.consolidate_node(self.tools), ['remove_nodejs_package', 'retire_node_source'])
        command.assert_not_called()
        self.assertEqual(self.source.read_bytes(), self.content)
        self.assertFalse((self.apt / 'nodesource.sources.orbit-node-backup').exists())

    def test_verifies_replacement_then_removes_only_nodejs_and_retires_source(self):
        events = []
        def inspected(tools):
            events.append('verified')
            return {'node': '24.22.1'}
        def command(argv, packages, **kwargs):
            events.append(argv)
            return subprocess.CompletedProcess(argv, 0, 'Remv nodejs [24.20.0-1nodesource1]\n', '')
        with patch.object(recipe, 'packages', side_effect=[{'nodejs': '24.20.0-1nodesource1'}, {}]), \
             patch.object(recipe, 'inspect', side_effect=inspected), \
             patch.object(recipe, 'package_command', side_effect=command):
            recipe.consolidate_node(self.tools, apply=True)
        self.assertEqual(events, ['verified', ['apt-get', '--simulate', 'remove', '--', 'nodejs'],
                                  ['apt-get', 'remove', '--yes', '--', 'nodejs']])
        self.assertFalse(self.source.exists())
        self.assertEqual((self.apt / 'nodesource.sources.orbit-node-backup').read_bytes(), self.content)
        with patch.object(recipe, 'packages', return_value={}):
            self.assertEqual(recipe.consolidate_node(self.tools), [])

    def test_refuses_dependency_removal_and_keeps_source(self):
        with patch.object(recipe, 'packages', return_value={'nodejs': '24.20.0-1nodesource1'}), \
             patch.object(recipe, 'inspect', return_value={'node': '24.22.1'}), \
             patch.object(recipe, 'package_command', return_value=subprocess.CompletedProcess([], 0, 'Remv nodejs [1]\nRemv dependent-app [1]\n', '')) as command:
            with self.assertRaisesRegex(recipe.RecipeFailure, 'node-ownership'):
                recipe.consolidate_node(self.tools, apply=True)
        self.assertEqual(command.call_count, 1)
        self.assertTrue(self.source.exists())

    def test_missing_replacement_never_removes_package_or_source(self):
        with patch.object(recipe, 'packages', return_value={'nodejs': '24.20.0-1nodesource1'}), \
             patch.object(recipe, 'inspect', return_value={'node': None}), \
             patch.object(recipe, 'package_command') as command:
            with self.assertRaisesRegex(recipe.RecipeFailure, 'node-ownership'):
                recipe.consolidate_node(self.tools, apply=True)
        command.assert_not_called()
        self.assertTrue(self.source.exists())

    def test_mixed_provider_entry_is_refused_before_package_mutation(self):
        self.source.write_bytes(self.content.replace(b'node_24.x', b'node_24.x https://example.test/repo'))
        with patch.object(recipe, 'package_command') as command:
            with self.assertRaises(recipe.RecipeFailure):
                recipe.consolidate_node(self.tools, apply=True)
        command.assert_not_called()

    def test_profiles_without_node_do_not_change_apt_ownership(self):
        with patch.object(recipe, 'packages') as packages:
            self.assertEqual(recipe.consolidate_node({}, apply=True), [])
        packages.assert_not_called()
        self.assertTrue(self.source.exists())
