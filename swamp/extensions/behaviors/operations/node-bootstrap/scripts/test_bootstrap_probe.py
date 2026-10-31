"""Bootstrap script behavior and private argv boundary, without host effects."""
import base64
import hashlib
import json
import os
import stat
import subprocess
import unittest
from pathlib import Path
from unittest.mock import patch

import probe_host
import scan_host_key
from ssh_common import Refused


class BootstrapProbeTest(unittest.TestCase):
    def setUp(self):
        self.public = base64.b64encode(b'public-host-key').decode()
        self.fingerprint = 'SHA256:' + base64.b64encode(hashlib.sha256(b'public-host-key').digest()).decode().rstrip('=')
        self.observed = {'host_type': 'ssh-ed25519', 'public_key': self.public,
                         'host_key_fingerprint': self.fingerprint}
        self.admitted = {'node_id': 23, 'platform': 'linux', 'expected_architecture': 'x86_64',
                         'target': {'node_id': 23, 'host': 'dev.example', 'port': 2222,
                                    'user': 'candidate', 'fingerprint': self.fingerprint}}
        self.identity = 'synthetic-private-identity-argv-sentinel'
        self.environment = patch.dict(os.environ, {
            'ORBIT_BOOTSTRAP_INTENT': json.dumps(self.admitted),
            'ORBIT_BOOTSTRAP_HOST_KEY': json.dumps(self.observed),
            'ORBIT_BOOTSTRAP_IDENTITY': self.identity,
        })
        self.environment.start()
        self.addCleanup(self.environment.stop)

    def test_scan_requires_the_admitted_fingerprint(self):
        reply = subprocess.CompletedProcess([], 0, f'dev.example ssh-ed25519 {self.public}\n'.encode(), b'')
        with patch.object(scan_host_key.subprocess, 'run', return_value=reply) as invoke:
            self.assertEqual(scan_host_key.scan(), self.observed)
            self.assertEqual(invoke.call_args.args[0], ['/usr/bin/ssh-keyscan', '-T', '10', '-p', '2222', 'dev.example'])
        with (patch.object(scan_host_key.subprocess, 'run', return_value=subprocess.CompletedProcess([], 0, b'dev ssh-ed25519 YWJj\n', b'')),
              self.assertRaisesRegex(Refused, 'node.ssh_host_key_mismatch')):
            scan_host_key.scan()

    def test_probe_pins_ssh_and_removes_the_private_file_and_child_environment(self):
        paths = []
        def command(argv, **kwargs):
            self.assertNotIn(self.identity, '\0'.join(argv))
            self.assertNotIn('ORBIT_BOOTSTRAP_IDENTITY', kwargs['env'])
            private = Path(argv[argv.index('-i')+1])
            paths.append(private)
            self.assertEqual(private.read_text(), self.identity)
            self.assertEqual(stat.S_IMODE(private.stat().st_mode), 0o600)
            self.assertIn('StrictHostKeyChecking=yes', argv)
            self.assertEqual(argv[-2:], ['candidate@dev.example', '/usr/bin/uname -sm'])
            return subprocess.CompletedProcess(argv, 0, b'Linux x86_64\n', b'')
        with patch.object(probe_host.subprocess, 'run', side_effect=command):
            self.assertEqual(probe_host.probe(), {'probed': True, 'architecture': 'x86_64', **self.observed})
        self.assertFalse(paths[0].exists())

    def test_platform_architecture_and_connection_failures_return_bounded_codes(self):
        cases = [(0, b'Darwin arm64', 'node.platform_mismatch'),
                 (0, b'Linux aarch64', 'node.architecture_unsupported'),
                 (0, b'Linux invented', 'node.architecture_unsupported'),
                 (1, self.identity.encode(), 'node.bootstrap_channel_uncertain')]
        for code, stdout, expected in cases:
            with self.subTest(expected=expected):
                os.environ['ORBIT_BOOTSTRAP_IDENTITY'] = self.identity
                reply = subprocess.CompletedProcess([], code, stdout, self.identity.encode())
                with (patch.object(probe_host.subprocess, 'run', return_value=reply),
                      self.assertRaisesRegex(Refused, expected)):
                    probe_host.probe()

    def test_mac_architecture_alias_and_expected_architecture_are_preserved(self):
        self.admitted.update(platform='macos', expected_architecture='arm64')
        os.environ['ORBIT_BOOTSTRAP_INTENT'] = json.dumps(self.admitted)
        reply = subprocess.CompletedProcess([], 0, b'Darwin aarch64\n', b'')
        with patch.object(probe_host.subprocess, 'run', return_value=reply):
            self.assertEqual(probe_host.probe()['architecture'], 'arm64')
        self.admitted['expected_architecture'] = 'x86_64'
        os.environ['ORBIT_BOOTSTRAP_INTENT'] = json.dumps(self.admitted)
        os.environ['ORBIT_BOOTSTRAP_IDENTITY'] = self.identity
        with (patch.object(probe_host.subprocess, 'run', return_value=reply),
              self.assertRaisesRegex(Refused, 'node.architecture_mismatch')):
            probe_host.probe()


if __name__ == '__main__':
    unittest.main()
