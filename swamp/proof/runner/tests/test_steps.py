"""Each step against the fake fleet: what it does, and what makes it report a failure."""
import json
import sys
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
sys.path.insert(0, str(Path(__file__).resolve().parent))
import fleet
import runner
import spec
import steps
from fakes import BRIDGE, BRIDGE6, FakeFleet, completed


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

    def test_listener_starts_udp_and_both_protocols_on_the_same_ports(self):
        self.assertEqual(steps.listener(self.run, {'on': 'app-dev', 'proto': ['tcp', 'udp'], 'ports': ['port']}), [])
        port = int(self.run.names['port'])
        self.assertEqual(self.fleet.listeners['app-dev'], {('tcp', 4, port), ('udp', 4, port)})

    def test_ufw_rule_comment_is_the_text_of_the_row_and_the_default_is_the_name(self):
        self.assertEqual(steps.listener(self.run, {'on': 'app-dev', 'ports': ['port']}), [])
        port = self.run.names['port']
        self.assertEqual(steps.ufw_rule(self.run, {'as': 'drifted', 'on': 'app-dev', 'action': 'allow', 'proto': 'tcp', 'port': port, 'from': '10.98.0.9',
                                                   'comment': 'orbit drift probe', 'above': 'proof-network'}), [])
        self.assertEqual(self.run.rules['drifted']['tcp']['comment'], 'orbit drift probe')
        self.assertIn((f'{port}/tcp', 'ALLOW', '10.98.0.9', 'orbit drift probe'), self.fleet.ufw['app-dev'])
        with self.assertRaises(steps.Infra):
            steps.ufw_rule(self.run, {'on': 'app-dev', 'action': 'allow', 'proto': 'tcp', 'port': port, 'comment': "it's"})

    def test_listener_block_binds_consecutive_ports_and_the_names(self):
        self.assertEqual(steps.listener(self.run, {'on': 'app-dev', 'proto': 'tcp', 'block': {'as': 'range', 'size': 5}}), [])
        low = int(self.run.names['range.lo'])
        self.assertEqual({port for _, _, port in self.fleet.listeners['app-dev']}, set(range(low, low + 5)))
        self.assertEqual((self.run.names['range.mid'], self.run.names['range.hi'], self.run.names['range.spec']), (str(low + 2), str(low + 4), f'{low}:{low + 4}'))

    def test_listener_block_and_ports_never_share_a_port(self):
        self.run.names['port'] = '47500'
        for _ in range(20):
            self.fleet.listeners['app-dev'].clear()
            steps.listener(self.run, {'on': 'app-dev', 'ports': ['outside'], 'block': {'as': 'range', 'size': 5}})
            ports = [port for _, _, port in sorted(self.fleet.listeners['app-dev'])]
            self.assertEqual(len(ports), 6)
            self.assertEqual(len(set(ports)), 6)
            self.assertNotIn(47500, ports)

    def test_listener_over_ipv6_and_what_it_refuses(self):
        self.assertEqual(steps.listener(self.run, {'on': 'app-dev', 'family': 6, 'ports': ['port']}), [])
        self.assertEqual({item[:2] for item in self.fleet.listeners['app-dev']}, {('tcp', 6)})
        for args in ({'family': 5}, {'proto': ['icmp']}, {'block': {'as': 'range', 'size': 1}}, {'block': {'size': 5}}):
            with self.assertRaises(steps.Infra):
                steps.listener(self.run, {'on': 'app-dev', 'ports': ['x'], **args})

    def test_listener_that_does_not_answer_locally_is_a_failure(self):
        self.fleet.sh_override = None
        original = self.fleet.sh

        def broken(node, command, **kwargs):
            if command.startswith('for port in'):
                return completed(out='47001\n')
            return original(node, command, **kwargs)

        self.fleet.sh = broken
        self.assertIn('does not accept tcp connections locally', steps.listener(self.run, {'on': 'app-dev', 'ports': ['port']})[0])

    def test_ufw_rule_inserts_above_the_anchor_and_binds_the_rule(self):
        port = self.blocked_port()
        rows = self.fleet.ufw['app-dev']
        self.assertEqual(rows[1], (f'{port}/tcp', 'DENY', 'Anywhere', 'proof:deny'))
        self.assertEqual(rows[2][3], 'proof-network')
        self.assertEqual(self.run.rules['deny']['tcp']['comment'], 'proof:deny')

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

    def test_probe_udp_connects_and_blocks_by_the_table(self):
        steps.listener(self.run, {'on': 'app-dev', 'proto': ['tcp', 'udp'], 'ports': ['port']})
        port = self.run.names['port']
        self.assertEqual(steps.ufw_rule(self.run, {'as': 'deny', 'on': 'app-dev', 'action': 'deny', 'proto': 'udp', 'port': port, 'above': 'proof-network'}), [])

        def probe(proto, expect):
            return steps.probe(self.run, {'from': 'gateway', 'to': 'app-dev', 'proto': proto, 'port': port, 'expect': expect})

        self.assertEqual(probe('udp', 'blocked'), [])
        self.assertEqual(probe('tcp', 'connect'), [])
        self.assertEqual(probe('udp', 'connect'), [f'probe: gateway to {port}/udp blocked, expected connect'])
        self.fleet.ufw['app-dev'].insert(1, (f'{port}/udp', 'ALLOW', BRIDGE['gateway'], 'orbit'))
        self.assertEqual(probe('udp', 'connect'), [])
        self.assertIn('refused', steps.probe(self.run, {'from': 'gateway', 'to': 'app-dev', 'proto': 'udp', 'port': '1', 'expect': 'connect'})[0])

    def test_probe_over_ipv6_needs_an_address_on_the_target(self):
        steps.listener(self.run, {'on': 'app-dev', 'family': 6, 'ports': ['port']})
        args = {'from': 'gateway', 'to': 'app-dev', 'family': 6, 'port': self.run.names['port'], 'expect': 'connect'}
        with self.assertRaises(steps.Infra):
            steps.probe(self.run, args)
        for name in self.fleet.nodes:
            self.fleet.nodes[name]['bridge6'] = BRIDGE6[name]
        self.assertEqual(steps.probe(self.run, args), [])
        self.assertEqual(steps.probe(self.run, {**args, 'port': '1'}), ["probe: gateway to 1/tcp (v6) refused, expected connect"])

    def test_ufw_rule_with_one_name_for_both_protocols_adds_a_row_for_each(self):
        for proto in ('tcp', 'udp'):
            self.assertEqual(steps.ufw_rule(self.run, {'as': 'deny', 'on': 'app-dev', 'action': 'deny', 'proto': proto, 'port': '47001', 'above': 'proof-network'}), [])
        self.assertEqual([row[0] for row in self.fleet.ufw['app-dev'][1:3]], ['47001/tcp', '47001/udp'])

    def test_ufw_rule_uses_the_bound_anchor_when_an_earlier_case_reused_its_comment(self):
        for port in ('47001', '47002'):
            self.assertEqual(steps.ufw_rule(self.run, {'as': 'deny', 'on': 'app-dev', 'action': 'deny', 'proto': 'tcp', 'port': port, 'above': 'proof-network'}), [])

        self.assertEqual(steps.ufw_rule(self.run, {'as': 'misplaced', 'on': 'app-dev', 'action': 'allow', 'proto': 'tcp', 'port': '47002', 'below': 'deny'}), [])

        rows = self.fleet.ufw['app-dev']
        deny = rows.index(('47002/tcp', 'DENY', 'Anywhere', 'proof:deny'))
        allow = rows.index(('47002/tcp', 'ALLOW', 'Anywhere', 'proof:misplaced'))
        self.assertEqual(allow, deny + 1)

    def test_ufw_rule_refuses_an_anchor_bound_only_for_another_protocol(self):
        self.assertEqual(steps.ufw_rule(self.run, {'as': 'deny', 'on': 'app-dev', 'action': 'deny', 'proto': 'udp', 'port': '47002', 'above': 'proof-network'}), [])
        before = list(self.fleet.ufw['app-dev'])

        with self.assertRaisesRegex(steps.Unrun, 'anchor deny has no tcp rule'):
            steps.ufw_rule(self.run, {'as': 'existing', 'on': 'app-dev', 'action': 'allow', 'proto': 'tcp', 'port': '47002', 'above': 'deny'})

        self.assertEqual(self.fleet.ufw['app-dev'], before)

    def test_ufw_rule_moves_the_ipv6_line_above_the_ipv6_anchor(self):
        self.fleet.dual_stack = True
        self.fleet.ufw['app-dev'].append(('Anywhere (v6)', 'ALLOW', 'fd42:232:2::/64', 'proof-network'))
        self.assertEqual(steps.ufw_rule(self.run, {'as': 'deny', 'on': 'app-dev', 'action': 'deny', 'proto': 'tcp', 'port': '47001', 'above': 'proof-network'}), [])
        self.assertEqual([row[:2] + row[3:] for row in self.fleet.ufw['app-dev']],
                         [('22/tcp', 'ALLOW', 'orbit:ssh'), ('47001/tcp', 'DENY', 'proof:deny'), ('Anywhere', 'ALLOW', 'proof-network'),
                          ('47001/tcp (v6)', 'DENY', 'proof:deny'), ('Anywhere (v6)', 'ALLOW', 'proof-network')])

    def test_ufw_rule_leaves_a_table_without_an_ipv6_anchor_as_it_is(self):
        self.fleet.dual_stack = True
        self.assertEqual(steps.ufw_rule(self.run, {'as': 'deny', 'on': 'app-dev', 'action': 'deny', 'proto': 'tcp', 'port': '47001', 'above': 'proof-network'}), [])
        self.assertEqual(self.fleet.ufw['app-dev'][-1][0], '47001/tcp (v6)')
        self.assertEqual(len(self.fleet.ufw['app-dev']), 4)

    def test_ufw_rule_adds_udp_lines_but_not_ipv6_lines(self):
        steps.listener(self.run, {'on': 'app-dev', 'proto': 'udp', 'ports': ['port']})
        self.assertEqual(steps.ufw_rule(self.run, {'as': 'deny', 'on': 'app-dev', 'action': 'deny', 'proto': 'udp', 'port': self.run.names['port'], 'above': 'proof-network'}), [])
        self.assertEqual(self.fleet.ufw['app-dev'][1][0], self.run.names['port'] + '/udp')
        with self.assertRaises(steps.NotBuilt):
            steps.ufw_rule(self.run, {'on': 'app-dev', 'action': 'deny', 'proto': 'tcp', 'port': '1', 'family': 6})

    def test_cli_checks_exit_status_and_code(self):
        self.fleet.allow_status = 'failed'
        found = steps.cli(self.run, {'run': ['firewall:allow', '1', '--node=211', '--from=x'], 'expect': {'exit': 0, 'status': 'succeeded'}})
        self.assertEqual(found, ['cli firewall:allow: exit 1, expected 0', 'cli firewall:allow: status failed, expected succeeded'])
        self.assertEqual(steps.cli(self.run, {'run': ['node:list'], 'expect': {'exit': 0}}), [])
        self.assertEqual(steps.cli(self.run, {'run': ['node:list'], 'expect': {'code': 'x.y'}}), ['cli node:list: code None, expected x.y'])

    def test_failed_json_operation_names_the_failed_step_even_for_exit_only_setup(self):
        document = {'id': 17, 'status': 'failed', 'failed_step': 'Converge input', 'error_code': 'operation.native_failed',
                    'error': {'detail': 'private-error-sentinel'}}
        result = {'rc': 1, 'json': document}
        for expect in ({'exit': 0}, {'status': 'succeeded'}):
            with self.subTest(expect=expect):
                found = steps.check_result(self.run, 'cli node:converge', 'json', ['json'], result, expect)
                self.assertEqual(len(found), 1)
                self.assertIn("failed_step='Converge input', error_code='operation.native_failed'", found[0])
                self.assertNotIn('private-error-sentinel', found[0])
        self.assertEqual(self.fleet.cli_calls, [])

    def test_missing_step_uses_the_operation_show_diagnostics_in_every_failure(self):
        document = {'id': 17, 'status': 'failed'}
        operation = {**document, 'failed_step': 'Converge input', 'error_code': 'operation.native_failed', 'steps': []}
        with patch.object(self.run, 'cli', return_value={'rc': 0, 'json': operation}) as called:
            found = steps.check_result(self.run, 'cli node:converge', 'json', ['json'], {'rc': 1, 'json': document},
                                       {'exit': 0, 'status': 'succeeded', 'steps': {'name': 'Check the Node', 'count': 1}})
        self.assertEqual(len(found), 3)
        self.assertTrue(all("failed_step='Converge input', error_code='operation.native_failed'" in item for item in found))
        called.assert_called_once_with(['operation:show', '17'])

    def test_human_failure_fetches_operation_diagnostics_with_or_without_a_status_expectation(self):
        operation = {'id': 17, 'status': 'failed', 'failed_step': 'Converge input', 'error_code': 'operation.native_failed'}
        result = {'rc': 1, 'stdout': 'Operation: 17\n', 'stderr': ''}
        for expect in ({'exit': 0}, {'status': 'succeeded'}):
            with self.subTest(expect=expect), patch.object(self.run, 'cli', return_value={'rc': 0, 'json': operation}) as called:
                found = steps.check_result(self.run, 'cli node:converge', 'human', ['human'], result, expect)
                self.assertIn("failed_step='Converge input', error_code='operation.native_failed'", found[0])
                called.assert_called_once_with(['operation:show', '17'])

    def test_node_list_cases_reject_wrong_json_envelopes_and_recorded_rows(self):
        loaded = spec.load(Path(__file__).resolve().parents[2] / 'node' / 'list.cases.yaml',
                           Path(__file__).resolve().parents[2] / 'batches.yaml')
        args = self.run.resolve(loaded.case('recorded-nodes').stages[0].act[1].args)
        self.assertEqual(args['run'], ['node:list', '--json'])
        self.assertEqual(args['forms'], ['human'])
        rows = [
            {'id': self.fleet.nodes[name]['node_id'], 'cluster_id': None, 'name': name, 'status': 'active',
             'roles': [name], 'cluster_name': None,
             'assignments': [{'id': index + 1, 'role': name, 'status': 'active', 'failed_step': None, 'error_code': None}]}
            for index, name in enumerate(('app-dev', 'app-prod', 'gateway'))
        ]
        good = {'nodes': rows, 'request_id': '11111111-1111-4111-8111-111111111111'}

        def check(document):
            def cli(argv, form='json', node=None):
                return {'rc': 0, 'json': {'operations': []},
                        'stdout': json.dumps(document, separators=(',', ':')), 'stderr': ''}
            with patch.object(self.run, 'cli', side_effect=cli) as called:
                failures = steps.cli(self.run, args)
                called.assert_any_call(['node:list', '--json'], 'human', None)
            return failures

        self.assertEqual(check(good), [])
        wrong_rows = [
            [], rows[1:],
            [{**rows[0], 'id': 999}, *rows[1:]],
            [{**rows[0], 'name': 'wrong-node'}, *rows[1:]],
            [{**rows[0], 'status': 'failed'}, *rows[1:]],
            [{**rows[0], 'roles': []}, *rows[1:]],
            [{**rows[0], 'assignments': []}, *rows[1:]],
            [{**rows[0], 'assignments': [{**rows[0]['assignments'][0], 'status': 'failed'}]}, *rows[1:]],
        ]
        documents = [{}, [], {'nodes': {}}, {'nodes': rows}, *({'nodes': value, 'request_id': good['request_id']} for value in wrong_rows)]
        for document in documents:
            with self.subTest(document=document):
                self.assertTrue(check(document), 'A wrong successful JSON answer must fail the case')

    def test_cli_writes_the_output_to_the_evidence(self):
        steps.cli(self.run, {'run': ['node:list']})
        self.assertIn('nodes', (Path(self.directory.name) / 'cli-1.json').read_text())

    def test_cli_runs_the_forms_and_reads_the_code_from_json_only(self):
        found = steps.cli(self.run, {'run': ['firewall:allow', '80'], 'forms': ['human', 'json'], 'expect': {'exit': 1, 'code': 'firewall.node_id_invalid', 'human': 'Node ID must be'}})
        self.assertEqual(found, [])
        self.assertEqual(len(self.fleet.cli_calls), 2)
        wrong = steps.cli(self.run, {'run': ['firewall:allow', '80'], 'forms': ['human', 'json'], 'expect': {'exit': 0, 'code': 'a.b', 'human': 'nothing'}})
        self.assertEqual(wrong, ["cli firewall:allow (human): exit 1, expected 0", "cli firewall:allow (human): the output lacks 'nothing'",
                                 "cli firewall:allow (json): exit 1, expected 0", "cli firewall:allow (json): code firewall.node_id_invalid, expected a.b"])

    def test_cli_human_only_reads_the_code_from_the_text_and_the_status_from_the_operation(self):
        args = {'run': ['firewall:allow', '80', '--node=211', '--from=x'], 'forms': ['human'], 'expect': {'exit': 0, 'status': 'succeeded'}}
        self.assertEqual(steps.cli(self.run, args), [])
        self.fleet.human_status = 'failed'
        self.assertEqual(steps.cli(self.run, args), ['cli firewall:allow: status failed, expected succeeded'])
        none = steps.cli(self.run, {'run': ['firewall:allow', '80'], 'forms': ['human'], 'expect': {'exit': 1, 'status': 'succeeded', 'code': 'firewall.node_id_invalid'}})
        self.assertEqual(none, ['cli firewall:allow: the output lacks the code firewall.node_id_invalid', 'cli firewall:allow: status None, expected succeeded (no Operation in the output)'])

    def test_cli_request_none_compares_the_operations_before_and_after(self):
        refusal = {'run': ['firewall:allow', '80'], 'expect': {'exit': 1, 'request': 'none'}}
        self.assertEqual(steps.cli(self.run, refusal), [])
        created = steps.cli(self.run, {'run': ['firewall:allow', '80', '--node=211', '--from=x'], 'expect': {'exit': 0, 'request': 'none'}})
        self.assertEqual(created, ['cli firewall:allow: created Operation [1], expected no request'])

    @staticmethod
    def operation(number, kind='firewall.allow', target_type='node_firewall', target_id=211):
        return {'id': number, 'kind': kind, 'target_type': target_type, 'target_id': target_id}

    def test_operation_reads_filter_by_the_kind_and_the_placed_node(self):
        self.run.target = 'app-prod'
        self.fleet.operation_rows = [self.operation(1), self.operation(2, target_id=212), self.operation(3, kind='firewall.deny', target_id=212),
                                     self.operation(4, target_type='node', target_id=212)]
        self.assertEqual(steps.operation_ids(self.run), {2})
        self.assertEqual(self.fleet.operation_queries, [('firewall.allow', 'node_firewall', 212)])

    def test_other_nodes_and_kinds_do_not_change_the_operations_baseline(self):
        self.fleet.operation_rows = [self.operation(number) for number in range(1, 51)]
        expect = {'since': 'act', 'what': ['operations']}
        steps.take_baseline(self.run, [spec.Step('unchanged', expect)])
        self.fleet.operation_rows += [self.operation(number, target_id=212) for number in range(51, 111)]
        self.fleet.operation_rows += [self.operation(111, kind='firewall.deny'), self.operation(112, target_type='node')]
        self.assertEqual(steps.unchanged(self.run, expect), [])
        self.fleet.operation_rows.append(self.operation(113))
        self.assertEqual(len(steps.unchanged(self.run, expect)), 1)

    def test_a_created_operation_cannot_hide_behind_fifty_newer_unrelated_rows(self):
        self.fleet.operation_rows = [self.operation(number) for number in range(1, 51)]
        self.fleet.next_id = 51
        cli = self.fleet.cli

        def create_and_obscure(*args, **kwargs):
            result = cli(*args, **kwargs)
            self.fleet.operation_rows += [self.operation(number, target_id=212) for number in range(52, 112)]
            return result

        with patch.object(self.fleet, 'cli', create_and_obscure):
            found = steps.cli(self.run, {'run': ['firewall:allow', '80', '--node=211', '--from=x'], 'expect': {'exit': 0, 'request': 'none'}})
        self.assertEqual(found, ['cli firewall:allow: created Operation [51], expected no request'])

    def test_no_request_ignores_concurrent_operations_on_other_nodes(self):
        cli = self.fleet.cli

        def another_node(*args, **kwargs):
            self.fleet.operation_rows.append(self.operation(1, target_id=212))
            return cli(*args, **kwargs)

        with patch.object(self.fleet, 'cli', another_node):
            self.assertEqual(steps.cli(self.run, {'run': ['firewall:allow', '80'], 'expect': {'exit': 1, 'request': 'none'}}), [])

    def test_failed_or_malformed_operation_reads_do_not_prove_no_request(self):
        for result in ({'rc': 22, 'json': {'data': []}}, {'rc': 0, 'json': None}, {'rc': 0, 'json': {}},
                       {'rc': 0, 'json': {'data': [{'id': '1'}]}}, {'rc': 0, 'json': {'data': [{'id': 0}]}}):
            with self.subTest(result=result), patch.object(self.fleet, 'operations', return_value={**result, 'stdout': '', 'stderr': ''}):
                with self.assertRaises(steps.Infra):
                    steps.cli(self.run, {'run': ['firewall:allow', '80'], 'expect': {'exit': 1, 'request': 'none'}})

    def test_cli_skipped_reads_the_reason_from_the_json_document(self):
        args = {'run': ['firewall:allow', '80', '--node=211', '--from=x'], 'expect': {'exit': 0, 'status': 'succeeded', 'skipped': 'firewall.rule_exists'}}
        self.assertEqual(steps.cli(self.run, args), ['cli firewall:allow: not skipped with firewall.rule_exists'])
        self.fleet.allow_skipped = 'firewall.rule_exists'
        self.assertEqual(steps.cli(self.run, args), [])
        self.assertEqual(list(steps.skip_reasons({'a': [{'skipped': ['x', 'y']}], 'skip_reason': 'z', 'b': 'w'})), ['x', 'y', 'z'])
        with self.assertRaises(steps.Infra):
            steps.cli(self.run, {**args, 'forms': ['human']})

    def try_13_steps(self):
        """The exact Operation step names/statuses supplied from proof 12699, allow child 12700."""
        return [
            {'name': 'Read the rules', 'status': 'succeeded'},
            {'name': 'Plan the changes', 'status': 'succeeded'},
            {'name': 'Change the rules-0-0', 'status': 'succeeded'},
            {'name': 'Change the rules-1-1', 'status': 'skipped'},
            {'name': 'Change the rules-2-2', 'status': 'skipped'},
            {'name': 'Change the rules-3-3', 'status': 'skipped'},
            {'name': 'Change the rules-4-4', 'status': 'skipped'},
            {'name': 'Change the rules-5-5', 'status': 'skipped'},
            {'name': 'Change the rules-6-6', 'status': 'skipped'},
            {'name': 'Change the rules-7-7', 'status': 'skipped'},
            {'name': 'Read the result', 'status': 'succeeded'},
            {'name': 'Check the rules', 'status': 'succeeded'},
            {'name': 'accepted', 'status': 'succeeded'},
        ]

    def test_cli_counts_one_executed_indexed_step_in_the_try_13_operation(self):
        self.fleet.operation_steps = self.try_13_steps()
        args = {'run': ['firewall:allow', '80', '--node=211', '--from=x'],
                'expect': {'exit': 0, 'status': 'succeeded', 'steps': {'name': 'Change the rules', 'count': 1}}}
        self.assertEqual(steps.cli(self.run, args), [])
        self.assertEqual(self.fleet.cli_calls[-1], ['operation:show', '1'])
        self.assertIn('Change the rules-0-0', (Path(self.directory.name) / 'cli-2.json').read_text())
        self.fleet.operation_steps[2]['status'] = 'skipped'
        self.assertEqual(steps.cli(self.run, args), ['cli firewall:allow: ran 0 steps named Change the rules, expected 1'])

    def test_cli_counts_two_executed_indexed_steps_in_the_try_13_operation(self):
        self.fleet.operation_steps = self.try_13_steps()
        self.fleet.operation_steps[3]['status'] = 'succeeded'
        args = {'run': ['firewall:allow', '80', '--node=211', '--from=x'],
                'expect': {'exit': 0, 'status': 'succeeded', 'steps': {'name': 'Change the rules', 'count': 2}}}
        self.assertEqual(steps.cli(self.run, args), [])
        args['expect']['steps']['count'] = 1
        self.assertEqual(steps.cli(self.run, args), ['cli firewall:allow: ran 2 steps named Change the rules, expected 1'])

    def test_cli_step_name_is_exact_or_has_only_a_repeated_numeric_index(self):
        args = {'run': ['firewall:allow', '80', '--node=211', '--from=x'],
                'expect': {'steps': {'name': 'Change the rules', 'count': 1}}}
        self.fleet.operation_steps = [{'name': 'Change the rules', 'status': 'failed'}]
        self.assertEqual(steps.cli(self.run, args), [])
        args['expect']['steps']['count'] = 0
        for name in ('Change the rules-x', 'Change the rules-0', 'Change the rules-0-1',
                     'Change the rules elsewhere-0-0', 'Change the rules-0-0-extra', 'Change the rules-١-١'):
            with self.subTest(name=name):
                self.fleet.operation_steps = [{'name': name, 'status': 'succeeded'}]
                self.assertEqual(steps.cli(self.run, args), [])

    def test_cli_step_count_fails_without_an_operation_or_step_data(self):
        expect = {'steps': {'name': 'Change the rules', 'count': 1}}
        result = {'rc': 0, 'json': {}}
        self.assertEqual(steps.check_result(self.run, 'cli test', 'json', ['json'], result, expect),
                         ['cli test: no Operation ID for steps'])
        for document in ({}, {'steps': None}, {'steps': [{'name': 'Change the rules-0'}]}):
            with self.subTest(document=document), patch.object(self.run, 'cli', return_value={'rc': 0, 'json': document}):
                self.assertEqual(steps.check_result(self.run, 'cli test', 'json', ['json'], {'json': {'id': 12}}, expect),
                                 ['cli test: Operation step data is missing or invalid'])
        with patch.object(self.run, 'cli', return_value={'rc': 1, 'json': {'steps': []}}):
            self.assertEqual(steps.check_result(self.run, 'cli test', 'json', ['json'], {'json': {'id': 12}}, expect),
                             ['cli test: could not read Operation steps'])

    def test_cli_step_count_requires_json_and_a_name_and_nonnegative_integer_count(self):
        base = {'run': ['node:list'], 'expect': {'steps': {'name': 'Change the rules', 'count': 1}}}
        with self.assertRaises(steps.Infra):
            steps.cli(self.run, {**base, 'forms': ['human']})
        for wanted in (None, {}, {'name': 'x'}, {'name': '', 'count': 1}, {'name': 'x', 'count': -1},
                       {'name': 'x', 'count': True}, {'name': 'x', 'count': '1'}, {'name': 'x', 'count': 1, 'min': 0}):
            with self.subTest(wanted=wanted), self.assertRaises(steps.Infra):
                steps.cli(self.run, {**base, 'expect': {'steps': wanted}})

    def test_cli_tty_passes_the_script_and_checks_the_text(self):
        script = [{'see': 'port', 'send': '80'}]
        self.fleet.prompts = 'Which port?\n'
        args = {'run': ['firewall:allow', '80', '--node=211', '--from=x'], 'tty': script, 'expect': {'exit': 0, 'status': 'succeeded', 'human': ['Which port?', 'Operation']}}
        self.assertEqual(steps.cli(self.run, args), [])
        self.assertEqual(self.fleet.tty_calls, [(['firewall:allow', '80', '--node=211', '--from=x'], script)])
        self.assertEqual(steps.cli(self.run, {**args, 'expect': {'human': 'Which node?'}}), ["cli firewall:allow (tty): the output lacks 'Which node?'"])

    def test_cli_tty_that_does_not_prompt_is_a_failure_not_an_error(self):
        def stuck(args, script, timeout=120):
            raise steps.terminal.TerminalError("the program ended before 'port'")

        self.fleet.cli_tty = stuck
        self.assertEqual(steps.cli(self.run, {'run': ['firewall:allow'], 'tty': [{'see': 'port', 'send': '1'}]}), ["cli firewall:allow (tty): the program ended before 'port'"])

    def test_cli_refuses_arguments_that_make_no_sense(self):
        for args in ({'forms': ['xml']}, {'tty': [], 'forms': ['json']}, {'expect': {'speed': 1}}, {'expect': {'request': 'some'}}):
            with self.assertRaises(steps.Infra):
                steps.cli(self.run, {'run': ['node:list'], **args})

    def test_record_counts_matching_rows(self):
        self.fleet.records.append({'port': '80', 'action': 'allow', 'status': 'active'})
        args = {'list': 'firewall:list', 'node': 'app-dev', 'where': {'port': '80', 'action': 'allow'}, 'count': 1}
        self.assertEqual(steps.record(self.run, args), [])
        self.assertEqual(len(steps.record(self.run, {**args, 'count': 2})), 1)
        with self.assertRaises(steps.NotBuilt):
            steps.record(self.run, {**args, 'list': 'process:list'})

    def test_record_where_a_list_matches_any_item(self):
        self.fleet.records.extend([{'port': '80', 'status': 'active'}, {'port': '81', 'status': 'active'}, {'port': '82', 'status': 'active'}])
        args = {'list': 'firewall:list', 'node': 'app-dev', 'where': {'port': ['80', '81'], 'status': 'active'}, 'count': 2}
        self.assertEqual(steps.record(self.run, args), [])

    def list_nodes(self):
        """The answer of `firewall:list --managed --live` for two Nodes."""
        self.fleet.list_nodes = [
            {'node_id': 211, 'node': 'app-dev', 'rules': [{'port': '80', 'source': '10.0.0.1', 'match': 'exact'}, {'port': '81', 'source': 'any', 'match': 'drift'}],
             'managed': [{'name': 'orbit:wireguard-members', 'role': None}, {'name': 'orbit:app-dev', 'role': 'app-dev'}],
             'live': {'backend_status': 'active', 'possibly_outdated': False, 'observed_at': '2026-11-06T10:00:00Z', 'unmatched': [{'port': '9', 'match': 'unmanaged'}]}},
            {'node_id': 212, 'node': 'app-prod', 'rules': [{'port': '80', 'source': 'any', 'match': 'exact'}],
             'live': {'backend_status': 'active', 'possibly_outdated': True, 'observed_at': None, 'unmatched': []}}]

    def test_record_without_a_node_reads_every_node_and_the_name_of_each_is_a_key(self):
        self.list_nodes()
        base = {'list': 'firewall:list'}
        self.assertEqual(steps.record(self.run, {**base, 'where': {'port': '80'}, 'count': 2}), [])
        self.assertEqual(steps.record(self.run, {**base, 'where': {'node': 'app-dev', 'port': '80', 'match': 'exact'}, 'count': 1}), [])
        self.assertEqual(steps.record(self.run, {**base, 'where': {}, 'count': 0}), ['record firewall:list all Nodes rules: 3 rows match {}, expected 0'])
        self.assertEqual(steps.record(self.run, {**base, 'node': 'app-prod', 'where': {}, 'count': 1}), [])
        self.assertEqual(self.fleet.cli_calls[-1], ['firewall:list', '--node=212'])

    def test_record_reads_the_section_it_names_and_passes_args_to_the_call(self):
        self.list_nodes()
        base = {'list': 'firewall:list', 'args': ['--managed', '--live']}
        self.assertEqual(steps.record(self.run, {**base, 'node': 'app-dev', 'section': 'managed', 'where': {'name': 'orbit:wireguard-members', 'role': None}, 'count': 1}), [])
        self.assertEqual(steps.record(self.run, {**base, 'node': 'app-dev', 'section': 'unmatched', 'where': {'port': '9', 'match': 'unmanaged'}, 'count': 1}), [])
        self.assertEqual(steps.record(self.run, {**base, 'node': 'app-dev', 'section': 'live', 'where': {'backend_status': 'active', 'possibly_outdated': False, 'observed_at': 'present'}, 'count': 1}), [])
        self.assertEqual(steps.record(self.run, {**base, 'node': 'app-prod', 'section': 'live', 'where': {'possibly_outdated': True, 'observed_at': 'present'}, 'count': 1}),
                         ['record firewall:list app-prod live: 0 rows match {"observed_at": "present", "possibly_outdated": true}, expected 1'])
        self.assertEqual(self.fleet.cli_calls[0], ['firewall:list', '--managed', '--live', '--node=211'])

    def test_record_min_is_a_lower_bound_and_order_is_a_sequence_with_gaps(self):
        self.list_nodes()
        base = {'list': 'firewall:list', 'node': 'app-dev', 'where': {}}
        self.assertEqual(steps.record(self.run, {**base, 'min': 2}), [])
        self.assertIn('expected at least 3', steps.record(self.run, {**base, 'min': 3})[0])
        self.assertEqual(steps.record(self.run, {**base, 'order': [{'port': '80'}, {'port': '81'}]}), [])
        self.assertIn('comes before', steps.record(self.run, {**base, 'order': [{'port': '81'}, {'port': '80'}]})[0])
        self.assertIn('no row matches', steps.record(self.run, {**base, 'order': [{'port': '80'}, {'port': '99'}]})[0])

    def test_record_refuses_a_call_without_an_expectation_and_an_unknown_section(self):
        for args in ({'where': {}}, {'where': {}, 'count': 1, 'section': 'all'}):
            with self.assertRaises(steps.Infra):
                steps.record(self.run, {'list': 'firewall:list', 'node': 'app-dev', **args})

    def test_agent_report_waits_once_for_the_agents_of_the_listed_nodes(self):
        waits = []
        self.run.sleep = waits.append
        self.assertEqual(steps.agent_report(self.run, {'node': ['app-dev', 'app-prod']}), [])
        self.assertEqual(waits, [steps.REPORT_SECONDS])
        with self.assertRaises(steps.Infra):
            steps.agent_report(self.run, {'node': ['app-dev', 'nowhere']})

    def test_node_down_stops_the_agent_and_ssh_of_one_node(self):
        waits = []
        self.run.sleep = waits.append
        self.assertEqual(steps.node_down(self.run, {'node': 'app-dev'}), [])
        self.assertEqual((self.fleet.down, waits), ({'app-dev'}, [steps.OFFLINE_SECONDS]))
        with self.assertRaises(steps.Infra):
            steps.node_down(self.run, {'node': ['app-dev', 'app-prod']})

    def test_node_access_remove_names_both_nodes_by_id(self):
        self.assertEqual(steps.node_access_remove(self.run, {'consumer': 'app-prod', 'serving': 'app-dev'}), [])
        self.assertEqual(self.fleet.access_removed, [['node:access:remove', '212', '211', '--force']])
        with self.assertRaises(steps.Infra):
            steps.node_access_remove(self.run, {'consumer': 'app-prod', 'serving': 'nowhere'})

    def test_cli_as_runs_the_call_as_that_node_and_gateway_is_the_default_caller(self):
        steps.cli(self.run, {'run': ['node:list'], 'as': 'app-prod'})
        steps.cli(self.run, {'run': ['node:list'], 'as': 'gateway'})
        steps.cli(self.run, {'run': ['node:list']})
        self.assertEqual(self.fleet.callers, ['app-prod', None, None])
        with self.assertRaises(steps.Infra):
            steps.cli(self.run, {'run': ['node:list'], 'as': 'nowhere'})
        with self.assertRaises(steps.NotBuilt):
            steps.cli(self.run, {'run': ['node:list'], 'as': 'app-prod', 'tty': [{'see': 'x', 'send': '1'}]})

    def test_a_case_whose_node_cannot_run_the_cli_fails_with_cause_infrastructure(self):
        def refused(*args, **kwargs):
            raise fleet.FleetError('app-prod: cannot mount the tested checkout: exit 1')
        self.fleet.cli = refused
        with self.assertRaises(steps.Infra) as caught:
            steps.cli(self.run, {'run': ['node:list'], 'as': 'app-prod'})
        self.assertIn('cannot mount the tested checkout', str(caught.exception))

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

    def test_partial_application_requires_each_allow_above_deny_without_ordering_the_allows(self):
        port = self.blocked_port()
        steps.ufw_rule(self.run, {'as': 'existing', 'on': 'app-dev', 'action': 'allow', 'proto': 'tcp',
                                  'port': port, 'from': BRIDGE['gateway'], 'above': 'deny'})
        case = spec.load(Path(__file__).resolve().parents[2] / 'firewall/allow.cases.yaml',
                         Path(__file__).resolve().parents[2] / 'batches.yaml').case('partial-application')
        self.assertEqual(case.stages[0].act[0].args['expect']['steps'], {'name': 'Change the rules', 'count': 1})
        # The file may place the case on another Node; the rows below are those of app-dev.
        checks = [self.run.resolve(spec.swap_roles(step.args, 'app-dev', case.node)) for step in case.stages[0].expect if step.name == 'ufw']
        self.assertEqual(len(checks), 2)
        tcp = (f'{port}/tcp', 'ALLOW', BRIDGE['gateway'], 'proof:existing')
        udp = (f'{port}/udp', 'ALLOW', BRIDGE['gateway'], 'orbit')
        before, deny, after = self.fleet.ufw['app-dev'][0], self.fleet.ufw['app-dev'][2], self.fleet.ufw['app-dev'][3]
        for allows in ([tcp, udp], [udp, tcp]):
            self.fleet.ufw['app-dev'] = [before, *allows, deny, after]
            self.assertEqual([steps.ufw(self.run, check) for check in checks], [[], []])
        for misplaced, kept, check in ((tcp, udp, checks[0]), (udp, tcp, checks[1])):
            self.fleet.ufw['app-dev'] = [before, kept, deny, misplaced, after]
            self.assertIn('out of order', steps.ufw(self.run, check)[0])
        self.fleet.ufw['app-dev'] = [before, udp, (*tcp[:3], 'orbit'), deny, after]
        self.assertIn('appears 0 times', steps.ufw(self.run, checks[0])[0])
        self.fleet.ufw['app-dev'] = [before, tcp, deny, after]
        self.assertIn('appears 0 times', steps.ufw(self.run, checks[1])[0])

    def test_ufw_ref_in_family_6_means_the_v6_line_of_the_rule(self):
        port = self.blocked_port()
        self.fleet.ufw['app-dev'].append((f'{port}/tcp (v6)', 'DENY', 'Anywhere (v6)', 'proof:deny'))
        self.assertEqual(steps.ufw(self.run, {'on': 'app-dev', 'family': 6, 'rows': [{'ref': 'deny'}]}), [])
        self.assertEqual(steps.ufw(self.run, {'on': 'app-dev', 'family': 4, 'rows': [{'ref': 'deny'}]}), [])

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
        self.fleet.listeners['app-dev'].add(('tcp', 4, 5000))
        self.fleet.operation_rows.append({'id': 3, 'kind': 'firewall.allow', 'target_type': 'node_firewall', 'target_id': 211})
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

    def test_ipv6_addresses_gives_each_node_an_address_and_checks_that_they_reach_each_other(self):
        self.assertEqual(steps.ipv6_addresses(self.run, {'nodes': ['gateway', 'app-dev', 'app-prod']}), [])
        self.assertEqual(self.fleet.ipv6, {'gateway': 'fd42:232:2::10', 'app-dev': 'fd42:232:2::11', 'app-prod': 'fd42:232:2::12'})
        self.assertEqual(self.fleet.nodes['app-dev']['bridge6'], 'fd42:232:2::11')
        self.assertEqual(self.fleet.ipv6_allowed, {'gateway', 'app-dev', 'app-prod'})
        self.fleet.ipv6_reaches = False
        found = steps.ipv6_addresses(self.run, {'nodes': ['gateway', 'app-dev']})
        self.assertEqual(found, ['ipv6_addresses: gateway does not reach app-dev over IPv6', 'ipv6_addresses: app-dev does not reach gateway over IPv6'])
        with self.assertRaises(steps.Infra):
            steps.ipv6_addresses(self.run, {'nodes': ['mars']})

    def test_gateway_src6_is_the_source_address_of_the_ipv6_route(self):
        with self.assertRaises(steps.Infra):
            self.run.resolve('${gateway.src6}')
        steps.ipv6_addresses(self.run, {'nodes': ['gateway', 'app-dev']})
        self.assertEqual(self.run.resolve('--from=${gateway.src6}'), '--from=fd42:232:2::10')
        self.assertEqual(self.run.resolve('${gateway.src}'), BRIDGE['gateway'])

    def test_fault_fails_ufw_writes_after_the_counted_call_and_clear_removes_it(self):
        self.blocked_port()
        steps.fault(self.run, {'on': 'app-dev', 'command': 'ufw', 'fail_after': {'match': 'delete', 'count': 1}})
        self.assertEqual(self.fleet.fault['app-dev'], {'word': 'delete', 'after': 1, 'seen': 0})
        self.assertIn(('app-dev', 'ufw status numbered'), self.fleet.commands)
        self.assertEqual(steps.ufw_delete(self.run, {'on': 'app-dev', 'to': f"{self.run.names['port']}/tcp"}), [])
        failed = steps.ufw_rule(self.run, {'on': 'app-dev', 'action': 'allow', 'proto': 'tcp', 'port': '1'})  # the next write fails
        self.assertIn('injected fault', failed[0])
        self.assertEqual(steps.ufw(self.run, {'on': 'app-dev', 'rows': []}), [])  # a read passes
        self.assertEqual(steps.fault(self.run, {'clear': True}), [])
        self.assertEqual((self.fleet.fault, self.fleet.shim, self.run.shimmed), ({}, set(), set()))
        self.assertEqual(steps.ufw_rule(self.run, {'on': 'app-dev', 'action': 'allow', 'proto': 'tcp', 'port': '1'}), [])

    def test_fault_counts_only_calls_with_the_word(self):
        steps.fault(self.run, {'on': 'app-dev', 'command': 'ufw', 'fail_after': {'match': 'allow', 'count': 1}})
        self.assertEqual(steps.ufw_rule(self.run, {'on': 'app-dev', 'action': 'deny', 'proto': 'tcp', 'port': '1'}), [])
        self.assertEqual(steps.ufw_rule(self.run, {'on': 'app-dev', 'action': 'allow', 'proto': 'tcp', 'port': '2'}), [])
        self.assertIn('injected fault', steps.ufw_rule(self.run, {'on': 'app-dev', 'action': 'deny', 'proto': 'tcp', 'port': '3'})[0])

    def test_fault_refuses_what_it_cannot_do(self):
        good = {'on': 'app-dev', 'command': 'ufw', 'fail_after': {'match': 'delete', 'count': 1}}
        with self.assertRaises(steps.NotBuilt):
            steps.fault(self.run, {**good, 'command': 'iptables'})
        for args in ({'on': 'app-dev'}, {**good, 'fail_after': {'match': 'delete; rm', 'count': 1}}, {**good, 'fail_after': {'match': 'delete', 'count': 0}}, {'clear': True, 'on': 'x'}):
            with self.assertRaises(steps.Infra):
                steps.fault(self.run, args)

    def test_busy_node_holds_a_write_and_cleanup_releases_it(self):
        self.assertEqual(steps.busy_node(self.run, {'on': 'app-dev'}), [])
        self.assertEqual(self.fleet.stalled, {'app-dev'})
        started = self.fleet.background[0]
        self.assertEqual(started.args[0], 'firewall:allow')
        self.assertIn('--node=211', started.args)
        refused = steps.cli(self.run, {'run': ['firewall:allow', '80', '--node=211'], 'expect': {'exit': 1, 'code': 'operation.target_busy', 'request': 'none'}})
        self.assertEqual(refused, [])
        steps.cleanup(self.run)
        self.assertEqual((started.running, self.fleet.stalled, self.fleet.shim, self.run.background), (False, set(), set(), []))

    def test_busy_node_that_does_not_start_is_a_failure(self):
        self.fleet.starts_busy = False
        original = self.fleet.cli_background

        def background(args):
            handle = original(args)
            handle.starts = False
            return handle

        self.fleet.cli_background = background
        self.assertEqual(steps.busy_node(self.run, {'on': 'app-dev'}), ['busy_node: the firewall:allow did not start in 30 s'])
        with self.assertRaises(steps.NotBuilt):
            steps.busy_node(self.run, {'on': 'app-dev', 'command': 'node:converge'})

    def test_placeholders_resolve_from_names_nodes_and_routes(self):
        self.run.names['port'] = '47001'
        self.assertEqual(self.run.resolve('--node=${app-dev.id}'), '--node=211')
        self.assertEqual(self.run.resolve({'from': '${gateway.src}', 'to': '${port}/tcp'}), {'from': BRIDGE['gateway'], 'to': '47001/tcp'})
        with self.assertRaises(steps.Infra):
            self.run.resolve('${nothing}')



class FamilyRowsTest(unittest.TestCase):
    def test_a_line_from_one_ipv6_address_is_a_line_of_family_6(self):
        anywhere = {"to": "1/tcp (v6)", "from": "Anywhere (v6)"}
        address = {"to": "1/tcp", "from": "fd42:232:2::10"}
        plain = {"to": "1/tcp", "from": "10.0.0.1"}
        rows = [anywhere, address, plain]
        self.assertEqual(steps.family_rows(rows, 6), [anywhere, address])
        self.assertEqual(steps.family_rows(rows, 4), [plain])

if __name__ == '__main__':
    with patch.object(runner.Run, 'sleep', lambda self, seconds: None):
        unittest.main()
