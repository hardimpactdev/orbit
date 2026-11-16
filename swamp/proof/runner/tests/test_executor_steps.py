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


if __name__ == '__main__':
    unittest.main()
