"""Cases on more than one Node, at more than one at a time: placement, locks, the scheduler, `runner batch` and the shared Node IDs."""
import contextlib
import io
import json
import sys
import tempfile
import threading
import time
import unittest
from pathlib import Path
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
sys.path.insert(0, str(Path(__file__).resolve().parent))
import fleet as fleet_module
import runner
import spec
import steps
from fakes import NODE_IDS, FakeFleet

PROOF = Path(__file__).resolve().parents[2]
CASES = Path(__file__).resolve().parent / 'fixtures' / 'allow.cases.yaml'
BATCHES = PROOF / 'batches.yaml'
HEADER = 'command: firewall:allow\nchart: firewall/allow\ntarget: app-dev\nsources: [gateway, app-prod]\nbaseline: [ufw, firewall_records, operations]\n'


def writing(case_id, extra=''):
    return f'''  - id: {case_id}
    batch: fresh-fleet
    covers: [main]
    summary: A case that writes.
{extra}    act:
      - cli: {{run: [firewall:allow, "80", "--node=${{app-dev.id}}", "--from=${{gateway.src}}"], expect: {{exit: 0}}}}
    expect:
      - probe: {{from: [gateway, app-prod], to: app-dev, port: "80", expect: connect}}
      - ufw: {{on: app-dev, rows: [{{to: 80/tcp}}]}}
'''


def observer(case_id, extra=''):
    return f'''  - id: {case_id}
    batch: fresh-fleet
    covers: [main]
    summary: A case that compares the Node.
{extra}    act:
      - cli: {{run: [firewall:allow, "80"], expect: {{exit: 1}}}}
    expect:
      - unchanged: {{since: act}}
'''


def refusal(case_id):
    return f'''  - id: {case_id}
    batch: fresh-fleet
    covers: [main]
    summary: A case that expects no request.
    act:
      - cli: {{run: [firewall:allow, "80"], expect: {{exit: 1, request: none}}}}
    expect: []
'''


def load_text(text, header=HEADER):
    with tempfile.TemporaryDirectory() as directory:
        path = Path(directory) / 'x.cases.yaml'
        path.write_text(header + 'cases:\n' + text)
        return spec.load(path, BATCHES)


class PlacementTest(unittest.TestCase):
    def test_without_a_spread_every_case_stays_on_the_target(self):
        loaded = load_text(writing('a') + writing('b'))
        self.assertEqual([case.node for case in loaded.cases], ['app-dev', 'app-dev'])

    def test_a_spread_gives_the_nodes_in_turn_in_file_order(self):
        loaded = load_text(writing('a') + writing('b') + writing('c') + writing('d'), HEADER + 'spread: [app-dev, app-prod, gateway]\n')
        self.assertEqual([case.node for case in loaded.cases], ['app-dev', 'app-prod', 'gateway', 'app-dev'])

    def test_a_case_that_runs_alone_keeps_the_target_and_takes_no_turn(self):
        loaded = load_text(refusal('r') + writing('a') + writing('b'), HEADER + 'spread: [app-dev, app-prod]\n')
        self.assertEqual([(case.id, case.node, case.lock) for case in loaded.cases], [('r', 'app-dev', 'all'), ('a', 'app-dev', 'node'), ('b', 'app-prod', 'node')])

    def test_a_case_names_its_own_node(self):
        loaded = load_text(writing('a', '    node: gateway\n') + writing('b'), HEADER + 'spread: [app-prod, app-dev]\n')
        self.assertEqual([case.node for case in loaded.cases], ['gateway', 'app-prod'])

    def test_the_target_trades_places_with_the_node_in_every_step(self):
        loaded = load_text(writing('a', '    node: app-prod\n'))
        steps = {step.name: step.args for step in loaded.case('a').stages[0].act + loaded.case('a').stages[0].expect}
        self.assertEqual(steps['cli']['run'], ['firewall:allow', '80', '--node=${app-prod.id}', '--from=${gateway.src}'])
        self.assertEqual((steps['probe']['from'], steps['probe']['to']), (['gateway', 'app-dev'], 'app-prod'))
        self.assertEqual(steps['ufw']['on'], 'app-prod')

    def test_on_the_gateway_the_sources_are_the_other_two_nodes(self):
        loaded = load_text(writing('a', '    node: gateway\n'))
        steps = {step.name: step.args for step in loaded.case('a').stages[0].act + loaded.case('a').stages[0].expect}
        self.assertEqual(steps['cli']['run'], ['firewall:allow', '80', '--node=${gateway.id}', '--from=${app-dev.src}'])
        self.assertEqual((steps['probe']['from'], steps['probe']['to']), (['app-dev', 'app-prod'], 'gateway'))

    def test_names_a_case_binds_stay_when_the_roles_trade_places(self):
        loaded = load_text(writing('a', '    node: app-prod\n').replace('    act:\n', '    setup:\n      - listener: {on: app-dev, ports: [app-dev]}\n    act:\n'))
        setup = {step.name: step.args for step in loaded.case('a').setup}
        self.assertEqual(setup['listener'], {'on': 'app-prod', 'ports': ['app-dev']})

    def test_as_names_a_node_in_cli_and_a_binding_in_ufw_rule(self):
        text = writing('a', '    node: app-prod\n').replace('    act:\n', '    setup:\n      - ufw_rule: {as: app-dev, on: app-dev, action: deny, proto: tcp, port: "80"}\n    act:\n')
        text = text.replace('"--from=${gateway.src}"], expect', '"--from=${gateway.src}"], as: app-dev, expect')
        loaded = load_text(text)
        case = loaded.case('a')
        self.assertEqual(case.setup[0].args['as'], 'app-dev')
        self.assertEqual(case.stages[0].act[0].args['as'], 'app-prod')

    def test_a_prompt_choice_follows_the_placed_node(self):
        text = writing('a', '    node: gateway\n').replace('expect: {exit: 0}}', 'tty: [{see: Node, choose: app-dev}], expect: {exit: 0}}')
        self.assertEqual(load_text(text).case('a').stages[0].act[0].args['tty'], [{'see': 'Node', 'choose': 'gateway'}])

    def test_a_read_of_every_nodes_records_runs_alone(self):
        text = writing('a').replace('    expect:\n', '    expect:\n      - record: {list: firewall:list, count: 0}\n')
        self.assertEqual(load_text(text).cases[0].lock, 'all')
        text = writing('a').replace('    expect:\n', '    expect:\n      - record: {list: firewall:list, node: app-dev, count: 0}\n')
        self.assertEqual(load_text(text).cases[0].lock, 'node')

    def test_a_case_that_works_on_another_node_than_its_own_runs_alone(self):
        text = writing('a').replace('    expect:\n', '    expect:\n      - ufw: {on: gateway, rows: []}\n')
        self.assertEqual(load_text(text).cases[0].lock, 'all')

    def test_locks(self):
        loaded = load_text(writing('writes') + observer('observes') + refusal('no-request') + writing('asks', '    exclusive: true\n'))
        self.assertEqual({case.id: case.lock for case in loaded.cases}, {'writes': 'node', 'observes': 'node', 'no-request': 'all', 'asks': 'all'})

    def test_steps_that_change_what_all_cases_share_run_alone(self):
        for step in ('fault: {on: app-dev, command: ufw, fail_after: {match: insert, count: 1}}', 'busy_node: {on: app-dev}', 'agent_report: {node: app-dev}'):
            loaded = load_text(writing('a').replace('    act:\n', f'    setup:\n      - {step}\n    act:\n'))
            self.assertEqual(loaded.cases[0].lock, 'all', step)

    def test_a_comparison_of_the_operation_list_runs_alone(self):
        loaded = load_text(observer('a').replace('{since: act}', '{since: act, what: [operations]}'))
        self.assertEqual(loaded.cases[0].lock, 'all')

    def test_the_cases_file_sets_the_default_number_at_a_time(self):
        self.assertEqual(load_text(writing('a')).parallel, 4)
        self.assertEqual(load_text(writing('a'), HEADER + 'parallel: 2\n').parallel, 2)

    def test_a_mistake_in_the_placement_is_refused(self):
        for header, body in ((HEADER + 'spread: [app-dev, nowhere]\n', writing('a')), (HEADER + 'parallel: 0\n', writing('a')), (HEADER, writing('a', '    node: nowhere\n')),
                             (HEADER, writing('a', '    exclusive: yes please\n'))):
            with self.assertRaises(spec.SpecError):
                load_text(body, header)

    def test_the_real_allow_file_runs_most_cases_on_one_node_at_a_time_and_spreads_them(self):
        loaded = spec.load(PROOF / 'firewall' / 'allow.cases.yaml', BATCHES)
        self.assertEqual(loaded.parallel, 4)
        spread = {case.node for case in loaded.cases if case.lock != 'all'}
        self.assertEqual(spread, {'app-dev', 'app-prod', 'gateway'})
        self.assertEqual({case.node for case in loaded.cases if case.lock == 'all'}, {'app-dev'})


class RunOnAnotherNodeTest(unittest.TestCase):
    def test_a_case_placed_on_each_node_passes_against_that_node(self):
        for node in ('app-prod', 'gateway'):
            loaded = spec.load(CASES, BATCHES)
            case = loaded.case('protocols[tcp]')
            case.node = node
            spec.place([case], loaded.target, [])
            fleet = FakeFleet()
            with tempfile.TemporaryDirectory() as directory, patch.object(runner.Run, 'sleep', lambda self, seconds: None):
                result, _ = runner.execute(case, fleet, loaded, directory)
            self.assertEqual(result['verdict'], 'passed', (node, result))
            self.assertIn(f'--node={NODE_IDS[node]}', [item for call in fleet.cli_calls for item in call])
            self.assertTrue(any(row[0].endswith('/tcp') and row[3] == 'orbit' for row in fleet.ufw[node]), node)


class BaselineFollowsThePlacementTest(unittest.TestCase):
    def test_the_state_a_case_compares_is_the_state_of_its_own_node(self):
        loaded = spec.load(CASES, BATCHES)
        fleet = FakeFleet()
        run = runner.Run(fleet, loaded, tempfile.gettempdir(), 'app-prod')
        before = steps.snapshot(run, 'ufw')
        fleet.ufw['app-prod'].append(('9999/tcp', 'ALLOW', 'Anywhere', 'x'))
        self.assertNotEqual(steps.snapshot(run, 'ufw'), before)
        fleet.ufw['app-dev'].append(('8888/tcp', 'ALLOW', 'Anywhere', 'y'))
        self.assertEqual(len(steps.snapshot(run, 'ufw')), len(before) + 1)

    def run_observer(self, node, stray_on):
        loaded = load_text(observer('watch', '    node: %s\n' % node), HEADER + 'baseline: [ufw]\n')
        case = loaded.case('watch')
        fleet = FakeFleet()
        original = fleet.cli

        def cli(args, timeout=240, form='json', node=None):
            fleet.ufw[stray_on].append(('9999/tcp', 'ALLOW', 'Anywhere', 'stray'))
            return original(args, timeout, form, node)
        fleet.cli = cli
        with tempfile.TemporaryDirectory() as directory:
            return runner.execute(case, fleet, loaded, directory)[0]

    def test_a_case_placed_on_a_node_fails_when_that_node_changes_without_a_reason(self):
        result = self.run_observer('app-prod', 'app-prod')
        self.assertEqual(result['verdict'], 'failed', result)
        self.assertTrue(any('unchanged ufw' in message for message in result['failed']), result)

    def test_a_change_on_another_node_does_not_fail_a_case_placed_elsewhere(self):
        self.assertEqual(self.run_observer('app-prod', 'gateway')['verdict'], 'passed')


class FitTest(unittest.TestCase):
    @staticmethod
    def passed(number):
        return {'id': f'case-{number:03}', 'verdict': 'passed', 'secs': 1.5}

    @staticmethod
    def failed(number, size=900):
        return {'id': f'bad-{number:03}', 'verdict': 'failed', 'cause': 'assertion', 'secs': 2, 'failed': ['expect[%d] ' % number + 'x' * size] * 4}

    def test_lines_that_fit_stay_whole_and_the_ones_that_did_not_pass_come_first(self):
        kept = runner.fit([self.passed(1), self.failed(1, 20), self.passed(2)])
        self.assertEqual([json.loads(line)['id'] for line in kept], ['bad-001', 'case-001', 'case-002'])
        self.assertEqual(len(json.loads(kept[0])['failed']), 4)

    def test_failures_that_do_not_fit_keep_two_short_messages_before_any_line_is_cut(self):
        results = [self.failed(number) for number in range(5)] + [self.passed(number) for number in range(40)]
        kept = runner.fit(results)
        self.assertLessEqual(len('\n'.join(kept).encode()), runner.STEP_OUTPUT_LIMIT)
        documents = [json.loads(line) for line in kept]
        self.assertEqual([item['id'] for item in documents[:5]], [f'bad-{number:03}' for number in range(5)])
        self.assertTrue(all(len(item['failed']) == 2 and all(len(message) <= 100 for message in item['failed']) for item in documents[:5]))

    def test_what_does_not_fit_is_counted_and_the_output_stays_under_the_cut_of_a_step(self):
        results = [self.passed(number) for number in range(120)]
        kept = runner.fit(results)
        self.assertLessEqual(len('\n'.join(kept).encode()), runner.STEP_OUTPUT_LIMIT)
        counted = json.loads(kept[-1])['cut']
        self.assertEqual(counted + len(kept) - 1, 120)

    def test_the_last_line_needs_no_room_for_the_count(self):
        result = {'id': 'x' * 3900, 'verdict': 'passed', 'secs': 1}
        self.assertEqual(len(runner.fit([result])), 1)

    def test_the_whole_allow_batch_of_passed_cases_fits(self):
        loaded = spec.load(PROOF / 'firewall' / 'allow.cases.yaml', BATCHES)
        results = [{'id': case.id, 'verdict': 'passed', 'secs': 12.3} for case in loaded.cases if case.batch == 'fresh-fleet']
        self.assertEqual(len(runner.fit(results)), len(results))


class SchedulerTest(unittest.TestCase):
    @staticmethod
    def case(case_id, node, lock='node'):
        return spec.Case(case_id, 'b', [], '', [], [], None, 10, node=node, lock=lock)

    def watch(self, cases, limit):
        """Run the cases with a work function that records which cases were running at the same time."""
        active, together, lock = set(), [], threading.Lock()

        def work(case):
            with lock:
                active.add(case.id)
                together.append(set(active))
            time.sleep(0.05)
            with lock:
                active.discard(case.id)
            return {'id': case.id, 'verdict': 'passed'}
        finished = [case.id for case, _ in runner.schedule(cases, limit, work)]
        return finished, together

    def test_two_cases_on_one_node_never_run_together(self):
        _, together = self.watch([self.case('a', 'app-dev'), self.case('b', 'app-dev'), self.case('c', 'app-dev')], 4)
        self.assertTrue(all(len(running) == 1 for running in together))

    def test_cases_on_different_nodes_run_together(self):
        _, together = self.watch([self.case('a', 'app-dev'), self.case('b', 'app-prod'), self.case('c', 'gateway')], 4)
        self.assertIn({'a', 'b', 'c'}, together)

    def test_the_limit_caps_the_cases_at_a_time(self):
        _, together = self.watch([self.case('a', 'app-dev'), self.case('b', 'app-prod'), self.case('c', 'gateway')], 2)
        self.assertEqual(max(len(running) for running in together), 2)

    def test_a_case_that_runs_alone_goes_last_and_alone_whatever_its_place_in_the_file(self):
        cases = [self.case('alone', 'app-dev', 'all'), self.case('a', 'app-dev'), self.case('b', 'app-prod'), self.case('c', 'gateway'), self.case('d', 'app-dev')]
        finished, together = self.watch(cases, 4)
        self.assertEqual(finished[-1], 'alone')
        self.assertIn({'alone'}, together)
        self.assertEqual([running for running in together if 'alone' in running], [{'alone'}])
        self.assertEqual(set(finished), {'alone', 'a', 'b', 'c', 'd'})

    def test_one_at_a_time_runs_in_file_order(self):
        finished, _ = self.watch([self.case('a', 'app-dev'), self.case('b', 'app-prod'), self.case('c', 'gateway'), self.case('d', 'app-dev', 'all')], 1)
        self.assertEqual(finished, ['a', 'b', 'c', 'd'])


class BatchTest(unittest.TestCase):
    def call(self, argv):
        out, err = io.StringIO(), io.StringIO()
        with contextlib.redirect_stdout(out), contextlib.redirect_stderr(err):
            code = runner.main(argv)
        return code, [json.loads(line) for line in out.getvalue().splitlines()], err.getvalue()

    def test_a_batch_prints_one_verdict_line_a_case_and_exits_with_the_worst(self):
        with tempfile.TemporaryDirectory() as directory, patch.object(fleet_module, 'from_environment', lambda: FakeFleet()), \
                patch.object(runner.Run, 'sleep', lambda self, seconds: None):
            code, lines, _ = self.call(['batch', 'dual-stack', '--file', str(CASES), '--evidence', directory, '--parallel', '1'])
            receipts = len(list(Path(directory).glob('*/*/receipt.json')))
        ids = sorted(case.id for case in spec.load(CASES, BATCHES).cases if case.batch == 'dual-stack')
        self.assertEqual(sorted(line['id'] for line in lines), ids)
        self.assertEqual(receipts, len(ids))
        self.assertEqual(code, 1)   # the fake fleet has no IPv6 addresses: the cases are unrun or failed, never passed
        self.assertNotIn('passed', {line['verdict'] for line in lines})

    def test_the_exit_status_is_the_worst_verdict(self):
        for verdicts, expected in ((['passed', 'passed'], 0), (['passed', 'unrun'], 2), (['unrun', 'failed', 'passed'], 1)):
            results = iter(verdicts)
            with patch.object(fleet_module, 'from_environment', lambda: FakeFleet()), \
                    patch.object(runner, 'run_case', lambda case, fleet, spec, root, negative=False, discovery=0: {'id': case.id, 'verdict': next(results), 'secs': 0}), \
                    patch.object(runner, 'schedule', lambda cases, limit, work: [(case, work(case)) for case in cases[:len(verdicts)]]):
                code, _, _ = self.call(['batch', 'fresh-fleet', '--file', str(CASES), '--evidence', tempfile.gettempdir()])
            self.assertEqual(code, expected, verdicts)

    def test_the_lines_that_did_not_pass_come_first(self):
        verdicts = {'new-restricted-allow': 'passed', 'already-applied': 'failed', 'partial-application': 'passed'}
        with patch.object(fleet_module, 'from_environment', lambda: FakeFleet()), \
                patch.object(runner, 'run_case', lambda case, fleet, spec, root, negative=False, discovery=0: {'id': case.id, 'verdict': verdicts[case.id], 'secs': 1}):
            code, lines, _ = self.call(['batch', 'fresh-fleet', '--file', str(CASES), '--evidence', tempfile.gettempdir(), '--parallel', '1', '--only', ' '.join(verdicts)])
        self.assertEqual([line['verdict'] for line in lines], ['failed', 'passed', 'passed'])
        self.assertEqual(code, 1)

    def test_only_runs_the_named_cases(self):
        ran = []
        with patch.object(fleet_module, 'from_environment', lambda: FakeFleet()), \
                patch.object(runner, 'run_case', lambda case, fleet, spec, root, negative=False, discovery=0: ran.append(case.id) or {'id': case.id, 'verdict': 'passed', 'secs': 1}):
            code, lines, _ = self.call(['batch', 'fresh-fleet', '--file', str(CASES), '--evidence', tempfile.gettempdir(), '--only', 'conflict already-applied'])
        self.assertEqual(sorted(ran), ['already-applied', 'conflict'])
        self.assertEqual((code, len(lines)), (0, 2))

    def test_only_refuses_a_case_that_is_not_in_the_batch(self):
        code, _, err = self.call(['batch', 'dual-stack', '--file', str(CASES), '--only', 'conflict'])
        self.assertEqual(code, 3)
        self.assertIn('unknown case in batch dual-stack: conflict', err)

    def test_a_fleet_that_cannot_be_found_fails_every_case_of_the_batch_as_infrastructure(self):
        def missing():
            raise fleet_module.FleetError('expected one complete nested fleet')
        with patch.object(fleet_module, 'from_environment', missing):
            code, lines, _ = self.call(['batch', 'dual-stack', '--file', str(CASES), '--evidence', tempfile.gettempdir()])
        self.assertEqual(code, 1)
        self.assertEqual({line['cause'] for line in lines}, {'infrastructure'})
        self.assertEqual(len(lines), len([case for case in spec.load(CASES, BATCHES).cases if case.batch == 'dual-stack']))

    def test_an_unknown_batch_is_refused(self):
        code, _, err = self.call(['batch', 'nope', '--file', str(CASES)])
        self.assertEqual(code, 3)
        self.assertIn('unknown batch', err)

    def test_a_case_whose_receipt_cannot_be_written_is_a_failed_case(self):
        def broken(case, fleet, loaded, root, negative=False, discovery=0):
            raise OSError('disk full')
        with patch.object(fleet_module, 'from_environment', lambda: FakeFleet()), patch.object(runner, 'run_case', broken):
            code, lines, _ = self.call(['batch', 'dual-stack', '--file', str(CASES), '--evidence', tempfile.gettempdir()])
        self.assertEqual(code, 1)
        self.assertEqual({(line['verdict'], line['cause']) for line in lines}, {('failed', 'infrastructure')})


