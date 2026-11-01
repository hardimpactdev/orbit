"""DNS candidate validation and compensation without host commands."""
import hashlib
import importlib.util
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

spec = importlib.util.spec_from_file_location('footprint', Path(__file__).parent / 'scripts/footprint.py')
policy = importlib.util.module_from_spec(spec)
spec.loader.exec_module(policy)


class DnsPublication(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.records = self.path('/etc/dnsmasq.d/orbit-records.conf')
        self.catalog = self.path('/var/lib/orbit/private-dns/catalog.json')
        self.socket = self.path('/etc/systemd/system/orbit-private-dns.socket')
        for p in (self.records, self.catalog, self.socket):
            p.parent.mkdir(parents=True, exist_ok=True)
        self.records.write_text('old records')
        self.catalog.write_text('{"old":true}')
        self.socket.write_text('ListenDatagram=10.44.0.9:53\n')
        self.path('/etc/dnsmasq.conf').write_text('conf-dir=/etc/dnsmasq.d\n')
        self.payload = {'records':'new records', 'catalog':'{"new":true}'}
        self.addCleanup(patch.stopall)
        patch.object(policy, 'Path', side_effect=self.path).start()
        patch.object(policy, 'SOCKET', self.socket).start()
        patch.object(policy, 'UID', __import__('os').getuid()).start()
        patch.object(policy, 'GID', __import__('os').getgid()).start()

    def path(self, value):
        text = str(value)
        return self.root / text.lstrip('/') if text.startswith(('/etc/', '/var/lib/')) else Path(value)

    def test_refused_candidate_never_changes_live_data(self):
        with patch.object(policy, 'command', return_value=False):
            with self.assertRaises(policy.Invalid):
                policy.dns_validate(self.payload)
        self.assertEqual(self.records.read_text(), 'old records')
        self.assertEqual(self.catalog.read_text(), '{"old":true}')

    def test_validated_snapshot_restores_exact_prior_data(self):
        with patch.object(policy, 'command', return_value=True):
            before = policy.dns_validate(self.payload)['before']
        self.records.write_text('new records')
        self.catalog.write_text('{"new":true}')
        policy.dns_restore(before)
        self.assertEqual(self.records.read_text(), 'old records')
        self.assertEqual(self.catalog.read_text(), '{"old":true}')
        self.assertEqual(before['commands'][0], ['/usr/bin/systemctl', 'restart', 'dnsmasq.service'])

    def test_answer_alone_cannot_prove_a_different_loaded_catalog(self):
        class Client:
            def __enter__(self): return self
            def __exit__(self, *args): pass
            def settimeout(self, value): pass
            def connect(self, value): pass
            def send(self, query): self.query = query
            def recv(self, size): return self.query[:2] + b'\x80' + b'\x00' * 9
        loaded = Path(str(self.catalog) + '.loaded')
        with patch.object(policy.socket, 'socket', return_value=Client()):
            loaded.write_text('stale catalog')
            with self.assertRaises(policy.Invalid):
                policy.dns_health(self.payload)
            loaded.write_text(hashlib.sha256(self.payload['catalog'].encode()).hexdigest())
            self.assertTrue(policy.dns_health(self.payload)['ready'])


if __name__ == '__main__':
    unittest.main()
