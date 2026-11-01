"""Repository lifecycle uses real temporary files and isolated GnuPG, never apt writes."""
import copy
import importlib.util
from pathlib import Path
import subprocess
import tempfile
import unittest
from unittest.mock import patch

SPEC = importlib.util.spec_from_file_location('apt_repository', Path(__file__).with_name('apt_repository.py'))
repo = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(repo)

DEFINITION = {'name': 'vendor', 'uris': ['https://packages.example.test/apt'],
              'suites': ['preview'], 'components': ['main'], 'architectures': ['amd64'],
              'signing_key': {'url': 'https://packages.example.test/key.gpg', 'fingerprint': 'A' * 40},
              'packages': ['vendor-cli']}


class RepositoryTest(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name)
        (self.root / 'etc/apt/sources.list.d').mkdir(parents=True)
        self.other = self.root / 'etc/apt/sources.list.d/ubuntu.sources'
        self.other.write_text('distribution source\n')
        self.definition = copy.deepcopy(DEFINITION)

    def prepare(self):
        with patch.object(repo, 'fetch', side_effect=lambda url, _: b'-----BEGIN PGP SIGNED MESSAGE-----\nrelease' if url.endswith('/InRelease') else b'key'), \
                patch.object(repo, 'verified_key', return_value=b'verified key'), \
                patch.object(repo, 'command') as command:
            repo.prepare(self.definition, self.root)
            return command

    def test_deb822_and_scoped_refresh_are_repeatable_and_keep_other_sources(self):
        call = self.prepare()
        source, keyring, _pin = repo.paths(self.definition, self.root)
        self.assertEqual(source.read_text(), 'Types: deb\nURIs: https://packages.example.test/apt\nSuites: preview\nComponents: main\nArchitectures: amd64\nSigned-By: /etc/apt/keyrings/orbit-vendor.gpg\n')
        self.assertEqual(keyring.read_bytes(), b'verified key')
        self.assertEqual(source.stat().st_mode & 0o777, 0o644)
        argv = call.call_args.args[0]
        self.assertIn('Dir::Etc::sourcelist=' + str(source), argv)
        self.assertIn('Dir::Etc::sourceparts=-', argv)
        self.assertIn('APT::Update::Error-Mode=any', argv)
        self.assertIn('APT::Get::List-Cleanup=0', argv)
        self.prepare()
        self.assertEqual(self.other.read_text(), 'distribution source\n')

    def test_changed_suite_and_uri_replace_the_same_source(self):
        self.prepare()
        self.definition.update(suites=['stable'], uris=['https://next.example.test/repo'])
        self.prepare()
        text = repo.paths(self.definition, self.root)[0].read_text()
        self.assertIn('Suites: stable\n', text)
        self.assertIn('URIs: https://next.example.test/repo\n', text)
        self.assertNotIn('preview', text)

    def test_unpublished_suite_leaves_previous_source_key_and_other_sources_unchanged(self):
        self.prepare()
        self.definition['suites'] = ['stable']
        source, keyring, _pin = repo.paths(self.definition, self.root)
        previous = source.read_bytes(), keyring.read_bytes(), self.other.read_bytes()
        with patch.object(repo, 'fetch', side_effect=OSError('404')):
            with self.assertRaises(repo.Refusal) as error:
                repo.prepare(self.definition, self.root)
        self.assertEqual(error.exception.code, 'tool.repository_unavailable')
        self.assertEqual((source.read_bytes(), keyring.read_bytes(), self.other.read_bytes()), previous)

    def test_signature_refresh_failure_has_repository_error(self):
        with patch.object(repo, 'fetch', side_effect=[b'-----BEGIN PGP SIGNED MESSAGE-----\nrelease', b'key']), \
                patch.object(repo, 'verified_key', return_value=b'key'), \
                patch.object(repo, 'command', side_effect=subprocess.CalledProcessError(100, 'apt-get')):
            with self.assertRaises(repo.Refusal) as error:
                repo.prepare(self.definition, self.root)
        self.assertEqual(error.exception.code, 'tool.repository_unavailable')
        self.assertEqual(self.other.read_text(), 'distribution source\n')

    def test_failed_refresh_restores_existing_source_and_key_for_retry(self):
        self.prepare()
        source, keyring, _pin = repo.paths(self.definition, self.root)
        previous = source.read_bytes(), keyring.read_bytes()
        self.definition['suites'] = ['stable']
        with patch.object(repo, 'fetch', side_effect=[b'-----BEGIN PGP SIGNED MESSAGE-----\nrelease', b'new key']), \
                patch.object(repo, 'verified_key', return_value=b'new key'), \
                patch.object(repo, 'command', side_effect=subprocess.CalledProcessError(100, 'apt-get')):
            with self.assertRaises(repo.Refusal):
                repo.prepare(self.definition, self.root)
        self.assertEqual((source.read_bytes(), keyring.read_bytes()), previous)
        self.prepare()
        self.assertIn('Suites: stable', source.read_text())

    def test_final_reference_cleanup_retries_after_partial_and_completed_cleanup(self):
        self.prepare()
        source, keyring, _pin = repo.paths(self.definition, self.root)
        source.unlink()
        repo.cleanup(self.definition, self.root)
        repo.cleanup(self.definition, self.root)
        self.assertFalse(keyring.exists())
        self.assertEqual(self.other.read_text(), 'distribution source\n')

    def test_source_symlink_is_refused_before_publishing_key(self):
        source, keyring, _pin = repo.paths(self.definition, self.root)
        source.symlink_to(self.other)
        with self.assertRaises(repo.Refusal):
            self.prepare()
        self.assertFalse(keyring.exists())
        self.assertEqual(self.other.read_text(), 'distribution source\n')

    def test_primary_fingerprint_is_required_and_extra_primary_keys_are_rejected(self):
        # Genuine exported OpenPGP bytes exercise the parser and GnuPG import/export.
        home = self.root / 'gpg'
        home.mkdir(mode=0o700)
        base = ['/usr/bin/gpg', '--batch', '--no-options', '--homedir', str(home)]
        subprocess.run([*base, '--passphrase', '', '--quick-generate-key', 'Orbit fixture <fixture@example.test>', 'ed25519', 'sign', '0'], check=True, capture_output=True)
        data = subprocess.run([*base, '--export'], check=True, capture_output=True).stdout
        rows = subprocess.run([*base, '--with-colons', '--list-keys'], check=True, capture_output=True, text=True).stdout
        fingerprint = next(line.split(':')[9] for line in rows.splitlines() if line.startswith('fpr:'))
        self.assertTrue(repo.verified_key(data, fingerprint))
        subprocess.run([*base, '--passphrase', '', '--quick-add-key', fingerprint, 'ed25519', 'sign', '0'], check=True, capture_output=True)
        with_subkey = subprocess.run([*base, '--export'], check=True, capture_output=True).stdout
        rows = subprocess.run([*base, '--with-colons', '--list-keys'], check=True, capture_output=True, text=True).stdout
        subkey = [line.split(':')[9] for line in rows.splitlines() if line.startswith('fpr:')][-1]
        self.assertTrue(repo.verified_key(with_subkey, fingerprint))
        with self.assertRaises(repo.Refusal):
            repo.verified_key(with_subkey, subkey)
        with self.assertRaises(repo.Refusal) as error:
            repo.verified_key(data, 'B' * 40)
        self.assertEqual(error.exception.code, 'tool.repository_key_mismatch')
        subprocess.run([*base, '--passphrase', '', '--quick-generate-key', 'Other fixture <other@example.test>', 'ed25519', 'sign', '0'], check=True, capture_output=True)
        both = subprocess.run([*base, '--export'], check=True, capture_output=True).stdout
        with self.assertRaises(repo.Refusal):
            repo.verified_key(both, fingerprint)

    def test_adoption_is_read_only_and_requires_matching_source_and_key(self):
        self.prepare()
        with patch.object(repo, 'verified_key') as verify, patch.object(repo, 'command') as command:
            repo.adopt(self.definition, self.root)
            verify.assert_called_once_with(b'verified key', 'A' * 40)
            command.assert_not_called()
        self.definition['suites'] = ['stable']
        with self.assertRaises(repo.Refusal) as error:
            repo.adopt(self.definition, self.root)
        self.assertEqual(error.exception.code, 'tool.repository_adoption_mismatch')

    def test_invalid_slug_url_suite_and_missing_fingerprint_are_rejected(self):
        for field, value in [('name', '../foreign'), ('name', 'caddy'), ('uris', ['http://vendor.test']),
                             ('suites', ['preview\nTrusted: yes']), ('signing_key', {'url': 'https://vendor.test/key'})]:
            with self.subTest(field=field, value=value):
                definition = {**DEFINITION, field: value}
                with self.assertRaises(repo.Refusal):
                    repo.validate(definition)


    def test_pin_limits_the_vendor_origin_to_its_declared_packages_above_the_distribution(self):
        self.definition.update(uris=['https://packages.example.test/apt', 'https://mirror.example.test/apt'],
                               packages=['vendor-tool', 'vendor-cli'])
        self.prepare()
        _source, _keyring, pin = repo.paths(self.definition, self.root)
        self.assertEqual(pin, self.root / 'etc/apt/preferences.d/orbit-vendor.pref')
        self.assertEqual(pin.read_text(), (
            'Package: vendor-cli vendor-tool\nPin: origin "packages.example.test"\nPin-Priority: 990\n\n'
            'Package: *\nPin: origin "packages.example.test"\nPin-Priority: 1\n\n'
            'Package: vendor-cli vendor-tool\nPin: origin "mirror.example.test"\nPin-Priority: 990\n\n'
            'Package: *\nPin: origin "mirror.example.test"\nPin-Priority: 1\n'))
        self.assertEqual(pin.stat().st_mode & 0o777, 0o644)

    def test_pin_is_published_before_the_source_and_restored_when_refresh_fails(self):
        self.prepare()
        source, _keyring, pin = repo.paths(self.definition, self.root)
        previous = pin.read_bytes()
        self.definition['packages'] = ['vendor-cli', 'vendor-agent']
        written = []
        original = repo.write
        with patch.object(repo, 'fetch', side_effect=[b'-----BEGIN PGP SIGNED MESSAGE-----\nrelease', b'key']), \
                patch.object(repo, 'verified_key', return_value=b'verified key'), \
                patch.object(repo, 'write', side_effect=lambda path, data, root: (written.append(path), original(path, data, root))), \
                patch.object(repo, 'command', side_effect=subprocess.CalledProcessError(100, 'apt-get')), \
                self.assertRaises(repo.Refusal):
            repo.prepare(self.definition, self.root)
        self.assertLess(written.index(pin), written.index(source))
        self.assertEqual(pin.read_bytes(), previous)

    def test_cleanup_removes_the_pin_and_adoption_requires_it(self):
        self.prepare()
        source, keyring, pin = repo.paths(self.definition, self.root)
        pin.unlink()
        with patch.object(repo, 'verified_key'), self.assertRaises(repo.Refusal) as error:
            repo.adopt(self.definition, self.root)
        self.assertEqual(error.exception.code, 'tool.repository_adoption_mismatch')
        self.prepare()
        repo.cleanup(self.definition, self.root)
        self.assertEqual([path.exists() for path in (source, keyring, pin)], [False, False, False])

    def test_invalid_or_duplicate_package_names_are_rejected(self):
        for packages in (['Vendor'], ['vendor cli'], ['a\nPin-Priority: 1001'], ['vendor', 'vendor'], 'vendor'):
            with self.subTest(packages=packages), self.assertRaises(repo.Refusal):
                repo.validate({**DEFINITION, 'packages': packages})

if __name__ == '__main__':
    unittest.main()
