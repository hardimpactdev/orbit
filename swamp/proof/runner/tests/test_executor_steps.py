"""The executor proof steps: the signer runs on the nested Gateway, the order reaches the Node as one named caller, and each
expectation fails on the wrong reply."""
import json
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
import runner
import spec
import steps
from fakes import FakeFleet


def done(out='', code=0, err=''):
    return subprocess.CompletedProcess([], code, out, err)


class ExecutorStepsTest(unittest.TestCase):
    def run_step(self, answers, args):
        calls = []

        def sh(node, command, timeout=60, stdin_text=None):
            calls.append((node, command, stdin_text))
            for marker, answer in answers:
                if marker in command:
                    return answer
            return done()
        with tempfile.TemporaryDirectory() as directory:
            run = runner.Run(FakeFleet(), spec.Spec('node:converge', 'node/converge', 'app-dev', [], [], []), directory)
            run.fleet.sh = sh
            return steps.executor_order(run, args), calls, run

    def test_the_relay_hands_the_signed_order_over_and_checks_the_result_line(self):
        signer = ('executor-order.php', done(json.dumps({'order': 'cGF5.c2ln', 'nonce': 'ab' * 16})))
        reply = '{"status":"accepted"}\n{"status":"done","repeat":false,"operation":"proof-1"}\n'
        failures, calls, run = self.run_step([signer, ('orbit-executor relay', done(reply))],
                                             {'on': 'app-dev', 'operation': 'proof-1', 'save': 'n1', 'expect': {'status': 'done', 'repeat': False}})
        self.assertEqual(failures, [])
        gateway, _, payload = next(call for call in calls if 'executor-order.php' in call[1])
        self.assertEqual(gateway, 'gateway')
        self.assertEqual(json.loads(payload), {'mode': 'order', 'node': run.fleet.nodes['app-dev']['node_id'], 'operation': 'proof-1'})
        relay = next(call for call in calls if 'orbit-executor relay' in call[1])
        self.assertEqual(relay[:2], ('app-dev', 'sudo -n -u orbit-executor-relay /usr/local/libexec/orbit-executor relay'))
        self.assertEqual(relay[2], 'cGF5.c2ln')
        self.assertEqual(run.names['n1'], 'ab' * 16)

        failures, _, _ = self.run_step([signer, ('orbit-executor relay', done(reply))],
                                       {'on': 'app-dev', 'operation': 'proof-1', 'expect': {'status': 'refused', 'reason': 'replayed_nonce'}})
        self.assertTrue(failures)

    def test_a_refused_caller_needs_an_empty_reply_and_its_code_in_the_executor_log(self):
        signer = ('executor-order.php', done(json.dumps({'order': 'o', 'nonce': 'cd' * 16})))
        args = {'on': 'app-dev', 'operation': 'proof-2', 'as': 'root_outside_observer', 'expect': {'refused_caller': 'caller_outside_observer_unit'}}
        logged = ('journalctl', done('refused caller uid=0 pid=9 cgroup="0::/init.scope": caller_outside_observer_unit\n'))
        self.assertEqual(self.run_step([signer, logged], args)[0], [])
        self.assertTrue(self.run_step([signer, ('journalctl', done(''))], args)[0])
        self.assertTrue(self.run_step([signer, logged, ('libexec/orbit-executor relay', done('{"status":"accepted"}\n'))], args)[0])

    def test_another_user_is_refused_by_the_socket_mode(self):
        signer = ('executor-order.php', done(json.dumps({'order': 'o', 'nonce': 'ef' * 16})))
        args = {'on': 'app-dev', 'operation': 'proof-3', 'as': 'other_user', 'expect': {'connect': 'denied'}}
        denied = ('runuser -u nobody', done(code=1, err='orbit-executor: relay i/o: Permission denied (os error 13)'))
        self.assertEqual(self.run_step([signer, denied], args)[0], [])
        self.assertTrue(self.run_step([signer, ('runuser -u nobody', done('{"status":"accepted"}\n'))], args)[0])


    def publish(self, answers, args):
        calls = []

        def sh(node, command, timeout=60, stdin_text=None):
            calls.append((node, command, stdin_text))
            for marker, answer in answers:
                if marker in command:
                    return answer.pop(0) if isinstance(answer, list) and len(answer) > 1 else answer[0] if isinstance(answer, list) else answer
            return done()
        with tempfile.TemporaryDirectory() as directory:
            run = runner.Run(FakeFleet(), spec.Spec('node:converge', 'node/converge', 'app-dev', [], [], []), directory)
            run.fleet.sh = sh
            failures = steps.executor_publish(run, args)
            evidence = {path.name: path.read_text() for path in Path(directory).rglob('executor-ws-*')}
            return failures, calls, run, evidence

    def test_published_orders_need_a_done_record_each_and_their_latency_is_saved(self):
        orders = [{'operation': str(9_000_000_000_000_000 + index), 'nonce': f'{index:032x}', 'published_ms': 1000 + index} for index in range(3)]
        signer = ('executor-order.php', done(json.dumps({'orders': orders})))
        journal = '\n'.join(json.dumps({'record': 'done', 'nonce': order['nonce'], 'outcome': {'status': 'done'}, 'at_ms': order['published_ms'] + 4 + index})
                            for index, order in enumerate(orders)) + '\nnot json\n'
        failures, calls, run, evidence = self.publish([signer, ('/var/lib/orbit/executor/journal', done(journal))],
                                                      {'on': 'app-dev', 'operation': 'proof-ws', 'count': 3, 'expect': {'done': True}})
        self.assertEqual(failures, [])
        payload = json.loads(next(call for call in calls if 'executor-order.php' in call[1])[2])
        self.assertEqual(payload, {'mode': 'publish', 'node': run.fleet.nodes['app-dev']['node_id'], 'count': 3})
        summary = json.loads(evidence['executor-ws-proof-ws.json'])
        self.assertEqual((summary['count'], summary['p50_ms'], summary['max_ms']), (3, 5, 6))
        waits = [call for call in calls if call[1].startswith('sleep ')]
        self.assertEqual([call[:2] for call in waits], [('app-dev', f'sleep {steps.EXECUTOR_RESULT_SECONDS + 1}')], 'one fixed wait on the Node')
        reads = [call for call in calls if steps.EXECUTOR_JOURNAL in call[1]]
        self.assertEqual(len(reads), 1, 'the journal is read once')

        missing = '\n'.join(journal.splitlines()[:2])
        failures, _, _, _ = self.publish([signer, ('/var/lib/orbit/executor/journal', done(missing))],
                                         {'on': 'app-dev', 'operation': 'proof-ws', 'count': 3, 'expect': {'done': True}})
        self.assertEqual(failures, ['executor app-dev over the WebSocket: 1 of 3 orders have no done record within 3 s'])

    def test_a_tampered_order_needs_its_refusal_from_the_observer_path(self):
        signer = ('executor-order.php', done(json.dumps({'orders': [{'operation': '9000000000000000', 'nonce': 'ab' * 16, 'published_ms': 1}]})))
        args = {'on': 'app-dev', 'operation': 'proof-bad', 'tamper': 'bad_signature', 'expect': {'refused': 'bad_signature'}}
        earlier = 'refused order from Observer: bad_signature\n'
        observer = ('journalctl', [done(earlier), done(earlier * 2)])
        failures, calls, _, _ = self.publish([signer, observer], args)
        self.assertEqual(failures, [])
        self.assertEqual(json.loads(next(call for call in calls if 'executor-order.php' in call[1])[2])['tamper'], 'bad_signature')
        self.assertTrue(self.publish([signer, ('journalctl', [done(earlier), done(earlier)])], args)[0], 'an earlier refusal does not count')
        relay = ('journalctl', [done(''), done('refused order from Relay: bad_signature\n')])
        self.assertTrue(self.publish([signer, relay], args)[0])
        self.assertEqual(spec.STEPS['executor_publish'][1], {'on', 'operation', 'expect'})


    def test_a_replay_is_the_same_wire_published_with_its_original_before_the_one_wait(self):
        nonce = 'ab' * 16
        operation = '9000000000000000'
        first = {'operation': operation, 'nonce': nonce, 'published_ms': 1000, 'replay': False}
        replay = dict(first, published_ms=1012, replay=True)
        signer = ('executor-order.php', done(json.dumps({'orders': [first, replay]})))
        ran = json.dumps({'record': 'done', 'nonce': nonce, 'operation': operation, 'outcome': {'status': 'done'}, 'at_ms': 1003}) + '\n'
        journal = ('/var/lib/orbit/executor/journal', done(ran))
        args = {'on': 'app-dev', 'operation': 'proof-ws-replay', 'replay': True, 'expect': {'done': True, 'refused': 'replayed_nonce'}}

        def refused(first_result):
            return ('journalctl', [done(''), done(f'refused order {operation} from Observer: replayed_nonce first={first_result}\n')])

        failures, calls, _, evidence = self.publish([signer, journal, refused('done')], args)
        self.assertEqual(failures, [])
        self.assertEqual(json.loads(next(call for call in calls if 'executor-order.php' in call[1])[2])['replay'], True)
        commands = [call[1] for call in calls]
        published = next(index for index, command in enumerate(commands) if 'executor-order.php' in command)
        waits = [index for index, command in enumerate(commands) if command.startswith('sleep ')]
        self.assertEqual(len(waits), 1, 'one wait for the pair')
        self.assertLess(published, waits[0], 'the original and its replay are both published before the wait')
        self.assertEqual(json.loads(evidence['executor-ws-proof-ws-replay.json'])['count'], 1, 'only the original runs')
        self.assertEqual(self.publish([signer, journal, refused('none')], args)[0], [],
                         'a replay that reaches the executor before the first result exists is refused too')

        late = dict(replay, published_ms=1000 + steps.EXECUTOR_ORDER_EXPIRY_MS)
        failures, _, _, _ = self.publish([('executor-order.php', done(json.dumps({'orders': [first, late]}))), journal, refused('done')], args)
        self.assertEqual(failures, ['executor app-dev: replay of 9000000000000000 published 5000 ms after it, past the 5000 ms expiry'])

        self.assertTrue(self.publish([signer, journal, ('journalctl', [done(''), done('')])], args)[0], 'a replay that is not refused fails')
        twice = ('/var/lib/orbit/executor/journal', done(ran + ran.replace(nonce, 'cd' * 16)))
        self.assertEqual(self.publish([signer, twice, refused('done')], args)[0],
                         ['executor app-dev: Operation 9000000000000000 ran 2 times for an order and its replay, not once'])

    def test_a_done_record_with_its_status_at_the_top_level_is_not_the_executor_journal(self):
        orders = [{'operation': '42', 'nonce': 'ab' * 16, 'published_ms': 1000}]
        flat = json.dumps({'record': 'done', 'nonce': 'ab' * 16, 'status': 'done', 'at_ms': 1004}) + '\n'
        failures, _, _, _ = self.publish([('executor-order.php', done(json.dumps({'orders': orders}))), (steps.EXECUTOR_JOURNAL, done(flat))],
                                         {'on': 'app-dev', 'operation': 'proof-ws', 'expect': {'done': True}})
        self.assertEqual(failures, ['executor app-dev: results [None]'], 'the executor nests the status as outcome.status')

def guest(step, answers, args):
    """Run one executor step against fixed answers by command marker; return its failures, calls, run and JSON evidence."""
    calls = []

    def sh(node, command, timeout=60, stdin_text=None):
        calls.append((node, command, stdin_text))
        for marker, answer in answers:
            if marker in command:
                return answer
        return done()
    with tempfile.TemporaryDirectory() as directory:
        run = runner.Run(FakeFleet(), spec.Spec('node:converge', 'node/converge', 'app-dev', [], [], []), directory)
        run.fleet.sh = sh
        failures = step(run, args)
        evidence = {path.name: json.loads(path.read_text()) for path in Path(directory).rglob('executor-*.json')}
        return failures, calls, run, evidence


def line(operation, repeat, clock=1):
    return {'operation': operation, 'action': 'proof.noop', 'target': '3', 'status': 'done', 'repeat': repeat, 'node_clock_ms': clock}


def ran(*operations):
    return done(''.join(json.dumps({'record': 'done', 'nonce': f'{index:032x}', 'operation': operation, 'outcome': {'status': 'done'}, 'at_ms': 1})
                        + '\n' for index, operation in enumerate(operations)))


class ExecutorRoundTripTest(unittest.TestCase):
    def trip(self, operation, latency, **changes):
        trip = {'operation': operation, 'nonce': operation * 4, 'latency_ms': latency, 'status': 'done', 'accepted_ms': latency / 2,
                'wait_ms': latency - 1, 'line': line(operation, False), 'recorded': line(operation, False)}
        return {**trip, **changes}

    def round_trip(self, trips, journal, count=None):
        signer = ('executor-order.php', done(json.dumps({'wait_ms': 500, 'round_trips': trips})))
        args = {'on': 'app-dev', 'operation': 'proof-rt', 'count': count or len(trips)}
        return guest(steps.executor_round_trip, [signer, (steps.EXECUTOR_JOURNAL, journal)], args)

    def test_each_round_trip_needs_its_result_in_the_waiter_on_its_operation_and_one_run_and_its_latency_is_saved(self):
        trips = [self.trip(str(10 + index), 4.0 + index) for index in range(20)]
        failures, calls, run, evidence = self.round_trip(trips, ran(*(trip['operation'] for trip in trips)))
        self.assertEqual(failures, [])
        payload = json.loads(next(call for call in calls if 'executor-order.php' in call[1])[2])
        self.assertEqual(payload, {'mode': 'round_trip', 'node': run.fleet.nodes['app-dev']['node_id'], 'count': 20})
        summary = evidence['executor-round-trip-proof-rt.json']['summary']
        self.assertEqual((summary['count'], summary['p50_ms'], summary['p95_ms'], summary['max_ms'], summary['wait_bound_ms']), (20, 14.0, 23.0, 23.0, 500))
        self.assertFalse([call for call in calls if call[1].startswith('sleep ')], 'the waiter waits, not the runner')

    def test_a_round_trip_fails_on_a_timeout_a_repeat_another_record_a_second_run_or_a_missing_trip(self):
        trips = [self.trip('10', 4.0), self.trip('11', 5.0)]
        cases = [
            ([self.trip('10', 4.0, status='timeout', line=None, recorded=None), trips[1]], ran('10', '11'), None, 'waited timeout'),
            ([self.trip('10', 4.0, line=line('10', True), recorded=line('10', True)), trips[1]], ran('10', '11'), None, 'waited done'),
            ([self.trip('10', 4.0, recorded=None), trips[1]], ran('10', '11'), None, 'recorded None'),
            (trips, ran('10', '11', '11'), None, 'Operation 11 ran 2 times, not once'),
            (trips, ran('10', '11'), 3, '2 round trips, expected 3'),
        ]
        for answer, journal, count, message in cases:
            with self.subTest(message):
                failures = self.round_trip(answer, journal, count)[0]
                self.assertEqual(len(failures), 1, failures)
                self.assertIn(message, failures[0])


class ExecutorLostResultTest(unittest.TestCase):
    def answer(self, **changes):
        answer = {'operation': '42', 'first_nonce': 'ab' * 16, 'repeat_nonce': 'cd' * 16, 'recorded': line('42', False),
                  'dropped': {'status': 'done', 'line': line('42', False)}, 'lost': {'status': 'timeout', 'line': None},
                  'recovered': {'status': 'done', 'latency_ms': 6.5, 'line': line('42', True, clock=2)}, 'status': 'succeeded'}
        return {**answer, **changes}

    def lost(self, answer, journal):
        signer = ('executor-order.php', done(json.dumps(answer)))
        return guest(steps.executor_lost_result, [signer, (steps.EXECUTOR_JOURNAL, journal)], {'on': 'app-dev', 'operation': 'proof-lost'})

    def test_a_dropped_hand_off_times_out_and_a_repeat_with_a_new_nonce_returns_the_first_result_of_one_run(self):
        failures, calls, run, evidence = self.lost(self.answer(), ran('42'))
        self.assertEqual(failures, [])
        payload = json.loads(next(call for call in calls if 'executor-order.php' in call[1])[2])
        self.assertEqual(payload, {'mode': 'lost_result', 'node': run.fleet.nodes['app-dev']['node_id']})
        self.assertEqual(evidence['executor-lost-result-proof-lost.json']['recovered']['latency_ms'], 6.5)

    def test_a_lost_result_fails_without_the_loss_the_repeat_the_first_result_or_one_run(self):
        cases = [
            (self.answer(lost={'status': 'done', 'line': line('42', False)}), ran('42'), 'got done, not timeout'),
            (self.answer(repeat_nonce='ab' * 16), ran('42'), 'reused the first nonce'),
            (self.answer(recovered={'status': 'done', 'latency_ms': 1, 'line': line('42', False)}), ran('42'), 'the repeat: waited done'),
            (self.answer(recovered={'status': 'done', 'latency_ms': 1, 'line': {**line('42', True), 'target': '4'}}), ran('42'), 'is not the first result'),
            (self.answer(recovered={'status': 'timeout', 'latency_ms': 1, 'line': None}), ran('42'), 'the repeat: waited timeout'),
            (self.answer(), ran('42', '42'), 'ran 2 times, not once'),
        ]
        for answer, journal, message in cases:
            with self.subTest(message):
                failures = self.lost(answer, journal)[0]
                self.assertTrue(any(message in failure for failure in failures), failures)
        self.assertEqual(len(self.lost(self.answer(recovered={'status': 'timeout', 'latency_ms': 1, 'line': None}, status='failed'), ran('42'))[0]), 3)


if __name__ == '__main__':
    unittest.main()
