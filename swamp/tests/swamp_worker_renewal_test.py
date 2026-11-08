"""Run the installed Ansible renewal against fake CLI and systemd boundaries."""
import datetime
import json
import os
import pwd
import shutil
import subprocess
import sys
import tempfile
import time
import unittest
from pathlib import Path

PLAY = Path(__file__).resolve().parents[1] / 'ansible/swamp-worker-renewal.yml'
FAKE = r'''
import datetime
import json
import os
import pwd
import sys
import time
from pathlib import Path
root = Path(os.environ['RENEWAL_TEST_DIR'])
path = root / 'state.json'
s = json.loads(path.read_text())
def save():
    # The test thread may inspect state at a barrier; publish a whole snapshot.
    temporary = path.with_suffix('.new')
    temporary.write_text(json.dumps(s))
    temporary.replace(path)
def event(message):
    with (root / 'events').open('a') as stream:
        stream.write(message + '\n')
def now():
    return datetime.datetime.now(datetime.UTC).isoformat(timespec='milliseconds').replace('+00:00', 'Z')
cmd = Path(sys.argv[0]).name
if cmd == 'getent':
    user = pwd.getpwnam(sys.argv[2])
    print(':'.join([user.pw_name, 'x', str(user.pw_uid), str(user.pw_gid), user.pw_gecos, user.pw_dir, user.pw_shell]))
elif cmd == 'journalctl':
    slot = next(a for a in sys.argv if a.startswith('--unit=')).split('@')[1][0]
    print(s.get('receipts', {}).get(slot, '2026-11-08T01:00:00Z [INF] worker: Enrolled as "' + s['workers'][slot]['name'] + '"'))
elif cmd == 'queued-dispatch':
    event('queued-follow-on')
    deadline = time.monotonic() + 30
    while True:
        s = json.loads(path.read_text())
        accepting = [slot for slot, worker in s['workers'].items()
                     if worker['status'] in ('idle', 'busy')
                     and len(worker['activeDispatchIds']) < worker['capacity']]
        if accepting:
            event('follow-on-success-' + accepting[0])
            break
        assert time.monotonic() < deadline, 'follow-on exceeded queueTimeout: 30'
        time.sleep(.02)
elif cmd == 'busctl':
    print('t ' + str(s.get('stop_usec', 4500000000)))
elif cmd == 'systemctl':
    if sys.argv[1] == 'show':
        print('LoadState=loaded\nActiveState=active\nUnitFileState=enabled')
    elif sys.argv[1] == 'restart':
        slot = sys.argv[2].split('@')[1][0]
        peer = 'b' if slot == 'a' else 'a'
        assert s['workers'][peer]['status'] in ('idle', 'busy'), 'both workers unavailable'
        event('drain-' + slot)
        s['workers'][slot]['status'] = 'draining'
        save()
        if slot == 'a' and (root / 'hold-drain').exists():
            deadline = time.monotonic() + 40
            while (root / 'hold-drain').exists():
                assert time.monotonic() < deadline, 'drain barrier timed out'
                time.sleep(.02)
            event('dispatch-a-success')
        s['workers'][slot]['status'] = 'disconnected'
        save()
        event('stopped-' + slot)
    else:
        raise AssertionError(sys.argv)
elif cmd == 'swamp':
    if sys.argv[2] == 'list':
        for slot in ('a', 'b'):
            if s['workers'][slot]['status'] == 'disconnected' and s['workers'][slot]['instanceUuid'] == 'old-' + slot:
                if (root / 'hold-enrollment').exists():
                    event('waiting-' + slot)
                else:
                    s['workers'][slot].update(status='idle', instanceUuid='new-' + slot, enrolledAt=now())
                    event('enrolled-' + slot)
                    save()
        print(json.dumps({'workers': list(s['workers'].values())}))
    elif sys.argv[2] == 'verify':
        slot = next(slot for slot, worker in s['workers'].items() if worker['name'] == sys.argv[3])
        event('verify-' + slot)
        error = s.get('probe_error', {}).get(slot)
        if s.get('drain_on_probe') == slot:
            s['workers'][slot]['status'] = 'draining'
            save()
        if s.get('fill_on_probe') == slot:
            s['workers'][slot]['activeDispatchIds'] = ['one', 'two', 'three', 'four']
            save()
        if error == 'empty':
            print(json.dumps({'workers': [], 'total': 0, 'passed': 0, 'failed': 0}))
        elif error:
            print(json.dumps({'workers': [{'name': sys.argv[3], 'status': 'error', 'error': error}],
                              'total': 1, 'passed': 0, 'failed': 1}))
            sys.exit(1)
        else:
            print(json.dumps({'workers': [{'name': sys.argv[3], 'status': 'pass'}], 'total': 1, 'passed': 1, 'failed': 0}))
    else:
        raise AssertionError(sys.argv)
else:
    raise AssertionError(cmd)
'''


class RenewalTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.folder = Path(self.temp.name)
        self.state = {'workers': {}}
        for slot in ('a', 'b'):
            self.state['workers'][slot] = {
                'name': f'swamp-role-209-{slot}', 'status': 'idle', 'instanceUuid': f'old-{slot}',
                'capacity': 4, 'activeDispatchIds': [],
                'enrolledAt': (datetime.datetime.now(datetime.UTC) - datetime.timedelta(hours=4))
                .isoformat(timespec='milliseconds').replace('+00:00', 'Z')}
        for name in ('swamp', 'systemctl', 'busctl', 'getent', 'journalctl', 'queued-dispatch'):
            path = self.folder / name
            path.write_text(f'#!{sys.executable}\n' + FAKE)
            path.chmod(0o755)
        (self.folder / 'ansible.cfg').write_text('[defaults]\n')
        self.process = None
        self.addCleanup(self.stop)

    def stop(self):
        (self.folder / 'hold-drain').unlink(missing_ok=True)
        (self.folder / 'hold-enrollment').unlink(missing_ok=True)
        if self.process and self.process.poll() is None:
            self.process.terminate()
            self.process.communicate(timeout=10)

    def start(self):
        (self.folder / 'state.json').write_text(json.dumps(self.state))
        environment = dict(os.environ, PATH=f'{self.folder}:{os.environ["PATH"]}',
                           RENEWAL_TEST_DIR=str(self.folder), ANSIBLE_NOCOLOR='1',
                           ANSIBLE_LOCAL_TEMP=str(self.folder / 'local'), ANSIBLE_CONFIG=str(self.folder / 'ansible.cfg'))
        variables = {'orbit_user': pwd.getpwuid(os.getuid()).pw_name, 'orbit_node_id': 209,
                     'ansible_become': False, 'ansible_python_interpreter': sys.executable,
                     'renewal_swamp_binary': str(self.folder / 'swamp')}
        self.process = subprocess.Popen([shutil.which('ansible-playbook'), '-i', 'localhost,', '-c', 'local',
                                         str(PLAY), '-e', json.dumps(variables)],
                                        stdout=subprocess.PIPE, stderr=subprocess.STDOUT, text=True, env=environment)

    def events(self):
        path = self.folder / 'events'
        return path.read_text().splitlines() if path.exists() else []

    def wait_for(self, event):
        deadline = time.monotonic() + 35
        while time.monotonic() < deadline:
            if event in self.events():
                return
            if self.process.poll() is not None:
                output, _ = self.process.communicate()
                self.fail(f'Playbook exited before {event}: {output}')
            time.sleep(.02)
        self.fail(f'Barrier {event} not reached; events: {self.events()}')

    def result(self, success=True):
        output, _ = self.process.communicate(timeout=60)
        if success:
            self.assertEqual(self.process.returncode, 0, output)
        else:
            self.assertNotEqual(self.process.returncode, 0, output)
        return self.events()

    def test_a_dispatch_finishes_and_new_instance_is_verified_before_b_drains(self):
        (self.folder / 'hold-drain').touch()
        (self.folder / 'hold-enrollment').touch()
        self.start()
        self.wait_for('drain-a')
        self.assertNotIn('drain-b', self.events())
        live = json.loads((self.folder / 'state.json').read_text())
        self.assertEqual(live['workers']['b']['status'], 'idle')
        (self.folder / 'hold-drain').unlink()
        self.wait_for('waiting-a')
        self.assertNotIn('drain-b', self.events())
        (self.folder / 'hold-enrollment').unlink()
        events = self.result()
        significant = [e for e in events if not e.startswith(('waiting-', 'stopped-'))]
        self.assertEqual(significant, ['verify-b', 'drain-a', 'dispatch-a-success', 'enrolled-a',
                                       'verify-a', 'verify-a', 'drain-b', 'enrolled-b', 'verify-b'])

    def test_unavailable_peer_prevents_drain(self):
        self.state['workers']['b']['status'] = 'draining'
        self.start()
        self.assertEqual(self.result(success=False), [])

    def test_empty_live_check_prevents_drain_despite_idle_record(self):
        self.state['probe_error'] = {'b': 'empty'}
        self.start()
        self.assertEqual(self.result(success=False), ['verify-b'])

    def test_full_survivor_defers_round_without_draining_either_worker(self):
        self.state['workers']['b']['activeDispatchIds'] = ['one', 'two', 'three', 'four']
        self.start()
        self.assertEqual(self.result(success=False), [])

    def test_live_capacity_refusal_defers_round_without_draining(self):
        self.state['probe_error'] = {'b': "Worker 'swamp-role-209-b' is at capacity"}
        self.start()
        self.assertEqual(self.result(success=False), ['verify-b'])

    def test_capacity_refusal_cannot_hide_a_peer_that_started_draining(self):
        self.state['probe_error'] = {'b': "Worker 'swamp-role-209-b' is at capacity"}
        self.state['drain_on_probe'] = 'b'
        self.start()
        self.assertEqual(self.result(success=False), ['verify-b'])

    def test_survivor_filling_during_probe_defers_without_draining(self):
        self.state['fill_on_probe'] = 'b'
        self.start()
        self.assertEqual(self.result(success=False), ['verify-b'])

    def test_queued_follow_on_finishes_on_survivor_while_a_is_draining(self):
        (self.folder / 'hold-drain').touch()
        self.state['workers']['b']['activeDispatchIds'] = ['one', 'two', 'three']
        self.start()
        self.wait_for('drain-a')
        # Model a short burst after the admission check, then release one slot.
        path = self.folder / 'state.json'
        live = json.loads(path.read_text())
        live['workers']['b']['activeDispatchIds'].append('four')
        path.write_text(json.dumps(live))
        queued = subprocess.Popen([str(self.folder / 'queued-dispatch')],
                                  env=dict(os.environ, RENEWAL_TEST_DIR=str(self.folder)))
        self.addCleanup(lambda: queued.kill() if queued.poll() is None else None)
        self.wait_for('queued-follow-on')
        self.assertNotIn('follow-on-success-b', self.events())
        live['workers']['b']['activeDispatchIds'].pop()
        path.write_text(json.dumps(live))
        self.assertEqual(queued.wait(timeout=5), 0)
        self.assertIn('follow-on-success-b', self.events())
        self.assertNotIn('drain-b', self.events())
        self.assertEqual(json.loads(path.read_text())['workers']['a']['status'], 'draining')
        (self.folder / 'hold-drain').unlink()
        self.result()

    def test_bootstrap_names_are_resolved_from_their_unit_receipts(self):
        for slot in ('a', 'b'):
            self.state['workers'][slot]['name'] = f'orbit-worker-{slot}'
        self.start()
        events = self.result()
        self.assertLess(events.index('enrolled-a'), events.index('drain-b'))

    def test_reminted_names_and_ansi_receipts_are_resolved_consistently(self):
        self.state['receipts'] = {}
        for slot in ('a', 'b'):
            name = f'orbit-worker-{slot}-1794096000'
            self.state['workers'][slot]['name'] = name
            self.state['receipts'][slot] = f'\x1b[32m✓ Enrolled as {name}\x1b[0m'
        self.start()
        events = self.result()
        self.assertLess(events.index('enrolled-a'), events.index('drain-b'))

    def test_missing_enrollment_receipt_leaves_both_untouched(self):
        self.state['receipts'] = {'a': ''}
        self.start()
        self.assertEqual(self.result(success=False), [])

    def test_other_probe_error_prevents_drain(self):
        self.state['probe_error'] = {'b': "Worker 'swamp-role-209-b' is draining"}
        self.start()
        self.assertEqual(self.result(success=False), ['verify-b'])

    def test_new_instance_must_pass_live_check_before_b_is_touched(self):
        self.state['probe_error'] = {'a': 'data plane failed'}
        self.start()
        events = self.result(success=False)
        self.assertIn('enrolled-a', events)
        self.assertNotIn('drain-b', events)

    def test_failed_enrollment_leaves_b_accepting_and_untouched(self):
        (self.folder / 'hold-enrollment').touch()
        self.start()
        events = self.result(success=False)
        self.assertIn('waiting-a', events)
        self.assertNotIn('drain-b', events)
        live = json.loads((self.folder / 'state.json').read_text())
        self.assertEqual(live['workers']['b']['status'], 'idle')

    def test_retry_leaves_freshly_renewed_a_persistent(self):
        self.state['workers']['a'].update(instanceUuid='new-a', enrolledAt=self.fresh_time())
        self.start()
        self.assertEqual(self.result(), ['verify-a', 'drain-b', 'stopped-b', 'enrolled-b', 'verify-b'])

    def test_fresh_workers_are_not_restarted(self):
        for w in self.state['workers'].values():
            w['enrolledAt'] = self.fresh_time()
        self.start()
        self.assertEqual(self.result(), [])

    def test_old_stop_allowance_prevents_any_restart(self):
        self.state['stop_usec'] = 300000000
        self.start()
        self.assertEqual(self.result(success=False), ['verify-b'])

    @staticmethod
    def fresh_time():
        return datetime.datetime.now(datetime.UTC).isoformat(timespec='milliseconds').replace('+00:00', 'Z')


if __name__ == '__main__':
    unittest.main()
