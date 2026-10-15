"""Recipe floors and diagnostics. All APT, dpkg, service and network operations are faked."""
import contextlib
import importlib.util
import io
import json
import os
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
SUPERSEDED_BY = '8.18.0-1ubuntu2.9'
LEGACY_SOURCE = b'''Types: deb
URIs: https://snapshot.ubuntu.com/ubuntu/20261013T000000Z/
Suites: resolute resolute-updates resolute-security
Components: main universe restricted multiverse
Signed-By: /usr/share/keyrings/ubuntu-archive-keyring.gpg
Check-Valid-Until: no
'''


class RecipeVersionsTest(unittest.TestCase):
    def setUp(self):
        directory = tempfile.TemporaryDirectory()
        self.addCleanup(directory.cleanup)
        self.apt = Path(directory.name)
        (self.apt / 'sources.list.d').mkdir()
        for module in (recipe, image):
            source_patch = patch.object(module, 'APT_ETC', self.apt)
            source_patch.start()
            self.addCleanup(source_patch.stop)
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
            self.assertFalse(kwargs['required'])
            order = ['8.18.0-1ubuntu2.6', PIN, NEWER, SUPERSEDED_BY]
            left, right = order.index(argv[2]), order.index(argv[4])
            code = 0 if (left > right if argv[3] == 'gt' else left < right) else 1
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
        self.assertEqual([c[:2] for c in self.commands if c[0] == 'apt-get'], [['apt-get', 'update']])

    def test_superseded_installed_security_build_is_kept_when_trusted_pocket_has_newer_build(self):
        self.version_policy = f'''curl:
  Installed: {NEWER}
  Candidate: {SUPERSEDED_BY}
  Version table:
     {SUPERSEDED_BY} 500 (phased 20%)
        500 {INDEX}
 *** {NEWER} 100
        100 /var/lib/dpkg/status
'''
        tool = self.assess(NEWER)
        self.assertEqual((tool['status'], tool['changed']), ('newer_than_recipe', False))
        self.assertFalse(any(c[0] == 'apt-get' for c in self.commands))

    def test_phased_installed_version_and_later_version_blocks_are_parsed_independently(self):
        self.version_policy = self.version_policy.replace(f'*** {NEWER} 500', f'*** {NEWER} 500 (phased 30%)')
        self.assertEqual(self.assess(NEWER)['status'], 'newer_than_recipe')
        self.version_policy = self.version_policy.replace(f'500 {INDEX}', '100 /var/lib/dpkg/status')
        self.version_policy += f'     {PIN} 500 (phased 10%)\n        500 {INDEX}\n'
        self.assertEqual(self.assess(NEWER)['status'], 'unsupported_newer')

    def test_a_newer_trusted_version_that_is_not_the_candidate_does_not_satisfy_the_fallback(self):
        self.version_policy = f'''curl:
  Installed: {NEWER}
  Candidate: {NEWER}
  Version table:
     {SUPERSEDED_BY} -1
         -1 {INDEX}
 *** {NEWER} 100
        100 /var/lib/dpkg/status
'''
        self.assertEqual(self.assess(NEWER)['status'], 'unsupported_newer')

    def test_an_unparsed_version_line_does_not_reuse_the_preceding_version(self):
        self.version_policy = f'curl:\n *** {NEWER} 500\n     malformed version header\n        500 {INDEX}\n'
        self.assertEqual(self.assess(NEWER)['status'], 'unsupported_newer')

    def test_third_party_release_cannot_claim_to_be_ubuntu(self):
        for host in ('evil.example', 'security.ubuntu.com.evil.example', 'fakeubuntu.com'):
            with self.subTest(host=host):
                foreign_index = INDEX.replace('security.ubuntu.com', host)
                def command(argv, foreign_index=foreign_index, **kwargs):
                    result = self.command(argv, **kwargs)
                    result.stdout = result.stdout.replace(INDEX, foreign_index)
                    return result
                with patch.object(recipe, 'run', side_effect=command):
                    tool = recipe.assess(self.tools, {'curl': NEWER})['curl']
                self.assertEqual(tool['status'], 'unsupported_newer')
                self.assertEqual(tool['refusal'], 'origin:curl')

    def test_mirror_requires_matching_ubuntu_keyring_source_uri_suite_and_component(self):
        foreign_index = INDEX.replace('security.ubuntu.com', 'mirror.example')
        source = self.apt / 'sources.list.d/mirror.sources'
        def command(argv, **kwargs):
            result = self.command(argv, **kwargs)
            result.stdout = result.stdout.replace(INDEX, foreign_index)
            return result
        for uri, suite, keyring, expected in [
            ('https://mirror.example/ubuntu', 'resolute-security', recipe.UBUNTU_KEYRING, True),
            ('https://other.example/ubuntu', 'resolute-security', recipe.UBUNTU_KEYRING, False),
            ('https://mirror.example/ubuntu', 'resolute-updates', recipe.UBUNTU_KEYRING, False),
            ('https://mirror.example/ubuntu', 'resolute-security', '/etc/apt/keyrings/vendor.gpg', False),
        ]:
            with self.subTest(uri=uri, suite=suite, keyring=keyring):
                source.write_text(f'Types: deb\nURIs: {uri}\nSuites: {suite}\nComponents: main\nSigned-By: {keyring}\n')
                with patch.object(recipe, 'run', side_effect=command):
                    self.assertEqual(recipe.ubuntu_origin('curl', NEWER), expected)

    def test_ubuntu_archive_security_ports_snapshot_and_country_archive_hosts_are_trusted(self):
        for host in ('archive.ubuntu.com', 'security.ubuntu.com', 'ports.ubuntu.com', 'snapshot.ubuntu.com', 'nl.archive.ubuntu.com'):
            with self.subTest(host=host):
                self.assertTrue(recipe.ubuntu_index(INDEX.replace('security.ubuntu.com', host), set()))

    def test_legacy_list_sources_require_the_scoped_ubuntu_keyring_and_disabled_sources_do_not_count(self):
        source = self.apt / 'sources.list.d/mirror.list'
        trusted_index = ('https://mirror.example/ubuntu', 'resolute-security/main')
        for keyring, expected in ((recipe.UBUNTU_KEYRING, True), ('/etc/apt/keyrings/vendor.gpg', False)):
            source.write_text(f'deb [arch=amd64 signed-by={keyring}] https://mirror.example/ubuntu resolute-security main\n')
            self.assertEqual(trusted_index in recipe.ubuntu_signed_sources([]), expected)
        source.unlink()
        source = self.apt / 'sources.list.d/mirror.sources'
        source.write_text(f'Types: deb\nURIs: https://mirror.example/ubuntu\nSuites: resolute-security\nComponents: main\nSigned-By: {recipe.UBUNTU_KEYRING}\nEnabled: no\n')
        self.assertNotIn(trusted_index, recipe.ubuntu_signed_sources([]))

    def test_already_converged_node_repairs_only_the_exact_legacy_snapshot_before_refresh_and_assessment(self):
        source = self.apt / 'sources.list.d/ubuntu.sources'
        custom = self.apt / 'sources.list.d/custom.list'
        custom.write_text('deb https://vendor.example resolute main\n')
        for architecture in ('amd64', 'arm64'):
            with self.subTest(architecture=architecture):
                source.write_bytes(LEGACY_SOURCE)
                policy = self.apt / 'policy'
                refreshed = False
                def command(argv, architecture=architecture, **kwargs):
                    nonlocal refreshed
                    if argv[:2] == ['apt-get', 'update'] and 'Dir::Etc::sourceparts=-' not in argv:
                        self.assertNotIn('snapshot.ubuntu.com', source.read_text())
                        self.assertIn('Suites: resolute resolute-updates resolute-backports\n', source.read_text())
                        self.assertIn('Suites: resolute-security\n', source.read_text())
                        host = 'ports.ubuntu.com/ubuntu-ports' if architecture == 'arm64' else 'archive.ubuntu.com/ubuntu'
                        self.assertIn(host, source.read_text())
                        self.assertIn('ports.ubuntu.com/ubuntu-ports' if architecture == 'arm64' else 'security.ubuntu.com/ubuntu', source.read_text())
                        self.assertEqual(source.read_text().count('Signed-By: ' + recipe.UBUNTU_KEYRING), 2)
                        refreshed = True
                    if argv[0] == 'apt-cache':
                        self.assertTrue(refreshed)
                    return self.command(argv, **kwargs)
                with patch.object(recipe, 'POLICY', policy), \
                     patch.object(recipe, 'packages', return_value={'curl': NEWER}), \
                     patch.object(recipe, 'inspect', return_value={'curl': NEWER}), \
                     patch.object(recipe, 'active_services', return_value=set()), \
                     patch.object(recipe.os, 'chown'), \
                     patch.object(recipe.pwd, 'getpwnam', return_value=None), \
                     patch.object(recipe, 'run', side_effect=command):
                    # Use real atomic publication in this process-owned fixture directory.
                    original_publish = recipe.publish
                    with patch.object(recipe, 'publish', side_effect=lambda path, content, mode=0o644, original_publish=original_publish: original_publish(path, content, mode, uid=os.getuid(), gid=os.getgid())):
                        result = recipe.apply({'tools': self.tools, 'architecture': architecture, 'snapshot': '20261014T000000Z'})
                self.assertTrue(refreshed)
                self.assertEqual(result['changed_tools'], [])
                self.assertEqual(result['newer_than_recipe']['curl']['installed'], NEWER)
                self.assertEqual(custom.read_text(), 'deb https://vendor.example resolute main\n')
                self.assertFalse(any(c[:2] == ['apt-get', 'install'] for c in self.commands))

    def test_similar_but_not_exact_sources_and_symlinks_are_never_overwritten(self):
        source = self.apt / 'sources.list.d/ubuntu.sources'
        for content in (LEGACY_SOURCE + b'# user edit\n', LEGACY_SOURCE.replace(b'Check-Valid-Until: no\n', b''), b'Types: deb\nURIs: http://archive.ubuntu.com/ubuntu\n'):
            with self.subTest(content=content):
                source.write_bytes(content)
                with patch.object(recipe, 'publish') as publish:
                    self.assertFalse(recipe.repair_ubuntu_sources('amd64'))
                publish.assert_not_called()
                self.assertEqual(source.read_bytes(), content)
        source.unlink()
        target = self.apt / 'target'
        target.write_bytes(LEGACY_SOURCE)
        source.symlink_to(target)
        self.assertFalse(recipe.repair_ubuntu_sources('amd64'))
        self.assertEqual(target.read_bytes(), LEGACY_SOURCE)

    def test_dry_run_reports_a_planned_origin_refusal_without_any_mutation(self):
        self.release = 'o=ThirdParty,a=resolute-security,n=resolute'
        with patch.object(recipe, 'platform_check'), \
             patch.object(recipe, 'inspect', return_value={'curl': NEWER}), \
             patch.object(recipe, 'run', side_effect=self.command), \
             patch.object(recipe, 'publish') as publish, \
             patch.object(sys, 'argv', ['program', 'inspect', json.dumps({'architecture': 'amd64', 'tools': self.tools})]), \
             contextlib.redirect_stdout(io.StringIO()) as output:
            self.assertEqual(recipe.main(), 0)
        tool = json.loads(output.getvalue())['curl']
        self.assertEqual((tool['status'], tool['refusal']), ('unsupported_newer', 'origin:curl'))
        self.assertFalse(tool['changed'])
        publish.assert_not_called()
        self.assertFalse(any(c[0] == 'apt-get' for c in self.commands))

    def test_exact_deb_downgrades_are_allowed_but_newer_ubuntu_pins_are_explicitly_preserved(self):
        tools = {**self.tools, 'caddy': {'source': 'artifact', 'version': '2.0'}}
        def command(argv, **kwargs):
            self.commands.append(argv)
            return subprocess.CompletedProcess(argv, 0, 'Inst caddy [3.0] (2.0 local-deb [amd64])\n', '')
        with patch.object(recipe, 'run', side_effect=command):
            recipe.install_exact_debs(['/work/caddy.deb'], tools, {'curl': NEWER, 'caddy': '3.0'}, [], {})
        self.assertEqual(len(self.commands), 2)
        for argv in self.commands:
            self.assertIn('--allow-downgrades', argv)
            self.assertIn('curl=' + NEWER, argv)
            self.assertNotIn('curl=' + PIN, argv)
        self.assertIn('--simulate', self.commands[0])
        self.assertNotIn('--simulate', self.commands[1])

    def test_exact_deb_install_refuses_any_dependency_downgrade_before_applying(self):
        for dependency in ('curl', 'libother'):
            with self.subTest(dependency=dependency):
                self.commands = []
                def command(argv, dependency=dependency, **kwargs):
                    if argv[0] == 'apt-get':
                        self.commands.append(argv)
                        return subprocess.CompletedProcess(argv, 0, f'Inst {dependency} [{NEWER}] ({PIN} Ubuntu:26.04 [amd64])\n', '')
                    return self.command(argv, **kwargs)
                with patch.object(recipe, 'run', side_effect=command):
                    with self.assertRaises(recipe.RecipeFailure):
                        recipe.install_exact_debs(['/work/caddy.deb'], self.tools, {dependency: NEWER}, [], {})
                installs = [c for c in self.commands if c[0] == 'apt-get']
                self.assertEqual(len(installs), 1)
                self.assertIn('--simulate', installs[0])

    def test_node_and_image_install_exact_artifact_downgrades_without_downgrading_ubuntu(self):
        tools = {**self.tools, 'caddy': {'source': 'artifact', 'version': '2.0',
                 'artifacts': {'amd64': {'url': 'https://example.test/caddy.deb'}}}}
        payload = {'tools': tools, 'architecture': 'amd64', 'snapshot': '20261014T000000Z', 'profile': 'app-prod'}
        for module in (recipe, image):
            with self.subTest(module=module.__name__), tempfile.TemporaryDirectory() as directory:
                commands = []
                observed = {'curl': NEWER, 'caddy': '3.0'}
                def command(argv, commands=commands, observed=observed, **kwargs):
                    commands.append(argv)
                    if argv[:2] == ['dpkg-deb', '-f']:
                        return subprocess.CompletedProcess(argv, 0, 'caddy\n2.0\namd64\n', '')
                    if argv[:2] == ['apt-get', 'install']:
                        self.assertIn('--allow-downgrades', argv)
                        self.assertIn('curl=' + NEWER, argv)
                        self.assertNotIn('curl=' + PIN, argv)
                        if '--simulate' not in argv:
                            observed['caddy'] = '2.0'
                        return subprocess.CompletedProcess(argv, 0, 'Inst caddy [3.0] (2.0 local [amd64])\n', '')
                    return self.command(argv, **kwargs)
                with patch.object(module, 'POLICY', Path(directory) / 'policy'), \
                     patch.object(module, 'packages', side_effect=lambda observed=observed: dict(observed)), \
                     patch.object(module, 'inspect', side_effect=lambda tools, observed=observed: dict(observed)), \
                     patch.object(module, 'active_services', return_value=set()), \
                     patch.object(module, 'publish', side_effect=lambda path, content, *args: Path(path).write_bytes(content)), \
                     patch.object(module.pwd, 'getpwnam', return_value=None), \
                     patch.object(module, 'download', side_effect=lambda pin, architecture, path: path), \
                     patch.object(module, 'run', side_effect=command):
                    if module is recipe:
                        result = module.apply(payload)
                        self.assertEqual(result['changed_packages'], ['caddy'])
                        self.assertEqual(result['changed_tools'], ['caddy'])
                    else:
                        module.image_packages(payload)
                self.assertEqual(observed, {'curl': NEWER, 'caddy': '2.0'})
                self.assertEqual(sum(c[:2] == ['apt-get', 'install'] for c in commands), 2)
                self.assertIn(['apt-get', 'check'], commands)

    def test_failed_rolling_index_refresh_stops_before_assessment_or_download(self):
        def command(argv, **kwargs):
            self.assertEqual(argv[:2], ['apt-get', 'update'])
            self.assertIn('APT::Update::Error-Mode=any', argv)
            raise recipe.RecipeFailure(result=subprocess.CompletedProcess(argv, 100, '', 'index unavailable'))
        with patch.object(recipe, 'run', side_effect=command), \
             patch.object(recipe, 'assess') as assess, \
             patch.object(recipe, 'download') as download:
            with self.assertRaisesRegex(recipe.RecipeFailure, 'apt-source'):
                recipe.apply({'tools': self.tools, 'architecture': 'amd64'})
        assess.assert_not_called()
        download.assert_not_called()

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
