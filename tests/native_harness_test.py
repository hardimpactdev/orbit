import os
from unittest.mock import patch
from native_harness import NativeTestCase


class NativeHarnessScan(NativeTestCase):
    def test_private_bytes_across_read_boundary_are_detected_without_echo(self):
        self.secrets = ('private-test-sentinel',)
        (self.repo / 'history').write_bytes(b'x' * (1024 * 1024 - 3) + self.secrets[0].encode())
        with self.assertRaises(AssertionError) as failure:
            self.assert_private_absent()
        self.assertNotIn(self.secrets[0], str(failure.exception))

    def test_private_cli_output_is_detected_without_echo(self):
        self.secrets = ('private-test-sentinel',)
        with self.assertRaises(AssertionError) as failure:
            self.assert_private_absent(self.secrets[0])
        self.assertNotIn(self.secrets[0], str(failure.exception))

    def test_inherited_extensions_root_does_not_reach_the_native_process(self):
        probe = self.repo / 'env-probe'
        probe.write_text('#!/usr/bin/env python3\nimport os\nassert "SWAMP_EXTENSIONS_DIR" not in os.environ\nprint("isolated")\n')
        probe.chmod(0o755)
        with patch.dict(os.environ, {'SWAMP_BIN': str(probe),
                                    'SWAMP_EXTENSIONS_DIR': '/inherited/extensions'}):
            self.assertEqual(self.cli().stdout.strip(), 'isolated')
