"""Exercise image APT with real resolution and isolated snapshot lists. No network or host mutation."""
import importlib.util
from pathlib import Path
import subprocess
import tempfile
import unittest
from unittest.mock import patch

RESOURCES = Path(__file__).resolve().parents[2] / 'resources'
spec = importlib.util.spec_from_file_location('image_apt', RESOURCES / 'remote/lib/recipe.py')
image = importlib.util.module_from_spec(spec)
exec(compile((RESOURCES / 'remote/lib/artifacts.py').read_text(), 'artifacts.py', 'exec'), image.__dict__)
spec.loader.exec_module(image)
exec(compile((RESOURCES / 'machine-software/build-image.py').read_text(), 'build-image.py', 'exec'), image.__dict__)


class ImageAptTest(unittest.TestCase):
    def setUp(self):
        work = tempfile.TemporaryDirectory()
        self.addCleanup(work.cleanup)
        self.root = Path(work.name)
        self.stock = self.root / 'etc/apt'
        (self.stock / 'apt.conf.d').mkdir(parents=True)
        (self.stock / 'sources.list.d').mkdir()
        (self.stock / 'preferences.d').mkdir()
        (self.stock / 'apt.conf').write_text('ImageTest::Main "loaded";\n')
        (self.stock / 'apt.conf.d/01-test').write_text('ImageTest::Parts "loaded";\n')
        (self.stock / 'sources.list.d/live.list').write_text('deb https://live.invalid/ubuntu resolute main\n')
        self.lists = self.root / 'lists'
        (self.lists / 'partial').mkdir(parents=True)
        self.status = self.root / 'status'
        # The base full-upgrade can install a newer dependency before a missing recipe tool is installed.
        self.status.write_text('Package: bind9-libs\nStatus: install ok installed\nArchitecture: amd64\n'
                               'Version: 2.0\nDescription: fixture\n\n')
        self.payload = {'snapshot': '20261014T000000Z', 'architecture': 'amd64', 'profile': 'base',
                        'tools': {'bind9-dnsutils': {'source': 'ubuntu', 'package': 'bind9-dnsutils', 'version': '2.0'}}}
        prefix = 'snapshot.ubuntu.com_ubuntu_20261014T000000Z_dists_resolute'
        (self.lists / (prefix + '_Release')).write_text('Origin: Ubuntu\nLabel: Ubuntu\nSuite: resolute\n'
                                                      'Codename: resolute\nVersion: 26.04\nArchitectures: amd64\nComponents: main\n')
        packages = ''
        for name, version, dependency in [('bind9-dnsutils', '1.0', 'bind9-libs (= 1.0)'),
                                           ('bind9-dnsutils', '2.0', 'bind9-libs (= 2.0)'),
                                           ('bind9-libs', '1.0', ''), ('bind9-libs', '2.0', '')]:
            packages += (f'Package: {name}\nVersion: {version}\nArchitecture: amd64\n'
                         + (f'Depends: {dependency}\n' if dependency else '')
                         + f'Filename: pool/{name}_{version}_amd64.deb\nSize: 1\nDescription: fixture\n\n')
        (self.lists / (prefix + '_main_binary-amd64_Packages')).write_text(packages)
        self.results = []
        configuration = image.image_apt_configuration

        def isolated_configuration(directory):
            # Only redirect filesystem state; sources and resolver policy come from the production configuration.
            return configuration(directory) + (f'Dir::Etc "{self.stock}";\n'
                   f'Dir::State::lists "{self.lists}";\nDir::State::status "{self.status}";\n'
                   f'Dir::State::extended_states "{self.root}/extended_states";\n'
                   f'Dir::Cache "{self.root}/cache";\nDir::Cache::pkgcache "";\nDir::Cache::srcpkgcache "";\n'
                   'APT::Architecture "amd64";\n')

        for name, value in [('IMAGE_APT', self.root / 'image-apt'), ('POLICY', self.root / 'policy'),
                            ('image_apt_configuration', isolated_configuration), ('publish', self.publish)]:
            replacement = patch.object(image, name, value)
            replacement.start()
            self.addCleanup(replacement.stop)

    def publish(self, path, content, *args):
        Path(path).write_bytes(content)

    def apt(self, argv, **kwargs):
        result = subprocess.run(argv, env=kwargs.get('env'), capture_output=True, text=True, timeout=30, check=False)
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertEqual(result.stderr, '')
        return result

    def test_configuration_disables_stock_directories_without_apt_warnings(self):
        image.image_apt_setup(self.payload, image.IMAGE_APT)
        with image.image_apt_environment(self.payload):
            config = self.apt(['apt-config', 'dump']).stdout
            self.assertNotIn('ImageTest::', config)
            update = self.apt(['apt-get', '--print-uris', 'update']).stdout
            self.assertIn('https://snapshot.ubuntu.com/ubuntu/20261014T000000Z/', update)
            self.assertNotIn('live.invalid', update)
            policy = self.apt(['apt-cache', 'policy', 'bind9-libs']).stdout
            self.assertIn('https://snapshot.ubuntu.com/ubuntu/20261014T000000Z', policy)
            self.assertIn('Candidate: 2.0', policy)

    def test_packages_phase_resolves_snapshot_pins_with_zero_downgrades(self):
        def run(argv, **kwargs):
            if argv[0] == 'apt-get':
                # Run the real resolver without downloading or installing. All state is under the test directory.
                mode = '--print-uris' if argv[1] == 'update' else '--simulate'
                result = self.apt(['apt-get', mode, *argv[1:]], **kwargs)
                self.results.append((argv[1], result.stdout))
                if argv[1] == 'install':
                    # Simulation can bypass -y's downgrade gate. URI printing checks it without acquisition or dpkg.
                    download = self.apt(['apt-get', '--print-uris', '--download-only', *argv[1:]], **kwargs)
                    uris = [line.split()[0].strip("'") for line in download.stdout.splitlines() if line.startswith("'")]
                    self.assertEqual(uris, [
                        'https://snapshot.ubuntu.com/ubuntu/20261014T000000Z/pool/bind9-dnsutils_2.0_amd64.deb',
                    ])
                return result
            self.assertTrue(argv == ['dpkg', '--audit'] or argv[:2] == ['systemctl', 'show'], argv)
            return subprocess.CompletedProcess(argv, 0, '', '')

        with patch.object(image, 'run', side_effect=run), \
             patch.object(image, 'packages', return_value={'bind9-libs': '2.0'}):
            image.image_apt_source(self.payload)
            image.image_packages(self.payload)

        self.assertEqual([phase for phase, _ in self.results], ['update', 'full-upgrade', 'install', 'check'])
        install = next(output for phase, output in self.results if phase == 'install')
        self.assertIn('Inst bind9-dnsutils (2.0 Ubuntu:26.04/resolute [amd64])', install)
        self.assertNotRegex(install, r'[1-9][0-9]* downgraded')
        self.assertNotIn('DOWNGRADED', install)
        self.assertNotIn('Inst bind9-libs [2.0] (1.0', install)
        self.assertFalse(image.POLICY.exists())

    def test_strict_bind9_dependency_resolution_requires_the_snapshot_candidate_pin(self):
        # Neither BIND package is installed: an older exact dependency conflicts with the newer candidate.
        self.status.write_text('')
        image.image_apt_setup(self.payload, image.IMAGE_APT)
        with image.image_apt_environment(self.payload):
            stale = subprocess.run(['apt-get', '--simulate', 'install', '-y', 'bind9-dnsutils=1.0'],
                                   capture_output=True, text=True, check=False)
            self.assertNotEqual(stale.returncode, 0)
            resolved = self.apt(['apt-get', '--simulate', 'install', '-y', 'bind9-dnsutils=2.0']).stdout
            self.assertIn('Inst bind9-libs (2.0', resolved)
            self.assertNotRegex(resolved, r'[1-9][0-9]* downgraded')
            self.assertNotIn('DOWNGRADED', resolved)
            uris = self.apt(['apt-get', '--print-uris', '--download-only', 'install', '-y',
                             'bind9-dnsutils=2.0']).stdout
            self.assertIn('snapshot.ubuntu.com/ubuntu/20261014T000000Z/pool/bind9-libs_2.0', uris)
            self.assertNotIn('live.invalid', uris)
