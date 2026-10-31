"""Caddy package effects with local package files and mocked system tools."""
import hashlib
import importlib.util
import subprocess
import unittest
from pathlib import Path
from unittest.mock import patch

spec = importlib.util.spec_from_file_location('caddy_package', Path(__file__).with_name('caddy_package.py'))
caddy = importlib.util.module_from_spec(spec)
spec.loader.exec_module(caddy)


class CaddyPackage(unittest.TestCase):
    def setUp(self):
        self.data = {'release': '2.11.7', 'minimum_version': '2.11.0',
                     'amd64_sha512': hashlib.sha512(b'public package').hexdigest()}

    def test_current_release_does_not_download_or_install(self):
        with patch.object(caddy, 'version', return_value='2.11.7'), patch.object(caddy, 'sufficient', return_value=True), \
                patch.object(caddy, 'run') as run:
            self.assertEqual(caddy.install(self.data), {'caddy_installed': True})
            run.assert_not_called()

    def test_checksum_failure_prevents_package_install(self):
        def run(argv):
            if '--output' in argv:
                Path(argv[argv.index('--output') + 1]).write_bytes(b'wrong package')
            return subprocess.CompletedProcess(argv, 0, 'amd64\n')
        with patch.object(caddy, 'version', return_value=None), patch.object(caddy, 'run', side_effect=run), \
                patch.object(caddy.subprocess, 'run') as install:
            with self.assertRaisesRegex(ValueError, 'caddy.package_failed'):
                caddy.install(self.data)
            install.assert_not_called()

    def test_verified_package_installs_before_observing_the_final_version(self):
        calls = []
        def run(argv):
            calls.append(argv)
            if '--output' in argv:
                Path(argv[argv.index('--output') + 1]).write_bytes(b'public package')
            return subprocess.CompletedProcess(argv, 0, 'amd64\n')
        with patch.object(caddy, 'version', side_effect=[None, '2.11.7']), \
                patch.object(caddy, 'run', side_effect=run), \
                patch.object(caddy.subprocess, 'run', return_value=subprocess.CompletedProcess([], 0)) as system:
            self.assertEqual(caddy.install(self.data), {'caddy_installed': True})
        self.assertIn('https://github.com/caddyserver/caddy/releases/download/v2.11.7/caddy_2.11.7_linux_amd64.deb', calls[1])
        package = system.call_args_list[0].args[0]
        self.assertEqual(package[:2], ['/usr/bin/apt-get', '--no-remove'])
        self.assertEqual(system.call_args_list[1].args[0], ['/usr/bin/dpkg', '--compare-versions', '2.11.7', 'ge', '2.11.0'])

    def test_unsupported_architecture_stops_before_download(self):
        with patch.object(caddy, 'version', return_value=None), \
                patch.object(caddy, 'run', return_value=subprocess.CompletedProcess([], 0, 'arm64\n')) as run:
            with self.assertRaisesRegex(ValueError, 'caddy.package_failed'):
                caddy.install(self.data)
            self.assertEqual(run.call_count, 1)

    def test_a_failed_version_comparison_is_not_a_supported_installed_release(self):
        with patch.object(caddy.subprocess, 'run', return_value=subprocess.CompletedProcess([], 2)), \
                self.assertRaisesRegex(ValueError, 'caddy.package_failed'):
            caddy.sufficient('invalid', '2.11.0')
