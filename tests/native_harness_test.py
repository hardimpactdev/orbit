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
