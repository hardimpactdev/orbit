"""Unit tests for bin/php-build: recipe reading, build verification, and the Sury pin bump. No network or root."""
import copy
import hashlib
import importlib.machinery
import importlib.util
import io
import json
from contextlib import redirect_stderr, redirect_stdout
from pathlib import Path
import sys
import tempfile
import unittest
from unittest.mock import patch

loader = importlib.machinery.SourceFileLoader('php_build', sys.argv.pop(1))
spec = importlib.util.spec_from_loader(loader.name, loader)
build = importlib.util.module_from_spec(spec)
loader.exec_module(build)

CORE = '8.5.11-1+0~20260924.26+ubuntu26.04~1.gbpbcb504'


def pin(name, version, arch_url=None):
    url = arch_url or f'https://packages.sury.org/php/pool/main/p/{name}_{version}_amd64.deb'
    return {'source': 'artifact', 'version': version,
            'artifacts': {'amd64': {'url': url, 'sha256': hashlib.sha256(url.encode()).hexdigest()}}}


def recipe():
    return {
        'roles': {'gateway': ['php:8.5'], 'ci-runner': ['php:8.5'], 'operator': []},
        'base': ['php:8.5'],
        'artifact_mirror': 'https://mirror.example/php',
        'ubuntu': {'release': '26.04'},
        'architectures': ['amd64'],
        'tools': {
            'php-common': pin('php-common', '2:101~+0~20260503.72+ubuntu26.04~1.gbp7da167'),
            'libgd3': pin('libgd3', '2.3.3-14+0~20260731.21+ubuntu26.04~1.gbp938f32'),
            'php8.5-cli': pin('php8.5-cli', CORE),
            'php8.5-common': pin('php8.5-common', CORE),
            'php8.5-pcov': pin('php8.5-pcov', '1.0.12-2+0~20260622.34+ubuntu26.04~1.gbp5b3479'),
            'dpkg-repack': {'source': 'ubuntu', 'package': 'dpkg-repack', 'version': '1.54build1'},
        },
        'php': {'ci': '8.5', 'shared': ['libgd3', 'php-common'], 'rollback': 'dpkg-repack', 'pcov': ['app-dev', 'base', 'ci-runner'], 'opentelemetry': ['app-dev'],
                'purge_extras': ['ci-runner'], 'versions': {
            '8.5': {'packages': ['php8.5-cli', 'php8.5-common', 'php8.5-pcov'], 'extensions': ['core', 'pcov', 'zend opcache']},
        }},
    }


def stanza(name, version, arch='amd64'):
    filename = f'pool/main/p/php8.5/{name}_{version}_{arch}.deb'
    return (f'Package: {name}\nVersion: {version}\nArchitecture: {arch}\nFilename: {filename}\n'
            f'SHA256: {hashlib.sha256(filename.encode()).hexdigest()}\nDescription: test\n multi-line field\n')


class BuildTest(unittest.TestCase):
    def test_build_takes_the_ci_version_with_the_shared_pins_for_one_architecture(self):
        selected = build.build(recipe(), architecture='amd64', profile='ci-runner')
        self.assertEqual(selected['version'], '8.5')
        self.assertEqual(selected['php_version'], '8.5.11')
        self.assertEqual(selected['packages'], ['libgd3', 'php-common', 'php8.5-cli', 'php8.5-common', 'php8.5-pcov'])
        self.assertEqual(selected['pins']['php8.5-cli']['version'], CORE)
        with self.assertRaisesRegex(build.BuildError, 'no PHP 8.4 build'):
            build.build(recipe(), '8.4', profile='ci-runner')
        with self.assertRaisesRegex(build.BuildError, 'PHP is not available for arm64'):
            build.build(recipe(), architecture='arm64', profile='ci-runner')

    def test_modules_match_the_recipe_list_format(self):
        output = '[PHP Modules]\nCore\npcov\nZend OPcache\n\n[Zend Modules]\nZend OPcache\n\n'
        self.assertEqual(build.modules(output), ['core', 'pcov', 'zend opcache'])

    def test_compare_reports_every_difference_and_nothing_for_the_recipe_build(self):
        expected = build.build(recipe(), architecture='amd64', profile='ci-runner')
        installed = {name: pin['version'] for name, pin in expected['pins'].items()}
        self.assertEqual(build.compare(expected, '8.5.11', ['core', 'pcov', 'zend opcache'], installed), [])
        installed['php8.5-cli'] = '8.5.10-1'
        del installed['libgd3']
        problems = build.compare(expected, '8.5.10', ['apcu', 'core', 'zend opcache'], installed)
        self.assertEqual(problems, [
            'PHP_VERSION is 8.5.10, expected 8.5.11.',
            'Missing extensions: pcov.',
            'Extra extensions: apcu.',
            f'libgd3 is not installed, expected {expected["pins"]["libgd3"]["version"]}.',
            f'php8.5-cli is 8.5.10-1, expected {CORE}.',
        ])
        self.assertIn('PHP_VERSION is missing', build.compare(expected, '', [], installed)[0])

    def test_verify_fails_with_the_fix_and_passes_on_the_recipe_build(self):
        expected = build.build(recipe(), architecture='amd64', profile='ci-runner')
        installed = {name: pin['version'] for name, pin in expected['pins'].items()}
        with patch.object(build, 'architecture', return_value='amd64'), \
             patch.object(build, 'installed_versions', return_value=installed), \
             patch.object(build, 'host_build', return_value=('8.5.11', ['core', 'pcov', 'zend opcache'])), \
             redirect_stdout(io.StringIO()) as out:
            build.verify(recipe(), None, 'ci-runner')
        self.assertIn('PHP 8.5.11 matches the recipe build: 5 packages, 3 extensions.', out.getvalue())
        with patch.object(build, 'architecture', return_value='amd64'), \
             patch.object(build, 'installed_versions', return_value=installed), \
             patch.object(build, 'host_build', return_value=('8.5.11', ['apcu', 'core', 'pcov', 'zend opcache'])):
            with self.assertRaises(build.BuildError) as raised:
                build.verify(recipe(), None, 'ci-runner')
        self.assertIn('Extra extensions: apcu.', str(raised.exception))
        self.assertIn('node:converge-recipe sabre --profile=ci-runner', str(raised.exception))

    def test_main_exits_nonzero_with_the_message_on_stderr(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / 'recipe.json'
            path.write_text(json.dumps(recipe()))
            with patch.object(build, 'verify', side_effect=build.BuildError('different build')), \
                 redirect_stderr(io.StringIO()) as error:
                self.assertEqual(build.main(['verify', '--profile=ci-runner', '--recipe', str(path)]), 1)
        self.assertEqual(error.getvalue(), 'php-build: different build\n')

    def test_install_refuses_without_root(self):
        with patch.object(build.os, 'geteuid', return_value=1000):
            with self.assertRaisesRegex(build.BuildError, 'needs root'):
                build.install(recipe(), None, False, 'pcov', True, False, 'ci-runner')

    def test_install_runs_the_one_installer_and_purges_only_when_asked(self):
        calls = []

        def installer(payload):
            calls.append(payload)
            return 'applied', {'changed': ['php8.5-cli'], 'purged': []}, ''
        for purge, extras in ((False, 'refuse'), (True, 'purge')):
            with patch.object(build.os, 'geteuid', return_value=0), \
                 patch.object(build, 'run_installer', side_effect=installer), \
                 patch.object(build, 'verify'), patch.object(build, 'run'), \
                 patch.object(build.Path, 'write_text'), patch.object(build.Path, 'unlink'), \
                 redirect_stdout(io.StringIO()):
                build.install(recipe(), None, False, 'pcov', False, purge, 'ci-runner')
            self.assertEqual(calls[-1]['extras'], extras)
        self.assertEqual(calls[-1]['fpm'], 'shared')
        self.assertTrue(calls[-1]['pcov'])
        self.assertEqual(calls[-1]['repack'], {'package': 'dpkg-repack', 'version': '1.54build1'})

    def test_install_and_verify_use_the_selected_profiles_pcov_policy(self):
        for profile, pcov in (('gateway', False), ('ci-runner', True)):
            for coverage in ('none', 'pcov'):
                with self.subTest(profile=profile, coverage=coverage), \
                     patch.object(build.os, 'geteuid', return_value=0), \
                     patch.object(build, 'run_installer', return_value=('current', {}, '')) as installer, \
                     patch.object(build, 'architecture', return_value='amd64'), \
                     patch.object(build, 'installed_versions', return_value={
                         name: pin['version'] for name, pin in recipe()['tools'].items()}), \
                     patch.object(build, 'host_build', return_value=(
                         '8.5.11', ['core', 'zend opcache'] + (['pcov'] if pcov else []))), \
                     patch.object(build, 'run'), patch.object(build.Path, 'write_text'), \
                     patch.object(build.Path, 'unlink'), redirect_stdout(io.StringIO()):
                    build.install(recipe(), None, False, coverage, False, False, profile)

                payload = installer.call_args.args[0]
                self.assertEqual(payload['pcov'], pcov)
                self.assertIn('php8.5-pcov', [pin['name'] for pin in payload['versions']['8.5']['packages']])
                self.assertEqual('pcov' in payload['versions']['8.5']['extensions'], pcov)

    def test_gateway_verification_refuses_pcov_loaded_in_the_cli(self):
        with patch.object(build, 'architecture', return_value='amd64'), \
             patch.object(build, 'installed_versions', return_value={
                 name: pin['version'] for name, pin in recipe()['tools'].items()}), \
             patch.object(build, 'host_build', return_value=('8.5.11', ['core', 'pcov', 'zend opcache'])):
            with self.assertRaisesRegex(build.BuildError, 'Extra extensions: pcov'):
                build.verify(recipe(), None, 'gateway')

    def test_profile_is_required_and_must_declare_the_selected_php_version(self):
        for command in ('install', 'verify'):
            with redirect_stderr(io.StringIO()), self.assertRaises(SystemExit) as raised:
                build.main([command])
            self.assertEqual(raised.exception.code, 2)
        for profile, message in (('missing', 'no profile missing'), ('operator', 'does not declare PHP')):
            with self.assertRaisesRegex(build.BuildError, message):
                build.installer_input(recipe(), ['8.5'], profile=profile)
        with self.assertRaisesRegex(build.BuildError, 'gateway does not declare PHP 8.4'):
            build.build(build.load(build.RECIPE), '8.4', profile='gateway')

    def test_install_names_the_refusal_and_the_purge_option(self):
        with patch.object(build.os, 'geteuid', return_value=0), \
             patch.object(build, 'run_installer', return_value=('extra_packages', {'packages': ['php8.5-apcu']}, '')):
            with self.assertRaisesRegex(build.BuildError, 'extra_packages .*php8.5-apcu.*--purge-extras'):
                build.install(recipe(), None, False, 'pcov', False, False, 'ci-runner')

    def test_the_installer_input_matches_the_gateway_contract(self):
        payload = build.installer_input(recipe(), ['8.5'], profile='ci-runner')
        self.assertEqual(sorted(payload), ['extras', 'fpm', 'git_read', 'mirror', 'mode', 'opentelemetry', 'pcov', 'release', 'repack', 'versions'])
        names = [pin['name'] for pin in payload['versions']['8.5']['packages']]
        self.assertEqual(names, ['libgd3', 'php-common', 'php8.5-cli', 'php8.5-common', 'php8.5-pcov'])
        self.assertEqual(payload['release'], {'id': 'ubuntu', 'codenames': ['resolute']})

    def test_the_assembled_installer_is_the_program_the_gateway_sends(self):
        source = build.assemble('php.install-build')
        self.assertTrue(source.startswith('# Library: the runtime of every Python remote program.'))
        self.assertIn('# Program: php.install-build', source)

    def test_pins_digest_changes_with_any_pin_and_not_with_extensions(self):
        first = build.pins_digest(recipe(), 'amd64', ['8.5'])
        changed = recipe()
        changed['php']['versions']['8.5']['extensions'].append('zip')
        self.assertEqual(build.pins_digest(changed, 'amd64', ['8.5']), first)
        changed['tools']['php8.5-pcov']['artifacts']['amd64']['sha256'] = '0' * 64
        self.assertNotEqual(build.pins_digest(changed, 'amd64', ['8.5']), first)

    def test_version_comparison_matches_dpkg(self):
        self.assertGreater(build.compare_versions('8.5.12-1+0~20261020.1+ubuntu26.04~1.gbp1', CORE), 0)
        self.assertLess(build.compare_versions('1.0~rc1', '1.0'), 0)
        self.assertGreater(build.compare_versions('2:101~+0~2026', '2:100'), 0)
        self.assertGreater(build.compare_versions('1:1.0', '2.0'), 0)
        self.assertEqual(build.compare_versions('1.0-1', '1.0-1'), 0)


class BumpTest(unittest.TestCase):
    def test_release_hashes_and_stanzas_parse_the_signed_index(self):
        release = 'Origin: deb.sury.org\nMD5Sum:\n 0 1 main/binary-amd64/Packages\nSHA256:\n abc 12 main/binary-amd64/Packages.xz\n def 9 main/binary-arm64/Packages\n'
        self.assertEqual(build.release_hashes(release), {'main/binary-amd64/Packages.xz': 'abc', 'main/binary-arm64/Packages': 'def'})
        parsed = list(build.stanzas(stanza('php8.5-cli', CORE) + '\n' + stanza('php8.5-pcov', '1.0')))
        self.assertEqual([fields['Package'] for fields in parsed], ['php8.5-cli', 'php8.5-pcov'])

    def test_bump_moves_every_pin_of_a_version_together_to_the_newest_build(self):
        current = recipe()
        newer = '8.5.12-1+0~20261020.1+ubuntu26.04~1.gbp1'
        index = '\n'.join([
            stanza('php8.5-cli', CORE), stanza('php8.5-cli', newer), stanza('php8.5-common', newer),
            stanza('php8.5-pcov', '1.0.12-2+0~20260622.34+ubuntu26.04~1.gbp5b3479'),
            stanza('php-common', '2:101~+0~20260503.72+ubuntu26.04~1.gbp7da167', 'all'),
            stanza('libgd3', '2.3.3-14+0~20260731.21+ubuntu26.04~1.gbp938f32'),
            stanza('php8.5-soap', newer),
        ])
        changes = build.bump_recipe(current, {'amd64': index})
        self.assertEqual(changes, [f'libgd3 amd64: 2.3.3-14+0~20260731.21+ubuntu26.04~1.gbp938f32 -> 2.3.3-14+0~20260731.21+ubuntu26.04~1.gbp938f32',
                                   f'php-common amd64: 2:101~+0~20260503.72+ubuntu26.04~1.gbp7da167 -> 2:101~+0~20260503.72+ubuntu26.04~1.gbp7da167',
                                   f'php8.5-cli amd64: {CORE} -> {newer}', f'php8.5-common amd64: {CORE} -> {newer}',
                                   'php8.5-pcov amd64: 1.0.12-2+0~20260622.34+ubuntu26.04~1.gbp5b3479 -> 1.0.12-2+0~20260622.34+ubuntu26.04~1.gbp5b3479'])
        cli = current['tools']['php8.5-cli']
        self.assertEqual(cli['version'], newer)
        self.assertEqual(cli['artifacts']['amd64']['url'], f'{build.SURY}pool/main/p/php8.5/php8.5-cli_{newer}_amd64.deb')
        self.assertEqual(cli['artifacts']['amd64']['sha256'], hashlib.sha256(f'pool/main/p/php8.5/php8.5-cli_{newer}_amd64.deb'.encode()).hexdigest())
        self.assertNotIn('php8.5-soap', current['tools'])
        self.assertEqual(build.bump_recipe(current, {'amd64': index})[2:4], [])

    def test_bump_refuses_sury_between_builds_and_a_missing_package(self):
        newer = '8.5.12-1+0~20261020.1+ubuntu26.04~1.gbp1'
        rest = [stanza('php8.5-pcov', '1.0'), stanza('php-common', '2:101', 'all'), stanza('libgd3', '2.3.3')]
        with self.assertRaisesRegex(build.BuildError, 'between builds'):
            build.bump_recipe(recipe(), {'amd64': '\n'.join([stanza('php8.5-cli', newer), stanza('php8.5-common', CORE), *rest])})
        with self.assertRaisesRegex(build.BuildError, 'Sury has no amd64 package php8.5-cli'):
            build.bump_recipe(recipe(), {'amd64': '\n'.join([stanza('php8.5-common', CORE), *rest])})

    def test_bump_writes_the_recipe_in_its_own_format_only_when_pins_change(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / 'recipe.json'
            original = json.dumps(recipe(), indent=4) + '\n'
            path.write_text(original)
            index = '\n'.join([stanza(name, pin['version'], 'all' if name == 'php-common' else 'amd64')
                               for name, pin in recipe()['tools'].items() if pin['source'] == 'artifact'])
            same = copy.deepcopy(recipe())
            same['architectures'].append('arm64')
            for name, tool in same['tools'].items():
                if tool['source'] != 'artifact':
                    continue
                filename = f'pool/main/p/php8.5/{name}_{tool["version"]}_{"all" if name == "php-common" else "amd64"}.deb'
                tool['artifacts']['amd64'] = {'url': build.SURY + filename, 'sha256': hashlib.sha256(filename.encode()).hexdigest()}
            path.write_text(json.dumps(same, indent=4) + '\n')
            with patch.object(build, 'signed_release', return_value=''), \
                 patch.object(build, 'index', return_value=index) as fetch_index, \
                 redirect_stdout(io.StringIO()) as out:
                build.bump(str(path), dry_run=False)
            self.assertEqual(path.read_text(), json.dumps(same, indent=4) + '\n')
            self.assertIn("pins Sury's newest PHP build", out.getvalue())
            self.assertEqual([call.args[1] for call in fetch_index.call_args_list], ['amd64'])

    def test_bump_does_not_add_an_unsupported_architecture_or_take_its_older_versions(self):
        current = recipe()
        index = '\n'.join(stanza(name, pin['version'], 'all' if name == 'php-common' else 'amd64')
                          for name, pin in current['tools'].items() if pin['source'] == 'artifact')

        build.bump_recipe(current, {'amd64': index, 'arm64': ''})

        self.assertEqual(current['tools']['php8.5-common']['version'], CORE)
        for pin in current['tools'].values():
            if pin['source'] == 'artifact':
                self.assertEqual(list(pin['artifacts']), ['amd64'])


class RepositoryRecipeTest(unittest.TestCase):
    def test_mirror_downloads_only_declared_amd64_php_artifacts(self):
        document = build.load(build.RECIPE)
        names = set(document['php']['shared']).union(*(set(v['packages']) for v in document['php']['versions'].values()))
        document['tools'] = {name: document['tools'][name] for name in names}
        document['artifact_mirror'] = ''
        with tempfile.TemporaryDirectory() as directory, \
             patch.object(build, 'download') as download, redirect_stdout(io.StringIO()):
            build.mirror(document, Path(directory))

        self.assertEqual(download.call_count, len(names))
        for call in download.call_args_list:
            self.assertNotIn('_arm64.deb', call.args[0])
            self.assertTrue(call.args[0].endswith(('_amd64.deb', '_all.deb')))

    def test_ci_build_and_installer_exclude_app_dev_opentelemetry(self):
        document = build.load(build.RECIPE)
        for version in document['php']['versions']:
            selected = build.build(document, version, 'amd64', profile='base')
            self.assertNotIn(f'php{version}-opentelemetry', selected['packages'])
            self.assertNotIn('opentelemetry', selected['extensions'])
            payload = build.installer_input(document, [version], profile='base')
            self.assertFalse(payload['opentelemetry'])
            self.assertNotIn(f'php{version}-opentelemetry', [pin['name'] for pin in payload['versions'][version]['packages']])
            self.assertNotIn('opentelemetry', payload['versions'][version]['extensions'])

    def test_each_profile_uses_the_recipe_extension_policy(self):
        document = build.load(build.RECIPE)
        for profile, pcov, opentelemetry in (
                ('gateway', False, False), ('app-prod', False, False), ('websocket', False, False),
                ('ci-runner', True, False), ('base', True, False), ('app-dev', True, True)):
            with self.subTest(profile=profile):
                payload = build.installer_input(document, ['8.5'], profile=profile)
                self.assertEqual(payload['pcov'], pcov)
                self.assertEqual(payload['opentelemetry'], opentelemetry)
                packages = [pin['name'] for pin in payload['versions']['8.5']['packages']]
                self.assertEqual('php8.5-opentelemetry' in packages, opentelemetry)
                self.assertEqual('pcov' in payload['versions']['8.5']['extensions'], pcov)
                self.assertNotIn('opentelemetry', payload['versions']['8.5']['extensions'])

    def test_the_repository_recipe_names_one_exact_ci_build(self):
        document = build.load(build.RECIPE)
        selected = build.build(document, architecture='amd64', profile='ci-runner')
        self.assertEqual(selected['version'], document['php']['ci'])
        self.assertEqual(len({pin['version'] for name, pin in selected['pins'].items()
                              if pin['version'].startswith(selected['version'] + '.')}), 1)
        self.assertTrue(all(pin['url'].startswith(build.SURY) for pin in selected['pins'].values()))


unittest.main()
