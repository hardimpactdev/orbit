"""Image program regressions. No network, package manager, service, or live path is used."""
import importlib.util
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

    def test_smoke_rejects_wrong_recipe_identity_before_checking_software(self):
        self.payload.update({'identity': 'sha256:current'})
        with patch.object(recipe.Path, 'read_text', return_value='{"identity":"sha256:old","profile":"base"}'):
            with self.assertRaises(recipe.RecipeFailure):
                recipe.image_verify(self.payload)


unittest.main()
