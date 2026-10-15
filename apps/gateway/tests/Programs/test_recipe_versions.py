"""Recipe floors and diagnostics. All APT, dpkg, service and network operations are faked."""
import contextlib
import importlib.util
import io
import json
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest
from unittest.mock import patch

RESOURCE = Path(__file__).resolve().parents[2] / 'resources/machine-software'
spec = importlib.util.spec_from_file_location('recipe_versions', RESOURCE / 'converge-node.py')
recipe = importlib.util.module_from_spec(spec)
spec.loader.exec_module(recipe)
image_spec = importlib.util.spec_from_file_location('recipe_image_versions', RESOURCE / 'converge-node.py')
image = importlib.util.module_from_spec(image_spec)
image_spec.loader.exec_module(image)
exec(compile((RESOURCE / 'build-image.py').read_text(), 'build-image.py', 'exec'), image.__dict__)

PIN = '8.18.0-1ubuntu2.7'
NEWER = '8.18.0-1ubuntu2.8'
INDEX = 'https://security.ubuntu.com/ubuntu resolute-security/main amd64 Packages'


class RecipeVersionsTest(unittest.TestCase):
    def setUp(self):
        self.tools = {'curl': {'source': 'ubuntu', 'package': 'curl', 'version': PIN}}
        self.release = 'v=26.04,o=Ubuntu,a=resolute-security,n=resolute,l=Ubuntu,c=main,b=amd64'
        self.version_policy = f'''curl:
  Installed: {NEWER}
  Candidate: {NEWER}
  Version table:
 *** {NEWER} 500
        500 {INDEX}
        100 /var/lib/dpkg/status
     {PIN} 500
        500 https://snapshot.ubuntu.com/ubuntu/20261014T000000Z resolute/main amd64 Packages
'''
        self.commands = []

    def command(self, argv, **kwargs):
        self.commands.append(argv)
        output = ''
        code = 0
        if argv[:2] == ['dpkg', '--compare-versions']:
            self.assertEqual(argv[3:], ['gt', PIN])
            self.assertFalse(kwargs['required'])
            code = 0 if argv[2] == NEWER else 1
        elif argv == ['dpkg', '--print-architecture']:
            output = 'amd64\n'
        elif argv == ['apt-cache', 'policy']:
            output = f'Package files:\n 500 {INDEX}\n     release {self.release}\n     origin security.ubuntu.com\n'
        elif argv == ['apt-cache', 'policy', 'curl:amd64']:
            output = self.version_policy
        return subprocess.CompletedProcess(argv, code, output, '')

    def assess(self, installed):
        with patch.object(recipe, 'run', side_effect=self.command):
            return recipe.assess(self.tools, {'curl': installed})['curl']

    def test_newer_same_release_security_build_satisfies_floor_with_both_versions(self):
        self.assertEqual(self.assess(NEWER), {'source': 'ubuntu', 'version': PIN, 'installed': NEWER,
                                           'changed': False, 'status': 'newer_than_recipe'})

    def test_release_and_updates_pockets_are_also_accepted(self):
        for pocket in ('resolute', 'resolute-updates', 'resolute-security'):
            with self.subTest(pocket=pocket):
                self.release = f'o=Ubuntu,a={pocket},n=resolute'
                self.assertEqual(self.assess(NEWER)['status'], 'newer_than_recipe')

    def test_exact_older_and_missing_versions(self):
        for installed, status, changed in [(PIN, 'current', False), ('8.18.0-1ubuntu2.6', 'older_than_recipe', True),
                                            (None, 'missing', True)]:
            with self.subTest(installed=installed):
                result = self.assess(installed)
                self.assertEqual((result['status'], result['changed']), (status, changed))
        self.assertFalse(any(c[0] == 'apt-cache' for c in self.commands))

    def test_other_release_third_party_and_unknown_origins_are_refused_without_downgrade(self):
        for release in ('o=Ubuntu,a=other-security,n=other', 'o=ThirdParty,a=resolute-security,n=resolute',
                        'o=Ubuntu,a=resolute-proposed,n=resolute', ''):
            with self.subTest(release=release):
                self.release = release
                tool = self.assess(NEWER)
                self.assertEqual((tool['status'], tool['changed']), ('unsupported_newer', False))
                with self.assertRaisesRegex(recipe.RecipeFailure, 'origin:curl'):
                    recipe.supported({'curl': tool})

    def test_another_versions_trusted_index_does_not_prove_the_installed_origin(self):
        self.version_policy = self.version_policy.replace(f'500 {INDEX}', '100 /var/lib/dpkg/status')
        self.version_policy += f'     {PIN} 500\n        500 {INDEX}\n'
        self.assertEqual(self.assess(NEWER)['status'], 'unsupported_newer')

    def test_a_dpkg_status_only_version_is_not_accepted(self):
        self.version_policy = f'curl:\n *** {NEWER} 100\n        100 /var/lib/dpkg/status\n'
        self.assertEqual(self.assess(NEWER)['status'], 'unsupported_newer')

    def test_a_snapshot_only_newer_version_can_be_confirmed_without_losing_normal_origins(self):
        def command(argv, **kwargs):
            if argv[:2] == ['apt-cache', 'policy'] and '-o' not in argv:
                return subprocess.CompletedProcess(argv, 0, '', '')
            return self.command([arg for arg in argv if arg not in ('-o', 'snapshot-options')], **kwargs)
        with patch.object(recipe, 'run', side_effect=command):
            report = recipe.assess(self.tools, {'curl': NEWER}, ['-o', 'snapshot-options'])
        self.assertEqual(report['curl']['status'], 'newer_than_recipe')

    def test_epoch_versions_are_passed_intact_to_dpkg(self):
        tools = {'openssh-client': {'source': 'ubuntu', 'package': 'openssh-client', 'version': '1:10.2p1-2ubuntu3.7'}}
        with patch.object(recipe, 'run', return_value=subprocess.CompletedProcess([], 0, '', '')) as run, \
             patch.object(recipe, 'ubuntu_origin', return_value=True):
            result = recipe.assess(tools, {'openssh-client': '1:10.2p1-2ubuntu3.8'})
        self.assertEqual(run.call_args.args[0], ['dpkg', '--compare-versions', '1:10.2p1-2ubuntu3.8', 'gt', '1:10.2p1-2ubuntu3.7'])
        self.assertFalse(result['openssh-client']['changed'])

    def test_sury_php_artifacts_stay_exact_even_when_the_installed_build_is_newer(self):
        tools = {'php8.5-cli': {'source': 'artifact', 'version': '8.5.11-1'}}
        with patch.object(recipe, 'run') as run:
            result = recipe.assess(tools, {'php8.5-cli': '8.5.12-1'})
        self.assertTrue(result['php8.5-cli']['changed'])
        self.assertEqual(result['php8.5-cli']['status'], 'different')
        run.assert_not_called()

    def test_dpkg_inventory_requires_the_native_or_all_architecture_and_installed_status(self):
        output = (f'curl\tamd64\t{NEWER}\tinstall ok installed\n'
                  'curl\tarm64\t99\tinstall ok installed\n'
                  'ca-certificates\tall\t2026\tinstall ok installed\n'
                  'git\tamd64\t3\tdeinstall ok config-files\n')
        def command(argv, **kwargs):
            return subprocess.CompletedProcess(argv, 0, 'amd64' if argv[0] == 'dpkg' else output, '')
        with patch.object(recipe, 'run', side_effect=command):
            self.assertEqual(recipe.packages(), {'curl': NEWER, 'ca-certificates': '2026'})

    def test_node_apply_keeps_newer_packages_reports_drift_and_never_installs_the_old_pin(self):
        with tempfile.TemporaryDirectory() as directory:
            policy = Path(directory) / 'policy'
            def publish(path, content, *args):
                Path(path).write_bytes(content)
            with patch.object(recipe, 'POLICY', policy), \
                 patch.object(recipe, 'packages', return_value={'curl': NEWER}), \
                 patch.object(recipe, 'inspect', return_value={'curl': NEWER}), \
                 patch.object(recipe, 'active_services', return_value=set()), \
                 patch.object(recipe, 'publish', side_effect=publish), \
                 patch.object(recipe.pwd, 'getpwnam', return_value=None), \
                 patch.object(recipe, 'run', side_effect=self.command):
                result = recipe.apply({'tools': self.tools, 'architecture': 'amd64', 'snapshot': '20261014T000000Z'})
        self.assertEqual(result['changed_tools'], [])
        self.assertEqual(result['changed_packages'], [])
        self.assertEqual(result['newer_than_recipe'], {'curl': {'installed': NEWER, 'version': PIN}})
        self.assertFalse(any(c[:2] == ['apt-get', 'install'] for c in self.commands))

    def test_node_refuses_foreign_newer_package_before_sources_or_installs_change(self):
        self.release = 'o=Ubuntu,a=other-security,n=other'
        with patch.object(recipe, 'packages', return_value={'curl': NEWER}), \
             patch.object(recipe, 'inspect', return_value={'curl': NEWER}), \
             patch.object(recipe, 'publish') as publish, \
             patch.object(recipe.pwd, 'getpwnam', return_value=None), \
             patch.object(recipe, 'run', side_effect=self.command):
            with self.assertRaisesRegex(recipe.RecipeFailure, 'origin:curl'):
                recipe.apply({'tools': self.tools, 'architecture': 'amd64', 'snapshot': '20261014T000000Z'})
        publish.assert_not_called()
        self.assertFalse(any(c[0] == 'apt-get' for c in self.commands))

    def test_image_rechecks_after_full_upgrade_and_uses_the_same_floor_in_verification(self):
        payload = {'tools': self.tools, 'architecture': 'amd64', 'snapshot': '20261014T000000Z', 'profile': 'base', 'identity': 'sha256:current'}
        with tempfile.TemporaryDirectory() as directory:
            policy = Path(directory) / 'policy'
            with patch.object(image, 'POLICY', policy), \
                 patch.object(image, 'packages', return_value={'curl': NEWER}), \
                 patch.object(image, 'run', side_effect=self.command), \
                 patch.object(image, 'publish', side_effect=lambda path, content, *args: Path(path).write_bytes(content)):
                image.image_packages(payload)
            self.assertFalse(any(c[:2] == ['apt-get', 'install'] for c in self.commands))
            self.assertTrue(any(c[:2] == ['apt-get', 'full-upgrade'] for c in self.commands))
            with patch.object(image.Path, 'read_text', return_value=json.dumps({'identity': 'sha256:current', 'profile': 'base'})), \
                 patch.object(image.Path, 'exists', return_value=False), \
                 patch.object(image, 'publish', side_effect=lambda path, content, *args: Path(path).write_bytes(content)), \
                 patch.object(image, 'inspect', return_value={'curl': NEWER}), \
                 patch.object(image, 'run', side_effect=self.command):
                image.image_verify(payload)
                self.release = 'o=Ubuntu,a=other-security,n=other'
                with self.assertRaises(image.RecipeFailure):
                    image.image_verify(payload)

    def test_failed_command_keeps_output_and_package_identity_through_the_entrypoint(self):
        argv = [sys.executable, '-c', "import sys; print('curl dependency conflict'); print('API_TOKEN=private-token', file=sys.stderr); sys.exit(100)"]
        with self.assertRaises(recipe.RecipeFailure) as failed:
            recipe.package_command(argv, ['curl=' + PIN])
        self.assertEqual(failed.exception.result.returncode, 100)
        self.assertIn('curl dependency conflict', failed.exception.result.stdout)
        with patch.object(recipe, 'platform_check'), \
             patch.object(recipe, 'apply', side_effect=recipe.RecipeFailure('packages', result=failed.exception.result, packages=failed.exception.packages)), \
             patch.object(sys, 'argv', ['program', 'apply', '{"architecture":"amd64"}']), \
             contextlib.redirect_stdout(io.StringIO()) as output:
            self.assertEqual(recipe.main(), 1)
        failure = json.loads(output.getvalue())['failure']
        self.assertEqual(failure['packages'], ['curl=' + PIN])
        self.assertIn('curl dependency conflict', failure['stdout'])
        self.assertEqual(failure['stderr'], 'API_TOKEN=[REDACTED]\n')
        self.assertEqual(failure['command'], argv)

    def test_large_unicode_and_control_output_stays_within_the_ssh_response_limit(self):
        result = recipe.failure_output(('☃\n\t\x1b' * 15000))
        envelope = json.dumps({'failure': {'stdout': result, 'stderr': result}}, ensure_ascii=False)
        self.assertLess(len(envelope.encode()), 40000)
        self.assertNotIn('\x1b', result)

    def test_failure_output_is_redacted_before_it_is_bounded(self):
        value = 'x' * 15000 + '\nhttps://user:password@example.test/pkg\nAuthorization: Bearer private-token\nSECRET="a b c"\nAWS_ACCESS_KEY_ID=hidden-access\nCOOKIE=session-data\n'
        result = recipe.failure_output(value)
        self.assertLessEqual(len(result.encode()), 8000)
        for secret in ('user:password', 'private-token', 'a b c', 'hidden-access', 'session-data'):
            self.assertNotIn(secret, result)
        self.assertIn('[REDACTED]', result)


if __name__ == '__main__':
    unittest.main()
