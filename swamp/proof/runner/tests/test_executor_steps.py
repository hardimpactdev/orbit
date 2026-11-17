"""The executor proof steps: the signer runs on the nested Gateway, the order reaches the Node as one named caller, and each
expectation fails on the wrong reply."""
import json
import subprocess
import sys
import tempfile
import unittest
import unittest.mock
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
            with unittest.mock.patch.object(steps.time, 'sleep'), \
                    unittest.mock.patch.object(steps.time, 'monotonic', side_effect=[0, 1, 20, 21, 40]):
                failures = steps.executor_publish(run, args)
            evidence = {path.name: path.read_text() for path in Path(directory).rglob('executor-ws-*')}
            return failures, calls, run, evidence

    def test_published_orders_need_a_done_record_each_and_their_latency_is_saved(self):
        orders = [{'operation': str(9_000_000_000_000_000 + index), 'nonce': f'{index:032x}', 'published_ms': 1000 + index} for index in range(3)]
        signer = ('executor-order.php', done(json.dumps({'orders': orders})))
        journal = '\n'.join(json.dumps({'record': 'done', 'nonce': order['nonce'], 'status': 'done', 'at_ms': order['published_ms'] + 4 + index})
                            for index, order in enumerate(orders)) + '\nnot json\n'
        failures, calls, run, evidence = self.publish([signer, ('/var/lib/orbit/executor/journal', done(journal))],
                                                      {'on': 'app-dev', 'operation': 'proof-ws', 'count': 3, 'expect': {'done': True}})
        self.assertEqual(failures, [])
        payload = json.loads(next(call for call in calls if 'executor-order.php' in call[1])[2])
        self.assertEqual(payload, {'mode': 'publish', 'node': run.fleet.nodes['app-dev']['node_id'], 'count': 3})
        summary = json.loads(evidence['executor-ws-proof-ws.json'])
        self.assertEqual((summary['orders'], summary['p50_ms'], summary['max_ms']), (3, 5, 6))

        missing = '\n'.join(journal.splitlines()[:2])
        failures, _, _, _ = self.publish([signer, ('/var/lib/orbit/executor/journal', done(missing))],
                                         {'on': 'app-dev', 'operation': 'proof-ws', 'count': 3, 'expect': {'done': True}})
        self.assertEqual(failures, ['executor app-dev over the WebSocket: 1 of 3 orders have no done record within 15 s'])

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


if __name__ == '__main__':
    unittest.main()
