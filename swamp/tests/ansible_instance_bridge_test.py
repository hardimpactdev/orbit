"""Exact DNAT resolution and shared boot/attach/converge policy, without Docker or Incus mutations."""
import copy
import hashlib
import importlib.util
import io
import json
import os
import shutil
import socket
import subprocess
import sys
import tempfile
import threading
import unittest
from contextlib import redirect_stderr, redirect_stdout
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

    def api(self, name, value=None, etag=None, deadline=None):
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

    def interleave_writers(self, root, first, second, pause_at):
        paused, resume, attempted = (threading.Event() for _ in range(3))
        failures = []
        original = getattr(bridge, pause_at)
        flock = bridge.fcntl.flock

        def pause(*args, **kwargs):
            if threading.current_thread().name == 'first' and not paused.is_set():
                paused.set()
                if not resume.wait(5):
                    raise AssertionError('First writer was never resumed')
            return original(*args, **kwargs)

        def observe_lock(stream, operation):
            if threading.current_thread().name == 'second':
                attempted.set()
            return flock(stream, operation)

        def run(action):
            try:
                action()
            except BaseException as error:
                failures.append((threading.current_thread().name, str(error)))

        threads = [threading.Thread(target=run, args=(action,), name=name)
                   for name, action in [('first', first), ('second', second)]]
        with patch.object(bridge, pause_at, side_effect=pause), patch.object(bridge.fcntl, 'flock', side_effect=observe_lock):
            threads[0].start()
            try:
                self.assertTrue(paused.wait(5), 'First writer did not reach its record/ACL work')
                with (root / 'writer.lock').open('a') as contender:
                    with self.assertRaises(BlockingIOError):
                        flock(contender, bridge.fcntl.LOCK_EX | bridge.fcntl.LOCK_NB)
                threads[1].start()
                self.assertTrue(attempted.wait(5), 'Second writer did not attempt the shared lock')
                threads[1].join(1)
                self.assertFalse(threads[1].is_alive(), 'Busy writer waited for the holder')
                self.assertEqual(failures, [('second', 'instance.bridge_busy')])
            finally:
                resume.set()
                for thread in threads:
                    if thread.ident is not None:
                        thread.join(5)
            self.assertFalse(any(thread.is_alive() for thread in threads), 'Writer did not release the shared lock')
            self.assertEqual(failures, [('second', 'instance.bridge_busy')])
        # Only a new invocation may proceed after the holder releases; no queued
        # writer survives to apply intent from the failed invocation.
        second()

    def test_refresh_paused_after_read_cannot_resurrect_last_user_after_withdrawal(self):
        incus = Incus()
        with tempfile.TemporaryDirectory() as directory, patch.object(bridge, 'api', side_effect=incus.api):
            root = Path(directory)
            bridge.apply(root, intent(1, '10.44.0.7'), True)
            self.interleave_writers(root, lambda: bridge.apply(root, None),
                                    lambda: bridge.apply(root, intent(2), True, withdraw=True), 'resolve')
            self.assertEqual(list((root / 'endpoints').glob('*.json')), [])
            self.assertEqual(incus.current['egress'], [])
            self.assertEqual(bridge.apply(root, None), 0)
            self.assertEqual(list((root / 'endpoints').glob('*.json')), [])
            self.assertEqual(incus.current['egress'], [])

    def test_busy_refresh_must_be_reinvoked_and_then_reads_post_withdrawal_records(self):
        incus = Incus()
        with tempfile.TemporaryDirectory() as directory, patch.object(bridge, 'api', side_effect=incus.api):
            root = Path(directory)
            docker = {**intent(1), 'endpoints': [{'host': '10.44.0.7', 'port': 3306, 'docker': True}]}
            with patch.object(bridge.subprocess, 'check_output', return_value=nat()):
                bridge.apply(root, docker, True)
            with patch.object(bridge.subprocess, 'check_output', side_effect=AssertionError('Refresh read withdrawn Docker endpoint')):
                self.interleave_writers(root, lambda: bridge.apply(root, intent(2), True, withdraw=True),
                                        lambda: bridge.apply(root, None), 'write_rules')
            self.assertEqual(list((root / 'endpoints').glob('*.json')), [])
            self.assertEqual(incus.current['egress'], [])

    def test_busy_admission_must_be_reinvoked_after_withdrawal_compaction(self):
        incus = Incus()
        with tempfile.TemporaryDirectory() as directory, patch.object(bridge, 'api', side_effect=incus.api):
            root = Path(directory)
            bridge.apply(root, intent(1, '10.44.0.7'), True)
            self.interleave_writers(root, lambda: bridge.apply(root, intent(2), True, withdraw=True),
                                    lambda: bridge.apply(root, intent(3, '10.44.0.7')), 'write_rules')
            self.assertEqual([json.loads(p.read_text())['admitted_at'] for p in (root / 'endpoints').glob('*.json')], [1800000000000003])
            self.assertEqual(incus.current['egress'], [bridge.endpoint_rule('10.44.0.7', 3306, '10.253.0.0/24', 1800000000000003)])
            self.assertEqual(bridge.apply(root, None), 0)

    def test_every_busy_mode_acquires_once_and_mutates_no_records_acl_or_health(self):
        incus = Incus()
        with tempfile.TemporaryDirectory() as directory, patch.object(bridge, 'api', side_effect=incus.api):
            root = Path(directory)
            bridge.apply(root, intent(1, '10.44.0.7'), True)
            before = {str(path): (path.read_bytes(), path.stat().st_ino, path.stat().st_mtime_ns)
                      for path in root.rglob('*') if path.is_file()}
            acl = copy.deepcopy(incus.current)
            with (root / 'writer.lock').open('a') as holder:
                bridge.fcntl.flock(holder, bridge.fcntl.LOCK_EX | bridge.fcntl.LOCK_NB)
                for incoming, reconcile, withdraw in [(intent(2, '10.44.0.8'), False, False),
                                                       (None, False, False), (intent(2), True, True)]:
                    with self.subTest(incoming=incoming, withdraw=withdraw), \
                            patch.object(bridge.fcntl, 'flock', wraps=bridge.fcntl.flock) as acquire, \
                            patch.object(bridge, 'apply_locked') as apply, patch.object(bridge, 'state_file') as write:
                        with self.assertRaisesRegex(bridge.PolicyError, '^instance.bridge_busy$'):
                            bridge.apply(root, incoming, reconcile, withdraw)
                        self.assertEqual(acquire.call_count, 1)
                        self.assertEqual(acquire.call_args.args[1], bridge.fcntl.LOCK_EX | bridge.fcntl.LOCK_NB)
                        apply.assert_not_called()
                        write.assert_not_called()
            self.assertEqual(incus.current, acl)
            self.assertEqual({str(path): (path.read_bytes(), path.stat().st_ino, path.stat().st_mtime_ns)
                              for path in root.rglob('*') if path.is_file()}, before)

    def test_reads_share_remaining_budget_and_curl_keeps_its_request_limit(self):
        for incoming, maximum in [(intent(1), 300), (None, 30)]:
            with self.subTest(maximum=maximum), tempfile.TemporaryDirectory() as directory, \
                    patch.object(bridge, 'api', side_effect=Incus().api):
                root = Path(directory)
                docker = {**intent(1), 'endpoints': [{'host': '10.44.0.7', 'port': 3306, 'docker': True}]}
                with patch.object(bridge.subprocess, 'check_output', return_value=nat()):
                    bridge.apply(root, docker, True)
                with patch.object(bridge.subprocess, 'check_output', return_value=nat()) as read:
                    bridge.apply(root, None if incoming is None else docker)
                    self.assertGreater(read.call_args.kwargs['timeout'], maximum - 1)
                    self.assertLessEqual(read.call_args.kwargs['timeout'], maximum)
        response = bridge.subprocess.CompletedProcess([], 0, b'HTTP/1.1 200 OK\r\nETag: test\r\n\r\n{"metadata":{}}', b'')
        with patch.object(bridge.subprocess, 'run', return_value=response) as run:
            bridge.api('orbitinst0-egress')
            argv = run.call_args.args[0]
            self.assertEqual(argv[argv.index('--max-time') + 1], '5')
            self.assertEqual(run.call_args.kwargs['timeout'], 5)
        with patch.object(bridge.time, 'monotonic', return_value=299), \
                patch.object(bridge.subprocess, 'run', return_value=response) as run:
            bridge.api('orbitinst0-egress', deadline=300)
            self.assertEqual(run.call_args.kwargs['timeout'], 1)

    def test_curl_exit_28_records_the_public_command_timeout_after_readback(self):
        response = bridge.subprocess.CompletedProcess([], 0, b'HTTP/1.1 200 OK\r\nETag: test\r\n\r\n{"metadata":{"egress":[]}}', b'')
        with tempfile.TemporaryDirectory() as directory, \
                patch.object(bridge.subprocess, 'run', side_effect=[bridge.subprocess.CompletedProcess([], 28, b'', b'Operation timed out'), response]):
            root = Path(directory)
            with self.assertRaisesRegex(bridge.PolicyError, 'instance.bridge_command_timeout'):
                bridge.apply(root, intent(1, '10.44.0.7'), True)
            self.assertEqual(json.loads((root / 'health.json').read_text()), {'error_code': 'instance.bridge_command_timeout'})

    def test_first_acl_get_timeout_never_admits_a_staged_record_or_a_later_refresh(self):
        incus = Incus()
        with tempfile.TemporaryDirectory() as directory, patch.object(bridge, 'api', side_effect=incus.api):
            root = Path(directory)
            bridge.apply(root, intent(1), True)
            calls = []

            def timeout_then_read(name, value=None, etag=None, deadline=None):
                calls.append(value)
                if len(calls) == 1:
                    raise bridge.subprocess.TimeoutExpired('curl', 5)
                return incus.api(name, value, etag)

            with patch.object(bridge, 'api', side_effect=timeout_then_read):
                with self.assertRaises(bridge.subprocess.TimeoutExpired):
                    bridge.apply(root, intent(2, '10.44.0.7'), True)
            self.assertEqual(calls, [None, None])  # Exactly one bounded readback; no PUT.
            self.assertEqual(list((root / 'endpoints').glob('*.json')), [])
            self.assertEqual(list((root / '.staged').glob('*.json')), [])
            self.assertEqual(incus.current['egress'], [])
            bridge.apply(root, None)
            self.assertEqual(list((root / 'endpoints').glob('*.json')), [])
            self.assertEqual(incus.current['egress'], [])

    def test_later_ambiguous_put_commits_only_the_endpoints_confirmed_by_readback(self):
        for delivered in [True, False, None]:
            with self.subTest(delivered=delivered), tempfile.TemporaryDirectory() as directory:
                root, incus = Path(directory), Incus()
                with patch.object(bridge, 'api', side_effect=incus.api):
                    bridge.apply(root, intent(1), True)
                puts, readbacks = 0, 0
                ambiguous = False

                def timeout_second_put(name, value=None, etag=None, deadline=None):
                    nonlocal puts, readbacks, ambiguous
                    if value is not None:
                        puts += 1
                        if puts == 2:
                            if delivered is not False:
                                incus.api(name, value, etag)
                            ambiguous = True
                            raise bridge.subprocess.TimeoutExpired('curl', 5)
                    elif ambiguous:
                        readbacks += 1
                        if delivered is None:
                            raise bridge.subprocess.TimeoutExpired('curl', 5)
                    return incus.api(name, value, etag)

                with patch.object(bridge, 'api', side_effect=timeout_second_put):
                    if delivered is True:
                        bridge.apply(root, intent(2, '10.44.0.7', '10.44.0.8'), True)
                    else:
                        expected = bridge.PolicyError if delivered is None else bridge.subprocess.TimeoutExpired
                        with self.assertRaises(expected):
                            bridge.apply(root, intent(2, '10.44.0.7', '10.44.0.8'), True)
                self.assertEqual((puts, readbacks), (2, 1))
                records = {json.loads(path.read_text())['host'] for path in (root / 'endpoints').glob('*.json')}
                self.assertEqual(records, {'10.44.0.7', '10.44.0.8'} if delivered is True else {'10.44.0.7'})
                self.assertEqual(list((root / '.staged').glob('*.json')), [])
                expected_error = None if delivered is True else 'instance.bridge_state_unresolved' if delivered is None else 'instance.bridge_command_timeout'
                self.assertEqual(json.loads((root / 'health.json').read_text())['error_code'], expected_error)
                with patch.object(bridge, 'api', side_effect=incus.api):
                    if delivered is None:
                        missing = {**intent(3), 'endpoints': [{'host': '10.44.0.9', 'port': 3306, 'docker': True}]}
                        with patch.object(bridge.subprocess, 'check_output', return_value=''):
                            with self.assertRaisesRegex(bridge.PolicyError, 'instance.container_endpoint_unresolved'):
                                bridge.apply(root, missing)
                        self.assertEqual(json.loads((root / 'health.json').read_text())['error_code'], 'instance.bridge_state_unresolved')
                    bridge.apply(root, None)
                    if delivered is None:
                        self.assertEqual(json.loads((root / 'health.json').read_text())['error_code'], 'instance.bridge_state_unresolved')
                        bridge.apply(root, intent(3, '10.44.0.7'), True)
                        self.assertEqual(json.loads((root / 'health.json').read_text())['error_code'], None)
                        self.assertEqual([row['destination'] for row in incus.current['egress']], ['10.44.0.7/32'])

    def test_partial_removal_followed_by_refresh_never_resurrects_confirmed_withdrawal(self):
        for delivered, readable in [(False, True), (True, True), (False, False)]:
            with self.subTest(delivered=delivered, readable=readable), tempfile.TemporaryDirectory() as directory:
                root, incus = Path(directory), Incus()
                with patch.object(bridge, 'api', side_effect=incus.api):
                    bridge.apply(root, intent(1, '10.44.0.7', '10.44.0.8', '10.44.0.3'), True)
                    bridge.apply(root, intent(4, '10.44.0.9'))
                failed = False
                readbacks = 0

                def remove_then_timeout(name, value=None, etag=None, deadline=None):
                    nonlocal failed, readbacks
                    if value is not None and not any(row['destination'] == '10.44.0.8/32' for row in value['egress']):
                        self.assertFalse(any(row['destination'] == '10.44.0.7/32' for row in incus.current['egress']))
                        if delivered:
                            incus.api(name, value, etag)
                        failed = True
                        raise bridge.subprocess.TimeoutExpired('curl', 5)
                    if value is None and failed:
                        readbacks += 1
                        if not readable:
                            raise bridge.subprocess.TimeoutExpired('curl', 5)
                    return incus.api(name, value, etag)

                with patch.object(bridge, 'api', side_effect=remove_then_timeout):
                    if delivered:
                        bridge.apply(root, intent(3, '10.44.0.3'), True, withdraw=True)
                    else:
                        error = bridge.subprocess.TimeoutExpired if readable else bridge.PolicyError
                        with self.assertRaises(error):
                            bridge.apply(root, intent(3, '10.44.0.3'), True, withdraw=True)
                self.assertEqual(readbacks, 1)
                expected = {'10.44.0.3', '10.44.0.9'} | (set() if delivered else {'10.44.0.8'})
                health = json.loads((root / 'health.json').read_text())
                self.assertEqual(health['error_code'], None if delivered else
                                 'instance.bridge_command_timeout' if readable else 'instance.bridge_state_unresolved')

                with patch.object(bridge, 'api', side_effect=incus.api):
                    bridge.apply(root, None)
                    self.assertEqual({row['destination'] for row in incus.current['egress']}, {host + '/32' for host in expected})
                    records = {json.loads(path.read_text())['host'] for path in (root / 'endpoints').glob('*.json')}
                    self.assertEqual(records, expected)
                    self.assertEqual(json.loads((root / 'health.json').read_text()), health)
                    bridge.apply(root, intent(5, '10.44.0.3', '10.44.0.9'), True, withdraw=True)
                    self.assertEqual(json.loads((root / 'health.json').read_text()), {'error_code': None})
                    self.assertEqual({row['destination'] for row in incus.current['egress']}, {'10.44.0.3/32', '10.44.0.9/32'})

    @unittest.skipUnless(shutil.which('ansible-playbook'), 'ansible-core 2.20.11 is required for executable proof')
    def test_ansible_partial_withdrawal_then_refresh_keeps_confirmed_removal_and_failure_health(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            source = Path(__file__).parents[1] / 'ansible'
            (root / 'bin').mkdir()
            shutil.copy(Path(__file__).parent / 'fixtures/instance-bridge-curl.py', root / 'bin/curl')
            (root / 'bin/curl').chmod(0o755)
            shutil.copy(source / 'instance_bridge.py', root / 'instance_bridge.py')
            acl = root / 'acl.json'
            acl.write_text(json.dumps({'metadata': Incus().current, 'version': 0}))
            env = {**os.environ, 'PATH': str(root / 'bin') + os.pathsep + os.environ['PATH'],
                   'ORBIT_BRIDGE_FIXTURE': str(root), 'ANSIBLE_COLLECTIONS_PATH': str(root / 'collections')}
            installed = root / 'libexec/orbit-instance-bridge'
            state = root / 'state'

            def run_helper(incoming, reconcile=False):
                return subprocess.run([sys.executable, str(root / 'instance_bridge.py'), '--state-dir', str(state)]
                                      + ['--intent'] + (['--reconcile'] if reconcile else []),
                                      input=json.dumps(incoming), capture_output=True, text=True, env=env, timeout=30)

            for incoming, reconcile in [(intent(1, '10.44.0.7', '10.44.0.8', '10.44.0.3'), True), (intent(4, '10.44.0.9'), False)]:
                seeded = run_helper(incoming, reconcile)
                self.assertEqual(seeded.returncode, 0, seeded.stdout + seeded.stderr)
            tasks = (source / 'tasks/instance-bridge-rules.yml').read_text()
            tasks = tasks.replace('/usr/local/libexec', str(root / 'libexec')).replace('/var/lib/orbit/instance-bridge', str(state))
            tasks = tasks.replace(', owner: root, group: root', '')
            tasks = '\n'.join(line for line in tasks.splitlines() if line.strip() not in ('owner: root', 'group: root')) + '\n'
            (root / 'tasks.yml').write_text(tasks)
            playbook = root / 'proof.json'
            playbook.write_text(json.dumps([{'hosts': 'localhost', 'gather_facts': False,
                                            'tasks': [{'ansible.builtin.include_tasks': str(root / 'tasks.yml')}]}]))

            def withdraw(stamp, *hosts):
                config = intent(stamp, *hosts)
                variables = {'ansible_python_interpreter': sys.executable, 'bridge_network': config['network'],
                             'bridge_cidr': config['cidr'], 'bridge_admitted_at': config['admitted_at'],
                             'bridge_endpoints': config['endpoints'], 'bridge_reconcile': True, 'bridge_withdraw': True}
                return subprocess.run(['ansible-playbook', '-i', 'localhost,', '--connection', 'local',
                                       '--extra-vars', json.dumps(variables), str(playbook)],
                                      capture_output=True, text=True, env=env, timeout=30)

            # Execute the shipped Ansible task under real contention. It must
            # refuse before curl or any record/health write, with no delayed work.
            before = {str(path): (path.read_bytes(), path.stat().st_ino, path.stat().st_mtime_ns)
                      for path in state.rglob('*') if path.is_file()}
            before_acl = acl.read_bytes()
            with (state / 'writer.lock').open('a') as holder:
                bridge.fcntl.flock(holder, bridge.fcntl.LOCK_EX | bridge.fcntl.LOCK_NB)
                busy = withdraw(3, '10.44.0.3')
                self.assertEqual(busy.returncode, 2, busy.stdout + busy.stderr)
                self.assertIn('instance.bridge_busy', busy.stdout + busy.stderr)
            self.assertEqual(acl.read_bytes(), before_acl)
            self.assertEqual({str(path): (path.read_bytes(), path.stat().st_ino, path.stat().st_mtime_ns)
                              for path in state.rglob('*') if path.is_file()}, before)

            (root / 'timeout-remove-b').touch()
            refused = withdraw(3, '10.44.0.3')
            self.assertEqual(refused.returncode, 2, refused.stdout + refused.stderr)
            self.assertIn('instance.bridge_command_timeout', refused.stdout + refused.stderr)
            refreshed = subprocess.run([sys.executable, str(installed), '--state-dir', str(state)],
                                       capture_output=True, text=True, env=env, timeout=30)
            self.assertEqual(refreshed.returncode, 0, refreshed.stdout + refreshed.stderr)
            self.assertEqual({row['destination'] for row in json.loads(acl.read_text())['metadata']['egress']},
                             {'10.44.0.3/32', '10.44.0.8/32', '10.44.0.9/32'})
            self.assertEqual(json.loads((state / 'health.json').read_text()), {'error_code': 'instance.bridge_command_timeout'})
            (root / 'timeout-remove-b').unlink()
            completed = withdraw(5, '10.44.0.3', '10.44.0.9')
            self.assertEqual(completed.returncode, 0, completed.stdout + completed.stderr)
            self.assertEqual(json.loads((state / 'health.json').read_text()), {'error_code': None})

    def test_crash_after_confirmed_removal_cannot_be_reported_healthy_by_refresh(self):
        incus = Incus()
        with tempfile.TemporaryDirectory() as directory, patch.object(bridge, 'api', side_effect=incus.api):
            root = Path(directory)
            bridge.apply(root, intent(1, '10.44.0.7', '10.44.0.8'), True)

            def crash_on_second_removal(name, value=None, etag=None, deadline=None):
                if value is not None and not value['egress']:
                    raise KeyboardInterrupt('writer terminated after removing A')
                return incus.api(name, value, etag)

            with patch.object(bridge, 'api', side_effect=crash_on_second_removal), self.assertRaises(KeyboardInterrupt):
                bridge.apply(root, intent(2), True, withdraw=True)
            self.assertEqual(json.loads((root / 'health.json').read_text()), {'error_code': None})

            bridge.apply(root, None)

            self.assertEqual([row['destination'] for row in incus.current['egress']], ['10.44.0.8/32'])
            self.assertEqual(json.loads((root / 'health.json').read_text()), {'error_code': 'instance.bridge_state_unresolved'})

    def test_withdrawal_of_an_unsaved_docker_tuple_refuses_before_acl_mutation(self):
        incus = Incus()
        with tempfile.TemporaryDirectory() as directory, patch.object(bridge, 'api', side_effect=incus.api):
            root = Path(directory)
            docker = {**intent(1), 'endpoints': [{'host': '10.44.0.7', 'port': 3306, 'docker': True}]}
            with patch.object(bridge.subprocess, 'check_output', return_value=nat()):
                bridge.apply(root, docker, True)
            path = next((root / 'endpoints').glob('*.json'))
            row = json.loads(path.read_text())
            del row['translated']
            bridge.state_file(root, 'endpoint', row, host=row['host'], port=row['port'], capture=row['admitted_at'])
            before = copy.deepcopy(incus.current)

            with patch.object(bridge, 'api', side_effect=AssertionError('ACL touched before ownership was resolved')):
                with self.assertRaisesRegex(bridge.PolicyError, 'instance.container_endpoint_unresolved'):
                    bridge.apply(root, intent(2), True, withdraw=True)

            self.assertEqual(incus.current, before)
            self.assertTrue(path.exists())

    def test_busy_caller_exits_and_never_applies_its_intent_after_holder_releases(self):
        incus = Incus()
        with tempfile.TemporaryDirectory() as directory, patch.object(bridge, 'api', side_effect=incus.api):
            root = Path(directory)
            bridge.apply(root, intent(1, '10.44.0.7'), True)
            before = copy.deepcopy(incus.current)
            files = {str(p): p.read_text() for p in root.rglob('*.json')}
            errors = []

            def admit_stale_intent():
                try:
                    bridge.apply(root, intent(2, '10.44.0.8'))
                except bridge.PolicyError as error:
                    errors.append(str(error))

            waiter = threading.Thread(target=admit_stale_intent)
            with (root / 'writer.lock').open('a') as holder:
                bridge.fcntl.flock(holder, bridge.fcntl.LOCK_EX | bridge.fcntl.LOCK_NB)
                waiter.start()
                waiter.join(1)
                refused_before_release = not waiter.is_alive()
            waiter.join(1)
            self.assertTrue(refused_before_release, 'Busy caller waited for the holder')
            self.assertEqual(errors, ['instance.bridge_busy'])
            self.assertEqual(incus.current, before)
            self.assertEqual({str(p): p.read_text() for p in root.rglob('*.json')}, files)

    def test_descriptor_custody_spans_acl_records_health_and_releases_on_every_exit(self):
        with tempfile.TemporaryDirectory() as directory:
            root, incus = Path(directory), Incus()
            observed = set()
            original_state = bridge.state_file

            def assert_owned():
                with (root / 'writer.lock').open('a') as contender:
                    with self.assertRaises(BlockingIOError):
                        bridge.fcntl.flock(contender, bridge.fcntl.LOCK_EX | bridge.fcntl.LOCK_NB)

            def write(root, kind, *args, **kwargs):
                assert_owned()
                observed.add(kind)
                return original_state(root, kind, *args, **kwargs)

            def api(*args, **kwargs):
                assert_owned()
                observed.add('acl')
                return incus.api(*args, **kwargs)

            with patch.object(bridge, 'api', side_effect=api), patch.object(bridge, 'state_file', side_effect=write):
                bridge.apply(root, intent(1, '10.44.0.7'), True)
                self.assertEqual(observed, {'acl', 'staged', 'endpoint', 'config', 'health', 'pending'})
                inode = (root / 'writer.lock').stat().st_ino
                exits = [None, bridge.PolicyError('instance.container_endpoint_unresolved'), ValueError('bad'),
                         OSError('bad'), bridge.subprocess.TimeoutExpired('curl', 5), KeyboardInterrupt('terminated')]
                for error in exits:
                    with self.subTest(error=error), patch.object(bridge, 'apply_locked', side_effect=error, return_value=0):
                        if error is None:
                            self.assertEqual(bridge.apply(root, intent(2)), 0)
                        else:
                            with self.assertRaises(type(error)):
                                bridge.apply(root, intent(2))
                        with (root / 'writer.lock').open('a') as contender:
                            bridge.fcntl.flock(contender, bridge.fcntl.LOCK_EX | bridge.fcntl.LOCK_NB)
                        self.assertEqual((root / 'writer.lock').stat().st_ino, inode)

    def test_expired_helper_budget_refuses_before_record_or_acl_work(self):
        incus = Incus()
        with tempfile.TemporaryDirectory() as directory, patch.object(bridge, 'api', side_effect=incus.api):
            root = Path(directory)
            bridge.apply(root, intent(1, '10.44.0.7'), True)
            before = {str(path): path.read_bytes() for path in root.rglob('*.json') if path.name != 'health.json'}
            with patch.object(bridge.time, 'monotonic', side_effect=[0, 301]), \
                    patch.object(bridge, 'api') as api, patch.object(bridge.subprocess, 'check_output') as nat:
                with self.assertRaisesRegex(bridge.PolicyError, 'instance.bridge_command_timeout'):
                    bridge.apply(root, intent(2, '10.44.0.8'))
                api.assert_not_called()
                nat.assert_not_called()
            self.assertEqual({str(path): path.read_bytes() for path in root.rglob('*.json') if path.name != 'health.json'}, before)
            self.assertEqual(json.loads((root / 'health.json').read_text()), {'error_code': 'instance.bridge_command_timeout'})
            with (root / 'writer.lock').open('a') as contender:
                bridge.fcntl.flock(contender, bridge.fcntl.LOCK_EX | bridge.fcntl.LOCK_NB)

    def test_cli_reports_busy_without_a_second_acquire_or_health_mutation(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            output, errors = io.StringIO(), io.StringIO()
            with (root / 'writer.lock').open('a') as holder, \
                    patch.object(bridge.sys, 'argv', ['instance_bridge.py', '--state-dir', directory, '--intent']), \
                    patch.object(bridge.sys, 'stdin', io.StringIO(json.dumps(intent(1, '10.44.0.7')))), \
                    patch.object(bridge, 'writer_lock', wraps=bridge.writer_lock) as lock, \
                    redirect_stdout(output), redirect_stderr(errors):
                bridge.fcntl.flock(holder, bridge.fcntl.LOCK_EX | bridge.fcntl.LOCK_NB)
                with self.assertRaises(SystemExit) as result:
                    bridge.main()
                self.assertEqual(result.exception.code, 1)
                self.assertEqual(lock.call_count, 1)
            self.assertEqual(json.loads(output.getvalue()), {'error_code': 'instance.bridge_busy'})
            self.assertEqual(list(root.rglob('*.json')), [])

    def test_nat_and_incus_timeouts_release_the_lock_and_record_named_failure(self):
        for command in ['nat', 'incus']:
            with self.subTest(command=command), tempfile.TemporaryDirectory() as directory:
                root = Path(directory)
                incoming = intent(1, '10.44.0.7')
                incoming['endpoints'][0]['docker'] = command == 'nat'
                with patch.object(bridge.subprocess, 'check_output', side_effect=bridge.subprocess.TimeoutExpired('iptables-save', 5)) as read, \
                        patch.object(bridge.subprocess, 'run', side_effect=bridge.subprocess.TimeoutExpired('curl', 5)) as run:
                    with self.assertRaises(bridge.subprocess.TimeoutExpired if command == 'nat' else bridge.PolicyError):
                        bridge.apply(root, incoming, True)
                    call = read if command == 'nat' else run
                    self.assertGreater(call.call_args.kwargs['timeout'], 0)
                    self.assertLessEqual(call.call_args.kwargs['timeout'], 300 if command == 'nat' else 5)
                    self.assertEqual(json.loads((root / 'health.json').read_text()),
                                     {'error_code': 'instance.bridge_command_timeout' if command == 'nat' else 'instance.bridge_state_unresolved'})
                with (root / 'writer.lock').open('a') as contender:
                    bridge.fcntl.flock(contender, bridge.fcntl.LOCK_EX | bridge.fcntl.LOCK_NB)

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

        def api(name, value=None, etag=None, deadline=None):
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

    def test_wildcard_and_explicit_bridge_publications_share_the_exact_resolver(self):
        endpoint = {'host': '10.253.0.1', 'port': 1025, 'docker': True}
        wildcard = '-A DOCKER ! -i docker0 -p tcp -m tcp --dport 1025 -j DNAT --to-destination 172.17.0.4:1025\n'
        explicit = wildcard.replace('-A DOCKER ', '-A DOCKER -d 10.253.0.1/32 ')
        for table in [wildcard, explicit, wildcard.replace('-A DOCKER ', '-A DOCKER -d 0.0.0.0/0 ')]:
            self.assertEqual(bridge.resolve([endpoint], table), [('172.17.0.4', 1025)])
        with self.assertRaisesRegex(bridge.PolicyError, 'instance.container_endpoint_unresolved'):
            bridge.resolve([endpoint], wildcard + explicit)

    def test_address_change_adds_new_tuple_until_host_reconcile_removes_the_old(self):
        current = {'description': 'boundary', 'config': {}, 'egress': [], 'ingress': []}
        calls = []

        def api(name, value=None, etag=None, deadline=None):
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
            bridge.state_file(root, 'health', {'error_code': 'instance.container_endpoint_unresolved'})
            with patch.object(bridge.subprocess, 'check_output', return_value=nat('172.17.0.9')):
                bridge.apply(root, intent, False)
            self.assertEqual(json.loads((root / 'health.json').read_text())['error_code'], 'instance.container_endpoint_unresolved')
            before = json.loads(json.dumps(current))
            with patch.object(bridge.subprocess, 'check_output', return_value=''):
                bridge.apply(root, None)
                self.assertEqual(current, before)
                self.assertEqual(json.loads((root / 'health.json').read_text())['warning_count'], 1)

    def test_isolate_mixed_live_dead_refresh_preserves_saved_rule_and_updates_live(self):
        with tempfile.TemporaryDirectory() as directory:
            root, incus = Path(directory), Incus()
            dead = {'host': '10.253.0.1', 'port': 13310, 'docker': True}
            live = {'host': '10.44.0.7', 'port': 3306, 'docker': True}
            others = [{'host': '10.253.0.1', 'port': 1025, 'docker': False},
                      {'host': '172.17.0.5', 'port': 3306, 'docker': False}]
            dead_nat = '-A DOCKER -d 10.253.0.1/32 -p tcp --dport 13310 -j DNAT --to-destination 172.17.0.8:9999\n'
            admitted = {**intent(1), 'endpoints': [live, dead, *others],
                        'baseline': {'egress': [{'action': 'reject', 'protocol': 'udp', 'state': 'enabled'}]}}
            with patch.object(bridge, 'api', side_effect=incus.api):
                with patch.object(bridge.subprocess, 'check_output', return_value=nat() + dead_nat):
                    bridge.apply(root, admitted, True)
                before = copy.deepcopy(incus.current)
                dead_record = next((root / 'endpoints').glob('10.253.0.1-13310-*.json'))
                saved = dead_record.read_bytes()
                output = io.StringIO()
                with (
                    patch.object(bridge.subprocess, 'check_output', return_value=nat('172.17.0.9')),
                    patch.object(sys, 'argv', ['bridge', '--state-dir', str(root)]),
                    redirect_stdout(output),
                ):
                    self.assertIsNone(bridge.main())
                self.assertNotIn('error_code', json.loads(output.getvalue()))
                self.assertIn('172.17.0.9/32', [row.get('destination') for row in incus.current['egress']])
                for row in before['egress']:
                    self.assertIn(row, incus.current['egress'])  # No old, dead, mail, DB or baseline rule removed.
                self.assertEqual(dead_record.read_bytes(), saved)
                self.assertEqual(json.loads((root / 'health.json').read_text()), {
                    'error_code': None, 'warning_count': 1,
                    'warnings': [{'code': 'instance.container_endpoint_unresolved', 'host': dead['host'], 'port': dead['port']}],
                })
                # Same-address restart adds no ACL outage: its saved allow never left.
                with patch.object(bridge.subprocess, 'check_output', return_value=nat('172.17.0.9') + dead_nat):
                    version = incus.version
                    bridge.apply(root, None)
                    self.assertEqual(incus.version, version)
                    self.assertEqual(json.loads((root / 'health.json').read_text()), {'error_code': None})

    def test_isolate_empty_or_failed_nat_snapshot_changes_no_acl_or_endpoint_record(self):
        for snapshot in ('', '*nat\n:DOCKER - [0:0]\nCOMMIT\n', '-A POSTROUTING -j MASQUERADE\n',
                         subprocess.CalledProcessError(1, 'iptables-save'), subprocess.TimeoutExpired('iptables-save', 5)):
            with self.subTest(snapshot=type(snapshot).__name__), tempfile.TemporaryDirectory() as directory:
                root, incus = Path(directory), Incus()
                admitted = {**intent(1), 'endpoints': [
                    {'host': '10.44.0.7', 'port': 3306, 'docker': True},
                    {'host': '10.44.0.8', 'port': 3306, 'docker': True},
                    {'host': '10.253.0.1', 'port': 1025, 'docker': False},
                ]}
                table = nat() + nat('172.17.0.5').replace('10.44.0.7/32', '10.44.0.8/32')
                with patch.object(bridge, 'api', side_effect=incus.api) as api:
                    with patch.object(bridge.subprocess, 'check_output', return_value=table):
                        bridge.apply(root, admitted, True)
                    before = copy.deepcopy(incus.current)
                    records = {str(p): p.read_bytes() for p in (root / 'endpoints').glob('*.json')}
                    api.reset_mock()
                    with patch.object(bridge.subprocess, 'check_output',
                                      return_value=snapshot if isinstance(snapshot, str) else '',
                                      side_effect=None if isinstance(snapshot, str) else snapshot):
                        bridge.apply(root, None)
                    api.assert_not_called()  # Even direct endpoints are untouched on a global empty read.
                    self.assertEqual(incus.current, before)
                    self.assertEqual(records, {str(p): p.read_bytes() for p in (root / 'endpoints').glob('*.json')})
                    self.assertEqual(json.loads((root / 'health.json').read_text())['warning_count'], 2)
                    with patch.object(bridge.subprocess, 'check_output', return_value=table):
                        version = incus.version
                        bridge.apply(root, None)
                        self.assertEqual(incus.version, version)
                        self.assertEqual(incus.current, before)

    def test_isolate_duplicate_dnat_rows_never_change_the_saved_live_allow(self):
        for duplicate in (nat() + nat(), nat() + nat('172.17.0.9'), nat('172.17.0.9') + nat('172.17.0.9')):
            with self.subTest(duplicate=duplicate), tempfile.TemporaryDirectory() as directory:
                root, incus = Path(directory), Incus()
                with patch.object(bridge, 'api', side_effect=incus.api):
                    with patch.object(bridge.subprocess, 'check_output', return_value=nat()):
                        bridge.apply(root, {**intent(1), 'endpoints': [{'host': '10.44.0.7', 'port': 3306, 'docker': True}]}, True)
                    before = copy.deepcopy(incus.current)
                    records = {str(p): p.read_bytes() for p in (root / 'endpoints').glob('*.json')}
                    with patch.object(bridge.subprocess, 'check_output', return_value=duplicate):
                        bridge.apply(root, None)
                    self.assertEqual(incus.current, before)
                    self.assertEqual(records, {str(p): p.read_bytes() for p in (root / 'endpoints').glob('*.json')})
                    self.assertEqual(json.loads((root / 'health.json').read_text())['warning_count'], 1)

    def test_isolate_two_hundred_unresolved_records_preserve_rules_and_bound_health(self):
        with tempfile.TemporaryDirectory() as directory:
            root, incus = Path(directory), Incus()
            config = {key: value for key, value in intent(1).items() if key != 'endpoints'}
            bridge.state_file(root, 'config', config)
            for number in range(200):
                row = {'host': '255.255.255.254', 'port': 60000 + number, 'docker': True,
                       'admitted_at': config['admitted_at'], 'translated': {'host': '172.17.0.8', 'port': 10000 + number}}
                bridge.state_file(root, 'endpoint', row, host=row['host'], port=row['port'], capture=row['admitted_at'])
                incus.current['egress'].append(bridge.endpoint_rule(row['translated']['host'], row['translated']['port'], config['cidr'], row['admitted_at']))
            bridge.state_file(root, 'health', {'error_code': 'instance.bridge_state_unresolved'})
            before = copy.deepcopy(incus.current)
            records = {str(p): p.read_bytes() for p in (root / 'endpoints').glob('*.json')}
            with patch.object(bridge, 'api', side_effect=incus.api), patch.object(bridge.subprocess, 'check_output', return_value=nat()):
                bridge.apply(root, None)
                report = (root / 'health.json').read_bytes()
                self.assertLess(len(report), 4096)
                health = json.loads(report)
                self.assertEqual(health['error_code'], 'instance.bridge_state_unresolved')
                self.assertEqual(health['warning_count'], 200)
                self.assertEqual(len(health['warnings']), 20)
                self.assertEqual(incus.current, before)
                self.assertEqual(records, {str(p): p.read_bytes() for p in (root / 'endpoints').glob('*.json')})
                stat = (root / 'health.json').stat()
                self.assertEqual(bridge.apply(root, None), 0)
                after = (root / 'health.json').stat()
                self.assertEqual((stat.st_ino, stat.st_mtime_ns), (after.st_ino, after.st_mtime_ns))
                print('HEALTH-200 bytes=' + str(len(report)) + ' warnings=200 samples=20')
                # The byte cap also holds if a sample is unexpectedly oversized.
                bridge.refresh_health(root, True, [{'code': 'instance.container_endpoint_unresolved', 'host': 'x' * 5000, 'port': 1}])
                bounded = (root / 'health.json').read_bytes()
                self.assertLess(len(bounded), 4096)
                self.assertEqual(json.loads(bounded), {'error_code': None, 'warning_count': 1, 'warnings': []})

    def test_isolate_write_failure_still_exits_nonzero(self):
        with tempfile.TemporaryDirectory() as directory:
            root, incus = Path(directory), Incus()
            docker = {**intent(1), 'endpoints': [{'host': '10.44.0.7', 'port': 3306, 'docker': True}]}
            with patch.object(bridge, 'api', side_effect=incus.api), patch.object(bridge.subprocess, 'check_output', return_value=nat()):
                bridge.apply(root, docker, True)

            def refuse(name, value=None, etag=None, deadline=None):
                if value is not None:
                    raise bridge.PolicyError('instance.container_bridge_write_failed')
                return incus.api(name, value, etag, deadline)

            output, error = io.StringIO(), io.StringIO()
            with (
                patch.object(bridge, 'api', side_effect=refuse),
                patch.object(bridge.subprocess, 'check_output', return_value=nat('172.17.0.9')),
                patch.object(sys, 'argv', ['bridge', '--state-dir', str(root)]),
                redirect_stdout(output), redirect_stderr(error), self.assertRaises(SystemExit) as failed,
            ):
                bridge.main()
            self.assertEqual(failed.exception.code, 1)
            self.assertEqual(json.loads(output.getvalue())['error_code'], 'instance.container_bridge_write_failed')
            self.assertEqual(json.loads((root / 'health.json').read_text())['error_code'], 'instance.container_bridge_write_failed')
            self.assertEqual(len(incus.current['egress']), 1)

    def test_withdrawal_retains_stopped_shared_service_and_unrelated_stopped_database(self):
        mail = {'host': '10.253.0.1', 'port': 1025, 'docker': True}
        database = {'host': '10.44.0.7', 'port': 3306, 'docker': True}
        table = nat() + '-A DOCKER ! -i docker0 -p tcp -m tcp --dport 1025 -j DNAT --to-destination 172.17.0.4:1025\n'
        incus = Incus()
        with tempfile.TemporaryDirectory() as directory, patch.object(bridge, 'api', side_effect=incus.api):
            root = Path(directory)
            with patch.object(bridge.subprocess, 'check_output', return_value=table):
                bridge.apply(root, {**intent(1), 'endpoints': [database, mail]}, True)
            # Both stopped containers have lost DNAT. Neither retained endpoint needs a new read.
            with patch.object(bridge.subprocess, 'check_output', side_effect=AssertionError('withdrawal read live NAT')):
                shared = {**intent(2), 'endpoints': [database, mail]}
                bridge.apply(root, shared, True, withdraw=True)
                self.assertEqual({r['destination'] for r in incus.current['egress']}, {'172.17.0.2/32', '172.17.0.4/32'})
                self.assertEqual(bridge.apply(root, shared, True, withdraw=True), 0)
                bridge.apply(root, {**intent(3), 'endpoints': [database]}, True, withdraw=True)
                self.assertEqual([r['destination'] for r in incus.current['egress']], ['172.17.0.2/32'])
                self.assertEqual([json.loads(p.read_text())['translated'] for p in (root / 'endpoints').glob('*.json')],
                                 [{'host': '172.17.0.2', 'port': 3306}])

    def test_withdrawal_uses_last_refresh_translation_and_preserves_newer_admission(self):
        incus = Incus()
        database = {'host': '10.44.0.7', 'port': 3306, 'docker': True}
        with tempfile.TemporaryDirectory() as directory, patch.object(bridge, 'api', side_effect=incus.api):
            root = Path(directory)
            with patch.object(bridge.subprocess, 'check_output', return_value=nat()):
                bridge.apply(root, {**intent(1), 'endpoints': [database]}, True)
            with patch.object(bridge.subprocess, 'check_output', return_value=nat('172.17.0.9')):
                bridge.apply(root, None)
            bridge.apply(root, intent(4, '10.44.0.8'))  # Concurrent admission newer than withdrawal's capture.
            with patch.object(bridge.subprocess, 'check_output', side_effect=AssertionError('withdrawal read live NAT')):
                bridge.apply(root, {**intent(3), 'endpoints': [database]}, True, withdraw=True)
            self.assertEqual({r['destination'] for r in incus.current['egress']}, {'172.17.0.9/32', '10.44.0.8/32'})

    def test_withdrawal_cannot_admit_an_unrecorded_endpoint_or_changed_publication_kind(self):
        incus = Incus()
        with tempfile.TemporaryDirectory() as directory, patch.object(bridge, 'api', side_effect=incus.api):
            root = Path(directory)
            bridge.apply(root, intent(1, '10.44.0.7'), True)
            before = copy.deepcopy(incus.current)
            files = {str(p): p.read_text() for p in root.rglob('*.json')}
            changed_kind = {**intent(2), 'endpoints': [{'host': '10.44.0.7', 'port': 3306, 'docker': True}]}
            for incoming in [intent(2, '10.44.0.8'), changed_kind]:
                with self.assertRaisesRegex(bridge.PolicyError, 'instance.container_endpoint_unresolved'):
                    bridge.apply(root, incoming, True, withdraw=True)
                self.assertEqual(incus.current, before)
                self.assertEqual({str(p): p.read_text() for p in root.rglob('*.json') if p.name != 'health.json'},
                                 {path: value for path, value in files.items() if Path(path).name != 'health.json'})
                self.assertEqual(json.loads((root / 'health.json').read_text()), {'error_code': 'instance.container_endpoint_unresolved'})

    def test_reconcile_preserves_proxy_ingress_and_rules_outside_its_endpoint_shape(self):
        proxy = {'action': 'allow', 'destination': '10.253.0.1/32', 'protocol': 'tcp', 'destination_port': '3128', 'state': 'enabled'}
        foreign = {'action': 'allow', 'destination': '192.0.2.0/24', 'state': 'enabled'}
        ingress = {'action': 'allow', 'source': '10.253.0.1/32', 'protocol': 'tcp', 'destination_port': '1024-65535', 'state': 'enabled'}
        current = {'egress': [proxy, foreign, bridge.endpoint_rule('172.17.0.2', 3306, '10.253.0.0/24', 1800000000000000)], 'ingress': [ingress]}
        self.assertEqual(bridge.plan(current, {}, '10.253.0.0/24', {'egress': [proxy], 'ingress': [ingress]}, 1800000000000001),
                         [('remove', 'egress', current['egress'][-1])])
        self.assertEqual(bridge.plan(current, {}, '10.253.0.0/24', {}), [])




@unittest.skipUnless(shutil.which('ansible-playbook'), 'pinned Ansible is required')
class ReleaseHelperDelivery(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.run = self.root / 'run'
        self.run.mkdir()
        self.installed = self.root / 'installed-helper'
        self.installed.write_text('# old complete helper\n')
        self.unit = self.root / 'refresh.service'
        self.unit.write_text('[Service]\nExecStart=/usr/bin/python3 /usr/local/libexec/orbit-instance-bridge\n')
        self.source = Path(__file__).parents[1] / 'ansible/instance_bridge.py'
        self.capture = {
            'sha256': hashlib.sha256(self.source.read_bytes()).hexdigest(),
            'nodes': [self.node(7)],
        }
        self.inventory = {'all': {'hosts': {
            'localhost': {'ansible_connection': 'local'},
            'bridge_7': {'ansible_connection': 'local'},
        }}}

    def node(self, node_id, port=22):
        import base64
        import struct
        key = base64.b64encode(struct.pack('!I', 11) + b'ssh-ed25519' + struct.pack('!I', 32) + b'x' * 32).decode()
        return {'node_id': node_id, 'name': f'node-{node_id}', 'host': '127.0.0.1',
                'user': 'fixture', 'port': port, 'identity_file': '/unused',
                'host_keys': ['ssh-ed25519 ' + key]}

    def publish(self, *, extra=None, deadline=None):
        (self.run / 'intent.json').write_text(json.dumps({'bridge_release_json': json.dumps(self.capture)}))
        inventory = self.root / 'inventory.json'
        inventory.write_text(json.dumps(self.inventory))
        values = {'ansible_become': False, 'ansible_python_interpreter': sys.executable,
                  'bridge_release_path': str(self.installed), 'bridge_release_unit': str(self.unit)}
        values.update(extra or {})
        argv = ['ansible-playbook', '-i', str(inventory), '--forks', '4', '-e', json.dumps(values),
                str(Path(__file__).parents[1] / 'ansible/gateway-release-bridge.yml')]
        if deadline is not None:
            argv = ['timeout', '--kill-after=1', str(deadline), *argv]
        result = subprocess.run(argv,
            env={**os.environ, 'ORBIT_RUN': str(self.run), 'ANSIBLE_NOCOLOR': '1', 'ANSIBLE_CONNECTION_PLUGINS': str(self.root / 'connections'), 'ORBIT_TEST_INSTALLED_HELPER': str(self.installed), 'ORBIT_TEST_HELPER_DIGEST': self.capture['sha256']},
            text=True, capture_output=True, timeout=45, check=False)
        self.phase_exit = result.returncode
        return result

    def receipt(self):
        import yaml
        workflow = yaml.safe_load((Path(__file__).parents[1] / 'workflows/workflow-gateway-release-activate.yaml').read_text())
        step = next(step for job in workflow['jobs'] for step in job['steps'] if step['name'] == 'bridge_helpers')
        result = subprocess.run(['/bin/sh', '-c', step['task']['inputs']['run']],
                                env={**os.environ, 'ORBIT_RUN': str(self.run), 'BRIDGE_PHASE_EXIT': str(self.phase_exit)}, capture_output=True,
                                text=True, timeout=15, check=False)
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        return json.loads(result.stdout)['nodes']

    def fault_connection(self, body):
        plugins = self.root / 'connections'
        plugins.mkdir(exist_ok=True)
        (plugins / 'bridge_fault.py').write_text(
            "import hashlib, os, time\n"
            "from pathlib import Path\n"
            "from ansible.plugins.connection.local import Connection as LocalConnection\n"
            "class Connection(LocalConnection):\n"
            "    transport = 'bridge_fault'\n"
            "    def exec_command(self, cmd, in_data=None, sudoable=True):\n"
            + ''.join('        ' + line + '\n' for line in body.splitlines())
            + "        return super().exec_command(cmd, in_data, sudoable)\n")
        self.inventory['all']['hosts']['bridge_7']['ansible_connection'] = 'bridge_fault'

    def test_post_copy_corruption_is_reported_as_checksum_and_does_not_fail_delivery(self):
        self.fault_connection(
            "helper = Path(os.environ['ORBIT_TEST_INSTALLED_HELPER'])\n"
            "if helper.exists() and hashlib.sha256(helper.read_bytes()).hexdigest() == os.environ['ORBIT_TEST_HELPER_DIGEST']:\n"
            "    helper.write_text('# corrupted after copying\\n')")
        result = self.publish()
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertEqual(self.receipt(), [{'node_id': 7, 'updated': False, 'reason': 'checksum'}])

    def test_copy_failure_is_reported_without_failing_delivery(self):
        self.installed = self.root / 'missing-parent' / 'helper'
        result = self.publish()
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertEqual(self.receipt(), [{'node_id': 7, 'updated': False, 'reason': 'copy'}])

    def test_each_node_task_has_a_bounded_wait_and_a_timeout_receipt(self):
        self.fault_connection("time.sleep(2)")
        result = self.publish(extra={'bridge_release_read_seconds': 1})
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertEqual(self.receipt(), [{'node_id': 7, 'updated': False, 'reason': 'timeout'}])

    def test_the_outer_deadline_keeps_a_receipt_for_unfinished_nodes(self):
        self.fault_connection("time.sleep(4)")
        result = self.publish(deadline=2)
        self.assertEqual(result.returncode, 124, result.stdout + result.stderr)
        self.assertEqual(self.receipt(), [{'node_id': 7, 'updated': False, 'reason': 'timeout'}])

    def test_an_unobserved_node_after_a_phase_error_is_not_misreported_as_a_timeout(self):
        del self.capture['nodes'][0]['host']
        result = self.publish()
        self.assertNotEqual(result.returncode, 0)
        self.assertEqual(self.receipt(), [{'node_id': 7, 'updated': False, 'reason': 'report'}])

    def test_a_destination_symlink_is_replaced_without_changing_its_referent(self):
        referent = self.root / 'foreign-file'
        referent.write_text('keep the referent\n')
        referent.chmod(0o600)
        inode = referent.stat().st_ino
        self.installed.unlink()
        self.installed.symlink_to(referent)
        result = self.publish()
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertFalse(self.installed.is_symlink())
        self.assertEqual(self.installed.read_bytes(), self.source.read_bytes())
        self.assertEqual(referent.read_text(), 'keep the referent\n')
        self.assertEqual(referent.stat().st_ino, inode)
        self.assertEqual(referent.stat().st_mode & 0o777, 0o600)

    def test_release_replaces_the_real_helper_atomically_and_repeats_without_replacing_it(self):
        with self.installed.open() as running_refresh:
            inode = self.installed.stat().st_ino
            result = self.publish()
            self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
            self.assertEqual(running_refresh.read(), '# old complete helper\n')
            self.assertNotEqual(self.installed.stat().st_ino, inode)
        self.assertEqual(self.installed.read_bytes(), self.source.read_bytes())
        self.assertEqual(self.installed.stat().st_mode & 0o777, 0o755)
        inode = self.installed.stat().st_ino
        result = self.publish()
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertEqual(self.installed.stat().st_ino, inode)

    def test_a_node_without_the_refresh_unit_keeps_its_helper_unchanged(self):
        self.unit.unlink()
        result = self.publish()
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertEqual(self.installed.read_text(), '# old complete helper\n')

    def test_an_unreachable_node_does_not_block_a_reachable_host_or_fail_delivery(self):
        with socket.socket() as closed_port:
            closed_port.bind(('127.0.0.1', 0))
            self.capture['nodes'].insert(0, self.node(8, closed_port.getsockname()[1]))
            result = self.publish()
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertIn('UNREACHABLE', result.stdout)
        self.assertEqual(self.installed.read_bytes(), self.source.read_bytes())
        self.assertEqual(self.receipt(), [
            {'node_id': 8, 'updated': False, 'reason': 'unreachable'},
            {'node_id': 7, 'updated': True, 'reason': 'updated'}])

    def test_a_connection_lost_during_readback_does_not_fail_the_release(self):
        plugins = self.root / 'connections'
        plugins.mkdir()
        (plugins / 'bridge_drop.py').write_text(
            "import hashlib, os\n"
            "from pathlib import Path\n"
            "from ansible.plugins.connection.local import Connection as LocalConnection\n"
            "from ansible.errors import AnsibleConnectionFailure\n"
            "class Connection(LocalConnection):\n"
            "    transport = 'bridge_drop'\n"
            "    def exec_command(self, cmd, in_data=None, sudoable=True):\n"
            "        helper = Path(os.environ['ORBIT_TEST_INSTALLED_HELPER'])\n"
            "        if helper.exists() and hashlib.sha256(helper.read_bytes()).hexdigest() == os.environ['ORBIT_TEST_HELPER_DIGEST']:\n"
            "            raise AnsibleConnectionFailure('fixture connection lost during readback')\n"
            "        return super().exec_command(cmd, in_data, sudoable)\n")
        self.inventory['all']['hosts']['bridge_7']['ansible_connection'] = 'bridge_drop'
        result = self.publish()
        self.assertIn('fixture connection lost during readback', result.stdout)
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertEqual(self.installed.read_bytes(), self.source.read_bytes())

    def test_a_wrong_candidate_checksum_refuses_before_any_replacement(self):
        self.capture['sha256'] = '0' * 64
        result = self.publish()
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertEqual(self.receipt(), [{'node_id': 7, 'updated': False, 'reason': 'checksum'}])
        self.assertEqual(self.installed.read_text(), '# old complete helper\n')

if __name__ == '__main__':
    unittest.main()
