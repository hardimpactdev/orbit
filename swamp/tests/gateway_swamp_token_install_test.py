"""Install a Gateway Swamp token on a release-container host from the candidate's reveal; every secret here is a fake."""
import json
import os
import subprocess
import tempfile
import unittest
from pathlib import Path

SCRIPT = Path(__file__).resolve().parents[1] / 'scripts/gateway-swamp-token-install.py'


class GatewaySwampTokenInstallTest(unittest.TestCase):
    def setUp(self):
        self.folder = Path(tempfile.mkdtemp())
        self.env = self.folder / 'gateway.env'
        self.env.write_text('APP_NAME=Orbit\nORBIT_SWAMP_READ_TOKEN=orbit-gateway-read-20261101.fake-secret-old\nAPP_KEY=keep\n')
        self.env.chmod(0o600)

    def tearDown(self):
        subprocess.run(['rm', '-rf', str(self.folder)], check=True)

    def install(self, kind, reveal):
        return subprocess.run(['python3', '-I', str(SCRIPT), kind, str(self.env)], input=reveal, capture_output=True, text=True,
                              timeout=30)

    def test_empty_input_means_nothing_is_due(self):
        before = self.env.read_text()
        result = self.install('read', '')
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(self.env.read_text(), before)
        self.assertEqual(list(self.folder.iterdir()), [self.env])

    def test_a_new_generation_replaces_only_its_three_settings_after_a_private_backup(self):
        reveal = json.dumps({'name': 'orbit-gateway-read-20991121', 'token': 'orbit-gateway-read-20991121.fake-secret-new',
                             'expiresAt': '2099-12-21T12:00:00.000Z'})
        result = self.install('read', reveal)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(result.stdout.strip(), 'installed orbit-gateway-read-20991121')
        self.assertEqual(self.env.read_text(), 'APP_NAME=Orbit\nORBIT_SWAMP_READ_TOKEN=orbit-gateway-read-20991121.fake-secret-new\n'
                         'APP_KEY=keep\nORBIT_SWAMP_READ_TOKEN_NAME=orbit-gateway-read-20991121\n'
                         'ORBIT_SWAMP_READ_TOKEN_EXPIRES_AT=2099-12-21T12:00:00.000000Z\n')
        self.assertEqual(oct(self.env.stat().st_mode & 0o777), '0o600')
        backups = list(self.folder.glob('gateway.env.before-swamp-*'))
        self.assertEqual(len(backups), 1)
        self.assertIn('fake-secret-old', backups[0].read_text())
        self.assertNotIn('fake-secret', result.stdout + result.stderr)

    def test_a_wrong_kind_a_near_expiry_or_a_foreign_name_is_refused_and_the_env_kept(self):
        before = self.env.read_text()
        for kind, name, expires in [('run', 'orbit-gateway-read-20991121', '2099-12-21T12:00:00Z'),
                                    ('read', 'orbit-gateway-read-20991121', '2000-01-01T00:00:00Z'),
                                    ('read', 'serve-driver-20991121', '2099-12-21T12:00:00Z')]:
            result = self.install(kind, json.dumps({'name': name, 'token': name + '.fake-secret-new', 'expiresAt': expires}))
            self.assertNotEqual(result.returncode, 0)
            self.assertIn('gateway.swamp_credentials_install_failed', result.stderr)
            self.assertNotIn('fake-secret', result.stdout + result.stderr)
        self.assertEqual(self.env.read_text(), before)
        self.assertEqual(self.install('read', 'not json fake-secret-x').returncode, 1)


if __name__ == '__main__':
    unittest.main()
