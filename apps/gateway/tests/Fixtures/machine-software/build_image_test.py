"""Image program regressions. No network, package install, service change, or live file write is used."""
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
exec(compile((Path(spec.origin).parent / 'artifacts.py').read_text(), 'lib/artifacts.py', 'exec'), recipe.__dict__)
spec.loader.exec_module(recipe)
exec(compile(Path(image).read_text(), image, 'exec'), recipe.__dict__)


class ImageRecipeTest(unittest.TestCase):
    def setUp(self):
        self.work = tempfile.TemporaryDirectory()
        self.addCleanup(self.work.cleanup)
        self.policy = Path(self.work.name) / 'policy'
        self.apt = Path(self.work.name) / 'apt'
        (self.apt / 'preferences.d').mkdir(parents=True)
        self.commands = []
        self.files = {}
        self.payload = {'snapshot': '20261014T000000Z', 'architecture': 'amd64', 'profile': 'base',
                        'tools': {'curl': {'source': 'ubuntu', 'version': '2.0', 'package': 'curl'}}}

    def run_command(self, argv, **kwargs):
        self.commands.append(argv)
        output = 'PHP 8.5.11' if argv[-2:] == ['/usr/bin/php8.5', '--version'] else ''
        return subprocess.CompletedProcess(argv, 1 if argv[:2] == ['dpkg', '--compare-versions'] else 0, output, '')

    def publish(self, path, content, *args):
        self.files[str(path)] = content
        Path(path).write_bytes(content)

    def packages(self, command=None, inventory=None):
        with patch.object(recipe, 'POLICY', self.policy), \
             patch.object(recipe, 'IMAGE_APT', self.apt), \
             patch.object(recipe, 'APT_ETC', self.apt), \
             patch.object(recipe, 'packages', return_value={'curl': '1.0'} if inventory is None else inventory), \
             patch.object(recipe, 'publish', side_effect=self.publish), \
             patch.object(recipe, 'run', side_effect=command or self.run_command):
            recipe.image_apt_source(self.payload)
            recipe.image_packages(self.payload)

    def test_installs_exact_pins_and_upgrades_base_only_from_snapshot(self):
        self.packages()
        self.assertIn(b'https://snapshot.ubuntu.com/ubuntu/20261014T000000Z/',
                      next(content for path, content in self.files.items() if path.endswith('/ubuntu.sources')))
        install = next(c for c in self.commands if c[:2] == ['apt-get', 'install'])
        self.assertIn('curl=2.0', install)
        config = next(content for path, content in self.files.items() if path.endswith('/apt.conf'))
        self.assertIn(b'Dir::Etc::sourceparts "";', config)
        self.assertTrue(any(c[:2] == ['apt-get', 'full-upgrade'] for c in self.commands))
        self.assertEqual(self.commands.count(['apt-get', 'update']), 1)
        self.assertFalse(self.policy.exists())

    def snapshot_php_probe(self):
        self.php_payload()
        # Use a real child and APT's parser, with no update or install. A stock/live source must be invisible.
        self.payload['php']['bootstrap'] = '''
import json, os, subprocess
from pathlib import Path
config = subprocess.run(['apt-config', 'dump'], capture_output=True, text=True, check=True).stdout
root = Path(os.environ['APT_CONFIG']).parent
assert f'Dir::Etc::sourcelist "{root}/ubuntu.sources";' in config
assert 'Dir::Etc::sourceparts "";' in config
assert 'Dir::Etc::parts "";' in config
assert 'Dir::State::lists "lists/";' in config
assert 'https://snapshot.ubuntu.com/ubuntu/20261014T000000Z/' in (root / 'ubuntu.sources').read_text()
print(json.dumps({'outcome': 'current'}))
'''

    def test_php_subprocess_uses_the_same_snapshot_as_image_packages(self):
        self.snapshot_php_probe()
        with patch.dict(os.environ, {'APT_CONFIG': '/stock/apt.conf'}):
            self.packages()
            self.assertEqual(os.environ['APT_CONFIG'], '/stock/apt.conf')

    def test_missing_snapshot_setup_names_the_required_phase(self):
        with patch.object(recipe, 'IMAGE_APT', Path(self.work.name) / 'missing'), \
             patch.object(recipe, 'run') as command:
            with self.assertRaisesRegex(recipe.RecipeFailure, 'apt-source') as failure:
                with recipe.image_apt_environment(self.payload):
                    self.fail('Missing configuration was accepted')
        self.assertIn('apt-source must run', failure.exception.detail)
        command.assert_not_called()

    def test_packages_fail_loudly_without_commands_when_snapshot_setup_is_missing_or_partial(self):
        for present in ((), ('apt.conf',), ('ubuntu.sources',)):
            with self.subTest(present=present):
                directory = Path(self.work.name) / ('-'.join(present) or 'missing')
                if present:
                    directory.mkdir()
                    content = (recipe.image_apt_configuration(directory) if present == ('apt.conf',)
                               else recipe.snapshot_source(self.payload['snapshot']))
                    (directory / present[0]).write_text(content)
                with patch.object(recipe, 'IMAGE_APT', directory), \
                     patch.object(recipe, 'publish', side_effect=self.publish), \
                     patch.object(recipe, 'image_install_packages') as install, \
                     patch.object(recipe, 'run') as command:
                    with self.assertRaisesRegex(recipe.RecipeFailure, '^apt-source$') as failure:
                        recipe.image_packages(self.payload)
                    self.assertIn('apt-source must run before packages', failure.exception.detail)
                command.assert_not_called()
                install.assert_not_called()

    def test_packages_refuse_another_snapshot_before_any_install(self):
        with patch.object(recipe, 'IMAGE_APT', Path(self.work.name) / 'apt'), \
             patch.object(recipe, 'publish', side_effect=self.publish), \
             patch.object(recipe, 'run', side_effect=self.run_command):
            recipe.image_apt_source(self.payload)
            self.payload['snapshot'] = '20261015T000000Z'
            with self.assertRaisesRegex(recipe.RecipeFailure, 'apt-source') as failure:
                recipe.image_packages(self.payload)
        self.assertIn('apt-source must run', failure.exception.detail)
        self.assertFalse(any(c[:2] == ['apt-get', 'install'] for c in self.commands))

    @contextlib.contextmanager
    def verification(self):
        original_read = Path.read_text
        original_exists = Path.exists
        def read_text(path, *args, **kwargs):
            if path == Path('/etc/orbit-machine-recipe.json'):
                return json.dumps({'identity': self.payload['identity'], 'profile': self.payload['profile']})
            return original_read(path, *args, **kwargs)
        def exists(path):
            if str(path) in ('/var/lib/orbit/package-cache', '/opt/orbit/package-cache', '/home/orbit/orbit', '/home/orbit/.orbit/gateway.sqlite'):
                return False
            return original_exists(path)
        with patch.object(recipe.Path, 'read_text', read_text), \
             patch.object(recipe.Path, 'exists', exists), \
             patch.object(recipe, 'publish', side_effect=self.publish), \
             patch.object(recipe, 'image_guest_cache'):
            yield

    def test_verify_after_cleanup_gives_assessment_and_php_the_same_snapshot_without_refresh(self):
        self.snapshot_php_probe()
        self.payload['identity'] = 'sha256:current'
        def assessment(selected, observed, *args, **kwargs):
            config = Path(os.environ['APT_CONFIG'])
            self.assertIn('Dir::Etc::parts "";', config.read_text())
            self.assertIn(self.payload['snapshot'], (config.parent / 'ubuntu.sources').read_text())
            self.assertEqual(args, ())
            return {'curl': {'status': 'current'}}
        with self.verification(), \
             patch.dict(os.environ, {'APT_CONFIG': '/stock/apt.conf'}), \
             patch.object(recipe, 'inspect', return_value={'curl': '2.0'}), \
             patch.object(recipe, 'assess', side_effect=assessment) as assess, \
             patch.object(recipe, 'run', side_effect=self.run_command):
            recipe.image_verify(self.payload)
            self.assertEqual(os.environ['APT_CONFIG'], '/stock/apt.conf')
            temporary = Path(next(path for path in self.files if path.endswith('/apt.conf'))).parent
            self.assertFalse(temporary.exists())
            assess.side_effect = recipe.RecipeFailure('assessment')
            with self.assertRaises(recipe.RecipeFailure):
                recipe.image_verify(self.payload)
            self.assertEqual(os.environ['APT_CONFIG'], '/stock/apt.conf')
        self.assertFalse(any(c[:2] == ['apt-get', 'update'] for c in self.commands))

    def test_verify_names_its_own_snapshot_configuration_failure(self):
        self.payload['identity'] = 'sha256:current'
        with self.verification(), \
             patch.dict(os.environ, {'APT_CONFIG': '/stock/apt.conf'}), \
             patch.object(recipe, 'image_apt_setup'), \
             patch.object(recipe, 'inspect') as inspect, \
             patch.object(recipe, 'run') as command:
            with self.assertRaisesRegex(recipe.RecipeFailure, '^verify$') as failure:
                recipe.image_verify(self.payload)
            self.assertIn('Verification snapshot configuration', failure.exception.detail)
            self.assertNotIn('before packages', failure.exception.detail)
            self.assertEqual(os.environ['APT_CONFIG'], '/stock/apt.conf')
        inspect.assert_not_called()
        command.assert_not_called()

    def php_payload(self):
        pin = {'name': 'php8.5-cli', 'version': '8.5.11-1', 'architecture': 'amd64', 'url': 'https://example/x.deb', 'sha256': 'a' * 64}
        self.payload['tools']['php8.5-cli'] = {'source': 'artifact', 'version': '8.5.11-1',
                                               'artifacts': {'amd64': {'url': 'https://example/x.deb', 'sha256': 'a' * 64}}}
        self.payload['php'] = {'program': 'program', 'bootstrap': 'bootstrap',
                               'input': {'versions': {'8.5': {'packages': [pin], 'extensions': []}}}}

    def installer(self, outcome):
        calls = []

        def fake(argv, **kwargs):
            calls.append((argv, kwargs['input']))
            return subprocess.CompletedProcess(argv, 0, json.dumps({'outcome': outcome, 'data': {}}) + '\n', '')
        return calls, patch.object(recipe.subprocess, 'run', side_effect=fake)

    def test_the_php_build_comes_from_the_one_installer_not_the_package_phase(self):
        self.php_payload()
        calls, installer = self.installer('applied')
        with installer:
            self.packages()
        self.assertFalse(any('x.deb' in ' '.join(c) for c in self.commands))
        self.assertEqual(calls[0][0], ['python3', '-I', '-c', 'bootstrap', 'php.install-build'])
        self.assertTrue(calls[0][1].startswith('program\0'))

    def test_a_failed_php_install_fails_the_package_phase(self):
        self.php_payload()
        _, installer = self.installer('extra_packages')
        with installer, self.assertRaises(recipe.RecipeFailure):
            self.packages()

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
        self.assertEqual(errors.getvalue(), '')

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
        self.payload.update({'identity': 'sha256:current', 'tools': {'pnpm': {'source': 'artifact', 'version': '12.11.2'}}})
        with self.verification(), \
             patch.object(recipe, 'inspect', return_value={'pnpm': '12.11.2'}), \
             patch.object(recipe, 'run', side_effect=recipe.RecipeFailure('pnpm executable failed')) as command:
            with self.assertRaises(recipe.RecipeFailure):
                recipe.image_verify(self.payload)
        self.assertEqual(command.call_args.args[0][-2:], ['/usr/local/bin/pnpm', '--version'])

    def test_verification_rejects_tools_that_root_can_run_but_the_guest_cannot(self):
        for name, binary in [('bun', '/opt/orbit/bun/bin/bun'), ('node', '/usr/local/bin/node'),
                             ('cargo', '/home/orbit/.cargo/bin/cargo'), ('rust', '/home/orbit/.cargo/bin/rustc'),
                             ('composer', '/usr/bin/composer'), ('git', '/usr/bin/git'), ('php8.5-cli', '/usr/bin/php8.5')]:
            with self.subTest(tool=name):
                self.payload.update({'identity': 'sha256:current', 'tools': {name: {'source': 'artifact', 'version': '1.2.3',
                                     'artifacts': {'amd64': {'sha256': 'a' * 64}}}}})
                def command(argv, **kwargs):
                    if argv[-2:] == [binary, '--version'] and argv[:4] == ['sudo', '-u', 'orbit', '-H']:
                        raise recipe.RecipeFailure('guest permission denied')
                    return subprocess.CompletedProcess(argv, 0, '1.2.3', '')
                with self.verification(), \
                     patch.object(recipe, 'inspect', return_value={name: '1.2.3'}), \
                     patch.object(recipe, 'assess', return_value={name: {'status': 'current'}}), \
                     patch.object(recipe, 'json_file', return_value={name: {'sha256': 'a' * 64}}), \
                     patch.object(recipe, 'run', side_effect=command):
                    with self.assertRaisesRegex(recipe.RecipeFailure, 'guest permission denied'):
                        recipe.image_verify(self.payload)

    def test_guest_cache_probe_only_reads_the_existing_parent(self):
        with patch.object(recipe, 'run', side_effect=recipe.RecipeFailure('guest cache read failed')) as command, \
             patch.object(recipe.tempfile, 'TemporaryDirectory') as temporary:
            with self.assertRaisesRegex(recipe.RecipeFailure, 'guest cache read failed'):
                recipe.image_guest_cache()
        command.assert_called_once_with(['sudo', '-u', 'orbit', '-H', 'env', '-C', '/home/orbit',
                                         'ls', '-A', '--', '/opt/orbit/.'])
        temporary.assert_not_called()

    def test_guest_access_check_accepts_the_same_newer_ubuntu_binary_as_root(self):
        self.payload.update({'identity': 'sha256:current', 'tools': {'git': {'source': 'ubuntu', 'version': '2.54.0', 'package': 'git'}}})
        with self.verification(), \
             patch.object(recipe, 'inspect', return_value={'git': '2.55.0'}), \
             patch.object(recipe, 'assess', return_value={'git': {'status': 'newer_than_recipe'}}), \
             patch.object(recipe, 'run', return_value=subprocess.CompletedProcess([], 0, 'git version 2.55.0', '')) as command:
            recipe.image_verify(self.payload)
        self.assertIn(unittest.mock.call(['/usr/bin/git', '--version'], required=False, env=None), command.call_args_list)
        self.assertIn(unittest.mock.call(['sudo', '-u', 'orbit', '-H', 'env', '-C', '/home/orbit',
                                         '/usr/bin/git', '--version'], required=False, env=None), command.call_args_list)

    def test_guest_launchers_only_run_as_orbit_and_match_the_inspected_version(self):
        for name, binary in [('node', '/usr/local/bin/node'), ('pnpm', '/usr/local/bin/pnpm'),
                             ('bun', '/opt/orbit/bun/bin/bun'), ('vp-cli', '/usr/local/bin/vp')]:
            with self.subTest(tool=name):
                self.payload.update({'identity': 'sha256:current', 'tools': {name: {'source': 'artifact', 'version': '1.2.2'}}})
                def command(argv, **kwargs):
                    if argv[-2:] == [binary, '--version']:
                        self.assertEqual(argv[:-2], recipe.IMAGE_GUEST, 'Guest launcher ran as root')
                    return subprocess.CompletedProcess(argv, 0, '1.2.3', '')
                with self.verification(), \
                     patch.object(recipe, 'inspect', return_value={name: '1.2.3'}), \
                     patch.object(recipe, 'assess', return_value={name: {'status': 'current'}}), \
                     patch.object(recipe, 'run', side_effect=command) as run:
                    recipe.image_verify(self.payload)
                self.assertIn(unittest.mock.call([*recipe.IMAGE_GUEST, binary, '--version'], required=False, env=None), run.call_args_list)

    def test_rust_and_cargo_must_report_the_pinned_version_even_with_a_matching_receipt(self):
        for name in ('rust', 'cargo'):
            with self.subTest(tool=name):
                artifact = {'sha256': 'a' * 64}
                self.payload.update({'identity': 'sha256:current', 'tools': {name: {'source': 'artifact', 'version': '1.2.3',
                                     'artifacts': {'amd64': artifact}}}})
                with self.verification(), \
                     patch.object(recipe, 'inspect', return_value={}), \
                     patch.object(recipe, 'assess', return_value={}), \
                     patch.object(recipe, 'json_file', return_value={name: artifact}), \
                     patch.object(recipe, 'run', return_value=subprocess.CompletedProcess([], 0, '1.2.4', '')):
                    with self.assertRaises(recipe.RecipeFailure):
                        recipe.image_verify(self.payload)

    def test_puts_orbit_in_incus_admin_after_the_packages_only_when_the_profile_installs_incus(self):
        self.packages()
        self.assertNotIn(['usermod', '--append', '--groups', 'incus-admin', 'orbit'], self.commands)

        self.commands = []
        document = json.loads((Path(image).parent / 'recipe.json').read_text())
        for name in recipe.INCUS_PACKAGES:
            pin = document['tools'][name]
            self.payload['tools'][name] = {**pin, 'version': pin['artifacts']['amd64']['version']}
        inventory = {'curl': '1.0'}
        def command(argv, **kwargs):
            if argv[:2] == ['dpkg-deb', '-f']:
                self.commands.append(argv)
                name = Path(argv[2]).stem
                return subprocess.CompletedProcess(argv, 0, '\n'.join([name, self.payload['tools'][name]['version'], 'amd64']), '')
            if argv[:2] == ['apt-get', 'install'] and '--simulate' not in argv and any(arg.endswith('.deb') for arg in argv):
                inventory.update({name: self.payload['tools'][name]['version'] for name in recipe.INCUS_PACKAGES})
            if argv[:2] == ['usermod', '--append']:
                for name in recipe.INCUS_PACKAGES:
                    self.assertEqual(inventory.get(name), self.payload['tools'][name]['version'])
            return self.run_command(argv, **kwargs)
        with patch.object(recipe, 'download_deb', side_effect=lambda name, pin, payload, path: path):
            self.packages(command, inventory=inventory)
        install = next(i for i, c in enumerate(self.commands) if c[:2] == ['apt-get', 'install'] and '--simulate' not in c and any(arg.endswith('.deb') for arg in c))
        for name in recipe.INCUS_PACKAGES:
            self.assertTrue(any(arg.endswith(f'/{name}.deb') for arg in self.commands[install]))
        self.assertGreater(self.commands.index(['usermod', '--append', '--groups', 'incus-admin', 'orbit']), install)

    def verify_incus_admin(self, groups):
        document = json.loads((Path(image).parent / 'recipe.json').read_text())
        pin = document['tools']['incus']
        version = pin['artifacts']['amd64']['version']
        self.payload.update({'identity': 'sha256:current', 'tools': {'incus': {**pin, 'version': version}}})

        def command(argv, **kwargs):
            self.commands.append(argv)
            return subprocess.CompletedProcess(argv, 0, groups + '\n' if argv == ['id', '-nG', 'orbit'] else '', '')
        with self.verification(), \
             patch.object(recipe, 'inspect', return_value={'incus': version}), \
             patch.object(recipe, 'assess', return_value={'incus': {'status': 'current'}}), \
             patch.object(recipe.Path, 'exists', return_value=False), \
             patch.object(recipe, 'run', side_effect=command):
            recipe.image_verify(self.payload)

    def test_smoke_requires_orbit_in_incus_admin_when_the_image_has_incus(self):
        self.verify_incus_admin('orbit incus-admin')
        self.assertIn(['id', '-nG', 'orbit'], self.commands)
        with self.assertRaises(recipe.RecipeFailure):
            self.verify_incus_admin('orbit sudo')

    def test_smoke_rejects_wrong_recipe_identity_before_checking_software(self):
        self.payload.update({'identity': 'sha256:current'})
        with patch.object(recipe.Path, 'read_text', return_value='{"identity":"sha256:old","profile":"base"}'):
            with self.assertRaises(recipe.RecipeFailure):
                recipe.image_verify(self.payload)


unittest.main()
