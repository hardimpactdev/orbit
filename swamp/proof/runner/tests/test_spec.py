"""The loader: the fixture copy of the allow cases file loads, and each kind of mistake is refused with a message that names it."""
import sys
import tempfile
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
import spec

PROOF = Path(__file__).resolve().parents[2]
CASES = Path(__file__).resolve().parent / 'fixtures' / 'allow.cases.yaml'
LIST_CASES = Path(__file__).resolve().parent / 'fixtures' / 'list.cases.yaml'
BATCHES = PROOF / 'batches.yaml'

HEADER = 'command: firewall:allow\nchart: firewall/allow\ntarget: app-dev\nsources: [gateway, app-prod]\n'
GOOD = '''
cases:
  - id: one
    batch: fresh-fleet
    covers: [main]
    summary: One case.
    act:
      - cli: {run: [firewall:allow, "80"], expect: {exit: 0}}
    expect:
      - probe: {from: gateway, to: app-dev, port: "80", expect: connect}
'''


def load_text(text, header=HEADER):
    with tempfile.TemporaryDirectory() as directory:
        path = Path(directory) / 'x.cases.yaml'
        path.write_text(header + text)
        return spec.load(path, BATCHES)


class FixtureFileTest(unittest.TestCase):
    def setUp(self):
        self.spec = spec.load(CASES, BATCHES)

    def test_every_case_of_the_file_loads(self):
        ids = [case.id for case in self.spec.cases]
        self.assertEqual(len(ids), 16)
        self.assertIn('new-restricted-allow', ids)
        self.assertEqual(ids[6:9], ['protocols[tcp]', 'protocols[udp]', 'protocols[both]'])

    def test_the_list_cases_load_and_every_batch_they_name_exists(self):
        loaded = spec.load(LIST_CASES, BATCHES)
        names = {step.name for case in loaded.cases for step in case.setup + [item for stage in case.stages for item in stage.act + stage.expect]}
        self.assertTrue({'record', 'agent_report', 'node_down'} <= names)
        self.assertIn('node-access-required', [case.id for case in loaded.cases])
        self.assertIn('no-grant', [item['batch'] for item in spec.plan(loaded)])

    def test_batches_run_in_the_order_of_the_batch_file(self):
        plan = spec.plan(self.spec)
        self.assertEqual([item['batch'] for item in plan], ['fresh-fleet', 'dual-stack'])
        self.assertEqual(plan[0]['cases'][0], 'new-restricted-allow')
        self.assertEqual(plan[1]['cases'], ['family-ipv6'])

    def test_a_fixture_is_inserted_and_a_list_var_is_spliced(self):
        case = self.spec.case('new-restricted-allow')
        self.assertEqual([step.name for step in case.setup], ['listener', 'ufw_rule', 'probe', 'probe'])
        self.assertEqual(case.setup[1].args['proto'], ['tcp'])
        run = case.stages[0].act[0].args['run']
        self.assertEqual(run, ['firewall:allow', '${port}', '--node=${app-dev.id}', '--from=${gateway.src}', '--protocol=tcp'])

    def test_sources_stay_a_list_for_the_probe_to_expand(self):
        self.assertEqual(self.spec.case('new-restricted-allow').setup[2].args['from'], ['gateway', 'app-prod'])

    def test_on_is_a_key_and_not_a_boolean(self):
        self.assertEqual(self.spec.case('new-restricted-allow').setup[0].args['on'], 'app-dev')

    def test_a_variant_becomes_one_case_with_its_own_values(self):
        udp = self.spec.case('protocols[udp]')
        self.assertEqual(udp.stages[0].act[0].args['run'][-1], '--protocol=udp')
        both = self.spec.case('protocols[both]')
        self.assertEqual(both.stages[0].act[0].args['run'], ['firewall:allow', '${port}', '--node=${app-dev.id}', '--from=${gateway.src}'])
        self.assertEqual(both.setup[1].args['proto'], ['tcp', 'udp'])

    def test_a_list_placeholder_in_a_row_makes_one_row_for_each_item(self):
        rows = self.spec.case('protocols[both]').stages[0].expect[3].args['rows']
        self.assertEqual([row.get('to') for row in rows[:2]], ['${port}/tcp', '${port}/udp'])

    def test_stages_are_kept_in_order(self):
        case = self.spec.case('interrupted-reorder')
        self.assertEqual(len(case.stages), 2)
        self.assertEqual(case.stages[1].act[0].name, 'fault')


class RefusalTest(unittest.TestCase):
    def refused(self, text, message, header=HEADER):
        with self.assertRaises(spec.SpecError) as caught:
            load_text(text, header)
        self.assertIn(message, str(caught.exception))

    def test_a_good_minimal_file_loads(self):
        self.assertEqual(len(load_text(GOOD).cases), 1)

    def test_unknown_step(self):
        self.refused(GOOD.replace('probe:', 'poke:'), 'unknown step poke')

    def test_unknown_batch(self):
        self.refused(GOOD.replace('fresh-fleet', 'nowhere'), 'unknown batch nowhere')

    def test_missing_keys_of_a_case(self):
        for key in ('batch', 'covers', 'summary'):
            lines = [line for line in GOOD.splitlines() if not line.strip().startswith(key + ':')]
            self.refused('\n'.join(lines), 'missing ' + key)

    def test_missing_keys_of_the_file(self):
        self.refused(GOOD, 'missing command', header='chart: x\ntarget: app-dev\nsources: [gateway]\n')

    def test_unknown_key_of_a_case_and_of_the_file(self):
        self.refused(GOOD.replace('summary:', 'sumary:'), 'unknown keys')
        self.refused(GOOD, 'unknown keys', header=HEADER + 'extra: 1\n')

    def test_a_step_with_a_missing_or_unknown_argument(self):
        self.refused(GOOD.replace(', expect: connect', ''), 'lacks')
        self.refused(GOOD.replace('expect: connect', 'expect: connect, speed: 3'), 'unknown keys')

    def test_ufw_delete_stands_in_setup_and_negative_but_not_in_expect(self):
        delete = '- ufw_delete: {on: app-dev, to: 80/tcp}'
        self.assertEqual(load_text(GOOD.replace('act:', 'setup:\n      ' + delete + '\n    act:')).cases[0].setup[0].name, 'ufw_delete')
        self.refused(GOOD.replace('- probe: {from: gateway', delete + '\n      - probe: {from: gateway'), 'not allowed in expect')

    def test_the_setup_steps_of_the_vocabulary_and_the_keys_of_record_and_cli(self):
        steps = '''
    setup:
      - agent_report: {node: [app-dev, app-prod]}
      - node_down: {node: app-dev}
    act:
      - cli: {as: app-prod, run: [firewall:list], expect: {exit: 0}}
    expect:
      - record: {list: firewall:list, args: [--live], node: app-dev, section: live, where: {observed_at: present}, min: 1}
      - record: {list: firewall:list, order: [{port: "1"}, {port: "2"}]}
'''
        text = GOOD[:GOOD.index('    act:')] + steps
        self.assertEqual([step.name for step in load_text(text).cases[0].setup], ['agent_report', 'node_down'])
        self.refused(text.replace('node_down: {node: app-dev}', 'node_access_remove: {consumer: app-prod}'), 'lacks')

    def test_a_record_without_count_min_or_order_is_refused_at_plan_time(self):
        self.refused(GOOD.replace('- probe: {from: gateway, to: app-dev, port: "80", expect: connect}', '- record: {list: firewall:list, node: app-dev, where: {}}'), 'needs count, min or order')

    def test_a_step_outside_its_section(self):
        self.refused(GOOD.replace('- cli: {run: [firewall:allow, "80"], expect: {exit: 0}}', '- doctor: {node: app-dev, drift: 0}'), 'not allowed in act')

    def test_unknown_fixture_var_and_placeholder(self):
        self.refused(GOOD.replace('act:', 'setup:\n      - use: missing\n    act:'), 'unknown fixture')
        self.refused(GOOD.replace('"80"]', '"80", "@nothing"]'), 'unknown var @nothing')
        self.refused(GOOD.replace('port: "80"', 'port: "${nothing}"'), 'unknown placeholder ${nothing}')

    def test_a_case_without_act_and_expect(self):
        self.refused(GOOD.replace('    expect:\n      - probe: {from: gateway, to: app-dev, port: "80", expect: connect}\n', ''), 'needs act and expect')

    def test_a_duplicate_id(self):
        self.refused(GOOD + GOOD.replace('cases:\n', ''), 'appears twice')

    def test_a_fixture_param_that_does_not_exist(self):
        text = 'fixtures:\n  f:\n    params: {a: 1}\n    steps: []\n' + GOOD.replace('act:', 'setup:\n      - use: {fixture: f, b: 2}\n    act:')
        self.refused(text, 'has no params')

    def test_target_must_be_a_node(self):
        self.refused(GOOD, 'target and sources', header=HEADER.replace('target: app-dev', 'target: mars'))


class ExpandTest(unittest.TestCase):
    def test_combinations_of_list_values(self):
        args = {'from': ['a', 'b'], 'proto': ['tcp', 'udp'], 'port': '1'}
        self.assertEqual(len(spec.expand_args('probe', args)), 4)

    def test_a_scalar_stays_one_step(self):
        self.assertEqual(spec.expand_args('probe', {'from': 'a', 'port': '1'}), [{'from': 'a', 'port': '1'}])

    def test_subst_leaves_unknown_names_for_the_runner(self):
        self.assertEqual(spec.subst('${port}/${proto}', {'proto': 'tcp'}), '${port}/tcp')


if __name__ == '__main__':
    unittest.main()
