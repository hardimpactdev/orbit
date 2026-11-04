"""Fast mechanics regressions. Live resets are separate acceptance evidence."""
import hashlib
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest
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
            self.assertEqual([['stop', 'owned'], ['config', 'set', 'owned', 'limits.memory=2560MiB'], ['start', 'owned']], [c.args[0] for c in run.call_args_list[1:]])
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
                self.assertEqual(17 if action == 'verify' else 0, code)
                status = json.loads((Path(request['evidence']) / 'status.json').read_text())
                self.assertEqual(code, status['exit'])
                self.assertFalse(status['cases_ran'])
                self.assertEqual('failed' if code else 'succeeded', status['status'])
            self.assertEqual(3, len(owners))
            self.assertTrue(all(owner == owners[0] for owner in owners))
            self.assertEqual(['@command/shell', 'execute', 'proof-owner'], owners[0][0])
            self.assertTrue(any(c[:2] == ['report', 'get'] for c in calls))

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


if __name__ == '__main__':
    unittest.main()
