"""The `base` memory snapshot: how it is taken, how it is restored inside 45 s, and where the workflows call them.

These tests drive a fake Incus and a fake CLI. A live restore on a task VM is separate acceptance evidence.
"""
import json
import subprocess
import sys
import threading
import unittest
from pathlib import Path
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
import phases
import ready

RESET = Path(__file__).resolve().parents[1]
GUESTS = {'gateway': 'orbit-e2e-task-3187-aaaa-gateway', 'app-dev': 'orbit-e2e-task-3187-aaaa-app-dev',
          'app-prod': 'orbit-e2e-task-3187-aaaa-app-prod'}
TOPOLOGY = {'construction': {'nodes': {role: {'instance': name} for role, name in GUESTS.items()}}}
REQUEST = {'candidate': '/candidate', 'group': '3187', 'evidence': '/nonexistent'}
STATEFUL = {'config': {'migration.stateful': 'true'}, 'expanded_config': {'limits.memory': '2560MiB'},
            'expanded_devices': {'root': {'type': 'disk', 'size.state': '2560MiB'}}}


class FakeIncus:
    def __init__(self, *, guests=None, hook=None):
        self.calls, self.hook, self.lock = [], hook, threading.Lock()
        self.guests = guests or {name: dict(STATEFUL) for name in GUESTS.values()}

    def __call__(self, args, **kwargs):
        args = [str(argument) for argument in args]
        with self.lock:
            self.calls.append((args, kwargs))
        if args[0] == 'query':
            return json.dumps(self.guests[args[1].rsplit('/', 1)[1]]).encode()
        if self.hook:
            self.hook(args)
        return b''

    def argv(self):
        return [argv for argv, _ in self.calls]


class FakeCli:
    """Answers the three CLI reads of the restore and records their order."""

    def __init__(self, *, status='ok', healthy=True, agents=None, online_after=0, on_call=None):
        self.status, self.healthy, self.calls, self.clocks_set, self.incus = status, healthy, [], [], None
        self.agents = agents or {role: 'online' for role in ready.ROLES}
        self.online_after, self.reads, self.on_call, self.timeouts = online_after, 0, on_call, []

    def __call__(self, request, *args, **kwargs):
        self.calls.append(args)
        self.timeouts.append(kwargs.get('timeout'))
        if self.on_call:
            self.on_call()
        if self.incus:
            self.clocks_set.append(sum(1 for argv in self.incus.argv() if argv[0] == 'exec'))
        if args[0] == 'gateway:status':
            return 0, {'status': self.status}
        if args[0] == 'doctor':
            return 0, {'healthy': self.healthy}
        self.reads += 1
        offline = self.reads <= self.online_after
        return 0, {'nodes': [{'name': 'operator', 'agent': {'state': 'offline'}},
                             *({'name': role, 'agent': {'state': 'offline' if offline else state}} for role, state in self.agents.items())]}


class FakeTunnel:
    """Records what the guests had done when the ping started, and answers like a tunnel that is up (or not)."""

    def __init__(self, incus, *, up=True):
        self.incus, self.up, self.calls = incus, up, []

    def __call__(self, address, seconds):
        self.calls.append({'address': address, 'seconds': seconds, 'restored': sum(1 for argv in self.incus.argv() if argv[:2] == ['snapshot', 'restore'])})
        return self.up


def patched(incus, cli):
    return (patch.object(phases, 'incus', incus), patch.object(phases, 'local_cli', cli),
            patch.object(phases, 'prepared', return_value=TOPOLOGY),
            patch.object(phases, 'wireguard', return_value={'nodes': {'gateway': {'ip': '10.45.0.1'}}}))


class TakeTests(unittest.TestCase):
    def take(self, incus):
        incus_patch, _, prepared, _ = patched(incus, None)
        with incus_patch, prepared:
            return phases.base_take(REQUEST)

    def test_all_three_guests_are_snapshotted_with_memory_in_parallel(self):
        # Every snapshot waits for the other two: only a parallel take gets past the barrier.
        barrier = threading.Barrier(3)
        incus = FakeIncus(hook=lambda args: barrier.wait(timeout=5) if args[:2] == ['snapshot', 'create'] else None)
        result = self.take(incus)
        for name in GUESTS.values():
            self.assertIn(['snapshot', 'create', name, 'base', '--stateful', '--reuse'], incus.argv())
        self.assertEqual('base', result['snapshot'])
        self.assertEqual(set(GUESTS.values()), set(result['guests']))

    def test_a_guest_that_is_not_stateful_fails_before_any_snapshot(self):
        guests = {name: dict(STATEFUL) for name in GUESTS.values()}
        guests[GUESTS['app-dev']] = {**STATEFUL, 'config': {}}
        incus = FakeIncus(guests=guests)
        with self.assertRaisesRegex(RuntimeError, 'app-dev is not stateful'):
            self.take(incus)
        self.assertFalse(any(argv[0] == 'snapshot' for argv in incus.argv()))

    def test_a_size_state_that_differs_from_the_memory_is_not_stateful(self):
        guests = {name: dict(STATEFUL) for name in GUESTS.values()}
        guests[GUESTS['gateway']] = {**STATEFUL, 'expanded_devices': {'root': {'type': 'disk'}}}
        with self.assertRaisesRegex(RuntimeError, 'gateway is not stateful'):
            self.take(FakeIncus(guests=guests))

    def test_a_failed_snapshot_fails_the_phase(self):
        def fail(args):
            if args[:2] == ['snapshot', 'create']:
                raise RuntimeError('incus exited 1: snapshot')
        with self.assertRaisesRegex(RuntimeError, 'snapshot'):
            self.take(FakeIncus(hook=fail))


class RestoreTests(unittest.TestCase):
    def restore(self, incus, cli, tunnel=None):
        incus_patch, cli_patch, prepared, wireguard = patched(incus, cli)
        self.tunnel = tunnel or FakeTunnel(incus)
        with incus_patch, cli_patch, prepared, wireguard, patch.object(phases, 'tunnel_up', self.tunnel):
            return phases.base_restore(REQUEST)

    def test_each_guest_is_restored_then_waited_for_then_gets_the_outer_clock(self):
        incus, cli = FakeIncus(), FakeCli()
        with patch.object(phases.time, 'time', return_value=1793836800.25):
            result = self.restore(incus, cli)
        for name in GUESTS.values():
            order = [argv for argv in incus.argv() if name in argv]
            self.assertEqual(['snapshot', 'restore', name, 'base', '--stateful'], order[0])
            self.assertEqual(['wait', name, 'agent'], order[1][:3])
            self.assertEqual(['exec', name, '--', 'date', '-u', '-s', '@1793836800.250000'], order[2])
        self.assertEqual({'restored', 'clocks', 'tunnel', 'healthy'}, set(result['seconds']))

    def test_the_guests_are_restored_in_parallel_and_every_exec_has_an_empty_stdin(self):
        barrier = threading.Barrier(3)
        incus = FakeIncus(hook=lambda args: barrier.wait(timeout=5) if args[:2] == ['snapshot', 'restore'] else None)
        self.restore(incus, FakeCli())
        for argv, kwargs in incus.calls:
            if argv[0] == 'exec':
                self.assertEqual(b'', kwargs['data'])

    def test_the_tunnel_ping_starts_when_the_guests_are_back_and_runs_beside_the_agent_waits(self):
        incus = FakeIncus()
        self.restore(incus, FakeCli())
        self.assertEqual(1, len(self.tunnel.calls))
        call = self.tunnel.calls[0]
        self.assertEqual({'address': '10.45.0.1', 'restored': 3}, {key: call[key] for key in ('address', 'restored')})
        self.assertLessEqual(call['seconds'], 45)

    def test_the_fleet_is_checked_after_every_clock_and_tunnel_and_doctor_comes_last(self):
        incus, cli = FakeIncus(), FakeCli()
        cli.incus = incus
        self.restore(incus, cli)
        self.assertEqual([3, 3, 3], cli.clocks_set)
        self.assertEqual([('gateway:status', '--json'), ('node:list', '--json'), ('doctor', '--json')], cli.calls)

    def test_the_agents_are_read_until_they_are_online_and_doctor_runs_after_that(self):
        cli = FakeCli(online_after=3)
        self.restore(FakeIncus(), cli)
        self.assertEqual(['gateway:status'] + ['node:list'] * 4 + ['doctor'], [call[0] for call in cli.calls])

    def test_agents_that_never_come_online_end_at_the_bound_and_doctor_never_runs(self):
        clock = [1000.0]
        cli = FakeCli(agents={'gateway': 'online', 'app-dev': 'online', 'app-prod': 'offline'}, on_call=lambda: clock.__setitem__(0, clock[0] + 10))
        with patch.object(phases.time, 'monotonic', side_effect=lambda: clock[0]):
            with self.assertRaisesRegex(phases.InfrastructureError, r'Infrastructure error.*\(the bound is 45 s\)'):
                self.restore(FakeIncus(), cli)
        self.assertNotIn('doctor', [call[0] for call in cli.calls])
        self.assertLessEqual(len(cli.calls), 5)

    def test_a_tunnel_that_does_not_come_back_fails_before_any_check(self):
        incus, cli = FakeIncus(), FakeCli()
        with self.assertRaisesRegex(phases.InfrastructureError, 'tunnel to the nested Gateway did not come back'):
            self.restore(incus, cli, FakeTunnel(incus, up=False))
        self.assertEqual([], cli.calls)

    def test_a_gateway_that_does_not_answer_ok_fails(self):
        cli = FakeCli(status='down')
        with self.assertRaisesRegex(phases.InfrastructureError, 'does not answer ok'):
            self.restore(FakeIncus(), cli)
        self.assertEqual([('gateway:status', '--json')], cli.calls)

    def test_a_doctor_finding_fails(self):
        cli = FakeCli(healthy=False)
        with self.assertRaisesRegex(phases.InfrastructureError, 'Doctor is not healthy'):
            self.restore(FakeIncus(), cli)
        self.assertEqual(('doctor', '--json'), cli.calls[-1])

    def test_an_incus_error_is_an_infrastructure_error_and_stops_the_checks(self):
        def fail(args):
            if args[:2] == ['snapshot', 'restore'] and GUESTS['gateway'] in args:
                raise RuntimeError('incus exited 1: no such snapshot')
        cli = FakeCli()
        with self.assertRaisesRegex(phases.InfrastructureError, 'no such snapshot'):
            self.restore(FakeIncus(hook=fail), cli)
        self.assertEqual([], cli.calls)
        self.assertEqual([], self.tunnel.calls)

    def test_a_timeout_in_a_child_process_is_an_infrastructure_error(self):
        def slow(args):
            raise subprocess.TimeoutExpired('incus', 1)
        with self.assertRaisesRegex(phases.InfrastructureError, 'Infrastructure error'):
            self.restore(FakeIncus(hook=slow), FakeCli())

    def test_the_whole_phase_has_one_45_second_bound(self):
        clock = [1000.0]

        def slow_restore(args):
            if args[:2] == ['snapshot', 'restore']:
                clock[0] += 46

        cli = FakeCli()
        with patch.object(phases.time, 'monotonic', side_effect=lambda: clock[0]):
            with self.assertRaisesRegex(phases.InfrastructureError, r'Infrastructure error.*\(the bound is 45 s\)'):
                self.restore(FakeIncus(hook=slow_restore), cli)
        self.assertEqual([], cli.calls)
        self.assertEqual(45, phases.BASE_RESTORE_SECONDS)

    def test_every_child_process_gets_what_is_left_of_the_bound(self):
        incus, cli = FakeIncus(), FakeCli()
        self.restore(incus, cli)
        for argv, kwargs in incus.calls:
            if argv[0] != 'query':
                self.assertLessEqual(kwargs['timeout'], 45)
        self.assertTrue(all(timeout <= 45 for timeout in cli.timeouts))


class WiringTests(unittest.TestCase):
    def text(self, name):
        return (RESET / name).read_text()

    def test_both_phases_are_bounded_actions_that_the_dispatcher_runs(self):
        self.assertTrue({'base-take', 'base-restore'} <= set(phases.ACTIONS))
        for action, function in (('base-take', 'base_take'), ('base-restore', 'base_restore')):
            with patch.object(phases, function, return_value={'ran': action}):
                self.assertEqual({'ran': action}, phases.execute({'evidence': '/nonexistent'}, action))

    def test_the_reset_from_ready_copies_takes_base_after_its_readiness_check(self):
        text = self.text('workflow-proof-fleet-reset-ready.yaml')
        self.assertIn('phases.py" base-take', text)
        step = text[text.index('name: base'):]
        self.assertIn('step: readiness', step[:step.index('name: publish')])
        self.assertLess(text.index('name: base'), text.index('name: publish'))

    def test_making_ready_copies_takes_base_after_the_set_is_complete(self):
        text = self.text('workflow-proof-fleet-ready.yaml')
        step = text[text.index('name: base'):]
        self.assertIn('step: complete', step[:step.index('name: failure')])

    def test_a_full_build_does_not_take_base(self):
        # Its guests are not stateful: Incus changes that only on a stopped VM, and a build never stops them.
        self.assertNotIn('base-take', self.text('workflow-proof-fleet-reset.yaml'))

    def test_the_restore_workflow_is_one_bounded_placed_step(self):
        text = self.text('workflow-proof-fleet-base-restore.yaml')
        self.assertIn('name: proof-fleet-base-restore', text)
        self.assertIn('phases.py" base-restore', text)
        self.assertIn('behaviorName: proof-base-restore', text)
        self.assertNotIn('retry', text.lower())

    def test_the_chart_shows_both_steps(self):
        chart = self.text('reset.mmd')
        self.assertIn('incus snapshot create base --stateful --reuse', chart)
        self.assertIn('incus snapshot restore base --stateful', chart)
        self.assertIn('45 s limit', chart)


if __name__ == '__main__':
    unittest.main()
