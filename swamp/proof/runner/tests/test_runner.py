"""One case from the fixture copy of the allow file against the fake fleet: the verdicts, the line, and the commands."""
import contextlib
import io
import json
import sys
import tempfile
import unittest
from pathlib import Path
from unittest.mock import Mock, patch
from urllib.parse import parse_qs, urlparse

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
sys.path.insert(0, str(Path(__file__).resolve().parent))
import fleet as fleet_module
import runner
import spec
import steps
from fakes import BRIDGE, FakeFleet, completed

PROOF = Path(__file__).resolve().parents[2]
CASES = Path(__file__).resolve().parent / 'fixtures' / 'allow.cases.yaml'
BATCHES = PROOF / 'batches.yaml'
CASE = 'new-restricted-allow'


class RunnerTest(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory()
        self.addCleanup(self.directory.cleanup)
        self.spec = spec.load(CASES, BATCHES)
        self.fleet = FakeFleet()
        patcher = patch.object(runner.Run, 'sleep', lambda self, seconds: None)
        patcher.start()
        self.addCleanup(patcher.stop)

    def execute(self, case=CASE, negative=False):
        return runner.execute(self.spec.case(case), self.fleet, self.spec, self.directory.name, negative)

    def test_the_first_case_passes_when_the_effect_is_real(self):
        result, receipt = self.execute()
        self.assertEqual(result['verdict'], 'passed', result)
        self.assertNotIn('cause', result)
        self.assertEqual([step['step'] for step in receipt['steps'] if not step['ok']], [])
        self.assertTrue(any('firewall:allow' in call for call in map(' '.join, self.fleet.cli_calls)))

    def test_what_a_case_started_on_a_node_ends_with_the_case(self):
        self.spec.case(CASE).setup.append(spec.Step('busy_node', {'on': 'app-dev'}))
        result, _ = self.execute()
        self.assertEqual((self.fleet.stalled, self.fleet.shim, self.fleet.background[0].running), (set(), set(), False), result)

    def test_partial_application_has_working_tcp_and_blocked_udp_before_the_action(self):
        run = runner.Run(self.fleet, self.spec, self.directory.name)

        runner.run_steps(run, self.spec.case('partial-application').setup, 'setup', [], float('inf'))

        port = int(run.names['port'])
        self.assertEqual(self.fleet.connect('gateway', BRIDGE['app-dev'], port, 'tcp', 4), 0)
        self.assertNotEqual(self.fleet.connect('gateway', BRIDGE['app-dev'], port, 'udp', 4), 0)
        self.assertFalse(any(call and call[0] == 'firewall:allow' for call in self.fleet.cli_calls))

    def test_it_uses_the_address_the_kernel_route_gives(self):
        self.execute()
        allow = next(call for call in self.fleet.cli_calls if call[0] == 'firewall:allow')
        self.assertIn('--from=' + BRIDGE['gateway'], allow)
        self.assertIn('--node=211', allow)

    def test_a_command_that_changes_no_rule_fails_on_assertion_and_names_every_check(self):
        self.fleet.allow_effect = False
        result, _ = self.execute()
        self.assertEqual((result['verdict'], result['cause']), ('failed', 'assertion'))
        failed = ' '.join(result['failed'])
        self.assertIn('expect[0] probe gateway-connects: gateway to', failed)
        self.assertIn('expect[4] ufw', failed)

    def test_a_failed_operation_fails_the_act_and_the_expect_still_runs(self):
        self.fleet.allow_status = 'failed'
        result, receipt = self.execute()
        self.assertEqual(result['cause'], 'assertion')
        self.assertTrue(result['failed'][0].startswith('act[0] cli firewall:allow: exit 1'))
        self.assertTrue(any(step['step'].startswith('expect') for step in receipt['steps']))

    def test_operation_diagnostics_survive_setup_receipts_and_ci_verdict_lines(self):
        case = self.spec.case(CASE)
        case.setup.append(spec.Step('cli', {'run': ['firewall:allow', '80'], 'expect': {'exit': 0}}))
        operation = {'id': 17, 'status': 'failed', 'failed_step': 'Converge input', 'error_code': 'operation.native_failed',
                     'error': {'detail': 'private-error-sentinel'}}
        original = self.fleet.cli

        def cli(args, **kwargs):
            if args[0] == 'firewall:allow':
                return {'rc': 1, 'json': operation, 'stdout': json.dumps(operation), 'stderr': ''}
            return original(args, **kwargs)

        with patch.object(self.fleet, 'cli', side_effect=cli):
            result = runner.run_case(case, self.fleet, self.spec, Path(self.directory.name))
        receipt = json.loads(next(Path(self.directory.name).glob(CASE + '/*/receipt.json')).read_text())
        self.assertEqual((result['verdict'], result['cause']), ('unrun', 'precondition'))
        for failures in (receipt['failed'], json.loads(runner.verdict_line(result))['failed']):
            self.assertIn("failed_step='Converge input', error_code='operation.native_failed'", failures[0])
            self.assertNotIn('private-error-sentinel', failures[0])

    def test_a_port_that_is_not_blocked_before_the_command_is_unrun_not_a_pass(self):
        self.fleet.deny_ignored = True
        result, _ = self.execute()
        self.assertEqual((result['verdict'], result['cause']), ('unrun', 'precondition'))
        self.assertIn('setup: probe', result['failed'][0])
        self.assertFalse(any(call and call[0] == 'firewall:allow' for call in self.fleet.cli_calls))

    def test_a_cli_that_cannot_run_is_infrastructure(self):
        self.fleet.failing_cli = 'firewall:allow'
        result, _ = self.execute()
        self.assertEqual((result['verdict'], result['cause']), ('failed', 'infrastructure'))
        self.assertIn('the CLI did not start', result['failed'][-1])

    def test_a_step_that_is_not_built_fails_the_case_as_infrastructure(self):
        with patch.dict(steps.FUNCTIONS, {'doctor': steps.unbuilt('doctor')}):
            result, _ = self.execute()
        self.assertEqual((result['verdict'], result['cause']), ('failed', 'infrastructure'))
        self.assertIn('is not built', result['failed'][-1])

    def test_the_case_timeout_stops_the_run(self):
        self.spec.case(CASE).timeout = -1
        result, _ = self.execute()
        self.assertEqual((result['verdict'], result['cause']), ('failed', 'infrastructure'))
        self.assertIn('timeout', result['failed'][-1])

    def test_the_negative_run_passes_only_when_the_named_check_fails(self):
        result, receipt = self.execute(negative=True)
        self.assertEqual(result['verdict'], 'passed', result)
        self.assertTrue(result['negative'])
        self.assertTrue(any(step['step'].startswith('negative') for step in receipt['steps']))

    def test_the_negative_run_fails_when_the_break_changes_nothing_that_is_checked(self):
        case = self.spec.case(CASE)
        case.negative['must_fail'] = ['nothing']
        result, _ = self.execute(negative=True)
        self.assertEqual((result['verdict'], result['cause']), ('failed', 'assertion'))
        self.assertIn('did not fail', result['failed'][0])

    def test_a_negative_run_of_a_case_without_negative_is_refused(self):
        result, _ = self.execute('conflict', negative=True)
        self.assertEqual(result['cause'], 'infrastructure')

    def test_the_doctor_wait_is_one_sleep_on_the_target_node(self):
        patch.stopall()
        self.spec.case(CASE).stages[0].expect[-1].args['drift'] = 0
        with patch.object(runner.time, 'time', return_value=1000.0):
            runner.Run(self.fleet, self.spec, self.directory.name).sleep(64.2)
        self.assertEqual(self.fleet.commands[-1], ('app-dev', 'sleep 65'))

    def test_the_receipt_and_the_command_output_go_to_the_evidence_directory(self):
        self.execute()
        files = {path.name for path in Path(self.directory.name).iterdir()}
        self.assertTrue({'cli-1.json', 'cli-2.json'} <= files)
        self.assertTrue(any(name.startswith('ufw-app-dev') for name in files))


class VerdictLineTest(unittest.TestCase):
    def test_a_short_line_is_plain_json(self):
        line = runner.verdict_line({'id': 'a', 'verdict': 'passed', 'secs': 1.5})
        self.assertEqual(json.loads(line), {'id': 'a', 'verdict': 'passed', 'secs': 1.5})

    def test_many_long_failures_stay_under_1_kb_and_are_counted(self):
        failed = [f'expect[{n}] ' + 'x' * 400 for n in range(30)]
        line = runner.verdict_line({'id': 'a', 'verdict': 'failed', 'cause': 'assertion', 'secs': 2, 'failed': failed})
        self.assertLessEqual(len(line), 1000)
        document = json.loads(line)
        self.assertEqual(document['id'], 'a')
        self.assertRegex(document['failed'][-1], r'^\+\d+ more$')
        self.assertTrue(document['failed'][0].startswith('expect[0]'))


class CommandTest(unittest.TestCase):
    def call(self, argv):
        out, err = io.StringIO(), io.StringIO()
        with contextlib.redirect_stdout(out), contextlib.redirect_stderr(err):
            code = runner.main(argv)
        return code, out.getvalue(), err.getvalue()

    def test_plan_prints_the_batches_and_cases_without_a_fleet(self):
        code, out, _ = self.call(['plan', '--file', str(CASES)])
        self.assertEqual(code, 0)
        self.assertLess(out.index('batch fresh-fleet'), out.index('batch dual-stack'))
        self.assertIn('  new-restricted-allow', out)
        self.assertIn('  protocols[udp]', out)

    def test_json_plan_exposes_the_expanded_definition_without_diagnostic_locations(self):
        code, out, _ = self.call(['plan', '--json', '--file', str(CASES)])
        self.assertEqual(code, 0)
        definitions = json.loads(out)
        self.assertIn('new-restricted-allow', definitions)
        case = definitions['new-restricted-allow']['case']
        self.assertEqual(case['id'], 'new-restricted-allow')
        self.assertNotIn('where', case['stages'][0]['act'][0])
        self.assertIn('args', case['stages'][0]['act'][0])

    def test_plan_with_steps_prints_the_resolved_steps(self):
        _, out, _ = self.call(['plan', '--file', str(CASES), '--steps'])
        self.assertIn('listener {"family": 4, "on": "app-dev", "ports": ["port", "control"], "proto": ["tcp"]}', out)

    def test_a_refused_file_exits_3_and_says_why(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / 'bad.yaml'
            path.write_text('command: x\n')
            code, _, err = self.call(['plan', '--file', str(path)])
        self.assertEqual(code, 3)
        self.assertIn('cases file refused', err)

    def test_case_prints_one_verdict_line_and_exits_with_the_verdict(self):
        fleet = FakeFleet()
        with tempfile.TemporaryDirectory() as directory, patch.object(fleet_module, 'from_environment', lambda: fleet), \
                patch.object(runner.Run, 'sleep', lambda self, seconds: None):
            code, out, err = self.call(['case', CASE, '--file', str(CASES), '--evidence', directory])
            self.assertEqual(len(list(Path(directory).glob(CASE + '/*/receipt.json'))), 1)
        self.assertEqual(code, 0)
        self.assertEqual(json.loads(out)['verdict'], 'passed')
        self.assertEqual(len(out.strip().splitlines()), 1)
        self.assertIn('evidence', err)

    def test_line_plan_uses_the_original_arguments_and_preserves_whole_batches(self):
        loaded = runner.spec_module.load(CASES, runner.BATCHES)
        code, out, err = self.call(['plan', '--lines', '--file', str(CASES)])
        self.assertEqual(code, 0, err)
        batches = {line.split()[0]: set(line.split()[2:]) for line in out.strip().splitlines()}
        self.assertEqual(set(batches), {case.batch for case in loaded.cases})
        for batch, cases in batches.items():
            self.assertEqual(cases, {case.id for case in loaded.cases if case.batch == batch})

    def test_an_unknown_case_is_refused(self):
        code, _, err = self.call(['case', 'nope', '--file', str(CASES)])
        self.assertEqual(code, 3)
        self.assertIn('unknown case', err)

    def test_a_fleet_that_cannot_be_found_is_infrastructure(self):
        def missing():
            raise fleet_module.FleetError('expected one complete nested fleet')
        with patch.object(fleet_module, 'from_environment', missing):
            code, out, _ = self.call(['case', CASE, '--file', str(CASES)])
        self.assertEqual(code, 1)
        self.assertEqual(json.loads(out)['cause'], 'infrastructure')


class FleetCliTest(unittest.TestCase):
    def test_the_default_caller_runs_the_candidate_cli_of_the_checkout_on_the_outer_vm(self):
        found = fleet_module.Fleet({'app-prod': {'instance': 'nested-app-prod', 'node_id': 3, 'bridge': '10.0.0.3'}})
        done = Mock(returncode=0, stdout='{}', stderr='')
        with patch.object(fleet_module.subprocess, 'run', return_value=done) as run:
            found.cli(['firewall:list'])
        self.assertEqual(run.call_args_list[0].args[0][:2], ['php', str(Path(fleet_module.__file__).resolve().parents[3] / 'apps/cli/orbit')])
        self.assertEqual(run.call_args.kwargs['env']['COLUMNS'], '240')

    def test_operation_list_filters_on_the_api_with_the_pinned_nested_ca(self):
        with patch.object(fleet_module.subprocess, 'run', return_value=completed(out='{"data":[{"id":8}]}')) as call:
            result = fleet_module.Fleet({}).operations('firewall.allow', 'node_firewall', 211)
        argv = call.call_args.args[0]
        url = urlparse(argv[-1])
        self.assertEqual((url.scheme, url.netloc, url.path), ('https', '10.45.0.1', '/api/v1/operations'))
        self.assertEqual(parse_qs(url.query), {'kind': ['firewall.allow'], 'target_type': ['node_firewall'], 'target_id': ['211']})
        self.assertIn('--fail-with-body', argv)
        self.assertEqual(argv[argv.index('--cacert') + 1], fleet_module.OUTER_CA)
        self.assertEqual(result['json'], {'data': [{'id': 8}]})

    def test_an_unfiltered_operation_audit_sends_no_filter_values(self):
        with patch.object(fleet_module.subprocess, 'run', return_value=completed(out='{"data":[]}')) as call:
            fleet_module.Fleet({}).operations()
        self.assertEqual(urlparse(call.call_args.args[0][-1]).query, '')


class NodeCliTest(unittest.TestCase):
    """The real Fleet runs the tested CLI on a Node: Nothing installs an `orbit` binary on a nested Node, so a bare `orbit` ended in exit 127."""

    def setUp(self):
        self.fleet = fleet_module.Fleet({'app-prod': {'instance': 'nested-app-prod', 'node_id': 212, 'bridge': '10.0.0.3'}})
        self.calls = []

    def script(self, mounted=True, profile=True, failing=None):
        """A `subprocess.run` that answers the incus calls of one Node and records their words."""
        def run(argv, **kwargs):
            self.calls.append(list(argv))
            text = ' '.join(argv)
            if failing and failing in text:
                return completed(1, '', 'it failed')
            if argv[:4] == ['incus', 'config', 'device', 'get']:
                return completed(0 if mounted else 1, str(fleet_module.CHECKOUT) + '\n' if mounted else '')
            if argv[-3:-1] == ['-q', '"nested"']:   # the check of the profile: grep in config.json
                return completed(0 if profile else 1)
            return completed(0, '{"nodes": []}')
        return run

    def last(self):
        return self.calls[-1]

    def test_runs_the_checkout_cli_as_the_managed_user_of_the_node_and_never_a_bare_orbit(self):
        with patch.object(fleet_module.subprocess, 'run', self.script()):
            result = self.fleet.cli(['firewall:list', '--node=211'], node='app-prod')
        self.assertEqual(self.last(), ['incus', 'exec', 'nested-app-prod', '--user', '1000', '--cwd', '/home/orbit', '--env', 'HOME=/home/orbit', '--env', 'COLUMNS=240', '--',
                                        'php', '/home/orbit/orbit/apps/cli/orbit', 'firewall:list', '--node=211', '--json'])
        self.assertEqual(result['rc'], 0)
        self.assertNotIn('orbit', [call[call.index('--') + 1] for call in self.calls if '--' in call])

    def test_a_node_that_is_ready_gets_no_mount_and_no_profile(self):
        with patch.object(fleet_module.subprocess, 'run', self.script()):
            self.fleet.cli(['firewall:list'], node='app-prod')
        words = [' '.join(call) for call in self.calls]
        self.assertFalse(any('device add' in line or 'file push' in line or 'gateway:add' in line for line in words))

    def test_a_fresh_node_gets_the_checkout_the_nested_ca_and_a_profile_before_the_call(self):
        with patch.object(fleet_module.subprocess, 'run', self.script(mounted=False, profile=False)):
            self.fleet.cli(['firewall:list'], node='app-prod')
        words = [' '.join(call) for call in self.calls]
        mount = next(number for number, line in enumerate(words) if 'device add nested-app-prod checkout disk' in line)
        push = next(number for number, line in enumerate(words) if 'file push /home/orbit/.orbit/nested-ca.pem nested-app-prod/home/orbit/.orbit/nested-ca.pem' in line)
        profile = next(number for number, line in enumerate(words) if 'gateway:add 10.45.0.1 --name=nested --use --accept-ca-change --ca=/home/orbit/.orbit/nested-ca.pem' in line)
        self.assertLess(mount, push)
        self.assertLess(push, profile)
        self.assertLess(profile, len(words) - 1)
        self.assertIn(f'source={fleet_module.CHECKOUT}', self.calls[mount])
        self.assertIn('path=/home/orbit/orbit', self.calls[mount])

    def test_a_step_that_fails_is_named_and_not_an_exit_status(self):
        for what, options in (('cannot mount the tested checkout', {'mounted': False, 'failing': 'device add'}),
                              ('cannot give the nested CA', {'profile': False, 'failing': 'file push'}),
                              ('cannot add the profile nested', {'profile': False, 'failing': 'gateway:add'})):
            with self.subTest(what), patch.object(fleet_module.subprocess, 'run', self.script(**options)):
                with self.assertRaises(fleet_module.FleetError) as caught:
                    self.fleet.cli(['firewall:list'], node='app-prod')
            self.assertIn(what, str(caught.exception))
            self.assertIn('app-prod', str(caught.exception))


class FleetDiscoveryTest(unittest.TestCase):
    def guest(self, name, address='10.0.0.5', status='Running'):
        return {'name': name, 'status': status, 'state': {'network': {'lo': {'addresses': [{'family': 'inet', 'scope': 'local', 'address': '127.0.0.1'}]},
                'orbit': {'addresses': [{'family': 'inet', 'scope': 'global', 'address': '10.45.0.2'}]},
                'enp5s0': {'addresses': [{'family': 'inet', 'scope': 'global', 'address': address}]}}}}

    def test_discover_finds_the_guests_by_the_names_of_the_fleet_child(self):
        guests = [self.guest(f'nested-{role}', f'10.0.0.{n}') for n, role in enumerate(('gateway', 'app-dev', 'app-prod'), 1)]
        guests.append(self.guest('fleet-ready-gateway', status='Stopped'))
        guests.append(self.guest('orbit-e2e-task-3187-aa-gateway'))
        found = fleet_module.discover(guests=guests, node_ids={'gateway': 1, 'app-dev': 2, 'app-prod': 3})
        self.assertEqual(found.nodes['app-dev'], {'instance': 'nested-app-dev', 'node_id': 2, 'bridge': '10.0.0.2'})

    def test_a_stopped_or_missing_guest_is_refused(self):
        guests = [self.guest('nested-gateway'), self.guest('nested-app-dev', status='Stopped'), self.guest('nested-app-prod')]
        with self.assertRaises(fleet_module.FleetError):
            fleet_module.discover(guests=guests, node_ids={'gateway': 1, 'app-dev': 2, 'app-prod': 3})
        with self.assertRaises(fleet_module.FleetError):
            fleet_module.discover(guests=[self.guest('nested-gateway')], node_ids={})


if __name__ == '__main__':
    unittest.main()
