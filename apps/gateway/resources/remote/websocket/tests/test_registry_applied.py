import io
from pathlib import Path
import ssl
import unittest
from unittest.mock import Mock, patch
import urllib.error
import urllib.request


class RegistryProbeTest(unittest.TestCase):
    def setUp(self):
        self.program = {}
        source = Path(__file__).parents[1] / 'registry-applied.py'
        exec(compile(source.read_text(), str(source), 'exec'),
             {'run_program': lambda main: self.program.update(main=main)}, self.program)
        # Functions retain their globals, including imported dependencies.
        self.main = self.program['main']
        self.main.__globals__.update(self.program)
        self.identity = '56818f31-84e7-415a-ae29-b283e9903a99'

    def probe(self, states):
        opener = Mock()
        opener.open.side_effect = [io.BytesIO((' {"operation":{"state":"%s"}}' % state).encode()) for state in states]
        with patch('ssl.create_default_context') as trust, \
             patch('urllib.request.build_opener', return_value=opener) as build, \
             patch('time.monotonic', side_effect=[0] + [1] * len(states) + [301]), \
             patch('time.sleep'):
            result = self.main({'registry_operation_id': self.identity})
        trust.assert_called_once_with(cafile='/usr/local/share/ca-certificates/orbit-managed-root-ca.crt')
        self.assertIsInstance(build.call_args.args[0], urllib.request.HTTPSHandler)
        refusal = build.call_args.args[1]
        self.assertIsNone(refusal.redirect_request(None, None, 302, '', {}, 'https://foreign.test'))
        for call in opener.open.call_args_list:
            self.assertEqual(call.args[0].full_url, 'https://gateway.orbit/api/v1/websocket-operations/' + self.identity)
            self.assertEqual(call.kwargs['timeout'], 10)
        return result

    def test_waits_for_current_reports(self):
        self.assertEqual(self.probe(['pending', 'applied']), ('succeeded', {'backend': 'applied'}))

    def test_superseded_refuses_success(self):
        self.assertEqual(self.probe(['superseded']), ('superseded', {'error_code': 'websocket.registry_superseded'}))

    def test_missing_reports_remain_pending(self):
        self.assertEqual(self.probe(['pending']), ('pending', {'backend': 'pending'}))

    def test_transport_failure_proves_nothing(self):
        opener = Mock()
        opener.open.side_effect = urllib.error.URLError('unavailable')
        with patch('ssl.create_default_context'), patch('urllib.request.build_opener', return_value=opener), \
             patch('time.monotonic', side_effect=[0, 1, 301]), patch('time.sleep'):
            self.assertEqual(self.main({'registry_operation_id': self.identity}), ('pending', {'backend': 'pending'}))

    def test_missing_ca_is_pending_without_opening_a_connection(self):
        with patch('ssl.create_default_context', side_effect=FileNotFoundError), \
             patch('urllib.request.build_opener') as build:
            self.assertEqual(self.main({'registry_operation_id': self.identity}), ('pending', {'backend': 'pending'}))
            build.assert_not_called()

    def test_malformed_identity_never_connects(self):
        with patch('urllib.request.build_opener') as build:
            for value in [None, 42, '../pairings', 'https://foreign.test']:
                self.assertEqual(self.main({'registry_operation_id': value}), ('failed', {'error_code': 'websocket.registry_invalid'}))
            build.assert_not_called()


if __name__ == '__main__':
    unittest.main()
