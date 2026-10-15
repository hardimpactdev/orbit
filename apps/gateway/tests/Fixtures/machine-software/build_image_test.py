"""Image program regressions. No network, package manager, service, or live path is used."""
import importlib.util
import contextlib
import io
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest
from unittest.mock import patch

shared, image = sys.argv[1:]
sys.argv = sys.argv[:1]
spec = importlib.util.spec_from_file_location('image_recipe', shared)
recipe = importlib.util.module_from_spec(spec)
spec.loader.exec_module(recipe)
exec(compile(Path(image).read_text(), image, 'exec'), recipe.__dict__)


class ImageRecipeTest(unittest.TestCase):
    def setUp(self):
        self.work = tempfile.TemporaryDirectory()
        self.addCleanup(self.work.cleanup)
        self.policy = Path(self.work.name) / 'policy'
        self.commands = []
        self.files = {}
        self.payload = {'snapshot': '20261014T000000Z', 'architecture': 'amd64', 'profile': 'base',
                        'tools': {'curl': {'source': 'ubuntu', 'version': '2.0', 'package': 'curl'}}}

    def run_command(self, argv, **kwargs):
        self.commands.append(argv)
        return subprocess.CompletedProcess(argv, 0, '', '')

    def publish(self, path, content, *args):
        self.files[str(path)] = content
        if path == self.policy:
            self.policy.write_bytes(content)

    def packages(self, command=None):
        with patch.object(recipe, 'POLICY', self.policy), \
             patch.object(recipe, 'packages', return_value={'curl': '1.0'}), \
             patch.object(recipe, 'publish', side_effect=self.publish), \
             patch.object(recipe, 'run', side_effect=command or self.run_command):
            recipe.image_packages(self.payload)

    def test_installs_exact_pins_only_from_snapshot_and_upgrades_base_from_stock(self):
        self.packages()
        self.assertIn(b'https://snapshot.ubuntu.com/ubuntu/20261014T000000Z/',
                      self.files['/etc/apt/sources.list.d/ubuntu.sources'])
        install = next(c for c in self.commands if c[:2] == ['apt-get', 'install'])
        self.assertIn('curl=2.0', install)
        self.assertIn('Dir::Etc::sourceparts=-', install)
        self.assertTrue(any(c[:2] == ['apt-get', 'full-upgrade'] for c in self.commands))
        self.assertFalse(self.policy.exists())

    def test_php_sets_load_pcov_only_in_the_cli(self):
        self.payload['php'] = {'8.5': ['core', 'pcov'], '8.4': ['core', 'pcov']}
        self.packages()
        for version in ('8.4', '8.5'):
            self.assertIn(['phpenmod', '-v', version, '-s', 'cli', 'pcov'], self.commands)
            self.assertIn(['phpdismod', '-v', version, '-s', 'fpm', 'pcov'], self.commands)

    def test_smoke_rejects_a_php_cli_that_loads_other_extensions_than_the_recipe(self):
        self.payload.update({'identity': 'sha256:current', 'tools': {}, 'php': {'8.5': ['core', 'pcov']}})
        marker = json.dumps({'identity': self.payload['identity'], 'profile': 'base'})
        for modules, fails in (('[PHP Modules]\nCore\npcov\n', False), ('[PHP Modules]\nCore\npcov\napcu\n', True)):
            output = subprocess.CompletedProcess([], 0, modules, '')
            with self.subTest(modules=modules), patch.object(recipe.Path, 'read_text', return_value=marker), \
                 patch.object(recipe.Path, 'exists', return_value=False), \
                 patch.object(recipe, 'inspect', return_value={}), \
                 patch.object(recipe, 'run', return_value=output):
                if fails:
                    with self.assertRaises(recipe.RecipeFailure):
                        recipe.image_verify(self.payload)
                else:
                    recipe.image_verify(self.payload)

    def test_role_packages_do_not_upgrade_the_published_base(self):
        self.payload['profile'] = 'gateway'
        self.packages()
        self.assertFalse(any(c[:2] == ['apt-get', 'full-upgrade'] for c in self.commands))

    def test_removes_restart_policy_after_failed_install(self):
        def fail(argv, **kwargs):
            if argv[:2] == ['apt-get', 'install']:
                raise recipe.RecipeFailure()
            return self.run_command(argv, **kwargs)
        with self.assertRaises(recipe.RecipeFailure):
            self.packages(fail)
        self.assertFalse(self.policy.exists())

    def test_rejects_artifact_package_metadata_before_installing(self):
        self.payload['tools'] = {'caddy': {'source': 'artifact', 'version': '1.2.3',
                                         'artifacts': {'amd64': {'url': 'https://example.test/caddy-1.2.3.deb'}}}}
        def metadata(argv, **kwargs):
            self.commands.append(argv)
            return subprocess.CompletedProcess(argv, 0, 'Package: foreign\nVersion: 1.2.3\nArchitecture: amd64\n', '')
        with patch.object(recipe, 'download', side_effect=lambda pin, arch, path: path):
            with self.assertRaises(recipe.RecipeFailure):
                self.packages(metadata)
        self.assertFalse(any(c[:2] == ['apt-get', 'install'] for c in self.commands))

    def test_installs_architecture_independent_debs_with_exact_name_and_epoch_version(self):
        self.payload['tools'] = {'php-common': {'source': 'artifact', 'version': '2:101',
                                 'artifacts': {'amd64': {'url': 'https://example.test/php-common_101_all.deb'}}}}
        def metadata(argv, **kwargs):
            self.commands.append(argv)
            output = 'Package: php-common\nVersion: 2:101\nArchitecture: all\n' if argv[0] == 'dpkg-deb' else ''
            return subprocess.CompletedProcess(argv, 0, output, '')
        with patch.object(recipe, 'download', side_effect=lambda pin, arch, path: path):
            self.packages(metadata)
        self.assertTrue(any(c[:2] == ['apt-get', 'install'] for c in self.commands))

    def test_rejects_a_deb_for_another_architecture(self):
        self.payload['tools'] = {'caddy': {'source': 'artifact', 'version': '1.2.3',
                                'artifacts': {'amd64': {'url': 'https://example.test/caddy-1.2.3.deb'}}}}
        def metadata(argv, **kwargs):
            return subprocess.CompletedProcess(argv, 0, 'Package: caddy\nVersion: 1.2.3\nArchitecture: arm64\n', '')
        with patch.object(recipe, 'download', side_effect=lambda pin, arch, path: path):
            with self.assertRaises(recipe.RecipeFailure):
                self.packages(metadata)

    def test_failed_subprocess_keeps_both_streams_and_exception_in_private_phase_log(self):
        original_path = recipe.Path
        directory = original_path(self.work.name) / 'logs'
        def path(value):
            return directory if value == '/var/log/orbit/recipe-images' else original_path(value)
        def fail(payload):
            recipe.run([sys.executable, '-c', "import sys; print('package stdout'); print('dependency conflict', file=sys.stderr); sys.exit(17)"])
        with patch.object(recipe, 'Path', side_effect=path), \
             patch.object(recipe, 'platform_check'), \
             patch.object(recipe, 'image_packages', side_effect=fail), \
             patch.object(sys, 'argv', ['installer', 'packages', json.dumps(self.payload)]), \
             contextlib.redirect_stdout(io.StringIO()), contextlib.redirect_stderr(io.StringIO()) as errors:
            self.assertEqual(recipe.image_main(), 1)
        log = directory / 'base-packages.log'
        self.assertEqual(log.stat().st_mode & 0o777, 0o600)
        self.assertIn('package stdout', log.read_text())
        self.assertIn('dependency conflict', log.read_text())
        self.assertIn('exit code: 17', log.read_text())
        self.assertIn('RecipeFailure', log.read_text())
        self.assertIn('dependency conflict', errors.getvalue())

    def test_skips_matching_javascript_without_fetching_packages(self):
        self.payload['tools'] = {'node': {'version': '24.1.0'}}
        with patch.object(recipe, 'inspect', return_value={'node': '24.1.0'}), \
             patch.object(recipe, 'download') as download:
            recipe.image_javascript(self.payload)
        download.assert_not_called()

    def test_rust_and_agent_do_nothing_when_absent_from_the_effective_profile(self):
        with patch.object(recipe, 'download') as download:
            recipe.image_rust(self.payload)
            recipe.image_agent(self.payload)
        download.assert_not_called()

    def test_smoke_rejects_broken_pnpm_even_when_its_package_metadata_matches(self):
        self.payload.update({'identity': 'sha256:current', 'tools': {'pnpm': {'version': '12.11.2'}}})
        marker = json.dumps({'identity': self.payload['identity'], 'profile': 'base'})
        with patch.object(recipe.Path, 'read_text', return_value=marker), \
             patch.object(recipe, 'inspect', return_value={'pnpm': '12.11.2'}), \
             patch.object(recipe, 'run', side_effect=recipe.RecipeFailure('pnpm executable failed')) as command:
            with self.assertRaises(recipe.RecipeFailure):
                recipe.image_verify(self.payload)
        self.assertEqual(command.call_args.args[0][-2:], ['/usr/local/bin/pnpm', '--version'])

    def test_smoke_rejects_wrong_recipe_identity_before_checking_software(self):
        self.payload.update({'identity': 'sha256:current'})
        with patch.object(recipe.Path, 'read_text', return_value='{"identity":"sha256:old","profile":"base"}'):
            with self.assertRaises(recipe.RecipeFailure):
                recipe.image_verify(self.payload)


unittest.main()
