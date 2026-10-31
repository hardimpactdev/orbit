"""Exercise bootstrap actions with disposable files and system-command boundaries."""

import hashlib
import importlib.util
import io
import os
import subprocess
import tempfile
import unittest
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import patch


def load(name):
    spec = importlib.util.spec_from_file_location(name, Path(__file__).with_name('bootstrap_' + name + '.py'))
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


class BootstrapActions(unittest.TestCase):
    def test_unsupported_release_refuses_before_package_mutation(self):
        module = load('packages')
        with patch.object(module.Path, 'read_text', return_value='ID=other\nVERSION_CODENAME=resolute\n'), \
                patch.object(module.subprocess, 'run') as run, \
                self.assertRaisesRegex(ValueError, 'node.bootstrap_os_unsupported'):
            module.install({'releases': ['resolute'], 'packages': ['sudo']})
        run.assert_not_called()

    def test_package_install_preserves_packages_and_refuses_failed_update(self):
        module = load('packages')
        with patch.object(module.Path, 'read_text', return_value='ID=ubuntu\nVERSION_CODENAME=resolute\n'), \
                patch.object(module.subprocess, 'run') as run:
            self.assertEqual(module.install({'releases': ['resolute'], 'packages': ['sudo', 'curl']}),
                             {'packages_installed': True})
            self.assertEqual(run.call_args_list[1].args[0], [
                '/usr/bin/apt-get', '--no-remove', 'install', '--yes', '--no-install-recommends', '--', 'sudo', 'curl'])
            run.reset_mock()
            run.side_effect = subprocess.CalledProcessError(1, 'apt-get')
            with self.assertRaises(subprocess.CalledProcessError):
                module.install({'releases': ['resolute'], 'packages': ['sudo']})
            self.assertEqual(run.call_count, 1)

    def test_existing_account_is_preserved_without_system_changes(self):
        module = load('account')
        account = SimpleNamespace(pw_name='orbit', pw_dir='/srv/existing-home')
        with patch.object(module.pwd, 'getpwnam', return_value=account), patch.object(module.subprocess, 'run') as run:
            self.assertEqual(module.prepare({'user': 'orbit'}), {
                'account_prepared': True, 'user': 'orbit', 'home': '/srv/existing-home'})
        run.assert_not_called()

    def test_missing_account_uses_the_fixed_creation_contract(self):
        module = load('account')
        account = SimpleNamespace(pw_name='orbit', pw_dir='/home/orbit')
        with patch.object(module.pwd, 'getpwnam', side_effect=[KeyError(), account]), \
                patch.object(module.subprocess, 'run') as run:
            self.assertTrue(module.prepare({'user': 'orbit'})['account_prepared'])
        self.assertEqual(run.call_args.args[0], [
            '/usr/sbin/useradd', '--create-home', '--shell', '/bin/bash', '--', 'orbit'])

    def test_public_key_retains_existing_keys_and_is_idempotent(self):
        module = load('authorized_key')
        with tempfile.TemporaryDirectory(dir=os.environ.get('TMPDIR')) as temporary:
            home = Path(temporary)
            (home / '.ssh').mkdir(mode=0o750)
            keys = home / '.ssh/authorized_keys'
            keys.write_text('ssh-ed25519 OLD')
            account = SimpleNamespace(pw_dir=temporary, pw_uid=os.getuid(), pw_gid=os.getgid())
            data = {'user': 'orbit', 'public_key': 'ssh-ed25519 NEW'}
            with patch.object(module.pwd, 'getpwnam', return_value=account):
                self.assertEqual(module.authorize(data), {'authorized': True, 'changed': True})
                self.assertEqual(module.authorize(data), {'authorized': True, 'changed': False})
            self.assertEqual(keys.read_text(), 'ssh-ed25519 OLD\nssh-ed25519 NEW\n')
            self.assertEqual((home / '.ssh').stat().st_mode & 0o777, 0o750)

    def test_valid_sudo_is_retained_without_publishing_a_rule(self):
        module = load('sudo')
        with patch.object(module.subprocess, 'run', return_value=SimpleNamespace(returncode=0)), \
                patch.object(module.tempfile, 'mkstemp') as create:
            self.assertEqual(module.prepare({'user': 'orbit'}), {'sudo_prepared': True, 'changed': False})
        create.assert_not_called()

    def test_invalid_sudo_candidate_preserves_the_prior_rule(self):
        module = load('sudo')
        with tempfile.TemporaryDirectory(dir=os.environ.get('TMPDIR')) as temporary:
            directory = Path(temporary)
            target = directory / 'orbit'
            target.write_text('prior rule\n')
            actual_path = module.Path
            def path(value):
                return directory if value == '/etc/sudoers.d' else actual_path(value)
            with patch.object(module, 'Path', side_effect=path), \
                    patch.object(module.subprocess, 'run', side_effect=[
                        SimpleNamespace(returncode=1), subprocess.CalledProcessError(1, 'visudo')]), \
                    self.assertRaises(subprocess.CalledProcessError):
                module.prepare({'user': 'orbit'})
            self.assertEqual(target.read_text(), 'prior rule\n')
            self.assertEqual(list(directory.iterdir()), [target])

    def test_observer_checksum_failure_preserves_installed_binary(self):
        module = load('observer')
        with tempfile.TemporaryDirectory(dir=os.environ.get('TMPDIR')) as temporary:
            module.TARGET = Path(temporary) / 'orbit-agent'
            module.TARGET.write_bytes(b'installed')
            with patch.object(module.urllib.request, 'urlopen', return_value=io.BytesIO(b'corrupt')), \
                    self.assertRaisesRegex(ValueError, 'node.agent_checksum_mismatch'):
                module.install({'url': 'https://example.test/bin', 'sha256': 'a' * 64})
            self.assertEqual(module.TARGET.read_bytes(), b'installed')
            self.assertEqual(list(Path(temporary).iterdir()), [module.TARGET])

    def test_verified_observer_is_published_atomically_and_reused(self):
        module = load('observer')
        with tempfile.TemporaryDirectory(dir=os.environ.get('TMPDIR')) as temporary:
            module.TARGET = Path(temporary) / 'orbit-agent'
            data = {'url': 'https://example.test/bin', 'sha256': hashlib.sha256(b'observer').hexdigest()}
            with patch.object(module.urllib.request, 'urlopen', return_value=io.BytesIO(b'observer')) as download:
                self.assertTrue(module.install(data)['changed'])
                self.assertFalse(module.install(data)['changed'])
            download.assert_called_once()
            self.assertEqual(module.TARGET.read_bytes(), b'observer')
            self.assertEqual(module.TARGET.stat().st_mode & 0o777, 0o755)

    def test_wrong_candidate_account_refuses_before_observer_or_sudo_read(self):
        module = load('verify')
        with patch.object(module.pwd, 'getpwuid', return_value=SimpleNamespace(pw_name='other')), \
                patch.object(module.subprocess, 'run') as run, \
                self.assertRaisesRegex(ValueError, 'node.bootstrap_verification_failed'):
            module.verify({'user': 'orbit', 'sha256': 'a' * 64})
        run.assert_not_called()


if __name__ == '__main__':
    unittest.main()
