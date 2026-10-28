import hashlib
import importlib.util
from pathlib import Path
import sys
import tempfile
import unittest

spec = importlib.util.spec_from_file_location('route_dns', sys.argv.pop(1))
dns = importlib.util.module_from_spec(spec)
spec.loader.exec_module(dns)


class Publication(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix='orbit-route-dns-test-')
        self.addCleanup(self.temporary.cleanup)
        root = Path(self.temporary.name)
        dns.RECORDS_DIRECTORY = root / 'fragments'
        dns.RECORDS_DIRECTORY.mkdir()
        dns.RECORDS = dns.RECORDS_DIRECTORY / 'orbit-records.conf'
        dns.CONFIGURATION = root / 'dnsmasq.conf'
        dns.CONFIGURATION.write_text('conf-dir=' + str(dns.RECORDS_DIRECTORY) + '\n')
        dns.CATALOG = root / 'catalog.json'
        dns.RECORDS.write_text('old records\n')
        dns.CATALOG.write_text('{"old":true}\n')
        dns.RECORDS.chmod(0o640)
        self.units = {'dnsmasq': True, dns.LISTENER: True, dns.SOCKET: True}
        self.calls = []
        self.fail = None
        dns.command = self.command
        dns.Path(str(dns.CATALOG) + '.loaded').write_text(hashlib.sha256(dns.CATALOG.read_bytes()).hexdigest())
        self.payload = {'records': 'host-record=private.test,10.44.0.3\n', 'catalog': '{"requesters":{}}\n'}

    def command(self, *args):
        self.calls.append(args)
        if args[0] == 'dnsmasq':
            candidate = Path(args[2].removeprefix('--conf-file='))
            self.assertIn('fragments', candidate.read_text())
            return self.fail != 'validate'
        self.assertEqual(args[0], 'systemctl')
        if args[1] == 'is-active':
            return self.units.get(args[-1], False)
        action, unit = args[1:]
        if self.fail == unit:
            self.fail = None
            return False
        self.units[unit] = action == 'restart'
        if action == 'restart' and unit == dns.LISTENER:
            Path(str(dns.CATALOG) + '.loaded').write_text(hashlib.sha256(dns.CATALOG.read_bytes()).hexdigest())
        return True

    def test_validated_records_and_catalog_are_published_and_acknowledged(self):
        outcome, result = dns.publish(self.payload)
        self.assertEqual(outcome, 'published')
        self.assertTrue(result['ready'])
        self.assertEqual(dns.RECORDS.read_text(), self.payload['records'])
        self.assertEqual(dns.CATALOG.read_text(), self.payload['catalog'])
        self.assertEqual(result['digest'], hashlib.sha256(dns.CATALOG.read_bytes()).hexdigest())
        self.assertNotIn(('systemctl', 'stop', dns.SOCKET), self.calls)

    def test_validation_failure_preserves_live_records_catalog_and_modes(self):
        self.fail = 'validate'
        self.assertEqual(dns.publish(self.payload), ('refused', {'error_code': 'private_dns.validation_failed'}))
        self.assertEqual(dns.RECORDS.read_text(), 'old records\n')
        self.assertEqual(dns.RECORDS.stat().st_mode & 0o777, 0o640)
        self.assertEqual(dns.CATALOG.read_text(), '{"old":true}\n')
        self.assertFalse(any(c[0] == 'systemctl' and c[1] == 'restart' for c in self.calls))

    def test_failed_backend_restart_restores_previous_data_and_service_state(self):
        self.fail = 'dnsmasq'
        self.assertEqual(dns.publish(self.payload), ('refused', {'error_code': 'private_dns.activation_failed'}))
        self.assertEqual(dns.RECORDS.read_text(), 'old records\n')
        self.assertEqual(dns.RECORDS.stat().st_mode & 0o777, 0o640)
        self.assertTrue(all(self.units.values()))

    def test_failed_listener_restart_restores_both_files_and_loaded_catalog(self):
        self.fail = dns.LISTENER
        self.assertEqual(dns.publish(self.payload), ('refused', {'error_code': 'private_dns.activation_failed'}))
        self.assertEqual(dns.CATALOG.read_text(), '{"old":true}\n')
        self.assertEqual(Path(str(dns.CATALOG) + '.loaded').read_text(), hashlib.sha256(dns.CATALOG.read_bytes()).hexdigest())
        self.assertTrue(all(self.units.values()))

    def test_absent_listener_socket_refuses_before_writing_anything(self):
        self.units[dns.SOCKET] = False
        self.assertEqual(dns.publish(self.payload), ('refused', {'error_code': 'private_dns.listener_not_ready'}))
        self.assertEqual(dns.RECORDS.read_text(), 'old records\n')

    def test_unchanged_healthy_publication_reuses_running_services(self):
        self.payload = {'records': dns.RECORDS.read_text(), 'catalog': dns.CATALOG.read_text()}
        self.assertEqual(dns.publish(self.payload)[0], 'published')
        self.assertFalse(any(c[0] == 'systemctl' and c[1] == 'restart' for c in self.calls))

    def test_new_catalog_with_unchanged_records_restarts_only_listener(self):
        self.payload['records'] = dns.RECORDS.read_text()
        self.assertEqual(dns.publish(self.payload)[0], 'published')
        self.assertNotIn(('systemctl', 'restart', 'dnsmasq'), self.calls)
        self.assertIn(('systemctl', 'restart', dns.LISTENER), self.calls)

    def test_managed_symlink_refuses_without_following_it(self):
        target = dns.RECORDS_DIRECTORY / 'foreign.conf'
        target.write_text('foreign\n')
        dns.RECORDS.unlink()
        dns.RECORDS.symlink_to(target)
        with self.assertRaises(ValueError):
            dns.publish(self.payload)
        self.assertEqual(target.read_text(), 'foreign\n')


unittest.main()
