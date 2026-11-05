"""Fast mechanics regressions. Live resets are separate acceptance evidence."""
import io
import json
import os
import subprocess
import sys
import tempfile
import unittest
from contextlib import redirect_stdout
from pathlib import Path
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
import launcher
import phases


class CandidateTests(unittest.TestCase):
    def test_dirty_export_preserves_real_index_head_and_ignored_runtime(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            candidate = root / 'candidate'
            candidate.mkdir()
            def git(*args):
                return subprocess.check_output(['git', '-C', str(candidate), *args], stderr=subprocess.DEVNULL)
            git('init', '-q')
            git('config', 'user.email', 'fixture@example.invalid')
            git('config', 'user.name', 'Fixture')
            (candidate / '.gitignore').write_text('.env\nvendor/\n')
            (candidate / 'source').write_text('base')
            git('add', '.')
            git('commit', '-qm', 'temporary test fixture')
            (candidate / 'source').write_text('staged')
            git('add', 'source')
            (candidate / 'source').write_text('effective unstaged')
            (candidate / 'new').write_text('untracked candidate')
            (candidate / '.env').write_text('never export this secret')
            for package in ('apps/e2e', 'apps/gateway', 'apps/cli', 'packages/php-sdk'):
                vendor = candidate / package / 'vendor'
                vendor.mkdir(parents=True)
                (vendor.parent / 'composer.json').write_text('{}')
                (vendor / 'autoload.php').write_text('<?php // private copy')
            before = phases.identity(str(candidate))
            evidence = root / 'evidence'
            evidence.mkdir()
            launcher.write_json(evidence / 'inventory.json', {'candidate': before})
            request = {'candidate': str(candidate), 'group': '3187', 'root': str(root / 'owned'),
                       'generation': 'a' * 32, 'evidence': str(evidence)}
            result = phases.snapshot(request)
            exported = Path(result['source'])
            self.assertEqual(before, phases.identity(str(candidate)))
            self.assertEqual('effective unstaged', (exported / 'source').read_text())
            self.assertEqual('untracked candidate', (exported / 'new').read_text())
            self.assertFalse((exported / '.env').exists())
            (exported / 'apps/gateway/vendor/autoload.php').write_text('mutated')
            self.assertIn('private copy', (candidate / 'apps/gateway/vendor/autoload.php').read_text())
            self.assertEqual(before['effective_tree'], phases.identity(str(exported))['effective_tree'])

    def test_missing_fact_kind_or_old_generation_cannot_be_ready(self):
        facts = [{'node_id': node, 'kind': kind, 'enabled': True, 'received_at': '2026-11-04T17:00:00+00:00'}
                 for node in (209, 210, 211) for kind in ('host', 'ufw', 'units')]
        self.assertTrue(phases.fresh_facts({'facts': facts}, [209, 210, 211], 0))
        self.assertFalse(phases.fresh_facts({'facts': facts[:-1]}, [209, 210, 211], 0))
        self.assertFalse(phases.fresh_facts({'facts': facts}, [209, 210, 211], 9999999999))


class OwnershipTests(unittest.TestCase):
    def setUp(self):
        # Release stops the outer VM's tunnel; these tests must never reach systemctl.
        patcher = patch.object(phases, 'stop_operator_tunnel')
        patcher.start()
        self.addCleanup(patcher.stop)

    def test_generation_paths_reject_foreign_owner_and_symlinks(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            request = {'root': str(root), 'candidate': '/candidate', 'group': '3187'}
            directory = root / 'generations' / ('a' * 32)
            (directory / 'source').mkdir(parents=True)
            launcher.write_json(directory / 'owner.json', {'candidate': '/other', 'group': '3187'})
            with self.assertRaisesRegex(RuntimeError, 'owner'):
                phases.source_for(request, 'a' * 32)
            launcher.write_json(directory / 'owner.json', {'candidate': '/candidate', 'group': '3187'})
            self.assertEqual(directory / 'source', phases.source_for(request, 'a' * 32))
            (directory / 'source').rmdir()
            (directory / 'source').symlink_to(root, target_is_directory=True)
            with self.assertRaisesRegex(RuntimeError, 'real directory'):
                phases.source_for(request, 'a' * 32)

    def test_cleanup_refuses_unrecorded_issue_resources_without_losing_active_identity(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            request = {'root': str(root), 'candidate': '/candidate', 'group': '3187',
                       'issue': 'TASK-3187', 'generation': 'a' * 32, 'evidence': str(root)}
            source = root / 'source'
            source.mkdir()
            launcher.write_json(root / 'active.json', {'generation': 'a' * 32})
            resources = {'guests': [{'name': 'do-not-delete', 'metadata': {'user.orbit.e2e.issue': 'TASK-3187'}}], 'networks': []}
            with patch.object(phases, 'source_for', return_value=source), patch.object(phases, 'inventory', return_value=resources), patch.object(phases, 'harness') as harness:
                with self.assertRaisesRegex(RuntimeError, 'refusing cleanup'):
                    phases.release(request, 'a' * 32, 'cleanup')
                harness.assert_not_called()
            self.assertEqual('a' * 32, phases.active_generation(request))

    def test_failure_cleanup_never_releases_previous_generation(self):
        with tempfile.TemporaryDirectory() as temporary:
            request = {'root': temporary, 'generation': 'b' * 32, 'evidence': temporary}
            launcher.write_json(Path(temporary) / 'active.json', {'generation': 'a' * 32})
            with patch.object(phases, 'inventory', return_value={}), patch.object(phases, 'release') as release:
                result = phases.execute(request, 'failure-cleanup')
                release.assert_not_called()
            self.assertEqual('not_started', result['status'])
            self.assertTrue((Path(temporary) / 'failure-inventory.json').exists())

    def test_unmanaged_null_configuration_has_no_ownership(self):
        with patch.object(phases, 'incus', side_effect=[b'[{"name":"unrelated","status":"Stopped","config":null}]', b'[{"name":"enp5s0","config":null}]']):
            observed = phases.inventory()
        self.assertEqual({}, observed['guests'][0]['metadata'])
        self.assertEqual({}, observed['networks'][0]['metadata'])

    def test_inventory_diagnostic_timeout_does_not_skip_owned_release(self):
        with tempfile.TemporaryDirectory() as temporary:
            request = {'root': temporary, 'generation': 'b' * 32, 'evidence': temporary}
            launcher.write_json(Path(temporary) / 'active.json', {'generation': request['generation']})
            with patch.object(phases, 'inventory', side_effect=subprocess.TimeoutExpired('incus', 30)), patch.object(phases, 'release', return_value={'released': True}) as release:
                phases.execute(request, 'failure-cleanup')
            release.assert_called_once_with(request, request['generation'], 'cleanup-failed')
            self.assertIn('timed out', json.loads((Path(temporary) / 'failure-inventory.json').read_text())['error'])

    def test_diagnostic_timeout_does_not_skip_owned_failure_release(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            request = {'root': temporary, 'generation': 'b' * 32, 'evidence': temporary}
            launcher.write_json(root / 'active.json', {'generation': request['generation']})
            launcher.write_json(root / 'prepare.json', {'construction': {'nodes': {'gateway': {'instance': 'owned'}}}})
            with patch.object(phases, 'inventory', return_value={}), patch.object(phases, 'incus', side_effect=subprocess.TimeoutExpired('incus', 30)), patch.object(phases, 'release', return_value={'released': True}) as release:
                value = phases.execute(request, 'failure-cleanup')
            release.assert_called_once_with(request, request['generation'], 'cleanup-failed')
            self.assertEqual({'released': True}, value)
            self.assertIn('timed out', json.loads((root / 'failure-kernel.json').read_text())['gateway'])

    def test_gateway_memory_change_requires_complete_generation_ownership(self):
        prepared = {'construction': {'issue': 'TASK-3187', 'attempt_id': 'attempt', 'nodes': {'gateway': {'instance': 'owned'}}}, 'source': {'operation_id': 'operation'}}
        config = {'user.orbit.e2e.' + key: value for key, value in {'owner': 'orbit-e2e', 'issue': 'TASK-3187', 'attempt': 'attempt', 'operation': 'operation'}.items()}
        with patch.object(phases, 'incus', return_value=json.dumps({'config': config}).encode()) as run, patch.object(phases, 'harness') as harness:
            value = phases.gateway_resources({}, prepared, 'source')
            self.assertEqual('2560MiB', value['memory'])
            self.assertEqual([['stop', 'owned'], ['config', 'set', 'owned', 'limits.memory=2560MiB', 'limits.cpu=2'], ['start', 'owned']], [c.args[0] for c in run.call_args_list[1:]])
            harness.assert_called_once_with({}, 'wait-agent', 'source', guest='owned')
        for key in config:
            with patch.object(phases, 'incus', return_value=json.dumps({'config': {**config, key: 'foreign'}}).encode()) as run:
                with self.assertRaisesRegex(RuntimeError, 'foreign Gateway'):
                    phases.gateway_resources({}, prepared, 'source')
                self.assertEqual(1, run.call_count)

    def test_successive_release_records_exact_generations_and_failed_release_keeps_lease(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            request = {'root': temporary, 'evidence': temporary}
            source = root / 'source'
            (source / '.e2e').mkdir(parents=True)
            (source / '.e2e/attempt.json').write_text('{}')
            for generation in ('a' * 32, 'b' * 32):
                launcher.write_json(root / 'active.json', {'generation': generation})
                with patch.object(phases, 'source_for', return_value=source), patch.object(phases, 'harness', return_value={'released': ['owned-only']}):
                    receipt = phases.release(request, generation, 'cleanup-' + generation)
                self.assertEqual(generation, receipt['generation'])
                self.assertIsNone(phases.active_generation(request))
                self.assertEqual(receipt, json.loads((root / ('cleanup-' + generation + '.json')).read_text()))
            launcher.write_json(root / 'active.json', {'generation': 'c' * 32})
            with patch.object(phases, 'source_for', return_value=source), patch.object(phases, 'harness', side_effect=RuntimeError('foreign resource')):
                with self.assertRaisesRegex(RuntimeError, 'foreign resource'):
                    phases.release(request, 'c' * 32, 'refused')
            self.assertEqual('c' * 32, phases.active_generation(request))


class LauncherTests(unittest.TestCase):
    def test_all_actions_use_one_native_owner_and_preserve_inner_failure(self):
        with tempfile.TemporaryDirectory() as temporary:
            home = Path(temporary)
            key = home / '.config/swamp/club-api-key'
            key.parent.mkdir(parents=True)
            key.write_text('unit-test-not-a-real-key')
            key.chmod(0o600)
            calls, owners = [], []

            def native(request, args, name, cwd=None):
                calls.append(args)
                if args[0] == 'init':
                    controller = Path(args[1])
                    controller.mkdir(parents=True)
                    (controller / '.swamp.yaml').write_text('fixture')
                if args[:2] == ['behavior', 'run']:
                    owners.append((args[2:5], request['root']))
                    # This adapter tests routing and receipts, not Swamp's mutex.
                    return launcher.run_locked(request)
                if args[:2] == ['workflow', 'run']:
                    return 17 if request['action'] == 'verify' else 0
                return 0

            for action in ('reset', 'verify', 'release'):
                args = ['bin/proof-fleet', action, '999999'] + (['bin/proof-fleet'] if action == 'verify' else [])
                with patch.object(Path, 'home', return_value=home), patch.object(sys, 'argv', args), patch.object(launcher, 'swamp', side_effect=native) as invoked:
                    code = launcher.main()
                    request = invoked.call_args.args[0]
                # The workflow failed before any case started: the case is unrun and the run exits 1, not the native 17.
                self.assertEqual(1 if action == 'verify' else 0, code)
                self.assertEqual(['bin/proof-fleet'] if action == 'verify' else None, request['cases'])
                self.assertNotIn('case', request)
                status = json.loads((Path(request['evidence']) / 'status.json').read_text())
                self.assertEqual(code, status['exit'])
                self.assertFalse(status['cases_ran'])
                self.assertEqual('cases' in status, action == 'verify')
                self.assertEqual('failed' if code else 'succeeded', status['status'])
            self.assertEqual(3, len(owners))
            self.assertTrue(all(owner == owners[0] for owner in owners))
            self.assertEqual(['@command/shell', 'execute', 'proof-owner'], owners[0][0])
            self.assertTrue(any(c[:2] == ['report', 'get'] for c in calls))

    def verify(self, programs, native, environment=None, group='999999'):
        """Runs main() as `bin/proof-fleet verify GROUP PROGRAM...` against a fake Swamp; returns (code, stdout lines, request)."""
        with tempfile.TemporaryDirectory() as temporary:
            home = Path(temporary)
            key = home / '.config/swamp/club-api-key'
            key.parent.mkdir(parents=True)
            key.write_text('unit-test-not-a-real-key')
            key.chmod(0o600)
            controller = home / '.local/state' / ('orbit-proof-' + group) / 'controller'
            controller.mkdir(parents=True)
            (controller / '.swamp.yaml').write_text('fixture')
            output = io.StringIO()
            with patch.object(Path, 'home', return_value=home), patch.dict(os.environ, environment or {}), \
                    patch.object(sys, 'argv', ['bin/proof-fleet', 'verify', group, *programs]), \
                    patch.object(launcher, 'swamp', side_effect=native) as invoked, redirect_stdout(output):
                code = launcher.main()
            return code, output.getvalue().splitlines(), invoked.call_args_list[0].args[0] if invoked.call_args_list else None

    def test_verify_takes_one_or_more_programs_and_refuses_an_empty_list_and_a_duplicate(self):
        def native(request, args, name, cwd=None):
            raise AssertionError('Swamp must not start for a refused request.')
        with patch.object(sys, 'stderr', io.StringIO()) as error:
            self.assertEqual(2, self.verify([], native)[0])
        self.assertIn('verify GROUP PROGRAM [PROGRAM...]', error.getvalue())
        with self.assertRaisesRegex(RuntimeError, 'listed twice'):
            self.verify(['bin/proof-fleet', 'bin/proof-fleet'], native)
        with self.assertRaisesRegex(RuntimeError, 'listed twice'):
            self.verify(['bin/proof-fleet', './bin/../bin/proof-fleet'], native)
        with self.assertRaisesRegex(RuntimeError, 'inside the candidate'):
            self.verify(['bin/proof-fleet', '../outside.sh'], native)
        with self.assertRaisesRegex(RuntimeError, 'inside the candidate'):
            self.verify(['bin/no-such-program'], native)

    def test_each_program_is_checked_as_nonignored_candidate_source(self):
        with tempfile.TemporaryDirectory() as temporary:
            candidate = Path(temporary).resolve()
            subprocess.run(['git', '-C', str(candidate), 'init', '-q'], check=True)
            (candidate / '.gitignore').write_text('ignored.sh\n')
            for name in ('tracked.sh', 'untracked.sh', 'ignored.sh'):
                (candidate / name).write_text('exit 0\n')
            subprocess.run(['git', '-C', str(candidate), 'add', 'tracked.sh'], check=True)
            self.assertEqual(['tracked.sh', 'untracked.sh'], launcher.validated_programs(candidate, ['tracked.sh', 'untracked.sh']))
            with self.assertRaisesRegex(RuntimeError, 'nonignored'):
                launcher.validated_programs(candidate, ['tracked.sh', 'ignored.sh'])

    def test_a_failed_fleet_leaves_every_selected_program_unrun_and_starts_no_case(self):
        programs = ['bin/proof-fleet', 'swamp/proof/reset/cases/smoke.sh']

        def native(request, args, name, cwd=None):
            if args[:2] == ['behavior', 'run']:
                return launcher.run_locked(request)
            return 1 if args[:2] == ['workflow', 'run'] else 0

        with patch.object(phases, 'run_program', side_effect=AssertionError('no case may start')):
            code, lines, request = self.verify(programs, native)
        self.assertEqual(1, code)
        final = json.loads(lines[-1])
        self.assertEqual({'status', 'summary', 'exit', 'mode', 'seconds', 'cases_ran', 'generation', 'evidence'}, set(final))
        self.assertEqual(('failed', 1, False), (final['status'], final['exit'], final['cases_ran']))
        self.assertEqual([{'program': program, 'verdict': 'unrun', 'exit_code': None, 'seconds': 0,
                           'note': 'the test fleet was not ready'} for program in programs],
                         json.loads((Path(final['evidence']) / 'status.json').read_text())['cases'])
        self.assertEqual([['u', 0], ['u', 0]], final['summary'])
        self.assertEqual(programs, request['cases'])
        self.assertEqual(1800.0, request['budget_seconds'])

    def test_the_budget_comes_from_the_environment_and_must_be_sane(self):
        def native(request, args, name, cwd=None):
            return 0
        self.assertEqual(90.5, self.verify(['bin/proof-fleet'], native, {'PROOF_BUDGET_SECONDS': '90.5'})[2]['budget_seconds'])
        for bad in ('soon', '0', '-5', 'inf', '99999'):
            with self.subTest(bad), self.assertRaisesRegex(RuntimeError, 'PROOF_BUDGET_SECONDS'):
                self.verify(['bin/proof-fleet'], native, {'PROOF_BUDGET_SECONDS': bad})

    def test_native_lock_timeout_does_not_enter_transaction_or_lose_active_lease(self):
        with tempfile.TemporaryDirectory() as temporary:
            home = Path(temporary)
            root = home / '.local/state/orbit-proof-999999'
            controller = root / 'controller'
            controller.mkdir(parents=True)
            (controller / '.swamp.yaml').write_text('fixture')
            launcher.write_json(root / 'active.json', {'generation': 'a' * 32})
            key = home / '.config/swamp/club-api-key'
            key.parent.mkdir(parents=True)
            key.write_text('unit-test-not-a-real-key')
            key.chmod(0o600)
            with patch.object(Path, 'home', return_value=home), patch.object(sys, 'argv', ['bin/proof-fleet', 'reset', '999999']), patch.object(launcher, 'swamp', return_value=75) as native, patch.object(launcher, 'run_locked') as transaction:
                self.assertEqual(75, launcher.main())
                transaction.assert_not_called()
                native.assert_called_once()
                request = native.call_args.args[0]
            self.assertEqual({'generation': 'a' * 32}, json.loads((root / 'active.json').read_text()))
            self.assertEqual({'status': 'admission_failed', 'exit': 75, 'cases_ran': False},
                             json.loads((Path(request['evidence']) / 'status.json').read_text()))


class NativeObservationTests(unittest.TestCase):
    def test_transport_rejects_foreign_operation_before_contacting_guest(self):
        lease = {'construction': {'attempt_id': 'attempt', 'nodes': {'gateway': {'instance': 'owned'}}},
                 'source': {'operation_id': 'operation'}}
        metadata = {'user.orbit.e2e.' + key: value for key, value in {
            'owner': 'orbit-e2e', 'issue': 'TASK-3187', 'attempt': 'attempt', 'operation': 'foreign'}.items()}
        with patch.object(phases, 'prepared', return_value=lease), patch.object(phases, 'inventory', return_value={
            'guests': [{'name': 'owned', 'status': 'Running', 'metadata': metadata}]}), patch.object(phases, 'incus') as contact:
            with self.assertRaisesRegex(RuntimeError, 'ownership mismatch'):
                phases.transport_readiness({'issue': 'TASK-3187'})
            contact.assert_not_called()

    def test_missing_stale_or_disabled_facts_fail_after_exactly_one_observation(self):
        with tempfile.TemporaryDirectory() as temporary:
            evidence = Path(temporary)
            request = {'evidence': temporary}
            launcher.write_json(evidence / 'gateway-db.json', {'nodes': {'gateway': 209, 'app': 210, 'operator': 212}})
            launcher.write_json(evidence / 'prepare.json', {})
            facts = [{'node_id': node, 'kind': kind, 'enabled': True, 'received_at': '2099-01-01T00:00:00+00:00'}
                     for node in (209, 210) for kind in ('host', 'ufw', 'units')]
            states = [{'facts': facts[:-1]}, {'facts': [{**fact, 'received_at': '2000-01-01T00:00:00+00:00'} for fact in facts]},
                      {'facts': [{**fact, 'enabled': False} if fact['kind'] == 'ufw' else fact for fact in facts]}]
            for state in states:
                with patch.object(phases, 'gateway_state', return_value=state) as observe:
                    with self.assertRaisesRegex(RuntimeError, 'Fresh host/ufw/units'):
                        phases.execute(request, 'observer-facts')
                    observe.assert_called_once_with(request)
                self.assertEqual(state, json.loads((evidence / 'facts-not-ready.json').read_text()))
            with patch.object(phases, 'gateway_state', return_value={'facts': facts}) as observe:
                self.assertEqual({'facts': facts}, phases.execute(request, 'observer-facts'))
                observe.assert_called_once_with(request)

    def test_original_timeout_and_failed_cleanup_have_separate_receipts(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            request = {'candidate': '/candidate', 'group': '3187', 'generation': 'a' * 32,
                       'root': temporary, 'evidence': temporary}
            launcher.write_json(root / 'owner.json', {'candidate': '/candidate', 'group': '3187'})
            launcher.write_json(root / 'request.json', request)
            launcher.write_json(root / 'active.json', {'generation': request['generation']})
            with patch.dict(os.environ, {'PROOF_REQUEST': str(root / 'request.json')}):
                with patch.object(sys, 'argv', ['phases.py', 'observer-facts']), patch.object(phases, 'gateway_state', side_effect=subprocess.TimeoutExpired('incus', 120)):
                    launcher.write_json(root / 'gateway-db.json', {'nodes': {'gateway': 209}})
                    launcher.write_json(root / 'prepare.json', {})
                    with self.assertRaises(subprocess.TimeoutExpired):
                        phases.main()
                original = (root / 'observer-facts-failure.json').read_bytes()
                launcher.write_json(root / 'prepare.json', {'construction': {'nodes': {}}})
                with patch.object(sys, 'argv', ['phases.py', 'failure-cleanup']), patch.object(phases, 'inventory', return_value={}), patch.object(phases, 'release', side_effect=RuntimeError('foreign ownership: cleanup refused')):
                    with self.assertRaisesRegex(RuntimeError, 'cleanup refused'):
                        phases.main()
            self.assertIn('timed out', original.decode())
            self.assertEqual(original, (root / 'observer-facts-failure.json').read_bytes())
            self.assertIn('cleanup refused', (root / 'failure-cleanup-failure.json').read_text())
            self.assertEqual(request['generation'], phases.active_generation(request))


class OperatorTests(unittest.TestCase):
    def test_a_foreign_cli_profile_is_never_replaced(self):
        with tempfile.TemporaryDirectory() as temporary:
            home = Path(temporary)
            (home / 'config.json').write_text(json.dumps({'gateways': {'fleet': {}, 'nested': {}}}))
            with patch.dict(phases.CLI_ENV, {'ORBIT_HOME': str(home)}), patch.object(phases, 'prepared', return_value={'construction': {}}), \
                    patch.object(phases, 'install_cli') as install, patch.object(phases, 'local_cli') as cli:
                with self.assertRaisesRegex(RuntimeError, 'fleet'):
                    phases.operator({})
            install.assert_not_called()
            cli.assert_not_called()

    def test_the_wrapper_has_its_own_name_and_leaves_the_real_orbit_alone(self):
        with patch.object(phases, 'privileged') as privileged:
            phases.install_cli({'candidate': '/home/orbit/apps/orbit/workspace'})
        argv = privileged.call_args.args[0]
        self.assertEqual('/usr/local/bin/orbit-nested', argv[-1])
        self.assertNotIn('/usr/local/bin/orbit"', argv[2])
        self.assertIn(b'/home/orbit/apps/orbit/workspace/apps/cli/orbit', privileged.call_args.kwargs['data'])

    def test_release_stops_and_disables_the_operator_tunnel(self):
        with patch.object(phases, 'privileged') as privileged:
            phases.stop_operator_tunnel()
        privileged.assert_called_once_with(['systemctl', 'disable', '--now', 'wg-quick@wg-nested'])
        with patch.object(phases, 'privileged', side_effect=RuntimeError('Failed to disable unit: Unit file wg-quick@wg-nested.service does not exist.')):
            phases.stop_operator_tunnel()  # nothing to stop
        with patch.object(phases, 'privileged', side_effect=RuntimeError('sudo: a password is required')), self.assertRaises(RuntimeError):
            phases.stop_operator_tunnel()

    def test_every_release_path_stops_the_tunnel(self):
        with tempfile.TemporaryDirectory() as temporary, patch.object(phases, 'stop_operator_tunnel') as stop:
            phases.release({'evidence': temporary, 'root': temporary}, None, 'cleanup')
        stop.assert_called_once_with()

    def test_boot_files_reach_each_app_node_before_its_agent_starts(self):
        sys.path.insert(0, str(Path(__file__).resolve().parents[4] / 'tools/swamp-proof-guest'))
        import boot
        import fixture
        order = []
        topology = {'nodes': {'gateway': {'instance': 'g', 'incus_address': '10.232.1.10'},
                              'app-dev': {'instance': 'd', 'incus_address': '10.232.1.11'}, 'operator': {}}}
        reply = json.dumps({'baseline_commands': {}, 'agents': {'gateway': {}, 'app-dev': {'unit': 'u'}}}).encode()

        def incus(args, **kwargs):
            if 'python3' in args:
                return reply
            return b'secret' if args[-1] == '/etc/orbit/agent/secret' else b''
        request = {'candidate': str(Path(__file__).resolve().parents[4]), 'group': '3187', 'generation': 'a' * 32}
        with patch.object(phases, 'prepared', return_value={'construction': topology}), patch.object(phases, 'source_for'), \
                patch.object(phases, 'wireguard', return_value={'nodes': {'gateway': {'ip': '10.45.0.1'}}}), patch.object(phases, 'incus', side_effect=incus), \
                patch.object(boot, 'apply', side_effect=lambda *args, **kwargs: order.append(('boot', args[1:]))), \
                patch.object(fixture, 'install_agent', side_effect=lambda *args, **kwargs: order.append(('agent',))):
            phases.guest(request, 'gateway-runtime')
        self.assertEqual([('boot', ('node', '10.45.0.1')), ('agent',)], order)


if __name__ == '__main__':
    unittest.main()
