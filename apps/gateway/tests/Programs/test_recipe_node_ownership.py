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
        self.usr = self.apt / 'usr'
        self.enterContext(patch.object(recipe, 'NODE_USR', self.usr))
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
            self.assertEqual(kwargs['env']['NEEDRESTART_SUSPEND'], '1')
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

    def test_backs_up_preferences_and_keyring_and_removes_dangling_corepack_links(self):
        preferences = self.apt / 'preferences.d'
        preferences.mkdir()
        for name in ('nodejs', 'nsolid'):
            (preferences / name).write_text('Package: ' + name + '\nPin: origin deb.nodesource.com\nPin-Priority: 600\n')
        keyring = self.usr / 'share/keyrings/nodesource.gpg'
        keyring.parent.mkdir(parents=True)
        keyring.write_bytes(b'NodeSource public key')
        self.source.write_text(self.content.decode().replace('/usr/share/keyrings/nodesource.gpg', str(keyring)))
        binary = self.usr / 'bin'
        binary.mkdir()
        for name in ('yarn', 'yarnpkg', 'pnpm', 'pnpx'):
            (binary / name).symlink_to('../lib/node_modules/corepack/dist/' + name + '.js')
        with patch.object(recipe, 'packages', return_value={}), patch.object(recipe, 'inspect', return_value={'node': '24.22.1'}):
            self.assertEqual(recipe.consolidate_node(self.tools), ['retire_node_source', 'retire_node_config', 'remove_corepack_links'])
            recipe.consolidate_node(self.tools, apply=True)
            self.assertEqual(recipe.consolidate_node(self.tools), [])
        self.assertFalse(keyring.exists())
        self.assertEqual((self.apt / 'nodesource.gpg.orbit-node-usr-keyring-backup').read_bytes(), b'NodeSource public key')
        for name in ('nodejs', 'nsolid'):
            self.assertFalse((preferences / name).exists())
            self.assertIn(b'origin deb.nodesource.com', (self.apt / (name + '.orbit-node-preference-backup')).read_bytes())
        self.assertEqual(list(binary.iterdir()), [])

    def test_preserves_working_and_unrelated_corepack_named_links(self):
        binary = self.usr / 'bin'
        binary.mkdir(parents=True)
        real = self.usr / 'lib/node_modules/corepack/dist/yarn.js'
        real.parent.mkdir(parents=True)
        real.touch()
        (binary / 'yarn').symlink_to(real)
        (binary / 'pnpm').symlink_to('../unrelated/missing')
        self.assertEqual(recipe.dangling_corepack_links(), [])
        self.assertTrue((binary / 'yarn').is_symlink())
        self.assertTrue((binary / 'pnpm').is_symlink())

    def test_refuses_custom_preference_without_removing_source_or_package(self):
        path = self.apt / 'preferences.d/nodejs'
        path.parent.mkdir()
        path.write_text('Package: nodejs\nPin: origin example.test\nPin-Priority: 600\n')
        with patch.object(recipe, 'package_command') as command:
            with self.assertRaises(recipe.RecipeFailure):
                recipe.consolidate_node(self.tools, apply=True)
        command.assert_not_called()
        self.assertTrue(self.source.exists())
        self.assertTrue(path.exists())

    def test_refuses_keyring_still_used_by_another_source(self):
        keyring = self.usr / 'share/keyrings/nodesource.gpg'
        keyring.parent.mkdir(parents=True)
        keyring.write_bytes(b'public key')
        (self.apt / 'sources.list.d/custom.list').write_text('deb [signed-by=' + str(keyring) + '] https://example.test nodistro main\n')
        with patch.object(recipe, 'package_command') as command:
            with self.assertRaises(recipe.RecipeFailure):
                recipe.consolidate_node(self.tools, apply=True)
        command.assert_not_called()
        self.assertTrue(keyring.exists())
