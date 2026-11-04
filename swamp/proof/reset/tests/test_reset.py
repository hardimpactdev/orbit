"""Fast mechanics regressions. Live resets are separate acceptance evidence."""
import hashlib
import json
import multiprocessing
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import time
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
        with patch.object(phases, 'run', side_effect=[b'[{"name":"unrelated","status":"Stopped","config":null}]', b'[{"name":"enp5s0","config":null}]']):
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
            with patch.object(phases, 'inventory', return_value={}), patch.object(phases, 'run', side_effect=subprocess.TimeoutExpired('incus', 30)), patch.object(phases, 'release', return_value={'released': True}) as release:
                value = phases.execute(request, 'failure-cleanup')
            release.assert_called_once_with(request, request['generation'], 'cleanup-failed')
            self.assertEqual({'released': True}, value)
            self.assertIn('timed out', json.loads((root / 'failure-kernel.json').read_text())['gateway'])

    def test_gateway_memory_change_requires_complete_generation_ownership(self):
        prepared = {'construction': {'issue': 'TASK-3187', 'attempt_id': 'attempt', 'nodes': {'gateway': {'instance': 'owned'}}}, 'source': {'operation_id': 'operation'}}
        config = {'user.orbit.e2e.' + key: value for key, value in {'owner': 'orbit-e2e', 'issue': 'TASK-3187', 'attempt': 'attempt', 'operation': 'operation'}.items()}
        with patch.object(phases, 'run', return_value=json.dumps({'config': config}).encode()) as run, patch.object(phases, 'harness') as harness:
            value = phases.gateway_resources({}, prepared, 'source')
            self.assertEqual('2560MiB', value['memory'])
            self.assertEqual([['incus', 'stop', 'owned'], ['incus', 'config', 'set', 'owned', 'limits.memory=2560MiB'], ['incus', 'start', 'owned']], [c.args[0] for c in run.call_args_list[1:]])
            harness.assert_called_once_with({}, 'wait-agent', 'source', guest='owned')
        for key in config:
            with patch.object(phases, 'run', return_value=json.dumps({'config': {**config, key: 'foreign'}}).encode()) as run:
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
    def test_reset_and_verification_hold_same_lock_and_failure_is_not_green(self):
        with tempfile.TemporaryDirectory() as temporary:
            home = Path(temporary)
            key = home / '.config/swamp/club-api-key'
            key.parent.mkdir(parents=True)
            key.write_text('unit-test-not-a-real-key')
            key.chmod(0o600)
            events = home / 'events'
            def child(action):
                def fake_run(argv, **kwargs):
                    if argv[0] == '/usr/local/bin/swamp':
                        if argv[1] == 'init':
                            controller = Path(argv[2])
                            controller.mkdir(parents=True)
                            (controller / '.swamp.yaml').write_text('test')
                        if argv[1:3] == ['workflow', 'run']:
                            with events.open('a') as log:
                                log.write(action + ' start\n')
                            time.sleep(.2)
                            with events.open('a') as log:
                                log.write(action + ' end\n')
                            return subprocess.CompletedProcess(argv, 17 if action == 'verify' else 0)
                    return subprocess.CompletedProcess(argv, 0)
                args = ['bin/proof-fleet', action, '999999'] + (['bin/proof-fleet'] if action == 'verify' else [])
                with patch.object(Path, 'home', return_value=home), patch.object(sys, 'argv', args), patch.object(subprocess, 'run', side_effect=fake_run):
                    code = launcher.main()
                    assert code == (17 if action == 'verify' else 0)
            candidate = Path(launcher.__file__).resolve().parents[3]
            existing = set((candidate / '.orbit-artifacts/999999/reset').glob('*/status.json'))
            context = multiprocessing.get_context('fork')
            first, second = context.Process(target=child, args=('reset',)), context.Process(target=child, args=('verify',))
            first.start()
            deadline = time.monotonic() + 5
            while not events.exists() and time.monotonic() < deadline:
                time.sleep(.01)
            self.assertTrue(events.exists(), 'first launcher did not reach its native run')
            second.start()
            first.join(5)
            second.join(5)
            self.assertEqual((0, 0), (first.exitcode, second.exitcode))
            self.assertEqual(['reset start', 'reset end', 'verify start', 'verify end'], events.read_text().splitlines())
            statuses = [json.loads(p.read_text()) for p in set((candidate / '.orbit-artifacts/999999/reset').glob('*/status.json')) - existing]
            self.assertEqual(2, len(statuses))
            self.assertTrue(any(s['status'] == 'failed' and s['exit'] == 17 and not s['cases_ran'] for s in statuses))


if __name__ == '__main__':
    unittest.main()
