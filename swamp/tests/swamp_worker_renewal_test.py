"""Exercise the installed renewal controller with barriers at drain and enrollment."""
import concurrent.futures
import copy
import datetime
import importlib.util
import json
import threading
import unittest
from pathlib import Path
from unittest.mock import patch

SCRIPT = Path(__file__).resolve().parents[1] / 'ansible/files/swamp-worker-renewal.py'
SPEC = importlib.util.spec_from_file_location('renewal', SCRIPT)
renewal = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(renewal)
NOW = 1805068800


def worker(slot, age=4 * 3600, status='idle', instance=None):
    return {'name': f'swamp-role-209-{slot}', 'status': status,
            'instanceUuid': instance or f'old-{slot}',
            'enrolledAt': datetime.datetime.fromtimestamp(NOW - age, datetime.UTC).isoformat()}


class Runtime:
    def __init__(self):
        self.state = {slot: worker(slot) for slot in ('a', 'b')}
        self.events = []
        self.draining = threading.Event()
        self.finish = threading.Event()
        self.stopped = threading.Event()
        self.enroll = threading.Event()
        self.fail_verify = None
        self.no_enrollment = False

    def workers(self):
        if self.enroll.is_set() and self.state['a']['status'] == 'disconnected':
            self.state['a'] = worker('a', age=0, instance='new-a')
            self.events.append('enrolled-a')
        return copy.deepcopy(self.state)

    def verify(self, slot):
        self.events.append(f'verify-{slot}')
        if slot == self.fail_verify or not renewal.accepting(self.state[slot]):
            raise RuntimeError('live probe refused')

    def restart(self, slot):
        peer = 'b' if slot == 'a' else 'a'
        assert renewal.accepting(self.state[peer]), 'both workers unavailable'
        self.events.append(f'drain-{slot}')
        self.state[slot]['status'] = 'draining'
        if slot == 'a':
            self.draining.set()
            if not self.finish.wait(timeout=1):
                raise AssertionError('test did not release active dispatch')
            self.events.append('dispatch-a-success')
        self.state[slot]['status'] = 'disconnected'
        if slot == 'a':
            self.stopped.set()
        if slot == 'b' and not self.no_enrollment:
            self.state['b'] = worker('b', age=0, instance='new-b')
            self.events.append('enrolled-b')


class RenewalTest(unittest.TestCase):
    def test_a_finishes_and_reenrolls_before_b_can_drain(self):
        runtime = Runtime()
        with concurrent.futures.ThreadPoolExecutor(max_workers=1) as pool:
            future = pool.submit(renewal.renew, runtime, now=lambda: NOW,
                                 sleep=lambda _: runtime.enroll.wait(timeout=.01))
            try:
                self.assertTrue(runtime.draining.wait(timeout=1))
                self.assertEqual(runtime.state['a']['status'], 'draining')
                self.assertTrue(renewal.accepting(runtime.state['b']))
                self.assertNotIn('drain-b', runtime.events)
                runtime.finish.set()
                # A finished dispatch is insufficient: old/disconnected a must not let b drain.
                self.assertTrue(runtime.stopped.wait(timeout=1))
                self.assertEqual(runtime.state['a']['status'], 'disconnected')
                self.assertNotIn('drain-b', runtime.events)
                runtime.enroll.set()
            finally:
                runtime.finish.set()
                runtime.enroll.set()
                future.result(timeout=1)
        self.assertEqual(runtime.events, ['verify-b', 'drain-a', 'dispatch-a-success', 'enrolled-a',
                                          'verify-a', 'verify-a', 'drain-b', 'enrolled-b', 'verify-b'])

    def test_never_drains_a_if_b_is_draining_or_disconnected(self):
        for status in ('draining', 'disconnected', 'unverified'):
            with self.subTest(status=status):
                runtime = Runtime()
                runtime.state['b']['status'] = status
                with self.assertRaises(RuntimeError):
                    renewal.renew(runtime, now=lambda: NOW)
                self.assertEqual(runtime.events, [])

    def test_live_probe_failure_prevents_any_restart_despite_idle_record(self):
        runtime = Runtime()
        runtime.fail_verify = 'b'
        with self.assertRaises(RuntimeError):
            renewal.renew(runtime, now=lambda: NOW)
        self.assertEqual(runtime.events, ['verify-b'])

    def test_failed_enrollment_leaves_b_accepting_and_untouched(self):
        runtime = Runtime()
        runtime.finish.set()
        ticks = iter([0, 0, 121])
        with self.assertRaisesRegex(RuntimeError, 'did not re-enroll'):
            renewal.renew(runtime, now=lambda: NOW, monotonic=lambda: next(ticks), sleep=lambda _: None)
        self.assertTrue(renewal.accepting(runtime.state['b']))
        self.assertNotIn('drain-b', runtime.events)

    def test_new_a_instance_must_pass_live_probe_before_b_is_touched(self):
        runtime = Runtime()
        runtime.finish.set()
        runtime.enroll.set()
        runtime.fail_verify = 'a'
        with self.assertRaises(RuntimeError):
            renewal.renew(runtime, now=lambda: NOW)
        self.assertTrue(renewal.accepting(runtime.state['b']))
        self.assertNotIn('drain-b', runtime.events)

    def test_retry_does_not_recycle_a_freshly_renewed_peer(self):
        runtime = Runtime()
        runtime.state['a'] = worker('a', age=60, instance='new-a')
        renewal.renew(runtime, now=lambda: NOW)
        self.assertEqual(runtime.events, ['verify-a', 'drain-b', 'enrolled-b', 'verify-b'])

    def test_fresh_connections_stay_persistent(self):
        runtime = Runtime()
        runtime.state = {slot: worker(slot, age=60) for slot in ('a', 'b')}
        renewal.renew(runtime, now=lambda: NOW)
        self.assertEqual(runtime.events, [])


class NativeAdapterTest(unittest.TestCase):
    def test_empty_live_probe_is_not_a_healthy_peer(self):
        runtime = object.__new__(renewal.Runtime)
        runtime.names = {'b': 'swamp-role-209-b'}
        runtime.prefix, runtime.remote = [], []
        result = {'total': 0, 'passed': 0, 'failed': 0, 'workers': []}
        with (patch.object(runtime, 'run', return_value=json.dumps(result)),
              self.assertRaisesRegex(RuntimeError, 'not connected')):
            runtime.verify('b')

    def test_old_stop_timeout_prevents_restart_before_unit_convergence(self):
        runtime = object.__new__(renewal.Runtime)
        with (patch.object(runtime, 'run', return_value='t 300000000') as command,
              self.assertRaisesRegex(RuntimeError, '75 minute')):
            runtime.restart('a')
        self.assertEqual(command.call_count, 1)
        self.assertEqual(command.call_args.args[0][0], 'busctl')


if __name__ == '__main__':
    unittest.main()
