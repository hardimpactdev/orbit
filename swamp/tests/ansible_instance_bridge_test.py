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


def intent(stamp, *hosts):
    return {'network': 'orbitinst0', 'cidr': '10.253.0.0/24', 'admitted_at': 1800000000000000 + stamp,
            'endpoints': [{'host': host, 'port': 3306, 'docker': False} for host in hosts], 'baseline': {}}


class Incus:
    def __init__(self):
        self.current = {'description': 'boundary', 'config': {}, 'egress': [], 'ingress': []}
        self.version = 0
        self.before_put = None

    def api(self, name, value=None, etag=None):
        if value is not None:
            if self.before_put is not None:
                callback, self.before_put = self.before_put, None
                callback()
            if etag != str(self.version):
                raise bridge.PolicyError('412 Precondition Failed')
            self.current.update(value)
            self.version += 1
        return json.loads(json.dumps(self.current)), str(self.version)


class BridgePolicy(unittest.TestCase):
    def test_stale_capture_keeps_later_admission_then_current_capture_prunes_it(self):
        incus = Incus()
        with tempfile.TemporaryDirectory() as directory, patch.object(bridge, 'api', side_effect=incus.api):
            root = Path(directory)
            bridge.apply(root, intent(1, '10.44.0.3'), True)
            captured = intent(2, '10.44.0.3')
            bridge.apply(root, intent(3, '10.44.0.7'))
            b_file = next((root / 'endpoints').glob('10.44.0.7-*.json'))
            bridge.apply(root, captured, True)
            self.assertTrue(b_file.exists())
            self.assertEqual({r['destination'] for r in incus.current['egress']}, {'10.44.0.3/32', '10.44.0.7/32'})
            self.assertEqual(json.loads((root / 'health.json').read_text()), {'error_code': None})
            bridge.apply(root, intent(3, '10.44.0.3'), True)
            self.assertTrue(b_file.exists())  # Equal stamps also survive: removal is strictly older only.
            bridge.apply(root, intent(4, '10.44.0.3'), True)
            self.assertFalse(b_file.exists())
            self.assertEqual([r['destination'] for r in incus.current['egress']], ['10.44.0.3/32'])
            self.assertEqual(bridge.apply(root, None), 0)

    def test_same_tuple_readmission_during_stale_prune_bumps_etag_and_survives_retry(self):
        incus = Incus()
        with tempfile.TemporaryDirectory() as directory, patch.object(bridge, 'api', side_effect=incus.api):
            root = Path(directory)
            bridge.apply(root, intent(1, '10.44.0.7'), True)
            captured = intent(2)
            incus.before_put = lambda: bridge.apply(root, intent(3, '10.44.0.7'))
            with self.assertRaisesRegex(bridge.PolicyError, '412'):
                bridge.apply(root, captured, True)
            self.assertEqual(incus.version, 2)  # Same selectors, but the newer stamp changes the ACL ETag.
            bridge.apply(root, captured, True)
            self.assertEqual(incus.current['egress'], [bridge.endpoint_rule('10.44.0.7', 3306, '10.253.0.0/24', 1800000000000003)])
            self.assertEqual([json.loads(p.read_text())['admitted_at'] for p in (root / 'endpoints').glob('*.json')], [1800000000000003])
            bridge.apply(root, intent(4), True)
            self.assertEqual(incus.current['egress'], [])
            self.assertEqual(list((root / 'endpoints').glob('*.json')), [])

    def test_readmission_after_acl_write_survives_old_record_compaction(self):
        incus = Incus()
        with tempfile.TemporaryDirectory() as directory, patch.object(bridge, 'api', side_effect=incus.api):
            root = Path(directory)
            bridge.apply(root, intent(1, '10.44.0.7'), True)
            write_rules = bridge.write_rules

            def readmit_after_write(name, resolved, config, reconcile):
                changed = write_rules(name, resolved, config, reconcile)
                if reconcile:
                    bridge.apply(root, intent(3, '10.44.0.7'))
                return changed

            with patch.object(bridge, 'write_rules', side_effect=readmit_after_write):
                bridge.apply(root, intent(2), True)
            self.assertEqual([json.loads(p.read_text())['admitted_at'] for p in (root / 'endpoints').glob('*.json')], [1800000000000003])
            self.assertEqual(incus.current['egress'], [bridge.endpoint_rule('10.44.0.7', 3306, '10.253.0.0/24', 1800000000000003)])
            self.assertEqual(bridge.apply(root, None), 0)

    def test_reconcile_compacts_old_records_deduplicates_and_never_deletes_newer_records(self):
        incus = Incus()
        with tempfile.TemporaryDirectory() as directory, patch.object(bridge, 'api', side_effect=incus.api):
            root = Path(directory)
            bridge.apply(root, intent(1, '10.44.0.7'), True)
            bridge.apply(root, intent(2, '10.44.0.7'))
            bridge.apply(root, intent(4, '10.44.0.7'))
            self.assertEqual(len(list((root / 'endpoints').glob('*.json'))), 3)
            bridge.apply(root, intent(3, '10.44.0.7'), True)
            self.assertEqual([json.loads(p.read_text())['admitted_at'] for p in (root / 'endpoints').glob('*.json')], [1800000000000004])
            version = incus.version
            self.assertEqual(bridge.apply(root, None), 0)
            self.assertEqual(incus.version, version)
            bridge.apply(root, intent(5, '10.44.0.7'), True)
            self.assertEqual([json.loads(p.read_text())['admitted_at'] for p in (root / 'endpoints').glob('*.json')], [1800000000000005])

    def test_rechecks_newer_rule_without_a_record_before_removing_and_preserves_unknown_descriptions(self):
        incus = Incus()
        old = bridge.endpoint_rule('10.44.0.7', 3306, '10.253.0.0/24', 1800000000000001)
        incus.current['egress'] = [old]
        reads = 0

        def api(name, value=None, etag=None):
            nonlocal reads
            if value is None:
                reads += 1
                if reads == 2:
                    incus.current['egress'][0] = {**old, 'description': 'orbit:endpoint:1800000000000003'}
                    incus.version += 1
            return incus.api(name, value, etag)

        with patch.object(bridge, 'api', side_effect=api):
            self.assertEqual(bridge.write_rules('orbitinst0-egress', {}, intent(2), True), 0)
            self.assertEqual(incus.version, 1)
        for description in ['', 'manual', 'orbit:endpoint:1800000000000001x', 'orbit:endpoint:01800000000000001', None]:
            incus.current['egress'] = [{**old, 'description': description}]
            self.assertEqual(bridge.plan(incus.current, {}, '10.253.0.0/24', {}, 1800000000000004), [])

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
                          'admitted_at': 1800000000000000, 'endpoints': [{'host': '10.44.0.7', 'port': 3306, 'docker': True}], 'baseline': {}}
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
                bridge.apply(root, {**intent, 'admitted_at': 1800000000000001}, True)
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
        current = {'egress': [proxy, foreign, bridge.endpoint_rule('172.17.0.2', 3306, '10.253.0.0/24', 1800000000000000)], 'ingress': [ingress]}
        self.assertEqual(bridge.plan(current, {}, '10.253.0.0/24', {'egress': [proxy], 'ingress': [ingress]}, 1800000000000001),
                         [('remove', 'egress', current['egress'][-1])])
        self.assertEqual(bridge.plan(current, {}, '10.253.0.0/24', {}), [])


if __name__ == '__main__':
    unittest.main()
