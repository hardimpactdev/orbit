"""Exact Incus Debian artifacts use the recipe installer and mirror, on Nodes and images."""
import copy
import hashlib
import json
import io
import os
import sys
from contextlib import redirect_stdout, redirect_stderr
from pathlib import Path
import runpy
import subprocess
import tempfile
import unittest
from unittest.mock import patch

from test_recipe_versions import recipe, image

ROOT = Path(__file__).resolve().parents[4]
build = runpy.run_path(str(ROOT / 'bin/recipe-artifacts'))
DOCUMENT = json.loads((ROOT / 'apps/gateway/resources/machine-software/recipe.json').read_text())
PIN = DOCUMENT['tools']['incus']['artifacts']['amd64']['version']


class IncusRecipeTest(unittest.TestCase):
    def setUp(self):
        work = tempfile.TemporaryDirectory()
        self.addCleanup(work.cleanup)
        self.apt = Path(work.name)
        (self.apt / 'sources.list.d').mkdir()
        (self.apt / 'preferences.d').mkdir()
        self.tools = copy.deepcopy({name: DOCUMENT['tools'][name] for name in recipe.INCUS_PACKAGES})
        for pin in self.tools.values():
            pin['version'] = PIN
        self.payload = {'tools': self.tools, 'profile': 'base', 'architecture': 'amd64',
                        'snapshot': DOCUMENT['ubuntu']['snapshot']}
        self.installed = dict.fromkeys(self.tools, '6.0.5-8')
        self.commands = []
        for module in (recipe, image):
            for key, value in [('APT_ETC', self.apt), ('POLICY', self.apt / 'policy')]:
                fake = patch.object(module, key, value)
                fake.start()
                self.addCleanup(fake.stop)
        image_apt = patch.object(image, 'IMAGE_APT', self.apt / 'image-apt')
        image_apt.start()
        self.addCleanup(image_apt.stop)

    def command(self, argv, **kwargs):
        self.commands.append(argv)
        if argv[:2] == ['dpkg', '--compare-versions']:
            return subprocess.run(argv, capture_output=True, text=True)
        output = ''
        if argv[:2] == ['incus', 'list']:
            output = '[]'
        elif argv[:2] == ['dpkg-deb', '-f']:
            output = '\n'.join([Path(argv[2]).stem, PIN, 'amd64'])
        elif argv[:2] == ['apt-get', 'install'] and '--simulate' not in argv:
            self.installed.update(dict.fromkeys(self.tools, PIN))
        return subprocess.CompletedProcess(argv, 0, output, '')

    def test_nodes_and_images_install_all_three_packages_and_keep_exact_preferences(self):
        for module in (recipe, image):
            with self.subTest(module=module.__name__):
                self.installed = dict.fromkeys(self.tools, '6.0.5-8')
                self.commands = []
                def command(argv, module=module, **kwargs):
                    if module is image and argv[0] == 'apt-get':
                        self.assertEqual(os.environ['APT_CONFIG'], str(image.IMAGE_APT / 'apt.conf'))
                    return self.command(argv, **kwargs)
                with patch.object(module, 'packages', side_effect=lambda: self.installed.copy()), \
                     patch.object(module, 'run', side_effect=command), \
                     patch.object(module, 'ubuntu_origin', return_value=True), \
                     patch.object(module, 'active_units', return_value=set()), \
                     patch.object(module, 'configure_incus_firmware'), \
                     patch.object(module.pwd, 'getpwnam', return_value=None), \
                     patch.object(module, 'publish', side_effect=lambda path, data, *args: Path(path).write_bytes(data)), \
                     patch.object(module, 'download', side_effect=lambda pin, arch, path: path):
                    if module is recipe:
                        module.apply(self.payload)
                    else:
                        module.image_apt_source(self.payload)
                        module.image_packages(self.payload)
                installs = [argv for argv in self.commands if argv[:2] == ['apt-get', 'install'] and '--simulate' not in argv]
                self.assertEqual(len(installs), 1)
                self.assertEqual({Path(arg).stem for arg in installs[0] if arg.endswith('.deb')}, set(self.tools))
                self.assertEqual(json.loads((self.apt / 'orbit-incus.json').read_text()), self.installed)
                preferences = (self.apt / 'preferences.d/orbit-incus').read_text()
                for name in self.tools:
                    self.assertIn(f'Package: {name}\nPin: version {PIN}\n', preferences)

    def test_foreign_packages_and_downgrades_are_refused_before_install(self):
        for installed, trusted in [('1:7.0.0-vendor1', False), ('1:7.0.2-ubuntu26.04-202610180000', True)]:
            with self.subTest(installed=installed), patch.object(recipe, 'run', side_effect=self.command), \
                 patch.object(recipe, 'ubuntu_origin', return_value=trusted):
                report = recipe.assess(self.tools, dict.fromkeys(self.tools, installed))
                with self.assertRaisesRegex(recipe.RecipeFailure, 'origin:incus'):
                    recipe.supported(report)
                self.assertFalse(any(argv[:2] == ['apt-get', 'install'] for argv in self.commands))

    def test_incomplete_package_set_is_refused(self):
        del self.payload['tools']['incus-client']
        with self.assertRaisesRegex(recipe.RecipeFailure, 'incus:incomplete'):
            recipe.configure_incus(self.payload)

    def test_old_firmware_names_use_bundled_firmware_and_preserve_nvram(self):
        for architecture in ('amd64', 'arm64'):
            with self.subTest(architecture=architecture), tempfile.TemporaryDirectory() as directory:
                root = Path(directory)
                firmware = root / 'opt/incus/share/qemu'
                firmware.mkdir(parents=True)
                for name in ('OVMF_CODE.4MB.fd', 'OVMF_VARS.4MB.fd', 'OVMF_VARS.4MB.ms.fd'):
                    (firmware / name).write_bytes(b'pinned firmware')
                payload = dict(self.payload, architecture=architecture)
                with patch.object(recipe, 'packages', return_value={}):
                    recipe.configure_incus_firmware(payload, root)
                    recipe.configure_incus_firmware(payload, root)
                legacy = 'OVMF_CODE_4M.ms.fd' if architecture == 'amd64' else 'AAVMF_CODE.fd'
                self.assertEqual((firmware / legacy).resolve(), (firmware / 'OVMF_CODE.4MB.fd').resolve())
                (firmware / legacy).unlink()
                (firmware / legacy).write_bytes(b'foreign firmware')
                with patch.object(recipe, 'packages', return_value={}), self.assertRaisesRegex(recipe.RecipeFailure, 'incus:firmware'):
                    recipe.configure_incus_firmware(payload, root)

    def test_removes_only_the_obsolete_agent_without_replacing_stock_units(self):
        with patch.object(recipe, 'packages', return_value={'incus-agent': 'distro-version'}), \
             patch.object(recipe, 'run', side_effect=self.command):
            recipe.remove_incus_agent(self.payload)
        self.assertEqual(self.commands, [['dpkg', '--remove', 'incus-agent']])

    def test_explicit_change_names_active_instances_in_every_project(self):
        instances = [{'project': project, 'name': name, 'type': kind, 'status': status}
                     for project, name, kind, status in [
                         ('orbit-tasks', 'builder', 'virtual-machine', 'Running'),
                         ('orbit-ci', 'ci-shark-1', 'container', 'Running'),
                         ('memory', 'experiment', 'virtual-machine', 'Frozen'),
                         ('orbit-tasks', 'parked', 'virtual-machine', 'Stopped')]]
        def command(argv, **kwargs):
            self.commands.append(argv)
            return subprocess.CompletedProcess(argv, 0, json.dumps(instances), '')
        with patch.object(recipe, 'packages', return_value=self.installed), \
             patch.object(recipe, 'run', side_effect=command), \
             patch.object(recipe, 'repair_ubuntu_sources') as repair:
            with self.assertRaises(recipe.RecipeFailure) as raised:
                recipe.apply(self.payload)
        self.assertEqual(str(raised.exception), 'incus:running')
        for name in ('orbit-tasks/builder', 'orbit-ci/ci-shark-1', 'memory/experiment'):
            self.assertIn(name, raised.exception.detail)
        self.assertNotIn('parked', raised.exception.detail)
        self.assertEqual(self.commands, [['incus', 'list', '--all-projects', '--format=json']])
        repair.assert_not_called()

    def test_instance_started_during_download_refuses_before_package_installation(self):
        active = False
        def command(argv, **kwargs):
            if argv[:2] == ['incus', 'list']:
                self.commands.append(argv)
                instances = [{'project': 'orbit-ci', 'name': 'late-runner', 'type': 'container', 'status': 'Running'}] if active else []
                return subprocess.CompletedProcess(argv, 0, json.dumps(instances), '')
            return self.command(argv, **kwargs)
        def download(pin, arch, path):
            nonlocal active
            active = True
            return path
        with patch.object(recipe, 'packages', side_effect=lambda: self.installed.copy()), \
             patch.object(recipe, 'run', side_effect=command), \
             patch.object(recipe, 'ubuntu_origin', return_value=True), \
             patch.object(recipe, 'active_units', return_value=set()), \
             patch.object(recipe.pwd, 'getpwnam', return_value=None), \
             patch.object(recipe, 'publish', side_effect=lambda path, data, *args: Path(path).write_bytes(data)), \
             patch.object(recipe, 'download', side_effect=download):
            with self.assertRaisesRegex(recipe.RecipeFailure, 'incus:running') as raised:
                recipe.apply(self.payload)
        self.assertIn('orbit-ci/late-runner', raised.exception.detail)
        self.assertEqual(self.commands.count(['incus', 'list', '--all-projects', '--format=json']), 2)
        self.assertFalse(any(argv[:2] == ['apt-get', 'install'] for argv in self.commands))
        self.assertFalse(recipe.POLICY.exists())

    def test_server_without_the_meta_package_still_requires_a_drain(self):
        del self.installed['incus']
        instance = {'project': 'orbit-ci', 'name': 'runner', 'type': 'container', 'status': 'Running'}
        with patch.object(recipe, 'packages', return_value=self.installed), \
             patch.object(recipe, 'run', return_value=subprocess.CompletedProcess([], 0, json.dumps([instance]), '')):
            with self.assertRaisesRegex(recipe.RecipeFailure, 'incus:running'):
                recipe.incus_preflight(self.payload)

    def test_unchanged_incus_allows_other_convergence_without_a_drain(self):
        installed = dict.fromkeys(self.tools, PIN)
        (self.apt / 'preferences.d/orbit-incus').write_bytes(recipe.artifact_preferences(self.tools))
        with patch.object(recipe, 'packages', return_value=installed), \
             patch.object(recipe, 'run', side_effect=self.command):
            recipe.incus_preflight(self.payload)
        self.assertEqual(self.commands, [])

    def test_malformed_instance_inventory_fails_closed(self):
        with patch.object(recipe, 'packages', return_value=self.installed), \
             patch.object(recipe, 'run', return_value=subprocess.CompletedProcess([], 0, '[{}]', '')):
            with self.assertRaisesRegex(recipe.RecipeFailure, 'incus:inventory'):
                recipe.incus_preflight(self.payload)


class IncusBumpTest(unittest.TestCase):
    def indexes(self):
        indexes = {}
        for arch in DOCUMENT['architectures']:
            indexes[arch] = '\n\n'.join('\n'.join([
                'Package: ' + name, 'Architecture: ' + arch,
                'Version: ' + pin['artifacts'][arch]['version'],
                'Filename: ' + pin['artifacts'][arch]['url'].split('lts-7.0/', 1)[1],
                'SHA256: ' + pin['artifacts'][arch]['sha256']])
                for name, pin in DOCUMENT['tools'].items() if name in recipe.INCUS_PACKAGES)
        return indexes

    def test_selects_architecture_versions_and_ignores_feature_releases(self):
        document = copy.deepcopy(DOCUMENT)
        indexes = self.indexes()
        indexes['amd64'] += '\n\nPackage: incus\nArchitecture: amd64\nVersion: 1:7.1.0-ubuntu26.04-202610010000\n'
        self.assertEqual(build['bump_incus_recipe'](document, indexes), [])
        self.assertEqual(document['tools']['incus']['version'], '7.0.1')

    def test_partial_build_and_untrusted_paths_do_not_change_pins(self):
        for damaged in (self.indexes()['arm64'].replace('Package: incus-client', 'Package: absent'),
                        self.indexes()['arm64'].replace('pool/main/i/incus/', '../../')):
            document = copy.deepcopy(DOCUMENT)
            indexes = self.indexes()
            indexes['arm64'] = damaged
            with self.assertRaises(build['BuildError']):
                build['bump_incus_recipe'](document, indexes)
            self.assertEqual(document, DOCUMENT)

    def test_signed_indexes_download_only_changed_artifacts_and_never_for_merge_proof(self):
        indexes = self.indexes()
        key = b'pinned test signing key'
        hashes = 'SHA256:\n' + ''.join(f' {hashlib.sha256(data.encode()).hexdigest()} {len(data)} main/binary-{arch}/Packages\n'
                                          for arch, data in indexes.items())
        def fetch(url):
            if url.endswith('/key.asc'):
                return key
            if url.endswith('/InRelease'):
                return b'signed release'
            return indexes['amd64' if 'binary-amd64/' in url else 'arm64'].encode()
        for changed, indexes_only, expected in [(False, False, 0), (True, False, 1), (True, True, 0)]:
            with self.subTest(changed=changed, indexes_only=indexes_only), tempfile.TemporaryDirectory() as directory:
                document = copy.deepcopy(DOCUMENT)
                if changed:
                    document['tools']['incus']['artifacts']['amd64']['sha256'] = '0' * 64
                path = Path(directory) / 'recipe.json'
                path.write_text(json.dumps(document))
                with patch.dict(build['bump_incus'].__globals__, {'fetch': fetch, 'ZABBLY_KEY_SHA256': hashlib.sha256(key).hexdigest()}), \
                     patch.object(build['subprocess'], 'run', return_value=subprocess.CompletedProcess([], 0, hashes.encode(), b'')), \
                     patch.dict(build['bump_incus'].__globals__, download=unittest.mock.Mock()) as globals_, \
                     redirect_stdout(io.StringIO()):
                    build['bump_incus'](path, indexes_only=indexes_only)
                    self.assertEqual(globals_['download'].call_count, expected)
                self.assertEqual(json.loads(path.read_text()), DOCUMENT)


class ArtifactUpdateTest(unittest.TestCase):
    def test_one_update_publishes_both_providers_atomically_and_dry_run_writes_nothing(self):
        for dry_run, fail in [(False, False), (True, False), (False, True)]:
            with self.subTest(dry_run=dry_run, fail=fail), tempfile.TemporaryDirectory() as directory:
                target = Path(directory) / 'recipe.json'
                original = '{"providers": []}\n'
                target.write_text(original)
                def sury(path):
                    data = json.loads(Path(path).read_text())
                    data['providers'].append('sury')
                    Path(path).write_text(json.dumps(data))
                def incus(path, *, indexes_only=False, fail=fail):
                    if fail:
                        raise build['BuildError']('signature rejected')
                    data = json.loads(Path(path).read_text())
                    data['providers'].append('incus')
                    Path(path).write_text(json.dumps(data))
                arguments = ['recipe-artifacts', 'bump', '--recipe', str(target)] + (['--dry-run'] if dry_run else [])
                with patch.object(sys, 'argv', arguments), \
                     patch.dict(build['main'].__globals__, {'bump': sury, 'bump_incus': incus}), \
                     redirect_stdout(io.StringIO()), redirect_stderr(io.StringIO()):
                    status = build['main']()
                self.assertEqual(status, 1 if fail else 0)
                if dry_run or fail:
                    self.assertEqual(target.read_text(), original)
                else:
                    self.assertEqual(json.loads(target.read_text()), {'providers': ['sury', 'incus']})


if __name__ == '__main__':
    unittest.main()
