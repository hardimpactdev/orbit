"""Ready copies and the fast reset: contracts of the copy, the restore and the phases that use them.

These tests drive a fake Incus. A live reset from ready copies is separate acceptance evidence.
"""
import json
import re
import subprocess
import sys
import tempfile
import unittest
from datetime import datetime, timezone
from pathlib import Path
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
import launcher
import phases
import ready

GUESTS = {'gateway': 'orbit-e2e-task-3187-aaaa-gateway', 'app-dev': 'orbit-e2e-task-3187-aaaa-app-dev',
          'app-prod': 'orbit-e2e-task-3187-aaaa-app-prod'}
ADDRESSES = {'gateway': '10.232.2.10', 'app-dev': '10.232.2.11', 'app-prod': '10.232.2.12'}
MACS = {'gateway': '00:16:3e:00:00:10', 'app-dev': '00:16:3e:00:00:11', 'app-prod': '00:16:3e:00:00:12'}
LABELS = {'user.orbit.e2e.owner': 'orbit-e2e', 'user.orbit.e2e.issue': 'TASK-3187',
          'user.orbit.e2e.attempt': 'a' * 32, 'user.orbit.e2e.operation': 'b' * 32}
NOW = datetime(2026, 11, 5, 12, 0, 0, tzinfo=timezone.utc)


def guest_row(role, status='Running'):
    devices = {'eth0': {'type': 'nic', 'network': 'oe-net', 'hwaddr': MACS[role], 'ipv4.address': ADDRESSES[role]},
               'root': {'type': 'disk', 'path': '/', 'pool': 'seed'}}
    if role != 'app-prod':
        devices['orbit-source'] = {'type': 'disk', 'path': '/home/orbit/orbit', 'source': '/generation/source'}
    return {'name': GUESTS[role], 'status': status, 'config': dict(LABELS), 'devices': devices}


def copy_row(role, **overrides):
    config = {'user.ready.set': 'set1', 'user.ready.complete': 'set1', 'user.ready.role': role,
              'user.ready.made': '2026-11-05T10:00:00Z', 'user.ready.hwaddr': MACS[role], 'user.ready.ipv4': ADDRESSES[role],
              'user.ready.subnet': '10.232.2.1/24', 'user.ready.nodes': json.dumps({'gateway': 209, 'app-dev': 211}),
              'user.ready.mounts': json.dumps({} if role == 'app-prod' else {'orbit-source': '/home/orbit/orbit'})}
    row = {'name': ready.name(role), 'status': 'Stopped', 'config': config, 'devices': {'root': {'type': 'disk', 'path': '/'}}}
    row.update(overrides)
    return row


def complete_set():
    return {ready.name(role): copy_row(role) for role in ready.ROLES}


class FakeIncus:
    """Records every call and answers the few queries ready.py makes."""

    def __init__(self, rows=(), fail_on=None, stop_works=True):
        self.calls, self.rows, self.fail_on, self.stop_works = [], list(rows), fail_on, stop_works

    def __call__(self, args, **kwargs):
        args = [str(argument) for argument in args]
        self.calls.append(args)
        if self.fail_on and args[:len(self.fail_on)] == self.fail_on:
            raise RuntimeError('incus exited 1: ' + ' '.join(self.fail_on))
        if args[0] == 'stop' and self.stop_works:
            for row in self.rows:
                if row['name'] in args[1:]:
                    row['status'] = 'Stopped'
        if args[0] == 'list':
            return json.dumps(self.rows).encode()
        if args[0] == 'query':
            name = args[1].rsplit('/', 1)[1]
            return json.dumps(next(row for row in self.rows if row['name'] == name)).encode()
        if args[:2] == ['network', 'get']:
            return b'10.232.2.1/24\n'
        return b''

    def verbs(self):
        return [call[:3] for call in self.calls if call[0] not in ('list', 'query', 'network')]


class MakeTests(unittest.TestCase):
    def make(self, incus):
        with patch.object(ready, 'incus', incus), patch.object(ready, 'stamp', return_value='2026-11-05T10:00:00Z'):
            return ready.make(GUESTS, 'oe-net', {'gateway': 209, 'app-dev': 211})

    def test_copies_carry_no_label_nic_or_mount_and_complete_last(self):
        incus = FakeIncus([guest_row(role) for role in ready.ROLES])
        result = self.make(incus)
        calls = incus.calls
        first_copy = next(i for i, call in enumerate(calls) if call[0] == 'copy')
        self.assertEqual(['stop', *GUESTS.values()], calls[next(i for i, call in enumerate(calls) if call[0] == 'stop')])
        self.assertLess(next(i for i, call in enumerate(calls) if call[0] == 'stop'), first_copy)
        for role in ready.ROLES:
            target = ready.name(role)
            self.assertIn(['copy', GUESTS[role], target], calls)
            for label in ready.LABELS:
                self.assertIn(['config', 'unset', target, 'user.orbit.e2e.' + label], calls)
            self.assertIn(['config', 'device', 'remove', target, 'eth0'], calls)
            self.assertEqual(role != 'app-prod', ['config', 'device', 'remove', target, 'orbit-source'] in calls)
        completions = [i for i, call in enumerate(calls) if call[:2] == ['config', 'set'] and any(a.startswith('user.ready.complete=') for a in call)]
        self.assertEqual(3, len(completions))
        self.assertGreater(min(completions), max(i for i, call in enumerate(calls) if call[0] == 'copy'))
        self.assertEqual(['start', *GUESTS.values()], calls[-1])
        self.assertEqual({'set', 'made', 'subnet', 'copies', 'started_at'}, set(result))

    def test_the_mac_the_ip_the_subnet_and_the_mounts_are_recorded_on_the_copies(self):
        incus = FakeIncus([guest_row(role) for role in ready.ROLES])
        self.make(incus)
        recorded = {call[2]: call[3:] for call in incus.calls if call[:2] == ['config', 'set'] and any(item.startswith('user.ready.role=') for item in call)}
        gateway = recorded[ready.name('gateway')]
        self.assertIn('user.ready.hwaddr=' + MACS['gateway'], gateway)
        self.assertIn('user.ready.ipv4=10.232.2.10', gateway)
        self.assertIn('user.ready.subnet=10.232.2.1/24', gateway)
        self.assertIn('user.ready.mounts=' + json.dumps({'orbit-source': '/home/orbit/orbit'}), gateway)
        self.assertTrue(any(item.startswith('user.ready.nodes=') for item in gateway))
        prod = recorded[ready.name('app-prod')]
        self.assertIn('user.ready.mounts={}', prod)
        self.assertFalse(any(item.startswith('user.ready.nodes=') for item in prod))

    def test_an_earlier_set_is_replaced_but_old_ready_names_are_never_touched(self):
        rows = [guest_row(role) for role in ready.ROLES] + list(complete_set().values())
        rows += [{'name': 'ready3-gateway', 'status': 'Stopped', 'config': {}, 'devices': {}}]
        incus = FakeIncus(rows)
        self.make(incus)
        deleted = {call[-1] for call in incus.calls if call[:2] == ['delete', '--force']}
        self.assertEqual({ready.name(role) for role in ready.ROLES}, deleted)
        self.assertTrue(all('ready3-gateway' not in call for call in incus.calls))

    def test_the_fleet_starts_again_and_no_set_completes_when_a_copy_fails(self):
        incus = FakeIncus([guest_row(role) for role in ready.ROLES], fail_on=['copy', GUESTS['app-dev']])
        with self.assertRaisesRegex(RuntimeError, 'copy'):
            self.make(incus)
        self.assertEqual(['start', *GUESTS.values()], incus.calls[-1])
        self.assertFalse(any('user.ready.complete=set' in ' '.join(call) for call in incus.calls))
        self.assertFalse(any(any(a.startswith('user.ready.complete=') for a in call) for call in incus.calls))

    def test_a_guest_without_a_static_nic_is_not_copied(self):
        rows = [guest_row(role) for role in ready.ROLES]
        del rows[1]['devices']['eth0']['hwaddr']
        incus = FakeIncus(rows)
        with self.assertRaisesRegex(RuntimeError, 'static MAC'):
            self.make(incus)
        self.assertEqual([], [call for call in incus.calls if call[0] in ('stop', 'copy', 'delete')])

    def test_nothing_is_copied_unless_every_guest_reports_stopped(self):
        incus = FakeIncus([guest_row(role) for role in ready.ROLES], stop_works=False)
        with self.assertRaisesRegex(RuntimeError, 'is not stopped; nothing copied'):
            self.make(incus)
        self.assertFalse(any(call[0] == 'copy' for call in incus.calls))
        self.assertEqual(['start', *GUESTS.values()], incus.calls[-1])

    def test_stopped_guests_are_not_stopped_again(self):
        incus = FakeIncus([guest_row(role, 'Stopped') for role in ready.ROLES])
        self.make(incus)
        self.assertFalse(any(call[0] == 'stop' for call in incus.calls))


class RestoreTests(unittest.TestCase):
    LABELS = {'owner': 'orbit-e2e', 'issue': 'TASK-3187', 'attempt': 'c' * 32, 'operation': 'd' * 32}

    def restore(self, rows):
        incus = FakeIncus(rows)
        with patch.object(ready, 'incus', incus), patch.object(ready, 'inspect', return_value=ready.inspect({row['name']: row for row in complete_set().values()}, now=NOW)):
            return incus, ready.restore(GUESTS, 'oe-new', '/new/source', self.LABELS)

    def test_guests_are_deleted_first_then_copied_labelled_attached_and_started(self):
        incus, result = self.restore([guest_row(role) for role in ready.ROLES] + list(complete_set().values()))
        calls = incus.calls
        self.assertEqual(['delete', '--force', *GUESTS.values()], calls[1])
        for role in ready.ROLES:
            target = GUESTS[role]
            self.assertIn(['copy', ready.name(role), target], calls)
            self.assertIn(['config', 'set', target, *(f'user.orbit.e2e.{k}={v}' for k, v in self.LABELS.items())], calls)
            self.assertIn(['config', 'device', 'add', target, 'eth0', 'nic', 'network=oe-new', 'hwaddr=' + MACS[role], 'ipv4.address=' + ADDRESSES[role]], calls)
            mount = ['config', 'device', 'add', target, 'orbit-source', 'disk', 'source=/new/source', 'path=/home/orbit/orbit']
            self.assertEqual(role != 'app-prod', mount in calls)
        # A copy needs the original's name and address free, so every delete precedes every copy.
        self.assertLess(max(i for i, c in enumerate(calls) if c[0] == 'delete'), min(i for i, c in enumerate(calls) if c[0] == 'copy'))
        self.assertEqual(['start', *GUESTS.values()], calls[-1])
        self.assertIsInstance(result['started_at'], float)

    def test_a_fleet_that_is_already_gone_is_restored_without_a_delete(self):
        incus, _ = self.restore(list(complete_set().values()))
        self.assertFalse(any(call[0] == 'delete' for call in incus.calls))

    def test_an_unusable_set_is_refused_before_anything_is_deleted(self):
        incus = FakeIncus([guest_row(role) for role in ready.ROLES])
        with patch.object(ready, 'incus', incus), patch.object(ready, 'inspect', return_value={'usable': False, 'reason': 'nothing'}):
            with self.assertRaisesRegex(RuntimeError, 'not usable: nothing'):
                ready.restore(GUESTS, 'oe-new', '/new/source', self.LABELS)
        self.assertEqual([], [call for call in incus.calls if call[0] in ('delete', 'copy', 'start')])


class InspectTests(unittest.TestCase):
    def inspect(self, rows, now=NOW):
        return ready.inspect(rows, now=now)

    def test_a_complete_stopped_set_is_usable_and_exposes_its_facts(self):
        found = self.inspect(complete_set())
        self.assertTrue(found['usable'])
        self.assertEqual('10.232.2.1/24', found['subnet'])
        self.assertEqual({'gateway': 209, 'app-dev': 211}, found['nodes'])
        self.assertEqual(ADDRESSES, found['addresses'])

    def test_every_defect_makes_the_set_unusable_with_a_reason(self):
        def without(role):
            rows = complete_set()
            del rows[ready.name(role)]
            return rows

        def edited(role, **changes):
            rows = complete_set()
            rows[ready.name(role)]['config'].update(changes)
            return rows

        carrying = complete_set()
        carrying[ready.name('app-dev')]['config']['user.orbit.e2e.issue'] = 'TASK-3187'
        nic = complete_set()
        nic[ready.name('app-dev')]['devices']['eth0'] = {'type': 'nic', 'network': 'oe-net'}
        running = complete_set()
        running[ready.name('gateway')]['status'] = 'Running'
        bare = complete_set()
        del bare[ready.name('app-prod')]['config']['user.ready.complete']
        cases = {
            'missing': (without('app-prod'), 'missing'),
            'other set': (edited('app-dev', **{'user.ready.set': 'other', 'user.ready.complete': 'other'}), 'one complete set'),
            'half made': (bare, 'one complete set'),
            'running': (running, 'not stopped'),
            'wrong role': (edited('app-dev', **{'user.ready.role': 'gateway'}), 'another role'),
            'label': (carrying, 'labels'),
            'nic': (nic, 'generation device'),
            'no time': (edited('gateway', **{'user.ready.made': ''}), 'creation time'),
            'no nodes': (edited('gateway', **{'user.ready.nodes': ''}), 'Node ids'),
        }
        for label, (rows, reason) in cases.items():
            with self.subTest(label):
                found = self.inspect(rows)
                self.assertFalse(found['usable'])
                self.assertIn(reason, found['reason'])

    def test_copies_older_than_the_tokens_they_hold_are_unusable(self):
        later = datetime(2026, 11, 6, 8, 0, 0, tzinfo=timezone.utc)
        found = self.inspect(complete_set(), now=later)
        self.assertFalse(found['usable'])
        self.assertIn('expire', found['reason'])

    def test_no_copies_is_not_an_error(self):
        self.assertFalse(ready.inspect({}, now=NOW)['usable'])


class FleetRecordTests(unittest.TestCase):
    def request(self, root, evidence):
        return {'root': str(root), 'evidence': str(evidence), 'candidate': '/c', 'group': '1'}

    def test_a_generation_published_before_the_record_has_the_same_facts_in_its_evidence(self):
        with tempfile.TemporaryDirectory() as temporary:
            root, reset = Path(temporary) / 'root', Path(temporary) / 'reset'
            earlier, current = reset / ('a' * 32), reset / ('b' * 32)
            earlier.mkdir(parents=True)
            current.mkdir()
            launcher.write_json(root / 'active.json', {'generation': 'a' * 32})
            request = self.request(root, current)
            with self.assertRaisesRegex(RuntimeError, 'never published'):
                phases.fleet_record(request)
            launcher.write_json(earlier / 'prepare.json', {'construction': {'nodes': {}}})
            launcher.write_json(earlier / 'gateway-db.json', {'nodes': {'gateway': 209}})
            with self.assertRaisesRegex(RuntimeError, 'never published'):
                phases.fleet_record(request)
            launcher.write_json(earlier / 'publish.json', {})
            self.assertEqual({'prepare': {'construction': {'nodes': {}}}, 'nodes': {'gateway': 209}}, phases.fleet_record(request))
            launcher.write_json(phases.fleet_path(request, 'a' * 32), {'prepare': {}, 'nodes': {'x': 1}})
            self.assertEqual({'prepare': {}, 'nodes': {'x': 1}}, phases.fleet_record(request))

    def test_publish_writes_the_record_that_later_runs_read(self):
        with tempfile.TemporaryDirectory() as temporary:
            root, evidence = Path(temporary) / 'root', Path(temporary) / 'evidence'
            evidence.mkdir()
            request = {**self.request(root, evidence), 'generation': 'c' * 32, 'mode': 'full'}
            launcher.write_json(root / 'active.json', {'generation': 'c' * 32})
            launcher.write_json(evidence / 'prepare.json', {'construction': {'nodes': {}}})
            launcher.write_json(evidence / 'gateway-db.json', {'nodes': {'gateway': 209}})
            launcher.write_json(evidence / 'readiness.json', {'passed': True})
            with patch.object(phases, 'preservation', return_value={'passed': True}):
                phases.execute(request, 'publish')
            self.assertEqual({'prepare': {'construction': {'nodes': {}}}, 'nodes': {'gateway': 209}},
                             json.loads(phases.fleet_path(request, 'c' * 32).read_text()))

    def test_the_fast_reset_publishes_its_own_readiness_evidence(self):
        with tempfile.TemporaryDirectory() as temporary:
            root, evidence = Path(temporary) / 'root', Path(temporary) / 'evidence'
            evidence.mkdir()
            request = {**self.request(root, evidence), 'generation': 'c' * 32, 'mode': 'ready'}
            launcher.write_json(root / 'active.json', {'generation': 'c' * 32})
            launcher.write_json(evidence / 'prepare.json', {'construction': {'nodes': {}}})
            launcher.write_json(evidence / 'gateway-db.json', {'nodes': {}})
            launcher.write_json(evidence / 'ready-check.json', {'passed': True, 'doctor': {}})
            with patch.object(phases, 'preservation', return_value={'passed': True}):
                self.assertEqual({'passed': True, 'doctor': {}}, phases.execute(request, 'publish')['readiness'])


class ReadyStateTests(unittest.TestCase):
    def setup(self, temporary):
        root, evidence = Path(temporary) / 'root', Path(temporary) / 'reset' / ('b' * 32)
        evidence.mkdir(parents=True)
        request = {'root': str(root), 'evidence': str(evidence), 'candidate': '/c', 'group': '1'}
        nodes = {role: {'instance': GUESTS[role], 'incus_address': ADDRESSES[role]} for role in ready.ROLES}
        launcher.write_json(root / 'active.json', {'generation': 'a' * 32})
        launcher.write_json(phases.fleet_path(request, 'a' * 32), {'prepare': {'construction': {'nodes': nodes}}, 'nodes': {}})
        return request

    def state(self, request, found, subnet='10.232.2.1/24'):
        with patch.object(ready, 'inspect', return_value=found), patch.object(ready, 'network_of', return_value='oe-net'), \
                patch.object(ready, 'subnet_of', return_value=subnet):
            return phases.ready_state(request)

    def test_usable_only_for_the_subnet_and_addresses_of_the_active_generation(self):
        usable = ready.inspect(complete_set(), now=NOW)
        with tempfile.TemporaryDirectory() as temporary:
            request = self.setup(temporary)
            found = self.state(request, usable)
            self.assertTrue(found['usable'])
            self.assertEqual({'guests': GUESTS, 'network': 'oe-net'}, {k: found[k] for k in ('guests', 'network')})
            self.assertIn('10.232.2.1/24', self.state(request, usable, subnet='10.232.3.1/24')['reason'])
            moved = {**usable, 'addresses': {**usable['addresses'], 'app-dev': '10.232.9.11'}}
            self.assertIn('app-dev', self.state(request, moved)['reason'])
            self.assertEqual('nothing', self.state(request, {'usable': False, 'reason': 'nothing'})['reason'])

    def test_no_generation_or_no_record_is_unusable_not_an_error(self):
        with tempfile.TemporaryDirectory() as temporary:
            request = {'root': temporary, 'evidence': temporary + '/e', 'candidate': '/c', 'group': '1'}
            self.assertIn('no active generation', phases.ready_state(request)['reason'])
            launcher.write_json(Path(temporary) / 'active.json', {'generation': 'a' * 32})
            self.assertFalse(phases.ready_state(request)['usable'])

    def test_incus_that_cannot_run_is_unusable_not_an_error(self):
        with tempfile.TemporaryDirectory() as temporary:
            request = self.setup(temporary)
            with patch.object(ready, 'inspect', side_effect=FileNotFoundError('incus')):
                self.assertFalse(phases.ready_state(request)['usable'])


class SnapshotReadyTests(unittest.TestCase):
    def fleet(self, temporary):
        root, evidence = Path(temporary) / 'root', Path(temporary) / 'evidence'
        evidence.mkdir()
        previous, new = 'a' * 32, 'b' * 32
        request = {'root': str(root), 'evidence': str(evidence), 'candidate': '/c', 'group': '1', 'generation': new}
        old_source = root / 'generations' / previous / 'source'
        for relative in ('.e2e', 'apps/gateway/storage/framework/cache', 'apps/agent/delivery', 'apps/gateway/bootstrap/cache'):
            (old_source / relative).mkdir(parents=True)
        (old_source / '.e2e/attempt.json').write_text('lease')
        (old_source / 'apps/gateway/.env').write_text('APP_KEY=carried')
        (old_source / 'apps/gateway/storage/framework/cache/entry').write_text('cached')
        (old_source / 'apps/agent/delivery/manifest.json').write_text('{}')
        launcher.write_json(root / 'generations' / previous / 'owner.json', {'candidate': '/c', 'group': '1'})
        launcher.write_json(root / 'active.json', {'generation': previous})
        launcher.write_json(evidence / 'inventory.json', {'previous': previous})
        mounts = {'gateway': {'device': 'orbit-source', 'source': str(old_source), 'path': '/home/orbit/orbit'}}
        launcher.write_json(phases.fleet_path(request, previous), {'prepare': {'construction': {}, 'mounts': mounts}, 'nodes': {'gateway': 1}})
        return request, root, previous, new

    def fake_snapshot(self, root, new):
        def snapshot(request, *, activate=True, vendors_from=None):
            self.assertFalse(activate)
            self.assertEqual(root / 'generations' / ('a' * 32) / 'source', vendors_from)
            source = root / 'generations' / new / 'source'
            (source / 'apps/gateway').mkdir(parents=True)
            return {'source': str(source), 'candidate': {}}
        return snapshot

    def test_the_new_generation_inherits_lease_state_and_nodes_and_activates_last(self):
        with tempfile.TemporaryDirectory() as temporary:
            request, root, previous, new = self.fleet(temporary)
            found = {'usable': True, 'set': 'set1', 'nodes': {'gateway': 209, 'app-dev': 211}}
            with patch.object(phases, 'ready_state', return_value=found), patch.object(phases, 'snapshot', side_effect=self.fake_snapshot(root, new)), \
                    patch.object(phases, 'carry_acls') as acls:
                result = phases.snapshot_ready(request)
            source = root / 'generations' / new / 'source'
            self.assertEqual('lease', (source / '.e2e/attempt.json').read_text())
            self.assertEqual('APP_KEY=carried', (source / 'apps/gateway/.env').read_text())
            self.assertEqual('cached', (source / 'apps/gateway/storage/framework/cache/entry').read_text())
            self.assertTrue((source / 'apps/agent/delivery/manifest.json').is_file())
            acls.assert_called_once()
            record = json.loads(phases.fleet_path(request, new).read_text())
            self.assertEqual({'gateway': 209, 'app-dev': 211}, record['nodes'])
            self.assertEqual(str(source), record['prepare']['mounts']['gateway']['source'])
            self.assertEqual({'generation': new}, json.loads((root / 'active.json').read_text()))
            self.assertEqual({'carried_from': previous, 'ready_set': 'set1'}, {k: result[k] for k in ('carried_from', 'ready_set')})

    def test_a_failure_before_the_end_leaves_the_previous_generation_active(self):
        with tempfile.TemporaryDirectory() as temporary:
            request, root, previous, new = self.fleet(temporary)
            found = {'usable': True, 'set': 'set1', 'nodes': {}}
            with patch.object(phases, 'ready_state', return_value=found), patch.object(phases, 'snapshot', side_effect=self.fake_snapshot(root, new)), \
                    patch.object(phases, 'carry_acls', side_effect=RuntimeError('setfacl exited 1')):
                with self.assertRaisesRegex(RuntimeError, 'setfacl'):
                    phases.snapshot_ready(request)
            self.assertEqual({'generation': previous}, json.loads((root / 'active.json').read_text()))

    def test_unusable_ready_copies_stop_before_a_new_generation_exists(self):
        with tempfile.TemporaryDirectory() as temporary:
            request, root, previous, new = self.fleet(temporary)
            with patch.object(phases, 'ready_state', return_value={'usable': False, 'reason': 'stale'}), patch.object(phases, 'snapshot') as snapshot:
                with self.assertRaisesRegex(RuntimeError, 'stale'):
                    phases.snapshot_ready(request)
            snapshot.assert_not_called()

    def test_prune_keeps_the_active_and_the_previous_generation_and_only_this_groups_unmounted_ones(self):
        with tempfile.TemporaryDirectory() as temporary:
            request, root, previous, new = self.fleet(temporary)
            request['mode'] = 'full'
            generations = root / 'generations'
            (generations / new).mkdir()
            launcher.write_json(generations / new / 'owner.json', {'candidate': '/c', 'group': '1'})
            old, foreign, mounted = 'c' * 32, 'd' * 32, 'e' * 32
            for name, owner in ((old, {'candidate': '/c', 'group': '1'}), (foreign, {'candidate': '/other', 'group': '1'}), (mounted, {'candidate': '/c', 'group': '1'})):
                (generations / name).mkdir()
                launcher.write_json(generations / name / 'owner.json', owner)
            (generations / 'not-a-generation').mkdir()
            listing = json.dumps([{'devices': {'orbit-source': {'source': str(generations / mounted / 'source')}}}]).encode()
            with patch.object(phases, 'incus', return_value=listing), patch.object(phases, 'privileged') as remove:
                result = phases.prune_generations(request)
            remove.assert_called_once_with(['rm', '-rf', '--', str(generations / old)], timeout=300)
            self.assertEqual({'kept': sorted([previous, new]), 'removed': [old]}, result)


class PreservationTests(unittest.TestCase):
    def check(self, mode, now_guests, now_networks=('net',)):
        with tempfile.TemporaryDirectory() as temporary:
            evidence = Path(temporary)
            owned = ['fleet-1', 'fleet-2']
            stable = {'name': 'unrelated', 'status': 'Stopped', 'metadata': {}}
            before = {'candidate': 'c', 'outer': 'o', 'previous_owned': {'guests': owned, 'networks': ['net']},
                      'resources': {'guests': [stable, *({'name': n, 'status': 'Running', 'metadata': {}} for n in owned)], 'networks': [{'name': 'net', 'metadata': {}}]}}
            launcher.write_json(evidence / 'inventory.json', before)
            now = {'guests': [stable, *({'name': n, 'status': 'Running', 'metadata': {}} for n in now_guests)], 'networks': [{'name': n, 'metadata': {}} for n in now_networks]}
            with patch.object(phases, 'inventory', return_value=now), patch.object(phases, 'identity', return_value='c'), patch.object(phases, 'outer_identity', return_value='o'):
                return phases.preservation({'evidence': temporary, 'candidate': '/c', 'mode': mode})

    def test_a_fast_reset_keeps_the_fleet_and_a_full_reset_must_not(self):
        self.assertTrue(self.check('ready', ['fleet-1', 'fleet-2'])['passed'])
        with self.assertRaisesRegex(RuntimeError, 'survived'):
            self.check('full', ['fleet-1', 'fleet-2'])
        self.assertTrue(self.check('full', [], ())['passed'])

    def test_a_fast_reset_fails_when_a_carried_guest_disappeared(self):
        with self.assertRaisesRegex(RuntimeError, 'fleet-2'):
            self.check('ready', ['fleet-1'])


class ReadyWaitTests(unittest.TestCase):
    def request(self, temporary, started=True, mode='ready'):
        evidence = Path(temporary)
        launcher.write_json(evidence / 'prepare.json', {'construction': {'nodes': {key: {'instance': key, 'incus_address': '10.232.2.1'} for key in ('gateway', 'app-dev', 'operator')}}})
        launcher.write_json(evidence / 'gateway-db.json', {'nodes': {'gateway': 1, 'app-dev': 2, 'operator': 3}})
        if started:
            launcher.write_json(evidence / 'restore-ready.json', {'started_at': 1000.0})
        return {'evidence': temporary, 'group': '1', 'generation': 'a' * 32, 'mode': mode}

    def wait(self, request):
        with patch.object(phases, 'wireguard', return_value={'nodes': {'gateway': {'ip': '10.45.0.1'}}}), patch.object(phases, 'incus', return_value=b'') as incus, \
                patch.object(phases.subprocess, 'run'), patch.object(phases, 'tunnel_ready', return_value=1.0) as tunnel, \
                patch.object(phases, 'gateway_state') as state, patch.object(phases.time, 'time', return_value=1010.0):
            result = phases.ready_wait(request)
        self.calls = [call.args[0] for call in incus.call_args_list]
        state.assert_not_called()
        return result, tunnel

    def test_each_wait_is_one_blocking_command(self):
        with tempfile.TemporaryDirectory() as temporary:
            result, tunnel = self.wait(self.request(temporary))
            calls = [' '.join(map(str, call)) for call in self.calls]
            self.assertEqual(['wait gateway agent --timeout 150', 'wait app-dev agent --timeout 150'], calls[:2])
            self.assertIn('exec gateway -- systemctl start php8.5-fpm caddy orbit-websocket orbit-swamp-serve orbit-node-worker orbit-proof-agent-view orbit-proof-operations orbit-agent', calls)
            self.assertIn('exec app-dev -- systemctl start orbit-agent', calls)
            self.assertEqual(10.0, tunnel.call_args.kwargs['seconds'])
            self.assertEqual(10.0, result['seconds_since_start'])

    def online(self, request, agents, mode='ready'):
        facts = [{'node_id': node, 'kind': kind, 'enabled': True, 'received_at': '2026-11-05T00:00:00+00:00'} for node in (1, 2) for kind in ('host', 'ufw', 'units')]
        state = {'facts': facts} if agents is None else {'facts': facts, 'agents': agents}
        with patch.object(phases, 'wireguard', return_value={'nodes': {}}), patch.object(phases, 'incus', return_value=b'') as incus, \
                patch.object(phases, 'gateway_state', return_value=state), \
                patch.object(phases, 'local_cli', return_value=(0, {'agent': {'state': 'online'}})) as cli:
            result = phases.agents_online({**request, 'mode': mode})
        return result, incus, cli

    def test_the_observation_names_a_silent_agent_and_checks_the_worker_in_a_fast_reset(self):
        with tempfile.TemporaryDirectory() as temporary:
            request = self.request(temporary)
            with self.assertRaisesRegex(RuntimeError, r'not online: 2'):
                self.online(request, {'1': 'online', '2': 'offline'})
            result, incus, _ = self.online(request, {'1': 'online', '2': 'online'})
            self.assertEqual({'online': [1, 2]}, result)
            self.assertTrue(any(call.args[0][-1] == 'worker' for call in incus.call_args_list))
            _, incus, _ = self.online(request, {'1': 'online', '2': 'online'}, mode='ready-make')
            incus.assert_not_called()

    def test_the_candidate_cli_answers_for_a_generation_that_does_not_report_presence(self):
        with tempfile.TemporaryDirectory() as temporary:
            _, _, cli = self.online(self.request(temporary), None, mode='ready-make')
            self.assertEqual(['node:show', '1', '--json'], list(cli.call_args_list[0].args[1:]))

    def test_the_handshake_is_forced_by_one_blocking_ping_before_it_is_read(self):
        with patch.object(phases.subprocess, 'run') as run:
            phases.force_handshake('10.45.0.1')
        self.assertEqual(['ping', '-c', '1', '-w', '60', '10.45.0.1'], run.call_args.args[0])
        lease = {'construction': {'attempt_id': 'a', 'nodes': {'gateway': {'instance': 'g', 'incus_address': '10.232.2.10'}}}, 'source': {'operation_id': 'o'}}
        order = []
        metadata = {'user.orbit.e2e.' + key: value for key, value in {'owner': 'orbit-e2e', 'issue': 'TASK-3187', 'attempt': 'a', 'operation': 'o'}.items()}
        with patch.object(phases, 'prepared', return_value=lease), patch.object(phases, 'inventory', return_value={'guests': [{'name': 'g', 'status': 'Running', 'metadata': metadata}]}), \
                patch.object(phases, 'incus', return_value=b'ssh-ed25519 KEY'), patch.object(phases, 'wireguard', return_value={'nodes': {'gateway': {'ip': '10.45.0.1'}}}), \
                patch.object(phases, 'force_handshake', side_effect=lambda address: order.append('ping')), \
                patch.object(phases, 'tunnel_ready', side_effect=lambda network: order.append('read') or 1.0), \
                patch.object(phases, 'local_cli', return_value=(0, {'data': {'auto_release': {}}, 'nodes': []})):
            try:
                phases.transport_readiness({'issue': 'TASK-3187', 'group': '1'})
            except RuntimeError:  # the stubbed node list is not the fixture's; only the order of the two calls matters here
                pass
        self.assertEqual(['ping', 'read'], order)

    def test_refuses_to_wait_for_a_fleet_that_never_started(self):
        with tempfile.TemporaryDirectory() as temporary:
            with patch.object(phases, 'wireguard', return_value={'nodes': {'gateway': {'ip': 'x'}}}):
                with self.assertRaisesRegex(RuntimeError, 'no recorded start'):
                    phases.ready_wait(self.request(temporary, started=False))

    def test_the_candidate_waits_for_the_agent_then_the_mount_and_never_loops(self):
        with patch.object(phases, 'prepared', return_value={'construction': {'nodes': {'gateway': {'instance': 'gw'}}}}), \
                patch.object(phases, 'incus', return_value=b'') as incus, patch.object(phases, 'guest', return_value={'migrated': True}) as guest:
            phases.apply_candidate({'key_file': '/k'})
        calls = [' '.join(map(str, call.args[0])) for call in incus.call_args_list]
        self.assertEqual(['wait gw agent --timeout 150', 'exec gw -- systemctl start orbit-proof-operations',
                          'exec gw -- test -f /home/orbit/orbit/apps/gateway/artisan'], [calls[0], calls[3], calls[4]])
        guest.assert_called_once_with({'key_file': '/k'}, 'candidate')

    def test_facts_after_a_start_from_ready_copies_need_only_exist(self):
        with tempfile.TemporaryDirectory() as temporary:
            evidence = Path(temporary)
            launcher.write_json(evidence / 'prepare.json', {})
            self.assertGreater(phases.fresh_since({'evidence': temporary}), 0)
            launcher.write_json(evidence / 'ready-make.json', {'started_at': 5.0})
            self.assertEqual(0.0, phases.fresh_since({'evidence': temporary}))
            self.assertEqual(5.0, phases.started_at({'evidence': temporary}))

    def test_the_proof_programs_hold_no_sleep_and_no_endless_loop(self):
        for name in ('phases.py', 'ready.py', 'launcher.py', 'guest.py'):
            source = (Path(__file__).resolve().parents[1] / name).read_text()
            self.assertNotIn('time.sleep', source, name)
            self.assertNotIn('while True', source, name)



class ReadyMakePhaseTests(unittest.TestCase):
    def test_a_guest_with_foreign_labels_is_never_copied(self):
        record = {'prepare': {'construction': {'attempt_id': 'a' * 32, 'nodes': {role: {'instance': GUESTS[role]} for role in ready.ROLES}},
                              'source': {'operation_id': 'b' * 32}}, 'nodes': {}}
        guests = [{'name': GUESTS[role], 'status': 'Running', 'metadata': {k: v for k, v in LABELS.items()}} for role in ready.ROLES]
        guests[1]['metadata'] = {**guests[1]['metadata'], 'user.orbit.e2e.attempt': 'foreign'}
        with patch.object(phases, 'fleet_record', return_value=record), patch.object(phases, 'inventory', return_value={'guests': guests}), \
                patch.object(ready, 'make') as make:
            with self.assertRaisesRegex(RuntimeError, 'ownership mismatch'):
                phases.ready_make({'issue': 'TASK-3187'})
            make.assert_not_called()
            guests[1]['metadata'] = dict(LABELS)
            with patch.object(ready, 'network_of', return_value='oe-net'), patch.object(phases, 'scrub_key', return_value={}), patch.object(phases, 'deliver_key'):
                phases.ready_make({'issue': 'TASK-3187', 'key_file': '/k'})
            make.assert_called_once_with(GUESTS, 'oe-net', {})


    def record(self):
        return {'prepare': {'construction': {'attempt_id': 'a' * 32, 'nodes': {role: {'instance': GUESTS[role]} for role in ready.ROLES}},
                            'source': {'operation_id': 'b' * 32}}, 'nodes': {}}

    def test_the_club_key_leaves_the_gateway_before_the_copy_and_returns_after_the_restart(self):
        order = []
        guests = [{'name': GUESTS[role], 'status': 'Running', 'metadata': dict(LABELS)} for role in ready.ROLES]
        with patch.object(phases, 'fleet_record', return_value=self.record()), patch.object(phases, 'inventory', return_value={'guests': guests}), \
                patch.object(phases, 'scrub_key', side_effect=lambda *args: order.append('scrub') or {'scanned': True}), \
                patch.object(phases, 'deliver_key', side_effect=lambda *args: order.append('deliver')), \
                patch.object(ready, 'network_of', return_value='oe-net'), patch.object(ready, 'make', side_effect=lambda *args: order.append('make') or {'set': 's'}):
            result = phases.ready_make({'issue': 'TASK-3187', 'key_file': '/k'})
        self.assertEqual(['scrub', 'make', 'deliver'], order)
        self.assertEqual({'scanned': True}, result['key'])

    def test_a_key_that_stays_on_the_disk_stops_the_copy(self):
        guests = [{'name': GUESTS[role], 'status': 'Running', 'metadata': dict(LABELS)} for role in ready.ROLES]
        with patch.object(phases, 'fleet_record', return_value=self.record()), patch.object(phases, 'inventory', return_value={'guests': guests}), \
                patch.object(phases, 'scrub_key', side_effect=RuntimeError('still on the Gateway disk')), patch.object(phases, 'deliver_key') as deliver, \
                patch.object(ready, 'network_of', return_value='oe-net'), patch.object(ready, 'make') as make:
            with self.assertRaisesRegex(RuntimeError, 'still on the Gateway disk'):
                phases.ready_make({'issue': 'TASK-3187', 'key_file': '/k'})
        make.assert_not_called()
        deliver.assert_not_called()


class ClubKeyTests(unittest.TestCase):
    def scrub(self, found):
        with tempfile.TemporaryDirectory() as temporary:
            key = Path(temporary) / 'key'
            key.write_text('club-secret-value\n')
            with patch.object(phases, 'incus', side_effect=lambda argv, **kwargs: b'/: 1 GiB (1073741824 bytes) trimmed on /dev/sda2\n' if 'fstrim' in argv else found) as incus:
                try:
                    return phases.scrub_key({'key_file': str(key)}, 'gw'), incus
                finally:
                    self.calls = incus.call_args_list

    def test_the_key_file_is_deleted_and_the_disk_scan_finds_nothing(self):
        result, incus = self.scrub(b'')
        self.assertEqual({'scanned': True, 'files_holding_the_key': 0, 'fstrim': ['/: 1 GiB (1073741824 bytes) trimmed on /dev/sda2']}, result)
        self.assertEqual(['exec', 'gw', '--', 'rm', '-f', phases.CLUB_KEY], list(map(str, incus.call_args_list[0].args[0])))
        scan = incus.call_args_list[1]
        # The key travels on stdin to a file in /run (memory); it is never an argument.
        self.assertEqual(b'club-secret-value\n', scan.kwargs['data'])
        self.assertNotIn('club-secret-value', ' '.join(map(str, scan.args[0])))
        self.assertIn('/run/club-key-scan', ' '.join(map(str, scan.args[0])))
        # The trim comes after the delete and the scan, under timeout, and its failure is not swallowed.
        self.assertEqual(['exec', 'gw', '--', 'timeout', '300', 'fstrim', '-av'], list(map(str, incus.call_args_list[2].args[0])))

    def test_a_failed_trim_fails_ready(self):
        def run(argv, **kwargs):
            if 'fstrim' in argv:
                raise RuntimeError('incus exited 1: fstrim failed')
            return b''
        with tempfile.TemporaryDirectory() as temporary:
            key = Path(temporary) / 'key'
            key.write_text('k\n')
            with patch.object(phases, 'incus', side_effect=run), self.assertRaisesRegex(RuntimeError, 'fstrim failed'):
                phases.scrub_key({'key_file': str(key)}, 'gw')

    def test_a_file_that_still_holds_the_key_fails_ready_and_names_only_paths(self):
        with self.assertRaises(RuntimeError) as raised:
            self.scrub(b'/home/orbit/.orbit/proof-control/config/auth.json\n')
        self.assertIn('/home/orbit/.orbit/proof-control/config/auth.json', str(raised.exception))
        self.assertNotIn('club-secret-value', str(raised.exception))

    def test_the_restored_gateway_gets_the_key_before_swamp_starts(self):
        with patch.object(phases, 'incus', return_value=b'') as incus:
            phases.deliver_key({'key_file': '/home/orbit/.config/swamp/club-api-key'}, 'gw')
        calls = [' '.join(map(str, call.args[0])) for call in incus.call_args_list]
        self.assertEqual('wait gw agent --timeout 150', calls[0])
        self.assertTrue(calls[1].startswith('file push /home/orbit/.config/swamp/club-api-key gw/home/orbit/.config/swamp/club-api-key --mode=0600'))
        self.assertIn('systemctl restart orbit-swamp-serve orbit-node-worker', calls[2])

    def test_the_fast_reset_delivers_the_key_first_and_the_copies_never_hold_it(self):
        with patch.object(phases, 'prepared', return_value={'construction': {'nodes': {'gateway': {'instance': 'gw'}}}}), \
                patch.object(phases, 'incus', return_value=b'') as incus, patch.object(phases, 'guest', return_value={}):
            phases.apply_candidate({'key_file': '/k'})
        calls = [' '.join(map(str, call.args[0])) for call in incus.call_args_list]
        self.assertEqual(['wait gw agent --timeout 150', 'file push /k gw' + phases.CLUB_KEY + ' --mode=0600 --uid=1000 --gid=1000'], calls[:2])
        self.assertLess(calls.index('file push /k gw' + phases.CLUB_KEY + ' --mode=0600 --uid=1000 --gid=1000'), calls.index('exec gw -- systemctl start orbit-proof-operations'))
        copies = ready.make.__code__.co_names
        self.assertNotIn('club', ' '.join(copies))


class ReadyCheckTests(unittest.TestCase):
    def run_check(self, reports, status=None):
        with tempfile.TemporaryDirectory() as temporary:
            launcher.write_json(Path(temporary) / 'gateway-db.json', {'nodes': {'gateway': 1, 'app-dev': 2, 'operator': 3}})
            request = {'evidence': temporary}

            def cli(request, *args):
                if args[0] == 'gateway:status':
                    return 0, {'data': {'auto_release': {}}} if status is None else status
                return 0, reports[args[1]]
            with patch.object(phases, 'local_cli', side_effect=cli), patch.object(phases, 'case_baseline', return_value={'ufw': {}}), \
                    patch.object(phases, 'agents_online') as online:
                result = phases.ready_check(request)
            online.assert_called_once_with(request)
            return result, json.loads((Path(temporary) / 'baseline.json').read_text())

    def test_every_node_is_checked_and_the_case_baseline_is_recorded(self):
        result, baseline = self.run_check({'--node=1': {'healthy': True}, '--node=2': {'healthy': True}})
        self.assertEqual({'gateway': {'healthy': True}, 'app-dev': {'healthy': True}}, result['doctor'])
        self.assertEqual({'ufw': {}}, baseline)

    def test_a_finding_or_an_unknown_operator_fails_the_check(self):
        with self.assertRaisesRegex(RuntimeError, 'app-dev'):
            self.run_check({'--node=1': {'healthy': True}, '--node=2': {'healthy': False}})
        with self.assertRaisesRegex(RuntimeError, 'WireGuard member'):
            self.run_check({'--node=1': {'healthy': True}, '--node=2': {'healthy': True}}, status={'data': {}})


class VendorReuseTests(unittest.TestCase):
    def candidate(self, root):
        candidate = root / 'candidate'
        candidate.mkdir()
        def git(*args):
            return subprocess.check_output(['git', '-C', str(candidate), *args], stderr=subprocess.DEVNULL)
        git('init', '-q')
        git('config', 'user.email', 'fixture@example.invalid')
        git('config', 'user.name', 'Fixture')
        for package in ('apps/e2e', 'apps/gateway', 'apps/cli', 'packages/php-sdk'):
            (candidate / package).mkdir(parents=True)
            (candidate / package / 'composer.lock').write_text('lock ' + package)
            (candidate / package / 'vendor').mkdir()
            (candidate / package / 'vendor/autoload.php').write_text('candidate vendor')
        (candidate / '.gitignore').write_text('vendor/\n')
        git('add', '.')
        git('commit', '-qm', 'fixture')
        return candidate

    def snapshot(self, root, candidate, earlier):
        evidence = root / 'evidence'
        evidence.mkdir()
        launcher.write_json(evidence / 'inventory.json', {'candidate': phases.identity(str(candidate))})
        request = {'candidate': str(candidate), 'group': '1', 'root': str(root / 'owned'), 'generation': 'a' * 32, 'evidence': str(evidence)}
        return Path(phases.snapshot(request, activate=False, vendors_from=earlier)['source'])

    def test_the_previous_vendors_are_linked_only_for_an_unchanged_lock(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            candidate = self.candidate(root)
            earlier = root / 'earlier'
            for package in ('apps/e2e', 'apps/gateway', 'apps/cli', 'packages/php-sdk'):
                (earlier / package / 'vendor').mkdir(parents=True)
                (earlier / package / 'vendor/autoload.php').write_text('earlier vendor')
                (earlier / package / 'composer.lock').write_text('lock ' + package)
            (earlier / 'apps/gateway/composer.lock').write_text('an older lock')
            source = self.snapshot(root, candidate, earlier)
            linked = source / 'apps/cli/vendor/autoload.php'
            self.assertEqual('earlier vendor', linked.read_text())
            self.assertEqual((earlier / 'apps/cli/vendor/autoload.php').stat().st_ino, linked.stat().st_ino)
            self.assertEqual('candidate vendor', (source / 'apps/gateway/vendor/autoload.php').read_text())
            self.assertNotEqual((candidate / 'apps/gateway/vendor/autoload.php').stat().st_ino, (source / 'apps/gateway/vendor/autoload.php').stat().st_ino)


class LauncherModeTests(unittest.TestCase):
    def test_command_lines(self):
        parse = launcher.parse
        self.assertEqual(('reset', '3187', [], False), parse(['p', 'reset', '3187']))
        self.assertEqual(('reset', '3187', [], True), parse(['p', 'reset', '3187', '--from-ready']))
        self.assertEqual(('reset', '3187', [], True), parse(['p', 'reset', '--from-ready', '3187']))
        self.assertEqual(('ready', '3187', [], False), parse(['p', 'ready', '3187']))
        self.assertEqual(('verify', '3187', ['case.sh'], False), parse(['p', 'verify', '3187', 'case.sh']))
        self.assertEqual(('verify', '3187', ['a.sh', 'b.sh'], False), parse(['p', 'verify', '3187', 'a.sh', 'b.sh']))
        self.assertEqual(('release', '3187', [], False), parse(['p', 'release', '3187']))
        for bad in (['p'], ['p', 'reset'], ['p', 'reset', '0'], ['p', 'verify', '3187'], ['p', 'reset', '3187', 'x'], ['p', 'verify', '3187', 'c', '--from-ready'],
                    ['p', 'release', '3187', '--from-ready'], ['p', 'ready', '3187', '--from-ready'], ['p', 'unknown', '3187']):
            with self.subTest(bad):
                self.assertIsNone(parse(bad))

    def test_each_command_picks_its_workflow(self):
        usable, unusable = {'usable': True, 'reason': 'ready'}, {'usable': False, 'reason': 'no ready copies'}
        cases = [
            ({'action': 'reset', 'from_ready': False}, unusable, ('full', 'proof-fleet-reset')),
            ({'action': 'reset', 'from_ready': False}, usable, ('full', 'proof-fleet-reset')),
            ({'action': 'reset', 'from_ready': True}, usable, ('ready', 'proof-fleet-reset-ready')),
            ({'action': 'reset', 'from_ready': True}, unusable, ('build-ready', 'proof-fleet-reset-build-ready')),
            ({'action': 'verify', 'from_ready': False}, usable, ('ready', 'proof-command-verification-ready')),
            ({'action': 'verify', 'from_ready': False}, unusable, ('full', 'proof-command-verification')),
            ({'action': 'ready', 'from_ready': False}, unusable, ('ready-make', 'proof-fleet-ready')),
            ({'action': 'release', 'from_ready': False}, usable, ('full', 'proof-fleet-release')),
        ]
        for request, state, expected in cases:
            with self.subTest(request=request, usable=state['usable']), patch.object(phases, 'ready_state', return_value=state):
                self.assertEqual(expected, choose(request))

    def test_the_run_records_its_mode_workflow_and_timings(self):
        with tempfile.TemporaryDirectory() as temporary:
            root, evidence = Path(temporary) / 'root', Path(temporary) / 'evidence'
            evidence.mkdir()
            request = {'root': str(root), 'evidence': str(evidence), 'candidate': '/c', 'group': '1', 'action': 'verify', 'from_ready': False, 'cases': [],
                       'generation': 'a' * 32}
            launcher.write_json(root / 'owner.json', {'candidate': '/c', 'group': '1'})
            (root / 'controller').mkdir()
            history = {'jobs': [{'name': 'fresh-fleet', 'startedAt': '2026-11-05T00:00:00.000Z', 'completedAt': '2026-11-05T00:00:51.500Z'},
                                {'name': 'command-cases', 'startedAt': '2026-11-05T00:00:52.000Z', 'completedAt': '2026-11-05T00:02:00.000Z'}]}
            calls = []

            def native(request, args, name, cwd=None):
                calls.append(args)
                if args[:2] == ['workflow', 'history']:
                    launcher.write_json(evidence / 'history.json', history)
                return 0
            with patch.object(phases, 'ready_state', return_value={'usable': True, 'reason': 'ready'}), patch.object(launcher, 'swamp', side_effect=native):
                code = launcher.run_locked(request)
            self.assertEqual(0, code)
            self.assertIn(['workflow', 'validate', 'proof-command-verification-ready'], calls)
            run = next(call for call in calls if call[:2] == ['workflow', 'run'])
            self.assertEqual('proof-command-verification-ready', run[2])
            status = json.loads((evidence / 'status.json').read_text())
            self.assertEqual('ready', status['mode'])
            self.assertEqual({'fresh-fleet': 51.5, 'command-cases': 68.0}, status['jobs'])
            self.assertEqual('ready', json.loads((evidence / 'request.json').read_text())['mode'])
            self.assertEqual('proof-command-verification-ready', json.loads((evidence / 'mode.json').read_text())['workflow'])


def choose(request):
    return launcher.choose(request)[:2]


class WorkflowDefinitionTests(unittest.TestCase):
    DIRECTORY = Path(__file__).resolve().parents[1]

    def definitions(self):
        return {path.name: path.read_text() for path in sorted(self.DIRECTORY.glob('workflow-*.yaml'))}

    def test_every_phase_a_workflow_runs_exists(self):
        for name, text in self.definitions().items():
            for action in re.findall(r'phases\.py" ([a-z-]+)\'', text):
                with self.subTest(workflow=name, action=action):
                    self.assertIn(action, phases.ACTIONS)

    def test_the_launcher_only_names_workflows_that_exist(self):
        names = {re.search(r'^name: (\S+)', text, re.MULTILINE)[1] for text in self.definitions().values()}
        for workflow in ('proof-fleet-reset', 'proof-fleet-reset-ready', 'proof-fleet-reset-build-ready', 'proof-fleet-ready',
                         'proof-command-verification', 'proof-command-verification-ready', 'proof-fleet-release'):
            self.assertIn(workflow, names)

    def test_steps_depend_only_on_earlier_steps_and_names_and_ids_are_unique(self):
        ids = []
        for name, text in self.definitions().items():
            ids.append(re.search(r'^id: (\S+)', text, re.MULTILINE)[1])
            seen = []
            for block in re.split(r'\n      - name: ', text)[1:]:
                step = block.split('\n', 1)[0]
                self.assertNotIn(step, seen, name)
                for dependency in re.findall(r'- step: (\S+)', block):
                    self.assertIn(dependency, seen, f'{name}: {step} depends on {dependency}')
                seen.append(step)
        self.assertEqual(len(ids), len(set(ids)))

    def test_the_fast_chain_never_releases_or_rebuilds(self):
        text = self.definitions()['workflow-proof-fleet-reset-ready.yaml']
        for forbidden in ('release-previous', 'phases.py" prepare', 'gateway-db', 'swamp-state', 'gateway-runtime'):
            self.assertNotIn(forbidden, text)
        for required in ('snapshot-ready', 'restore-ready', 'ready-wait', 'candidate', 'ready-check', 'prune-generations', 'failure-cleanup'):
            self.assertIn(required, text)

    def test_the_command_usage_names_every_action(self):
        for action in launcher.ACTIONS:
            self.assertIn(action, launcher.USAGE)
        self.assertIn('--from-ready', launcher.USAGE)

    def test_a_timeout_in_a_child_process_is_not_swallowed(self):
        with patch.object(ready, 'incus', side_effect=subprocess.TimeoutExpired('incus', 1)):
            with self.assertRaises(subprocess.TimeoutExpired):
                ready.copies()


if __name__ == '__main__':
    unittest.main()
