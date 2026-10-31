"""Frozen observer distribution; effects are confined to owned scratch."""
import hashlib
import io
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

PROGRAM = Path(__file__).with_name('release_program.ts').read_text().split('.raw`', 1)[1].rsplit('`;', 1)[0]


class ObserverPublication(unittest.TestCase):
    def setUp(self):
        self.scope = {'__name__': 'fixture'}
        exec(compile(PROGRAM, 'observer_publication', 'exec'), self.scope)
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.scope['BIN'] = str(self.root)
        self.scope['owned'] = lambda *a: None
        self.bytes = b'\x7fELFfrozen-observer'
        self.pin = {'url': 'https://example.invalid/agent', 'sha256': hashlib.sha256(self.bytes).hexdigest(), 'version': '0.5.0'}
        self.calls = []
        def service(*args):
            self.calls.append(args)
            return type('Reply', (), {'returncode': 0, 'stdout': b''})()
        self.scope['service'] = service
        self.scope['observer_digest'] = lambda: self.pin['sha256']

    def download(self):
        return patch.object(self.scope['urllib'].request, 'build_opener', return_value=type('Download', (), {'open': lambda *a, **k: io.BytesIO(self.bytes)})())

    def test_current_pin_needs_no_download_or_restart(self):
        (self.root / 'orbit-agent').write_bytes(self.bytes)
        self.scope['download'] = lambda *a: self.fail('downloaded current pin')
        self.assertEqual(self.scope['install_agent']({'agent': self.pin}), {'changed': False, 'version': '0.5.0'})
        self.assertEqual(self.calls, [('is-active', '--quiet', 'orbit-agent.service')])

    def test_verified_candidate_replaces_binary_and_preserves_previous(self):
        (self.root / 'orbit-agent').write_bytes(b'previous')
        with self.download(), patch.object(self.scope['os'], 'chown'):
            self.scope['install_agent']({'agent': self.pin})
        self.assertEqual((self.root / 'orbit-agent').read_bytes(), self.bytes)
        self.assertEqual((self.root / 'orbit-agent.previous').read_bytes(), b'previous')

    def test_bad_digest_preserves_binary_and_never_restarts(self):
        (self.root / 'orbit-agent').write_bytes(b'previous')
        self.pin['sha256'] = '0' * 64
        with self.download(), self.assertRaisesRegex(self.scope['Refusal'], 'agent.checksum_mismatch'):
            self.scope['install_agent']({'agent': self.pin})
        self.assertEqual((self.root / 'orbit-agent').read_bytes(), b'previous')
        self.assertEqual(self.calls, [])

    def test_service_restart_requires_live_digest_afterward(self):
        (self.root / 'orbit-agent').write_bytes(self.bytes)
        self.scope['observer_digest'] = lambda: None
        with self.assertRaisesRegex(self.scope['Refusal'], 'agent.observer_unhealthy'):
            self.scope['install_agent']({'agent': self.pin})
        self.assertEqual(self.calls, [('restart', 'orbit-agent.service')])


if __name__ == '__main__':
    unittest.main()
