"""Move a runner onto the role's pool only while it runs no job, keeping its running state."""
import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest

SETUP = Path(__file__).with_name('setup.sh')

# An Incus that holds runners and the role's pool as a JSON file and logs each call. `worker` is what the container
# answers to the idle probe: `idle`, `busy`, `fail` (the exec fails), or `after-stop` (a job arrives as the listener
# stops). `fail_move` makes the next move fail.
INCUS = '''#!/usr/bin/env python3
import json, os, sys
path = os.environ['MOVE_STATE']
state = json.load(open(path))
args = sys.argv[1:]
open(os.environ['MOVE_LOG'], 'a').write(' '.join(args) + '\\n')
def save():
    json.dump(state, open(path, 'w'))
def runner(name):
    return state['runners'][name]
if args[0] == 'query':
    target = args[1].split('?')[0]
    if target.startswith('/1.0/storage-pools/'):
        if state['pool'] is None:
            sys.exit(1)
        print(json.dumps(state['pool']))
    else:
        name = target.removeprefix('/1.0/instances/')
        if name not in state['runners'] or state.get('unreadable'):
            sys.exit(1)
        r = runner(name)
        root = {'type': 'disk', 'path': '/', 'pool': r['pool']}
        print(json.dumps({'status': r['status'], 'config': r['config'], 'expanded_devices': {'root': root},
                          'devices': {'root': root} if r['pinned'] else {}}))
elif args[0] == 'exec':
    r = runner(args[1])
    if 'pgrep' in args[-1]:
        worker = r['worker']
        if worker == 'fail':
            sys.exit(1)
        print('busy' if worker == 'busy' or (worker == 'after-stop' and not r['listener']) else 'idle')
    elif './svc.sh stop' in args[-1]:
        r['listener'] = False; save()
    elif './svc.sh start' in args[-1]:
        r['listener'] = True; save()
    else:
        sys.exit(2)
elif args[0] == 'stop':
    runner(args[1])['status'] = 'Stopped'; runner(args[1])['listener'] = False; save()
elif args[0] == 'start':
    runner(args[1])['status'] = 'Running'; runner(args[1])['listener'] = True; save()
elif args[0] == 'move':
    r = runner(args[1])
    assert r['status'] == 'Stopped' and args[-2:] == ['--storage', 'orbit-ci'], args
    if state.get('fail_move'):
        state['fail_move'] = False; save(); sys.exit(1)
    r['pool'] = 'orbit-ci'; save()
elif args[:2] == ['config', 'set']:
    key, value = args[3].split('=', 1)
    runner(args[2])['config'][key] = value; save()
elif args[:2] == ['config', 'unset']:
    runner(args[2])['config'].pop(args[3], None); save()
elif args[:3] == ['config', 'device', 'remove']:
    runner(args[3])['pinned'] = False; save()
else:
    sys.exit(2)
'''

POOL = {'driver': 'zfs', 'config': {'source': 'data/orbit-ci', 'user.orbit.ci-runner': 'true'}}


def runner(**fields):
    return {'pool': 'default', 'pinned': True, 'status': 'Running', 'listener': True, 'worker': 'idle', 'config': {},
            **fields}


class MoveTest(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory()
        self.root = Path(self.directory.name)
        (self.root / 'incus').write_text(INCUS)
        (self.root / 'incus').chmod(0o755)
        self.state = self.root / 'state.json'
        self.log = self.root / 'log'

    def tearDown(self):
        self.directory.cleanup()

    def given(self, pool=POOL, **state):
        state.setdefault('runners', {'ci-shark-1': runner()})
        self.state.write_text(json.dumps({'pool': pool, **state}))

    def move(self, name='ci-shark-1'):
        self.log.write_text('')
        environment = {**os.environ, 'PATH': f'{self.root}:{os.environ["PATH"]}',
                       'MOVE_STATE': str(self.state), 'MOVE_LOG': str(self.log)}
        result = subprocess.run(['bash', str(SETUP), 'move', name], env=environment, capture_output=True, text=True)
        return result, json.loads(self.state.read_text())

    def calls(self):
        return [row.split()[0] for row in self.log.read_text().splitlines()]

    def test_moves_an_idle_running_runner_after_stopping_its_listener_and_starts_it_again(self):
        self.given()
        result, state = self.move()
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(state['runners']['ci-shark-1'],
                         runner(pool='orbit-ci', pinned=False))
        rows = self.log.read_text().splitlines()
        stop_listener = next(i for i, row in enumerate(rows) if './svc.sh stop' in row)
        probes = [i for i, row in enumerate(rows) if 'pgrep' in row]
        self.assertEqual(len(probes), 2)
        self.assertLess(probes[0], stop_listener)
        self.assertLess(stop_listener, probes[1])
        self.assertLess(probes[1], next(i for i, row in enumerate(rows) if row.startswith('stop ')))

    def test_moves_a_stopped_runner_without_probing_or_starting_it(self):
        self.given(runners={'ci-shark-1': runner(status='Stopped', listener=False, worker='fail')})
        result, state = self.move()
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(state['runners']['ci-shark-1']['status'], 'Stopped')
        self.assertEqual(state['runners']['ci-shark-1']['pool'], 'orbit-ci')
        self.assertNotIn('exec', self.calls())

    def test_refuses_a_job_a_failed_probe_or_an_unreadable_runner_without_stopping_it(self):
        for worker, unreadable in (('busy', False), ('fail', False), ('idle', True)):
            with self.subTest(worker=worker, unreadable=unreadable):
                self.given(unreadable=unreadable, runners={'ci-shark-1': runner(worker=worker)})
                result, state = self.move()
                self.assertEqual(result.returncode, 75, result.stderr)
                self.assertIn('move it when idle', result.stderr)
                self.assertEqual(state['runners']['ci-shark-1'], runner(worker=worker))
                self.assertNotIn('stop', self.calls())
                self.assertNotIn('move', self.calls())

    def test_a_job_that_arrives_while_the_listener_stops_restores_the_listener_and_refuses(self):
        self.given(runners={'ci-shark-1': runner(worker='after-stop')})
        result, state = self.move()
        self.assertEqual(result.returncode, 75)
        self.assertEqual(state['runners']['ci-shark-1'], runner(worker='after-stop'))
        self.assertNotIn('stop', self.calls())

    def test_reads_only_the_named_runner_and_not_one_whose_name_extends_it(self):
        self.given(runners={'ci-shark-1': runner(status='Stopped', listener=False),
                            'ci-shark-10': runner(), 'ci-shark-11': runner(worker='busy')})
        result, state = self.move()
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(state['runners']['ci-shark-1']['status'], 'Stopped')
        self.assertEqual(state['runners']['ci-shark-10'], runner())
        self.assertNotIn('ci-shark-10', self.log.read_text())

    def test_refuses_a_missing_dir_or_unmarked_pool_before_any_change(self):
        for pool in (None, {'driver': 'dir', 'config': {'user.orbit.ci-runner': 'true'}},
                     {'driver': 'zfs', 'config': {'source': 'data/orbit-ci'}}):
            with self.subTest(pool=pool):
                self.given(pool=pool)
                result, state = self.move()
                self.assertEqual(result.returncode, 1)
                self.assertEqual(state['runners']['ci-shark-1'], runner())
                self.assertEqual(self.calls(), ['query', 'query'])

    def test_a_failed_move_starts_the_runner_it_stopped_and_a_retry_moves_it_running(self):
        self.given(fail_move=True)
        result, state = self.move()
        self.assertEqual(result.returncode, 1)
        self.assertEqual(state['runners']['ci-shark-1'], runner())
        result, state = self.move()
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(state['runners']['ci-shark-1'], runner(pool='orbit-ci', pinned=False))

    def test_a_retry_after_an_interrupted_move_starts_the_runner_it_had_stopped(self):
        # Interrupted after the stop: stopped, still on the old pool, marked as running before.
        marked = {'user.orbit.ci-move-running': 'true'}
        for pool in ('default', 'orbit-ci'):
            with self.subTest(pool=pool):
                self.given(runners={'ci-shark-1': runner(pool=pool, status='Stopped', listener=False, config=dict(marked))})
                result, state = self.move()
                self.assertEqual(result.returncode, 0, result.stderr)
                self.assertEqual(state['runners']['ci-shark-1'], runner(pool='orbit-ci', pinned=False))

    def test_a_runner_on_the_pool_only_loses_its_pin(self):
        for pinned, expected in ((True, ['query', 'config']), (False, ['query'])):
            with self.subTest(pinned=pinned):
                self.given(runners={'ci-shark-1': runner(pool='orbit-ci', pinned=pinned)})
                result, state = self.move()
                self.assertEqual(result.returncode, 0, result.stderr)
                self.assertEqual(self.calls(), expected)
                self.assertFalse(state['runners']['ci-shark-1']['pinned'])


if __name__ == '__main__':
    unittest.main()
