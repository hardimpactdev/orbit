"""The recipe owns Git artifacts, source retirement and image installs. No host or network operations run."""
import hashlib
import importlib.machinery
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest
from unittest.mock import patch

from test_recipe_versions import recipe, image

RESOURCE = Path(__file__).resolve().parents[2] / 'resources/machine-software'
PIN = '1:2.55.0-0ppa1~ubuntu26.04.2'
UBUNTU = '1:2.53.0-1ubuntu1'
INDEX = 'https://security.ubuntu.com/ubuntu resolute-security/main amd64 Packages'
SOURCE = '''Types: deb
URIs: https://ppa.launchpadcontent.net/git-core/ppa/ubuntu/
Suites: resolute
Components: main
Signed-By:
 -----BEGIN PGP PUBLIC KEY BLOCK-----
 .
 fixture-key
 -----END PGP PUBLIC KEY BLOCK-----
'''


class RecipeGitTest(unittest.TestCase):
    def setUp(self):
        directory = tempfile.TemporaryDirectory()
        self.addCleanup(directory.cleanup)
        self.apt = Path(directory.name)
        (self.apt / 'sources.list.d').mkdir()
        (self.apt / 'preferences.d').mkdir()
        self.source = self.apt / 'sources.list.d/git-core-ubuntu-ppa-resolute.sources'
        self.source.write_text(SOURCE)
        seed = json.loads((RESOURCE / 'recipe.json').read_text())
        self.tools = {name: seed['tools'][name] for name in seed['git']['packages']}
        self.payload = {'tools': self.tools, 'architecture': 'amd64', 'snapshot': seed['ubuntu']['snapshot'],
                        'profile': 'base', 'git_packages': seed['git']['packages'], 'artifact_mirror': seed['artifact_mirror']}
        self.installed = dict.fromkeys(self.tools, PIN)
        self.dpkg_metadata = {name: {'source:Package': 'git'} for name in self.tools}
        self.commands = []
        self.origin = INDEX
        for module in (recipe, image):
            for name, value in [('APT_ETC', self.apt), ('POLICY', self.apt / 'policy')]:
                fixture = patch.object(module, name, value)
                fixture.start()
                self.addCleanup(fixture.stop)

    def command(self, argv, **kwargs):
        self.commands.append(argv)
        if argv[:2] == ['apt-cache', 'policy'] and '-o' in argv:
            argv = argv[:argv.index('-o')]
        output = ''
        if argv == ['dpkg', '--print-architecture']:
            output = 'amd64\n'
        elif argv[:2] == ['dpkg-query', '-W']:
            for name, metadata in self.dpkg_metadata.items():
                fields = {'Package': name, 'Depends': '', 'Pre-Depends': '',
                          'db:Status-Status': 'installed' if name in self.installed else 'config-files', **metadata}
                line = argv[2].removeprefix('-f=')
                for field, value in fields.items():
                    line = line.replace('${' + field + '}', value)
                output += line
        elif argv[:2] == ['apt-cache', 'policy']:
            if len(argv) == 2:
                output = f' 500 {INDEX}\n     release o=Ubuntu,a=resolute-security,n=resolute\n'
            else:
                name = argv[2].split(':')[0]
                output = f'{name}:\n *** {self.installed[name]} 500\n        500 {self.origin}\n        100 /var/lib/dpkg/status\n'
        elif argv[:2] == ['dpkg-deb', '-f']:
            name = Path(argv[2]).stem
            output = '\n'.join([name, PIN, 'all' if name == 'git-man' else 'amd64'])
        elif argv[:2] == ['apt-get', 'install'] and '--simulate' not in argv:
            self.installed.update(dict.fromkeys(self.tools, PIN))
        return subprocess.CompletedProcess(argv, 0, output, '')

    def install(self, module):
        with patch.object(module, 'packages', side_effect=lambda: dict(self.installed)), \
             patch.object(module, 'run', side_effect=self.command), \
             patch.object(module, 'active_services', return_value=set()), \
             patch.object(module.pwd, 'getpwnam', return_value=None), \
             patch.object(module, 'publish', side_effect=lambda path, data, *args: Path(path).write_bytes(data)), \
             patch.object(module, 'download', side_effect=lambda pin, arch, path: path) as download:
            self.download = download
            result = module.apply(self.payload) if module is recipe else module.image_packages(self.payload)
        return result, download

    def test_beast_plans_cleanly_without_origin_refusal_or_mutation_then_retires_its_inline_key_source(self):
        with patch.object(recipe, 'packages', side_effect=lambda: dict(self.installed)), \
             patch.object(recipe, 'run', side_effect=self.command), patch.object(recipe, 'publish') as publish:
            plan = recipe.plan(self.payload)
            recipe.supported({name: plan[name] for name in self.tools})

        self.assertEqual(plan['_actions'], ['retire_git_source', 'pin_git'])
        self.assertTrue(all(plan[name]['status'] == 'current' and not plan[name]['changed'] for name in self.tools))
        self.assertEqual(self.source.read_text(), SOURCE)
        publish.assert_not_called()
        self.assertEqual(len(self.commands), 1)
        self.assertEqual(self.commands[0][:2], ['dpkg-query', '-W'])
        result, download = self.install(recipe)
        self.assertEqual(result['changed_tools'], [])
        download.assert_not_called()
        self.assertFalse(self.source.exists())
        self.assertEqual((self.apt / (self.source.name + '.orbit-git-backup')).read_text(), SOURCE)
        preference = (self.apt / 'preferences.d/orbit-git').read_text()
        self.assertIn('Pin: version ' + PIN + '\nPin-Priority: 1001', preference)
        self.assertIn('Pin: version *\nPin-Priority: -1', preference)

    def test_older_ubuntu_git_is_upgraded_to_both_artifacts_on_nodes_and_images(self):
        for module in (recipe, image):
            with self.subTest(module=module.__name__):
                self.installed = dict.fromkeys(self.tools, UBUNTU)
                self.commands = []
                result, download = self.install(module)
                self.assertEqual(self.installed, dict.fromkeys(self.tools, PIN))
                self.assertEqual(download.call_count, 2)
                installs = [argv for argv in self.commands if argv[:2] == ['apt-get', 'install']]
                self.assertEqual(len(installs), 2)
                self.assertIn('--simulate', installs[0])
                self.assertTrue(all(any(arg.endswith('/' + name + '.deb') for arg in installs[1]) for name in self.tools))
                if module is recipe:
                    self.assertEqual(result['changed_tools'], ['git', 'git-man'])

    def test_other_third_party_git_is_refused_even_when_older_before_download_or_source_changes(self):
        self.origin = 'https://vendor.example/git resolute/main amd64 Packages'
        for version in ('1:2.56.0-vendor1', '1:2.50.0-vendor1'):
            for module in (recipe, image):
                with self.subTest(version=version, module=module.__name__):
                    self.installed = dict.fromkeys(self.tools, version)
                    self.commands = []
                    with self.assertRaisesRegex(module.RecipeFailure, 'origin:git'):
                        self.install(module)
                    self.download.assert_not_called()
                    self.assertEqual(self.source.read_text(), SOURCE)
                    self.assertFalse((self.apt / 'preferences.d/orbit-git').exists())
                    self.assertFalse(any(argv[:2] in (['apt-get', 'install'], ['apt-get', 'full-upgrade']) for argv in self.commands))

    def test_retirement_preserves_unrelated_entries_and_handles_legacy_list_files(self):
        unrelated = 'Types: deb\nURIs: https://other.example/\nSuites: resolute\nComponents: main\n'
        self.source.write_text(SOURCE + '\n' + unrelated)
        legacy = self.apt / 'sources.list.d/git.list'
        legacy.write_text('deb [signed-by=/key.gpg] https://ppa.launchpad.net/git-core/ppa/ubuntu resolute main\n# keep me\n')
        with patch.object(recipe, 'publish', side_effect=lambda path, data: Path(path).write_bytes(data)):
            recipe.configure_git(self.payload, apply=True)
            recipe.pin_git(self.payload)
        self.assertIn(unrelated, self.source.read_text())
        self.assertNotIn('git-core', self.source.read_text())
        self.assertEqual(legacy.read_text(), '# keep me\n')
        self.assertEqual(recipe.configure_git(self.payload), [])

    def test_only_a_root_owned_receipt_allows_a_later_pin_to_replace_a_previous_recipe_build(self):
        self.install(recipe)
        self.origin = 'https://vendor.example/git resolute/main amd64 Packages'
        bumped = {name: {**pin, 'version': PIN.replace('2.55.0', '2.56.0')} for name, pin in self.tools.items()}
        original_stat = recipe.Path.stat
        for owner, writable, accepted in ((0, False, True), (1234, False, False), (0, True, False)):
            def receipt_stat(path, *args, owner=owner, writable=writable, **kwargs):
                state = original_stat(path, *args, **kwargs)
                if path.name == 'orbit-git.json':
                    fields = list(state)
                    fields[0] |= 0o020 if writable else 0
                    fields[4] = owner
                    return os.stat_result(fields)
                return state
            with self.subTest(owner=owner, writable=writable), \
                 patch.object(recipe.Path, 'stat', receipt_stat), patch.object(recipe, 'run', side_effect=self.command):
                report = recipe.assess(bumped, self.installed, git_names=self.tools)
            self.assertEqual(report['git']['status'], 'different' if accepted else 'unsupported_newer')
            self.assertEqual(report['git']['changed'], accepted)
        with patch.object(recipe.Path, 'stat', lambda path, *args, **kwargs: receipt_stat(path, *args, owner=0, writable=False, **kwargs)), \
             patch.object(recipe, 'run', side_effect=self.command):
            self.installed['git'] = '1:2.54.0-vendor1'
            self.assertEqual(recipe.assess(bumped, self.installed, git_names=self.tools)['git']['status'], 'unsupported_newer')
            (self.apt / 'orbit-git.json').write_text('not json')
            self.assertFalse(recipe.git_owned('git', PIN, self.tools))

    def test_mixed_or_wrong_suite_sources_and_conflicting_backups_are_refused(self):
        for content in (SOURCE.replace('resolute', 'other'),
                        SOURCE.replace('Suites:', ' https://other.example/\nSuites:')):
            self.source.write_text(content)
            with self.assertRaisesRegex(recipe.RecipeFailure, 'apt-source'):
                recipe.configure_git(self.payload)
        self.source.write_text(SOURCE)
        (self.apt / (self.source.name + '.orbit-git-backup')).write_text('another backup')
        with patch.object(recipe, 'publish') as publish:
            with self.assertRaisesRegex(recipe.RecipeFailure, 'apt-source'):
                recipe.configure_git(self.payload, apply=True)
        publish.assert_not_called()
        self.assertEqual(self.source.read_text(), SOURCE)

    def test_git_download_uses_the_mirror_then_ppa_and_checks_both_digests(self):
        pin = {'version': PIN, 'artifacts': {'amd64': {'url': 'https://ppa.example/git.deb',
               'sha256': hashlib.sha256(b'git bytes').hexdigest()}}}
        destination = self.apt / 'git.deb'
        for mirror_present in (True, False):
            urls = []
            def fetch(argv, mirror_present=mirror_present, urls=urls, **kwargs):
                urls.append(argv[-1])
                if not mirror_present and len(urls) == 1:
                    raise recipe.RecipeFailure(result=subprocess.CompletedProcess(argv, 22, '', 'missing'))
                Path(argv[argv.index('--output') + 1]).write_bytes(b'git bytes')
            with patch.object(recipe, 'run', side_effect=fetch):
                recipe.download_deb('git', pin, self.payload, destination)
            self.assertEqual(len(urls), 1 if mirror_present else 2)
            self.assertIn('/git_' + pin['artifacts']['amd64']['sha256'] + '.deb', urls[0])
            if not mirror_present:
                self.assertEqual(urls[1], pin['artifacts']['amd64']['url'])
        corrupt_urls = []
        def corrupt_mirror(argv, **kwargs):
            corrupt_urls.append(argv[-1])
            destination.write_bytes(b'wrong bytes' if len(corrupt_urls) == 1 else b'git bytes')
        with patch.object(recipe, 'run', side_effect=corrupt_mirror):
            with self.assertRaises(recipe.RecipeFailure):
                recipe.download_deb('git', pin, self.payload, destination)
        self.assertEqual(len(corrupt_urls), 1)

    def test_sources_list_and_single_paragraph_sources_are_removed_with_backups_outside_sourceparts(self):
        self.source.unlink()
        for relative, content in (('sources.list', 'deb https://ppa.launchpad.net/git-core/ppa/ubuntu resolute main\n'),
                                  ('sources.list.d/only.sources', SOURCE.rstrip('\n'))):
            with self.subTest(relative=relative):
                source = self.apt / relative
                source.write_text(content)
                with patch.object(recipe, 'publish', side_effect=lambda path, data: Path(path).write_bytes(data)):
                    recipe.configure_git(self.payload, apply=True)
                self.assertFalse(source.exists())
                backup = self.apt / (source.name + '.orbit-git-backup')
                self.assertEqual(backup.read_text(), content)
                self.assertEqual(list((self.apt / 'sources.list.d').iterdir()), [])

    def test_readded_ppa_with_a_changed_inline_key_cannot_overwrite_its_backup(self):
        with patch.object(recipe, 'publish', side_effect=lambda path, data: Path(path).write_bytes(data)):
            recipe.configure_git(self.payload, apply=True)
        self.source.write_text(SOURCE.replace('fixture-key', 'another-key'))
        with patch.object(recipe, 'publish') as publish:
            with self.assertRaisesRegex(recipe.RecipeFailure, 'apt-source'):
                recipe.configure_git(self.payload, apply=True)
        publish.assert_not_called()
        self.assertIn('another-key', self.source.read_text())
        self.assertEqual((self.apt / (self.source.name + '.orbit-git-backup')).read_text(), SOURCE)

    def test_failed_install_leaves_no_new_git_preference_on_nodes_and_images(self):
        for module in (recipe, image):
            self.installed = dict.fromkeys(self.tools, UBUNTU)
            base_command = self.command
            def failing(argv, module=module, base_command=base_command, **kwargs):
                if argv[:2] == ['apt-get', 'install'] and '--simulate' not in argv:
                    self.assertFalse((self.apt / 'preferences.d/orbit-git').exists())
                    raise module.RecipeFailure(result=subprocess.CompletedProcess(argv, 100, '', 'install failed'))
                return base_command(argv, **kwargs)
            with self.subTest(module=module.__name__), patch.object(self, 'command', side_effect=failing):
                with self.assertRaises(module.RecipeFailure):
                    self.install(module)
            self.assertFalse((self.apt / 'preferences.d/orbit-git').exists())

    def test_incomplete_packages_do_not_publish_a_git_preference_even_after_apt_exits_zero(self):
        for module in (recipe, image):
            self.installed = dict.fromkeys(self.tools, UBUNTU)
            base_command = self.command
            def command(argv, base_command=base_command, **kwargs):
                if argv == ['dpkg', '--audit']:
                    return subprocess.CompletedProcess(argv, 0, 'git is only half configured\n', '')
                return base_command(argv, **kwargs)
            with self.subTest(module=module.__name__), patch.object(self, 'command', side_effect=command):
                with self.assertRaisesRegex(module.RecipeFailure, 'packages'):
                    self.install(module)
            self.assertFalse((self.apt / 'preferences.d/orbit-git').exists())

    def test_unmanaged_git_siblings_with_real_range_dependencies_are_refused_before_git_changes(self):
        for sibling in ('gitk', 'git-gui', 'git-email', 'git-svn', 'git-cvs', 'gitweb'):
            for version, upstream in ((UBUNTU, '1:2.53.0'), (PIN, '1:2.55.0')):
                self.installed = {**dict.fromkeys(self.tools, version), sibling: version}
                self.dpkg_metadata = {**{name: {'source:Package': 'git'} for name in self.tools},
                                      sibling: {'source:Package': 'git',
                                                'Depends': 'git (>> ' + upstream + '), git (<< ' + upstream + '-.)'}}
                with self.subTest(sibling=sibling, version=version, action='plan'), \
                     patch.object(recipe, 'packages', side_effect=lambda: dict(self.installed)), \
                     patch.object(recipe, 'run', side_effect=self.command):
                    with self.assertRaisesRegex(recipe.RecipeFailure, 'git-dependency:' + sibling):
                        recipe.plan(self.payload)
                for module in (recipe, image):
                    self.commands = []
                    with self.subTest(sibling=sibling, version=version, module=module.__name__):
                        with self.assertRaisesRegex(module.RecipeFailure, 'git-dependency:' + sibling):
                            self.install(module)
                        self.download.assert_not_called()
                        self.assertFalse(any(argv[:2] in (['apt-get', 'install'], ['apt-get', 'full-upgrade']) for argv in self.commands))
                        self.assertEqual(self.installed[sibling], version)
                        self.assertEqual(self.source.read_text(), SOURCE)
                        self.assertFalse((self.apt / (self.source.name + '.orbit-git-backup')).exists())
                        self.assertFalse((self.apt / 'preferences.d/orbit-git').exists())

    def test_owned_git_binaries_other_sources_and_removed_siblings_do_not_block_convergence(self):
        self.installed['git-lfs'] = '3.7.1-1'
        self.dpkg_metadata.update({'git-lfs': {'source:Package': 'git-lfs', 'Depends': 'git'},
                                   'gitk': {'source:Package': 'git',
                                            'Depends': 'git (>> 1:2.53.0), git (<< 1:2.53.0-.)'}})

        for module in (recipe, image):
            with self.subTest(module=module.__name__):
                self.install(module)
                self.assertEqual(self.installed, {**dict.fromkeys(self.tools, PIN), 'git-lfs': '3.7.1-1'})

    def test_installed_git_sibling_outside_native_inventory_is_still_refused(self):
        self.dpkg_metadata['git-email'] = {'source:Package': 'git', 'db:Status-Status': 'installed',
                                           'Depends': 'git (>> 1:2.55.0), git (<< 1:2.55.0-.)'}

        for module in (recipe, image):
            with self.subTest(module=module.__name__):
                with self.assertRaisesRegex(module.RecipeFailure, 'git-dependency:git-email'):
                    self.install(module)
                self.assertEqual(self.source.read_text(), SOURCE)

    def test_image_uses_refreshed_snapshot_policy_when_normal_indexes_only_have_dpkg_status(self):
        self.installed = dict.fromkeys(self.tools, UBUNTU)
        curl = json.loads((RESOURCE / 'recipe.json').read_text())['tools']['curl']
        self.payload['tools'] = {**self.tools, 'curl': curl}
        self.installed['curl'] = curl['version']
        base_command = self.command
        refreshed = False
        policies = []
        def command(argv, **kwargs):
            nonlocal refreshed
            snapshot = any(arg.startswith('Dir::Etc::sourcelist=') for arg in argv)
            if argv[:2] == ['apt-get', 'update']:
                self.assertTrue(snapshot)
                self.assertIn('DPkg::Lock::Timeout=300', argv)
                refreshed = True
            if argv[:2] == ['apt-cache', 'policy']:
                self.assertTrue(refreshed, 'Git origin was checked before index refresh')
                policies.append(argv)
                if not snapshot:
                    name = argv[2].split(':')[0] if len(argv) > 2 else ''
                    output = (name + ':\n *** ' + self.installed[name] + ' 100\n        100 /var/lib/dpkg/status\n') if name else ''
                    return subprocess.CompletedProcess(argv, 0, output, '')
                self.assertIn('DPkg::Lock::Timeout=300', argv)
            return base_command(argv, **kwargs)
        with patch.object(self, 'command', side_effect=command), patch.object(image, 'snapshot_origin', side_effect=AssertionError('No log/network fallback needed')), \
             patch.object(image, 'assess', wraps=image.assess) as assess:
            self.install(image)
        self.assertTrue(policies)
        self.assertEqual(self.installed, {**dict.fromkeys(self.tools, PIN), 'curl': curl['version']})
        self.assertTrue(all(set(call.args[0]) == {*self.tools, 'curl'} for call in assess.call_args_list))

    def test_mirror_skips_published_assets_and_mirrors_a_non_php_non_git_deb(self):
        path = RESOURCE.parents[3] / 'bin/php-build'
        loader = importlib.machinery.SourceFileLoader('recipe_artifact_mirror', str(path))
        module = importlib.util.module_from_spec(importlib.util.spec_from_loader(loader.name, loader))
        loader.exec_module(module)
        digest = hashlib.sha512(b'caddy').hexdigest()
        seed = {'artifact_mirror': 'https://mirror.example', 'tools': {
            'caddy': {'source': 'artifact', 'artifacts': {'amd64': {'url': 'https://origin.example/caddy.deb', 'sha512': digest}}},
            'pi': {'source': 'internal', 'artifacts': {'amd64': {'filename': 'pi', 'sha256': 'a' * 64}}}}}
        filename = 'caddy_' + digest + '.deb'
        with patch.object(module, 'download') as download, patch('builtins.print'):
            module.mirror(seed, self.apt / 'mirror', [filename])
            download.assert_not_called()
            module.mirror(seed, self.apt / 'mirror')
            download.assert_called_once_with('https://mirror.example/' + filename, self.apt / 'mirror' / filename, digest, algorithm='sha512')

    def test_mirror_command_falls_back_on_transport_errors_and_stops_on_corruption(self):
        path = RESOURCE.parents[3] / 'bin/php-build'
        loader = importlib.machinery.SourceFileLoader('recipe_mirror_failures', str(path))
        module = importlib.util.module_from_spec(importlib.util.spec_from_loader(loader.name, loader))
        loader.exec_module(module)
        seed = {'artifact_mirror': 'https://mirror.example', 'tools': {'git': self.tools['git']}}
        for error, calls in ((module.DownloadUnavailable('404'), 2), (module.BuildError('digest mismatch'), 1)):
            with self.subTest(error=error), patch.object(module, 'download', side_effect=[error, None]) as download, patch('builtins.print'):
                if calls == 1:
                    with self.assertRaises(module.BuildError):
                        module.mirror(seed, self.apt / 'mirror')
                else:
                    # One architecture is sufficient to exercise the transport fallback.
                    single = {**seed, 'tools': {'git': {**self.tools['git'], 'artifacts': {'amd64': self.tools['git']['artifacts']['amd64']}}}}
                    module.mirror(single, self.apt / 'mirror')
                self.assertEqual(download.call_count, calls)

    def test_mirror_command_retains_git_files_with_content_addressed_names(self):
        path = RESOURCE.parents[3] / 'bin/php-build'
        loader = importlib.machinery.SourceFileLoader('git_mirror_build', str(path))
        module = importlib.util.module_from_spec(importlib.util.spec_from_loader(loader.name, loader))
        loader.exec_module(module)
        seed = json.loads((RESOURCE / 'recipe.json').read_text())
        with patch.object(module, 'download') as download, patch('builtins.print'):
            module.mirror(seed, self.apt / 'mirror')
        for name, pin in self.tools.items():
            for artifact in pin['artifacts'].values():
                filename = name + '_' + artifact['sha256'] + '.deb'
                self.assertTrue(any(call.args == (seed['artifact_mirror'] + '/' + filename,
                                self.apt / 'mirror' / filename, artifact['sha256']) and call.kwargs == {'algorithm': 'sha256'} for call in download.call_args_list))


if __name__ == '__main__':
    unittest.main()
