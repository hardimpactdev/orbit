"""Exact DNAT resolution and shared boot/attach/converge policy, without Docker or Incus mutations."""
import importlib.util
import json
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

spec = importlib.util.spec_from_file_location('bridge', Path(__file__).parents[1] / 'ansible/instance_bridge.py')
bridge = importlib.util.module_from_spec(spec)
spec.loader.exec_module(bridge)


def nat(address='172.17.0.2'):
    return f'-A DOCKER -d 10.44.0.7/32 ! -i docker0 -p tcp -m tcp --dport 3306 -j DNAT --to-destination {address}:3306\n'


class BridgePolicy(unittest.TestCase):
    def test_only_one_exact_published_mapping_is_accepted(self):
        endpoint = {'host': '10.44.0.7', 'port': 3306, 'docker': True}
        self.assertEqual(bridge.resolve([endpoint], nat()), [('172.17.0.2', 3306)])
        for table in ['', nat() + nat('172.17.0.3'), nat().replace('10.44.0.7', '10.44.0.8')]:
            with self.assertRaisesRegex(bridge.PolicyError, 'instance.container_endpoint_unresolved'):
                bridge.resolve([endpoint], table)
        self.assertEqual(bridge.resolve([{**endpoint, 'docker': False}], ''), [('10.44.0.7', 3306)])

    def test_address_change_adds_new_tuple_until_host_reconcile_removes_the_old(self):
        current = {'description': 'boundary', 'config': {}, 'egress': [], 'ingress': []}
        calls = []

        def api(name, value=None, etag=None):
            if value is not None:
                self.assertEqual(etag, 'version')
                current.update(value)
                calls.append(value)
            return json.loads(json.dumps(current)), 'version'

        with tempfile.TemporaryDirectory() as directory, patch.object(bridge, 'api', side_effect=api):
            root = Path(directory)
            intent = {'network': 'orbitinst0', 'cidr': '10.253.0.0/24',
                          'endpoints': [{'host': '10.44.0.7', 'port': 3306, 'docker': True}], 'baseline': {}}
            with patch.object(bridge.subprocess, 'check_output', return_value=nat()):
                bridge.apply(root, intent, True)
                before = {str(p): p.stat().st_mtime_ns for p in root.rglob('*.json')}
                calls.clear()
                self.assertEqual(bridge.apply(root, None), 0)
                self.assertEqual(calls, [])  # Timer no-op calls no Incus write and writes no files.
                self.assertEqual(before, {str(p): p.stat().st_mtime_ns for p in root.rglob('*.json')})
            with patch.object(bridge.subprocess, 'check_output', return_value=nat('172.17.0.9')):
                bridge.apply(root, None)
                self.assertEqual([r['destination'] for r in current['egress']], ['172.17.0.2/32', '172.17.0.9/32'])
                bridge.apply(root, intent, True)
                self.assertEqual([r['destination'] for r in current['egress']], ['172.17.0.9/32'])
            # A successful subset attach cannot clear the refresh unit's failure for another endpoint.
            bridge.write_file(root / 'health.json', {'error_code': 'instance.container_endpoint_unresolved'})
            with patch.object(bridge.subprocess, 'check_output', return_value=nat('172.17.0.9')):
                bridge.apply(root, intent, False)
            self.assertEqual(json.loads((root / 'health.json').read_text())['error_code'], 'instance.container_endpoint_unresolved')
            before = json.loads(json.dumps(current))
            with patch.object(bridge.subprocess, 'check_output', return_value=''):
                with self.assertRaisesRegex(bridge.PolicyError, 'instance.container_endpoint_unresolved'):
                    bridge.apply(root, None)
                self.assertEqual(current, before)

    def test_reconcile_preserves_proxy_ingress_and_rules_outside_its_endpoint_shape(self):
        proxy = {'action': 'allow', 'destination': '10.253.0.1/32', 'protocol': 'tcp', 'destination_port': '3128', 'state': 'enabled'}
        foreign = {'action': 'allow', 'destination': '192.0.2.0/24', 'state': 'enabled'}
        ingress = {'action': 'allow', 'source': '10.253.0.1/32', 'protocol': 'tcp', 'destination_port': '1024-65535', 'state': 'enabled'}
        current = {'egress': [proxy, foreign, bridge.endpoint_rule('172.17.0.2', 3306, '10.253.0.0/24')], 'ingress': [ingress]}
        self.assertEqual(bridge.plan(current, [], '10.253.0.0/24', {'egress': [proxy], 'ingress': [ingress]}, True),
                         [('remove', 'egress', current['egress'][-1])])
        self.assertEqual(bridge.plan(current, [], '10.253.0.0/24', {}, False), [])


if __name__ == '__main__':
    unittest.main()
