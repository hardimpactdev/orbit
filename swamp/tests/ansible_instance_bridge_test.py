"""Exact DNAT resolution and shared boot/attach/converge policy, without Docker or Incus mutations."""
import copy
import importlib.util
import io
import json
import os
import shutil
import subprocess
import sys
import tempfile
import threading
import time
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

    def test_address_refresh_waits_for_later_confirmation_before_removal(self):
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
                bridge.apply(root, None)
                self.assertEqual([r['destination'] for r in current['egress']], ['172.17.0.9/32'])
                self.assertEqual(bridge.apply(root, None), 0)
                bridge.apply(root, {**intent, 'admitted_at': 1800000000000001}, True)
                self.assertEqual([r['destination'] for r in current['egress']], ['172.17.0.9/32'])
            # A successful subset attach cannot clear the refresh unit's failure for another endpoint.
            bridge.state_file(root, 'health', {'error_code': 'instance.container_endpoint_unresolved'})
            with patch.object(bridge.subprocess, 'check_output', return_value=nat('172.17.0.9')):
                bridge.apply(root, intent, False)
            self.assertEqual(json.loads((root / 'health.json').read_text())['error_code'], 'instance.container_endpoint_unresolved')
            with patch.object(bridge.subprocess, 'check_output', return_value=''):
                bridge.apply(root, None)
                self.assertNotIn('172.17.0.9/32', [row['destination'] for row in current['egress']])
                self.assertEqual(json.loads((root / 'health.json').read_text()), {
                    'error_code': None, 'warnings': [{'code': 'instance.container_endpoint_unresolved', 'host': '10.44.0.7', 'port': 3306}],
                })

    def test_refresh_of_readmitted_publication_removes_old_address_then_is_unchanged(self):
        with tempfile.TemporaryDirectory() as directory, patch.object(bridge, 'api', side_effect=Incus().api) as api:
            root = Path(directory)
            docker = {**intent(1), 'endpoints': [{'host': '10.44.0.7', 'port': 3306, 'docker': True}]}
            with patch.object(bridge.subprocess, 'check_output', return_value=nat()):
                bridge.apply(root, docker, True)
            with patch.object(bridge.subprocess, 'check_output', return_value=nat('172.17.0.9')):
                bridge.apply(root, {**docker, 'admitted_at': 1800000000000002})
                self.assertEqual(len(list((root / 'endpoints').glob('*.json'))), 2)
                bridge.apply(root, None)
                before = {str(p): p.read_bytes() for p in root.rglob('*.json')}
                api.reset_mock()
                self.assertEqual(bridge.apply(root, None), 0)
                self.assertTrue(all(call.args[1] is None if len(call.args) > 1 else True for call in api.call_args_list))
                self.assertEqual(before, {str(p): p.read_bytes() for p in root.rglob('*.json')})

    def test_refresh_remembers_interrupted_adds_even_when_nat_changes_again(self):
        for interrupt in ('before_put', 'after_put', 'record_commit'):
            with self.subTest(interrupt=interrupt), tempfile.TemporaryDirectory() as directory:
                root, incus = Path(directory), Incus()
                docker = {**intent(1), 'endpoints': [{'host': '10.44.0.7', 'port': 3306, 'docker': True}]}
                with patch.object(bridge, 'api', side_effect=incus.api), patch.object(bridge.subprocess, 'check_output', return_value=nat()):
                    bridge.apply(root, docker, True)
                record = next((root / 'endpoints').glob('*.json'))
                state_file = bridge.state_file

                def api(name, value=None, etag=None, deadline=None, incus=incus, interrupt=interrupt):
                    adding = value is not None and any(row['destination'] == '172.17.0.9/32' for row in value['egress'])
                    if adding and interrupt == 'before_put':
                        raise KeyboardInterrupt('writer lost before PUT')
                    result = incus.api(name, value, etag, deadline)
                    if adding and interrupt == 'after_put':
                        raise KeyboardInterrupt('writer lost after PUT')
                    return result

                def commit(root, kind, value=None, state_file=state_file, interrupt=interrupt, **kwargs):
                    if (interrupt == 'record_commit' and kind == 'endpoint' and value is not None
                            and value['translated']['host'] == '172.17.0.9'):
                        raise KeyboardInterrupt('writer lost before confirmed record commit')
                    return state_file(root, kind, value, **kwargs)

                with patch.object(bridge, 'api', side_effect=api), patch.object(bridge, 'state_file', side_effect=commit),                         patch.object(bridge.subprocess, 'check_output', return_value=nat('172.17.0.9')), self.assertRaises(KeyboardInterrupt):
                    bridge.apply(root, None)
                saved = json.loads(record.read_text())
                self.assertEqual(saved['translated']['host'], '172.17.0.2')
                self.assertEqual({row['destination'] for row in saved['translations']}, {'172.17.0.2/32', '172.17.0.9/32'})
                # Another Docker restart happened before the next timer run.
                with patch.object(bridge, 'api', side_effect=incus.api), patch.object(bridge.subprocess, 'check_output', return_value=nat('172.17.0.10')):
                    bridge.apply(root, None)
                    bridge.apply(root, None)
                    self.assertEqual([row['destination'] for row in incus.current['egress']], ['172.17.0.10/32'])
                    self.assertEqual(json.loads(record.read_text())['translated']['host'], '172.17.0.10')
                    self.assertNotIn('translations', json.loads(record.read_text()))
                    self.assertEqual(bridge.apply(root, None), 0)

    def test_refresh_tracks_an_ambiguous_add_when_readback_is_unavailable(self):
        with tempfile.TemporaryDirectory() as directory:
            root, incus = Path(directory), Incus()
            docker = {**intent(1), 'endpoints': [{'host': '10.44.0.7', 'port': 3306, 'docker': True}]}
            with patch.object(bridge, 'api', side_effect=incus.api), patch.object(bridge.subprocess, 'check_output', return_value=nat()):
                bridge.apply(root, docker, True)
            ambiguous = False

            def api(name, value=None, etag=None, deadline=None):
                nonlocal ambiguous
                if ambiguous:
                    raise subprocess.TimeoutExpired('curl', 5)
                result = incus.api(name, value, etag, deadline)
                if value is not None:
                    ambiguous = True
                    raise subprocess.TimeoutExpired('curl', 5)
                return result

            with (
                patch.object(bridge, 'api', side_effect=api),
                patch.object(bridge.subprocess, 'check_output', return_value=nat('172.17.0.9')),
                self.assertRaisesRegex(bridge.PolicyError, 'instance.bridge_state_unresolved'),
            ):
                bridge.apply(root, None)
            saved = json.loads(next((root / 'endpoints').glob('*.json')).read_text())
            self.assertEqual(saved['translated']['host'], '172.17.0.2')
            self.assertEqual({row['destination'] for row in saved['translations']}, {'172.17.0.2/32', '172.17.0.9/32'})
            with patch.object(bridge, 'api', side_effect=incus.api), patch.object(bridge.subprocess, 'check_output', return_value=nat('172.17.0.10')):
                bridge.apply(root, None)
                bridge.apply(root, None)
            self.assertEqual([row['destination'] for row in incus.current['egress']], ['172.17.0.10/32'])
            # Existing unresolved-health policy still requires authoritative convergence.
            self.assertEqual(json.loads((root / 'health.json').read_text())['error_code'], 'instance.bridge_state_unresolved')

    def test_refresh_keeps_history_until_failed_removal_is_confirmed_absent(self):
        root_context = tempfile.TemporaryDirectory()
        self.addCleanup(root_context.cleanup)
        root, incus = Path(root_context.name), Incus()
        docker = {**intent(1), 'endpoints': [{'host': '10.44.0.7', 'port': 3306, 'docker': True}]}
        with patch.object(bridge, 'api', side_effect=incus.api), patch.object(bridge.subprocess, 'check_output', return_value=nat()):
            bridge.apply(root, docker, True)

        def refuse_removal(name, value=None, etag=None, deadline=None):
            if value is not None and all(row['destination'] != '172.17.0.2/32' for row in value['egress']):
                raise bridge.PolicyError('instance.container_bridge_write_failed')
            return incus.api(name, value, etag, deadline)

        with patch.object(bridge, 'api', side_effect=refuse_removal),                 patch.object(bridge.subprocess, 'check_output', return_value=nat('172.17.0.9')), self.assertRaises(bridge.PolicyError):
            bridge.apply(root, None)
            bridge.apply(root, None)
        record = next((root / 'endpoints').glob('*.json'))
        saved = json.loads(record.read_text())
        self.assertEqual(saved['translated']['host'], '172.17.0.9')
        self.assertEqual([row['destination'] for row in saved['translations']], ['172.17.0.2/32'])
        with patch.object(bridge, 'api', side_effect=incus.api), patch.object(bridge.subprocess, 'check_output', return_value=nat('172.17.0.10')):
            bridge.apply(root, None)
            bridge.apply(root, None)
            self.assertEqual([row['destination'] for row in incus.current['egress']], ['172.17.0.10/32'])
            self.assertNotIn('translations', json.loads(record.read_text()))

    def test_refresh_preserves_shared_tuples_mail_database_baseline_and_unknown_rules(self):
        for shared, newer in [(True, False), (False, True), (False, False)]:
            with self.subTest(shared=shared, newer=newer), tempfile.TemporaryDirectory() as directory:
                root, incus = Path(directory), Incus()
                docker = {'host': '10.44.0.7', 'port': 3306, 'docker': True}
                others = [{'host': '10.253.0.1', 'port': 1025, 'docker': False},
                          {'host': '10.44.0.8', 'port': 5434, 'docker': False}]
                if shared:
                    others.append({'host': '172.17.0.2', 'port': 3306, 'docker': False})
                admitted = {**intent(1), 'endpoints': [docker, *others],
                            'baseline': {'egress': [{'action': 'reject', 'protocol': 'udp', 'state': 'enabled'}]}}
                with patch.object(bridge, 'api', side_effect=incus.api), patch.object(bridge.subprocess, 'check_output', return_value=nat()):
                    bridge.apply(root, admitted, True)
                if newer:
                    next(row for row in incus.current['egress'] if row.get('destination') == '172.17.0.2/32')['description'] = 'orbit:endpoint:1800000000000009'
                manual = {**bridge.endpoint_rule('172.17.0.2', 3306, admitted['cidr'], admitted['admitted_at']), 'description': 'manual'}
                incus.current['egress'].append(manual)
                protected = [dict(row) for row in incus.current['egress']
                             if row.get('destination') in ('10.253.0.1/32', '10.44.0.8/32') or row.get('protocol') == 'udp']
                with patch.object(bridge, 'api', side_effect=incus.api), patch.object(bridge.subprocess, 'check_output', return_value=nat('172.17.0.9')):
                    bridge.apply(root, None)
                    bridge.apply(root, None)
                for rule in [manual, *protected]:
                    self.assertIn(rule, incus.current['egress'])
                old = [row for row in incus.current['egress'] if row.get('destination') == '172.17.0.2/32' and row.get('description') != 'manual']
                self.assertEqual(bool(old), shared or newer)

    def test_address_flap_during_add_defers_retirement_until_a_later_unchanged_run(self):
        for returned in ('172.17.0.2', '172.17.0.10'):
            with self.subTest(returned=returned), tempfile.TemporaryDirectory() as directory:
                root, incus = Path(directory), Incus()
                table, flipped, writes = [nat()], [False], []
                docker = {**intent(1), 'endpoints': [{'host': '10.44.0.7', 'port': 3306, 'docker': True}]}

                def api(name, value=None, etag=None, deadline=None, incus=incus, returned=returned, table=table, flipped=flipped, writes=writes):
                    result = incus.api(name, value, etag, deadline)
                    if value is not None:
                        writes.append({row['destination'] for row in value['egress']})
                        if not flipped[0] and '172.17.0.9/32' in writes[-1]:
                            # Exactly the reviewer's window: Docker moves after ADD's PUT.
                            flipped[0] = True
                            table[0] = nat(returned)
                    return result

                with patch.object(bridge, 'api', side_effect=api), patch.object(bridge.subprocess, 'check_output', side_effect=lambda *_args, table=table, **_kwargs: table[0]) as read:
                    bridge.apply(root, docker, True)
                    writes.clear()
                    table[0] = nat('172.17.0.9')
                    read.reset_mock()
                    bridge.apply(root, None)
                    self.assertEqual(read.call_count, 1)
                    self.assertEqual(writes, [{'172.17.0.2/32', '172.17.0.9/32'}])
                    self.assertEqual({row['destination'] for row in incus.current['egress']}, {'172.17.0.2/32', '172.17.0.9/32'})
                    bridge.apply(root, None)  # A or C differs from the record's B: retain history again.
                    self.assertIn('172.17.0.9/32', {row['destination'] for row in incus.current['egress']})
                    self.assertIn(returned + '/32', {row['destination'] for row in incus.current['egress']})
                    bridge.apply(root, None)  # Only now is A/C confirmed by a later unchanged observation.
                    self.assertEqual([row['destination'] for row in incus.current['egress']], [returned + '/32'])
                    self.assertEqual(bridge.apply(root, None), 0)

    def test_stable_publication_cannot_retire_an_unstable_publications_shared_history(self):
        with tempfile.TemporaryDirectory() as directory:
            root, incus = Path(directory), Incus()
            publications = [{'host': '10.44.0.7', 'port': port, 'docker': True} for port in (3306, 3307)]
            first, second = nat(), nat().replace('--dport 3306 ', '--dport 3307 ')
            with patch.object(bridge, 'api', side_effect=incus.api):
                with patch.object(bridge.subprocess, 'check_output', return_value=first + second):
                    bridge.apply(root, {**intent(1), 'endpoints': publications}, True)
                with patch.object(bridge.subprocess, 'check_output', return_value=nat('172.17.0.9') + second):
                    bridge.apply(root, None)
                # First publication now confirms B, while the second just moved from A to C.
                with patch.object(bridge.subprocess, 'check_output', return_value=nat('172.17.0.9') + second.replace('172.17.0.2', '172.17.0.10')):
                    bridge.apply(root, None)
                    self.assertEqual({row['destination'] for row in incus.current['egress']}, {'172.17.0.2/32', '172.17.0.9/32', '172.17.0.10/32'})
                    bridge.apply(root, None)
                    self.assertEqual({row['destination'] for row in incus.current['egress']}, {'172.17.0.9/32', '172.17.0.10/32'})

    def test_sixty_record_refresh_reports_lock_cost_and_one_nat_read(self):
        with tempfile.TemporaryDirectory() as directory:
            root, incus = Path(directory), Incus()
            publications = [{'host': '10.253.0.1', 'port': 24000 + i, 'docker': True} for i in range(60)]

            def table(network):
                return ''.join('-A DOCKER -d 10.253.0.1/32 -p tcp --dport ' + str(row['port']) +
                               ' -j DNAT --to-destination ' + network + '.' + str(i + 2) + ':3306\n'
                               for i, row in enumerate(publications))

            counts = {'get': 0, 'put': 0}

            def api(name, value=None, etag=None, deadline=None):
                counts['get' if value is None else 'put'] += 1
                return incus.api(name, value, etag, deadline)

            with patch.object(bridge, 'api', side_effect=api):
                with patch.object(bridge.subprocess, 'check_output', return_value=table('172.18.0')):
                    bridge.apply(root, {**intent(1), 'endpoints': publications}, True)
                with patch.object(bridge.subprocess, 'check_output', return_value=table('172.19.0')) as read:
                    for phase in ('add', 'confirm', 'steady'):
                        counts.update(get=0, put=0)
                        read.reset_mock()
                        started = time.perf_counter()
                        changed = bridge.apply(root, None)
                        elapsed = time.perf_counter() - started
                        print('BRIDGE-60', phase, 'seconds=' + format(elapsed, '.4f'), 'nat_reads=' + str(read.call_count),
                              'gets=' + str(counts['get']), 'puts=' + str(counts['put']))
                        self.assertEqual(read.call_count, 1)
                        self.assertEqual(counts, {'get': 1 if phase == 'steady' else 61, 'put': 0 if phase == 'steady' else 60})
                        if phase == 'steady':
                            self.assertEqual(changed, 0)
                    self.assertEqual(len(incus.current['egress']), 60)
                    self.assertTrue(all(row['destination'].startswith('172.19.0.') for row in incus.current['egress']))

    def test_dead_publication_warns_without_blocking_live_refresh_and_returns_automatically(self):
        with tempfile.TemporaryDirectory() as directory, patch.object(bridge, 'api', side_effect=Incus().api) as calls:
            root = Path(directory)
            incus = calls.side_effect.__self__
            live = {'host': '10.44.0.7', 'port': 3306, 'docker': True}
            dead = {'host': '10.253.0.1', 'port': 13310, 'docker': True}
            mail = {'host': '10.253.0.1', 'port': 1025, 'docker': False}
            database = {'host': '172.17.0.5', 'port': 3306, 'docker': False}
            baseline = {'action': 'allow', 'protocol': 'udp', 'destination_port': '53', 'state': 'enabled', 'description': 'baseline DNS'}
            admitted = {**intent(1), 'endpoints': [live, dead, mail, database], 'baseline': {'egress': [baseline]}}
            dead_nat = '-A DOCKER -d 10.253.0.1/32 -p tcp --dport 13310 -j DNAT --to-destination 172.17.0.8:9999\n'
            with patch.object(bridge.subprocess, 'check_output', return_value=nat() + dead_nat):
                bridge.apply(root, admitted, True)
            record_names = sorted(path.name for path in (root / 'endpoints').glob('*.json'))
            protected = [dict(row) for row in incus.current['egress']
                         if row.get('destination') in ('10.253.0.1/32', '172.17.0.5/32') or row.get('protocol') == 'udp']
            output = io.StringIO()
            with patch.object(bridge.subprocess, 'check_output', return_value=nat('172.17.0.9')),                     patch.object(sys, 'argv', ['bridge', '--state-dir', str(root)]), redirect_stdout(output):
                self.assertIsNone(bridge.main())  # Real CLI entry point exits successfully.
            self.assertNotIn('error_code', json.loads(output.getvalue()))
            self.assertIn('172.17.0.9/32', [row.get('destination') for row in incus.current['egress']])
            self.assertNotIn('172.17.0.8/32', [row.get('destination') for row in incus.current['egress']])
            self.assertEqual(json.loads((root / 'health.json').read_text()), {
                'error_code': None, 'warnings': [{'code': 'instance.container_endpoint_unresolved', 'host': dead['host'], 'port': dead['port']}],
            })
            self.assertEqual(sorted(path.name for path in (root / 'endpoints').glob('*.json')), record_names)
            for rule in protected:
                self.assertIn(rule, incus.current['egress'])
            # A stopped live service returns on the next timer invocation, at any new Docker address.
            with patch.object(bridge.subprocess, 'check_output', return_value=nat('172.17.0.9') + dead_nat.replace('172.17.0.8', '172.17.0.10')):
                bridge.apply(root, None)
                self.assertIn('172.17.0.10/32', [row.get('destination') for row in incus.current['egress']])
                self.assertEqual(json.loads((root / 'health.json').read_text()), {'error_code': None})
                self.assertEqual(bridge.apply(root, None), 0)

    def test_unresolved_refresh_revokes_all_saved_versions_but_protects_foreign_rules(self):
        with tempfile.TemporaryDirectory() as directory:
            root, incus = Path(directory), Incus()
            dead = {'host': '10.44.0.7', 'port': 3306, 'docker': True}
            with patch.object(bridge, 'api', side_effect=incus.api):
                with patch.object(bridge.subprocess, 'check_output', return_value=nat()):
                    bridge.apply(root, {**intent(1), 'endpoints': [dead]}, True)
                with patch.object(bridge.subprocess, 'check_output', return_value=nat('172.17.0.9')):
                    bridge.apply(root, {**intent(2), 'endpoints': [dead]})
                manual = {**incus.current['egress'][0], 'description': 'manual'}
                newer = {**incus.current['egress'][0], 'description': 'orbit:endpoint:1800000000000009'}
                incus.current['egress'] += [manual, newer]
                with patch.object(bridge.subprocess, 'check_output', return_value=''):
                    bridge.apply(root, None)
                self.assertEqual(incus.current['egress'], [manual, newer])
                self.assertEqual(len(list((root / 'endpoints').glob('*.json'))), 2)
                self.assertEqual(len(json.loads((root / 'health.json').read_text())['warnings']), 1)

    def test_unresolved_saved_tuple_stays_allowed_when_another_endpoint_proves_it(self):
        with tempfile.TemporaryDirectory() as directory:
            root, incus = Path(directory), Incus()
            shared = {'host': '172.17.0.2', 'port': 3306, 'docker': False}
            dead = {'host': '10.44.0.7', 'port': 3306, 'docker': True}
            with patch.object(bridge, 'api', side_effect=incus.api):
                with patch.object(bridge.subprocess, 'check_output', return_value=nat()):
                    bridge.apply(root, {**intent(1), 'endpoints': [dead, shared]}, True)
                before = copy.deepcopy(incus.current)
                with patch.object(bridge.subprocess, 'check_output', return_value=''):
                    bridge.apply(root, None)
                self.assertEqual(incus.current, before)
                self.assertEqual(len(json.loads((root / 'health.json').read_text())['warnings']), 1)

    def test_unresolved_warnings_remain_visible_beside_an_earlier_ambiguous_write(self):
        with tempfile.TemporaryDirectory() as directory, patch.object(bridge, 'api', side_effect=Incus().api):
            root = Path(directory)
            dead = {'host': '10.44.0.7', 'port': 3306, 'docker': True}
            with patch.object(bridge.subprocess, 'check_output', return_value=nat()):
                bridge.apply(root, {**intent(1), 'endpoints': [dead]}, True)
            bridge.state_file(root, 'health', {'error_code': 'instance.bridge_state_unresolved'})
            with patch.object(bridge.subprocess, 'check_output', return_value=''):
                bridge.apply(root, None)
            self.assertEqual(json.loads((root / 'health.json').read_text()), {
                'error_code': 'instance.bridge_state_unresolved',
                'warnings': [{'code': 'instance.container_endpoint_unresolved', 'host': dead['host'], 'port': dead['port']}],
            })

    def test_unresolved_refresh_write_failure_still_exits_nonzero_and_reaches_error_health(self):
        with tempfile.TemporaryDirectory() as directory:
            root, incus = Path(directory), Incus()
            dead = {'host': '10.44.0.7', 'port': 3306, 'docker': True}
            with patch.object(bridge, 'api', side_effect=incus.api), patch.object(bridge.subprocess, 'check_output', return_value=nat()):
                bridge.apply(root, {**intent(1), 'endpoints': [dead]}, True)

            def refuse(name, value=None, etag=None, deadline=None):
                if value is not None:
                    raise bridge.PolicyError('instance.container_bridge_write_failed')
                return incus.api(name, value, etag, deadline)

            output, error = io.StringIO(), io.StringIO()
            with (
                patch.object(bridge, 'api', side_effect=refuse),
                patch.object(bridge.subprocess, 'check_output', return_value=''),
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


if __name__ == '__main__':
    unittest.main()
