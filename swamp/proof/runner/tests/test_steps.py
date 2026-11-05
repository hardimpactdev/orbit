"""Each step against the fake fleet: what it does, and what makes it report a failure."""
import sys
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
sys.path.insert(0, str(Path(__file__).resolve().parent))
import runner
import spec
import steps
from fakes import BRIDGE, FakeFleet


class StepTest(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory()
        self.addCleanup(self.directory.cleanup)
        self.fleet = FakeFleet()
        target = spec.Spec('firewall:allow', 'firewall/allow', 'app-dev', ['gateway', 'app-prod'], ['ufw', 'firewall_records', 'operations', 'listeners'], [])
        self.run = runner.Run(self.fleet, target, self.directory.name)
        self.run.sleep = lambda seconds: None

    def blocked_port(self):
        """What the blocked-port fixture builds: listeners, a test deny above proof-network."""
        self.assertEqual(steps.listener(self.run, {'on': 'app-dev', 'proto': ['tcp'], 'family': 4, 'ports': ['port', 'control']}), [])
        port = self.run.names['port']
        self.assertEqual(steps.ufw_rule(self.run, {'as': 'deny', 'on': 'app-dev', 'action': 'deny', 'proto': 'tcp', 'port': port, 'above': 'proof-network'}), [])
        return port

    def test_listener_binds_two_different_free_ports(self):
        self.blocked_port()
        self.assertNotEqual(self.run.names['port'], self.run.names['control'])
        self.assertEqual(len(self.fleet.listeners['app-dev']), 2)

    def test_listener_refuses_what_is_not_built(self):
        for args in ({'proto': ['udp']}, {'family': 6}, {'block': {'as': 'range', 'size': 5}}):
            with self.assertRaises(steps.NotBuilt):
                steps.listener(self.run, {'on': 'app-dev', 'ports': ['port'], **args})

    def test_ufw_rule_inserts_above_the_anchor_and_binds_the_rule(self):
        port = self.blocked_port()
        rows = self.fleet.ufw['app-dev']
        self.assertEqual(rows[1], (f'{port}/tcp', 'DENY', 'Anywhere', 'proof:deny'))
        self.assertEqual(rows[2][3], 'proof-network')
        self.assertEqual(self.run.rules['deny']['comment'], 'proof:deny')

    def test_ufw_rule_below_a_bound_rule(self):
        port = self.blocked_port()
        self.assertEqual(steps.ufw_rule(self.run, {'as': 'late', 'on': 'app-dev', 'action': 'allow', 'proto': 'tcp', 'port': port, 'from': BRIDGE['gateway'], 'below': 'deny'}), [])
        self.assertEqual([row[3] for row in self.fleet.ufw['app-dev'][1:3]], ['proof:deny', 'proof:late'])

    def test_ufw_rule_without_its_anchor_is_unrun(self):
        with self.assertRaises(steps.Unrun):
            steps.ufw_rule(self.run, {'on': 'app-dev', 'action': 'deny', 'proto': 'tcp', 'port': '1', 'above': 'nothing'})

    def test_probe_connects_and_blocks_by_the_table(self):
        port = self.blocked_port()
        control = self.run.names['control']

        def probe(source, number, expect):
            return steps.probe(self.run, {'from': source, 'to': 'app-dev', 'port': number, 'expect': expect})

        self.assertEqual(probe('gateway', control, 'connect'), [])
        self.assertEqual(probe('gateway', port, 'blocked'), [])
        self.assertEqual(probe('gateway', port, 'connect'), [f'probe: gateway to {port}/tcp blocked, expected connect'])
        self.assertEqual(steps.probe(self.run, {'id': 'x', 'from': 'gateway', 'to': 'app-dev', 'port': '1', 'expect': 'connect'}), ['probe x: gateway to 1/tcp refused, expected connect'])

    def test_probe_refuses_udp_and_ipv6(self):
        for extra in ({'proto': 'udp'}, {'family': 6}):
            with self.assertRaises(steps.NotBuilt):
                steps.probe(self.run, {'from': 'gateway', 'to': 'app-dev', 'port': '1', 'expect': 'connect', **extra})

    def test_cli_checks_exit_status_and_code(self):
        self.fleet.allow_status = 'failed'
        found = steps.cli(self.run, {'run': ['firewall:allow', '1', '--from=x'], 'expect': {'exit': 0, 'status': 'succeeded'}})
        self.assertEqual(found, ['cli firewall:allow: exit 1, expected 0', 'cli firewall:allow: status failed, expected succeeded'])
        self.assertEqual(steps.cli(self.run, {'run': ['node:list'], 'expect': {'exit': 0}}), [])
        self.assertEqual(steps.cli(self.run, {'run': ['node:list'], 'expect': {'code': 'x.y'}}), ['cli node:list: code None, expected x.y'])

    def test_cli_writes_the_output_to_the_evidence(self):
        steps.cli(self.run, {'run': ['node:list']})
        self.assertIn('nodes', (Path(self.directory.name) / 'cli-1.json').read_text())

    def test_cli_refuses_what_is_not_built(self):
        for args in ({'forms': ['json', 'human']}, {'tty': []}, {'expect': {'request': 'none'}}, {'expect': {'skipped': 'a.b'}}):
            with self.assertRaises(steps.NotBuilt):
                steps.cli(self.run, {'run': ['node:list'], **args})

    def test_record_counts_matching_rows(self):
        self.fleet.records.append({'port': '80', 'action': 'allow', 'status': 'active'})
        args = {'list': 'firewall:list', 'node': 'app-dev', 'where': {'port': '80', 'action': 'allow'}, 'count': 1}
        self.assertEqual(steps.record(self.run, args), [])
        self.assertEqual(len(steps.record(self.run, {**args, 'count': 2})), 1)
        with self.assertRaises(steps.NotBuilt):
            steps.record(self.run, {**args, 'list': 'process:list'})

    def test_ufw_rows_in_order_once_each_and_absent(self):
        port = self.blocked_port()
        self.fleet.ufw['app-dev'].insert(1, (f'{port}/tcp', 'ALLOW', BRIDGE['gateway'], 'orbit'))
        allow = {'to': f'{port}/tcp', 'action': 'ALLOW', 'from': BRIDGE['gateway']}
        self.assertEqual(steps.ufw(self.run, {'on': 'app-dev', 'family': 4, 'rows': [allow, {'ref': 'deny'}]}), [])
        out_of_order = steps.ufw(self.run, {'on': 'app-dev', 'rows': [{'ref': 'deny'}, allow]})
        self.assertIn('out of order', out_of_order[0])
        self.assertIn('is present', steps.ufw(self.run, {'on': 'app-dev', 'rows': [], 'absent': [allow]})[0])
        self.assertIn('appears 0 times', steps.ufw(self.run, {'on': 'app-dev', 'rows': [{**allow, 'from': '1.1.1.1'}]})[0])
        self.fleet.ufw['app-dev'].append(self.fleet.ufw['app-dev'][1])
        self.assertIn('appears 2 times', steps.ufw(self.run, {'on': 'app-dev', 'rows': [allow]})[0])

    def test_ufw_ref_of_an_unbound_rule_is_infrastructure(self):
        with self.assertRaises(steps.Infra):
            steps.ufw(self.run, {'on': 'app-dev', 'rows': [{'ref': 'nothing'}]})

    def test_unchanged_sees_a_new_row_unless_it_is_for_the_excepted_port(self):
        port = self.blocked_port()
        steps.take_baseline(self.run, [spec.Step('unchanged', {'since': 'act'})])
        self.assertEqual(set(self.run.baseline), {'ufw', 'firewall_records', 'listeners'})
        self.fleet.ufw['app-dev'].insert(0, (f'{port}/tcp', 'ALLOW', BRIDGE['gateway'], 'orbit'))
        self.assertEqual(steps.unchanged(self.run, {'since': 'act', 'except': {'port': port}}), [])
        self.fleet.ufw['app-dev'].insert(0, ('9999/tcp', 'ALLOW', 'Anywhere', 'stray'))
        found = steps.unchanged(self.run, {'since': 'act', 'except': {'port': port}})
        self.assertEqual(len(found), 1)
        self.assertIn('unchanged ufw', found[0])

    def test_unchanged_compares_records_listeners_and_named_operations(self):
        steps.take_baseline(self.run, [spec.Step('unchanged', {'since': 'act', 'what': ['firewall_records', 'listeners', 'operations']})])
        self.fleet.records.append({'port': '5', 'status': 'active'})
        self.fleet.listeners['app-dev'].add(5000)
        self.fleet.next_id = 3
        found = steps.unchanged(self.run, {'since': 'act', 'what': ['firewall_records', 'listeners', 'operations']})
        self.assertEqual(len(found), 3)

    def test_unchanged_rejects_an_unknown_provider(self):
        with self.assertRaises(steps.Infra):
            steps.take_baseline(self.run, [spec.Step('unchanged', {'since': 'act', 'what': ['weather']})])

    def test_doctor_waits_until_after_the_next_report_and_checks_drift(self):
        waits = []
        self.run.sleep = waits.append
        self.run.acted = steps.time.time()
        self.assertEqual(steps.doctor(self.run, {'node': 'app-dev', 'drift': 0}), [])
        self.assertTrue(60 < waits[0] <= steps.REPORT_SECONDS)
        self.fleet.records.append({'port': '7', 'protocol': 'tcp', 'source': 'x'})
        self.assertEqual(steps.doctor(self.run, {'node': 'app-dev', 'drift': 0}), ['doctor app-dev: exit 1, drift 1, expected 0'])

    def test_ufw_delete_removes_the_matching_rows_only(self):
        port = self.blocked_port()
        self.fleet.ufw['app-dev'].insert(0, (f'{port}/tcp', 'ALLOW', BRIDGE['gateway'], 'orbit'))
        steps.ufw_delete(self.run, {'on': 'app-dev', 'to': f'{port}/tcp', 'from': BRIDGE['gateway']})
        self.assertEqual([row[1] for row in self.fleet.ufw['app-dev'] if row[0] == f'{port}/tcp'], ['DENY'])
        with self.assertRaises(steps.Infra):
            steps.ufw_delete(self.run, {'on': 'app-dev', 'to': 'nothing'})

    def test_steps_that_are_not_built_say_so(self):
        for name in ('fault', 'busy_node', 'ipv6_addresses'):
            with self.assertRaises(steps.NotBuilt):
                steps.FUNCTIONS[name](self.run, {})

    def test_placeholders_resolve_from_names_nodes_and_routes(self):
        self.run.names['port'] = '47001'
        self.assertEqual(self.run.resolve('--node=${app-dev.id}'), '--node=211')
        self.assertEqual(self.run.resolve({'from': '${gateway.src}', 'to': '${port}/tcp'}), {'from': BRIDGE['gateway'], 'to': '47001/tcp'})
        with self.assertRaises(steps.NotBuilt):
            self.run.resolve('${gateway.src6}')
        with self.assertRaises(steps.Infra):
            self.run.resolve('${nothing}')


if __name__ == '__main__':
    with patch.object(runner.Run, 'sleep', lambda self, seconds: None):
        unittest.main()
