"""Exact DNAT resolution and shared boot/attach/converge policy, without Docker or Incus mutations."""
import copy
import importlib.util
import io
import json
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
        paused, resume, waiting, acquired = (threading.Event() for _ in range(4))
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
                waiting.set()
                flock(stream, operation)
                acquired.set()
            else:
                flock(stream, operation)

        def run(action):
            try:
                action()
            except BaseException as error:
                failures.append(error)

        threads = [threading.Thread(target=run, args=(action,), name=name)
                   for name, action in [('first', first), ('second', second)]]
        with patch.object(bridge, pause_at, side_effect=pause), patch.object(bridge.fcntl, 'flock', side_effect=observe_lock):
            threads[0].start()
            try:
                self.assertTrue(paused.wait(5), 'First writer did not reach its record/ACL work')
                # Prove the actual flock is held, rather than relying on scheduler timing.
                with (root / 'writer.lock').open('a') as contender:
                    with self.assertRaises(BlockingIOError):
                        flock(contender, bridge.fcntl.LOCK_EX | bridge.fcntl.LOCK_NB)
                threads[1].start()
                self.assertTrue(waiting.wait(5), 'Second writer did not attempt the shared lock')
                self.assertFalse(acquired.is_set(), 'Second writer entered while first owned the lock')
            finally:
                resume.set()
                for thread in threads:
                    if thread.ident is not None:
                        thread.join(5)
            self.assertFalse(any(thread.is_alive() for thread in threads), 'Writer did not release the shared lock')
            self.assertEqual(failures, [])
            self.assertTrue(acquired.is_set())

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

    def test_refresh_queued_behind_withdrawal_reads_records_only_after_the_lock(self):
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

    def test_admission_waits_for_withdrawal_compaction_then_records_new_ownership(self):
        incus = Incus()
        with tempfile.TemporaryDirectory() as directory, patch.object(bridge, 'api', side_effect=incus.api):
            root = Path(directory)
            bridge.apply(root, intent(1, '10.44.0.7'), True)
            self.interleave_writers(root, lambda: bridge.apply(root, intent(2), True, withdraw=True),
                                    lambda: bridge.apply(root, intent(3, '10.44.0.7')), 'write_rules')
            self.assertEqual([json.loads(p.read_text())['admitted_at'] for p in (root / 'endpoints').glob('*.json')], [1800000000000003])
            self.assertEqual(incus.current['egress'], [bridge.endpoint_rule('10.44.0.7', 3306, '10.253.0.0/24', 1800000000000003)])
            self.assertEqual(bridge.apply(root, None), 0)

    def test_lock_wait_allows_a_refresh_longer_than_one_curl_request(self):
        unit = (Path(__file__).parents[1] / 'ansible/tasks/instance-bridge-refresh.yml').read_text()
        self.assertIn('TimeoutStartSec=' + str(bridge.LOCK_WAIT_SECONDS), unit)
        self.assertGreaterEqual(bridge.LOCK_WAIT_SECONDS, 30)
        with tempfile.TemporaryDirectory() as directory, \
                patch.object(bridge.fcntl, 'flock', side_effect=[BlockingIOError, None]), \
                patch.object(bridge.time, 'monotonic', side_effect=[0, 0, 7, 7, 7]), \
                patch.object(bridge.time, 'sleep'):
            with bridge.writer_lock(Path(directory)):
                pass  # A seven-second refresh must not make concurrent create/migrate fail.

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
            self.assertEqual(list(root.glob('.staged-*')), [])
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
                self.assertEqual(list(root.glob('.staged-*')), [])
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

    def test_timed_out_waiter_exits_and_never_applies_its_intent_after_holder_releases(self):
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
            with (root / 'writer.lock').open('a') as holder, patch.object(bridge, 'LOCK_WAIT_SECONDS', 0.05):
                bridge.fcntl.flock(holder, bridge.fcntl.LOCK_EX | bridge.fcntl.LOCK_NB)
                waiter.start()
                waiter.join(1)
                timed_out_before_release = not waiter.is_alive()
            waiter.join(1)
            self.assertTrue(timed_out_before_release, 'Waiter stayed alive beyond its acquisition deadline')
            self.assertEqual(errors, ['instance.bridge_lock_timeout'])
            self.assertEqual(incus.current, before)
            self.assertEqual({str(p): p.read_text() for p in root.rglob('*.json')}, files)

    def test_acquisition_after_deadline_never_enters_the_mutation(self):
        with tempfile.TemporaryDirectory() as directory, patch.object(bridge.time, 'monotonic', side_effect=[0, 0, 0, 30]), \
                patch.object(bridge, 'apply_locked') as apply:
            with self.assertRaisesRegex(bridge.PolicyError, 'instance.bridge_lock_timeout'):
                bridge.apply(Path(directory), intent(1, '10.44.0.7'))
            apply.assert_not_called()

    def test_cli_reports_lock_timeout_without_a_second_error_handler_wait(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            output, errors = io.StringIO(), io.StringIO()
            with (root / 'writer.lock').open('a') as holder, patch.object(bridge, 'LOCK_WAIT_SECONDS', 0.05), \
                    patch.object(bridge.sys, 'argv', ['instance_bridge.py', '--state-dir', directory, '--intent']), \
                    patch.object(bridge.sys, 'stdin', io.StringIO(json.dumps(intent(1, '10.44.0.7')))), \
                    patch.object(bridge, 'writer_lock', wraps=bridge.writer_lock) as lock, \
                    redirect_stdout(output), redirect_stderr(errors):
                bridge.fcntl.flock(holder, bridge.fcntl.LOCK_EX | bridge.fcntl.LOCK_NB)
                with self.assertRaises(SystemExit) as result:
                    bridge.main()
                self.assertEqual(result.exception.code, 1)
                self.assertEqual(lock.call_count, 1)
            self.assertEqual(json.loads(output.getvalue()), {'error_code': 'instance.bridge_lock_timeout'})
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
            bridge.write_file(root / 'health.json', {'error_code': 'instance.container_endpoint_unresolved'})
            with patch.object(bridge.subprocess, 'check_output', return_value=nat('172.17.0.9')):
                bridge.apply(root, intent, False)
            self.assertEqual(json.loads((root / 'health.json').read_text())['error_code'], 'instance.container_endpoint_unresolved')
            before = json.loads(json.dumps(current))
            with patch.object(bridge.subprocess, 'check_output', return_value=''):
                with self.assertRaisesRegex(bridge.PolicyError, 'instance.container_endpoint_unresolved'):
                    bridge.apply(root, None)
                self.assertEqual(current, before)

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
