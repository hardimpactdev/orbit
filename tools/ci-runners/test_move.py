"""Move a runner onto the role's pool: drained on GitHub, never touched inside, its prior state restored on any failure."""
import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest

SETUP = Path(__file__).with_name('setup.sh')

# Incus and gh over one JSON state; each call is logged. `fail` names the operations that fail: stop, move, mark, start,
# unpin, query (the runner read), pool (the pool read), find (the runner list), drain, route, busy.
SHIM = '''#!/usr/bin/env python3
import json, os, re, sys
path = os.environ['MOVE_STATE']
state = json.load(open(path))
tool, args = os.path.basename(sys.argv[0]), sys.argv[1:]
open(os.environ['MOVE_LOG'], 'a').write(tool + ' ' + ' '.join(args) + '\\n')
def save():
    json.dump(state, open(path, 'w'))
def fail(name):
    if name in state['fail']:
        sys.exit(1)
    if name in state.get('once', []):
        state['once'].remove(name); save(); sys.exit(1)
if tool == 'gh':
    if '--paginate' in args:
        fail('find')
        name = re.search(r'select\\(.name == "([^"]+)"\\)', ' '.join(args)).group(1)
        for runner in state['github'].values():
            if runner['name'] == name:
                print(runner['id'])
    elif args[:3] == ['api', '-X', 'PUT']:
        labels = json.load(sys.stdin)['labels']
        fail('route' if labels else 'drain')
        state['github'][args[3].split('/')[4]]['labels'] = labels; save()
    elif args[-2:] == ['--jq', '.busy']:
        fail('busy')
        runner = state['github'][args[1].split('/')[-1]]
        print('true' if runner['busy'] and runner['busy'].pop(0) else 'false'); save()
    else:
        sys.exit(2)
    sys.exit(0)
runner = lambda name: state['runners'][name]
if args[0] == 'query':
    target = args[1].split('?')[0]
    if target.startswith('/1.0/storage-pools/'):
        fail('pool')
        if state['pool'] is None:
            sys.exit(1)
        print(json.dumps(state['pool']))
    else:
        fail('query')
        r = runner(target.removeprefix('/1.0/instances/'))
        root = {'type': 'disk', 'path': '/', 'pool': r['pool']}
        print(json.dumps({'status': r['status'], 'config': r['config'], 'expanded_devices': {'root': root},
                          'devices': {'root': root} if r['pinned'] else {}}))
elif args[0] == 'stop':
    fail('stop'); runner(args[1])['status'] = 'Stopped'; save()
elif args[0] == 'start':
    fail('start')
    assert runner(args[1])['status'] == 'Stopped', 'started a running runner'
    runner(args[1])['status'] = 'Running'; save()
elif args[0] == 'move':
    r = runner(args[1])
    assert r['status'] == 'Stopped' and args[-2:] == ['--storage', 'orbit-ci'], args
    fail('move'); r['pool'] = 'orbit-ci'; save()
elif args[:2] == ['config', 'set']:
    fail('mark')
    key, value = args[3].split('=', 1)
    runner(args[2])['config'][key] = value; save()
elif args[:2] == ['config', 'unset']:
    runner(args[2])['config'].pop(args[3], None); save()
elif args[:3] == ['config', 'device', 'remove']:
    fail('unpin'); runner(args[3])['pinned'] = False; save()
else:
    sys.exit(2)
'''

POOL = {'driver': 'zfs', 'config': {'source': 'data/orbit-ci', 'user.orbit.ci-runner': 'true'}}
ROUTED = ['hardimpact', 'orbit-shard']
MARK = {'user.orbit.ci-move-running': 'true'}


def runner(**fields):
    return {'pool': 'default', 'pinned': True, 'status': 'Running', 'config': {}, **fields}


def github(**fields):
    return {'name': 'ci-shark-1', 'id': 41, 'busy': [], 'labels': list(ROUTED), **fields}


class MoveTest(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory()
        self.root = Path(self.directory.name)
        for tool in ('incus', 'gh'):
            (self.root / tool).write_text(SHIM)
            (self.root / tool).chmod(0o755)
        self.state = self.root / 'state.json'
        self.log = self.root / 'log'

    def tearDown(self):
        self.directory.cleanup()

    def given(self, pool=POOL, fail=(), runners=None, hub=None, once=()):
        self.state.write_text(json.dumps({
            'pool': pool, 'fail': list(fail), 'once': list(once),
            'runners': runners or {'ci-shark-1': runner()},
            'github': {str(r['id']): r for r in (hub or [github()])}}))

    def move(self, name='ci-shark-1', wait='5'):
        self.log.write_text('')
        environment = {**os.environ, 'PATH': f'{self.root}:{os.environ["PATH"]}', 'MOVE_STATE': str(self.state),
                       'MOVE_LOG': str(self.log), 'ORBIT_CI_MOVE_WAIT': wait, 'ORBIT_CI_MOVE_POLL': '0'}
        result = subprocess.run(['bash', str(SETUP), 'move', name], env=environment, capture_output=True, text=True)
        state = json.loads(self.state.read_text())
        return result, state['runners'][name], state['github']['41']

    def rows(self):
        return self.log.read_text().splitlines()

    def test_drains_on_github_waits_for_idle_and_moves_a_running_runner_without_entering_it(self):
        self.given(hub=[github(busy=[True, True])])
        result, moved, hub = self.move()
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(moved, runner(pool='orbit-ci', pinned=False))
        self.assertEqual(hub['labels'], ROUTED)
        rows = self.rows()
        self.assertFalse(any(row.startswith('incus exec') for row in rows))
        at = lambda prefix: next(i for i, row in enumerate(rows) if row.startswith(prefix))
        busy = [i for i, row in enumerate(rows) if row.endswith('--jq .busy')]
        self.assertEqual(len(busy), 4)  # busy, busy, idle, idle again
        self.assertLess(at('incus config set'), at('gh api -X PUT'))
        self.assertLess(at('gh api -X PUT'), busy[0])
        self.assertLess(busy[-1], at('incus stop'))
        self.assertLess(at('incus stop'), at('incus move'))
        self.assertLess(at('incus start'), max(i for i, row in enumerate(rows) if row.startswith('gh api -X PUT')))

    def test_moves_a_stopped_runner_without_github_and_leaves_it_stopped(self):
        self.given(runners={'ci-shark-1': runner(status='Stopped')}, fail=['find', 'drain', 'busy'])
        result, moved, hub = self.move()
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(moved, runner(pool='orbit-ci', pinned=False, status='Stopped'))
        self.assertFalse(any(row.startswith('gh') for row in self.rows()))

    def test_unreadable_state_refuses_with_75_and_changes_nothing(self):
        for fail in ('query', 'find', 'busy'):
            with self.subTest(fail=fail):
                self.given(fail=[fail])
                result, moved, hub = self.move()
                self.assertEqual(result.returncode, 75, result.stderr)
                self.assertEqual(moved, runner())
                self.assertEqual(hub['labels'], ROUTED)

    def test_a_runner_still_busy_at_the_deadline_gets_its_labels_back_and_75(self):
        self.given(hub=[github(busy=[True] * 50)])
        result, moved, hub = self.move(wait='0')
        self.assertEqual(result.returncode, 75)
        self.assertEqual(moved, runner())
        self.assertEqual(hub['labels'], ROUTED)
        self.assertFalse(any(row.startswith('incus stop') for row in self.rows()))

    def test_reads_only_the_named_runner_and_not_one_whose_name_extends_it(self):
        self.given(runners={'ci-shark-1': runner(), 'ci-shark-10': runner()},
                   hub=[github(), github(name='ci-shark-10', id=42, busy=[True] * 50)])
        result, moved, hub = self.move()
        self.assertEqual(result.returncode, 0, result.stderr)
        state = json.loads(self.state.read_text())
        self.assertEqual(state['runners']['ci-shark-10'], runner())
        self.assertEqual(state['github']['42']['labels'], ROUTED)
        self.assertFalse(any('ci-shark-10' in row or '/42' in row for row in self.rows()))

    def test_refuses_a_missing_dir_or_unmarked_pool_on_every_path_before_any_change(self):
        for pool in (None, {'driver': 'dir', 'config': {'user.orbit.ci-runner': 'true'}},
                     {'driver': 'zfs', 'config': {'source': 'data/orbit-ci'}}):
            for on in ('default', 'orbit-ci'):
                with self.subTest(pool=pool, on=on):
                    self.given(pool=pool, runners={'ci-shark-1': runner(pool=on)})
                    result, moved, hub = self.move()
                    self.assertEqual(result.returncode, 1)
                    self.assertEqual(moved, runner(pool=on))
                    self.assertEqual(hub['labels'], ROUTED)
                    self.assertEqual([row.split()[1] for row in self.rows()], ['query', 'query'])

    def test_each_failure_after_a_change_restores_labels_running_state_and_pin(self):
        for fail in ('drain', 'mark', 'stop', 'move', 'unpin'):
            with self.subTest(fail=fail):
                self.given(fail=[fail])
                result, moved, hub = self.move()
                self.assertIn(result.returncode, (1, 75))
                self.assertEqual(moved['status'], 'Running')
                self.assertEqual(moved['config'], {})
                self.assertTrue(moved['pinned'])
                self.assertEqual(hub['labels'], ROUTED)
                if fail != 'unpin':
                    self.assertEqual(moved['pool'], 'default')

    def test_a_failed_mark_changes_nothing(self):
        self.given(fail=['mark'])
        result, moved, hub = self.move()
        self.assertEqual(result.returncode, 1)
        self.assertEqual(moved, runner())
        self.assertFalse(any(row.startswith(('incus stop', 'gh api -X PUT')) for row in self.rows()))

    def test_the_mark_stays_until_the_start_and_the_routing_both_succeed_and_a_healthy_retry_finishes_them(self):
        for once in ('route', 'start'):
            with self.subTest(once=once):
                self.given(once=[once])
                result, moved, hub = self.move()
                self.assertEqual(result.returncode, 1, result.stderr)
                self.assertEqual(moved['config'], MARK)
                self.assertEqual(moved['pool'], 'orbit-ci')
                result, moved, hub = self.move()
                self.assertEqual(result.returncode, 0, result.stderr)
                self.assertEqual(moved, runner(pool='orbit-ci', pinned=False))
                self.assertEqual(hub['labels'], ROUTED)

    def test_a_failed_restore_on_the_undo_path_keeps_the_mark_and_a_retry_finishes_the_move(self):
        self.given(fail=['move'], once=['route'])
        result, moved, hub = self.move()
        self.assertEqual(result.returncode, 1)
        self.assertEqual(moved['config'], MARK)
        self.assertEqual(moved['status'], 'Running')
        self.assertEqual(hub['labels'], [])
        state = json.loads(self.state.read_text())
        state['fail'] = []
        self.state.write_text(json.dumps(state))
        result, moved, hub = self.move()
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(moved, runner(pool='orbit-ci', pinned=False))
        self.assertEqual(hub['labels'], ROUTED)

    def test_a_runner_stopped_before_stays_stopped_through_failures_and_retries(self):
        for once in ('move', 'unpin', 'start', 'route'):
            with self.subTest(once=once):
                self.given(runners={'ci-shark-1': runner(status='Stopped')}, once=[once])
                self.move()
                result, moved, hub = self.move()
                self.assertEqual(result.returncode, 0, result.stderr)
                self.assertEqual(moved, runner(pool='orbit-ci', pinned=False, status='Stopped'))
                self.assertEqual(hub['labels'], ROUTED)
                self.assertFalse(any(row.startswith(('gh', 'incus start')) for row in self.rows()))

    def test_a_failed_move_then_a_retry_ends_running_and_routed(self):
        self.given(fail=['move'])
        self.move()
        state = json.loads(self.state.read_text())
        state['fail'] = []
        self.state.write_text(json.dumps(state))
        result, moved, hub = self.move()
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(moved, runner(pool='orbit-ci', pinned=False))
        self.assertEqual(hub['labels'], ROUTED)

    def test_a_rerun_after_an_interruption_starts_and_routes_the_runner_it_had_stopped(self):
        for pool, status in (('default', 'Stopped'), ('orbit-ci', 'Stopped'), ('orbit-ci', 'Running')):
            with self.subTest(pool=pool, status=status):
                self.given(runners={'ci-shark-1': runner(pool=pool, status=status, config=dict(MARK))},
                           hub=[github(labels=[])])
                result, moved, hub = self.move()
                self.assertEqual(result.returncode, 0, result.stderr)
                self.assertEqual(moved, runner(pool='orbit-ci', pinned=False))
                self.assertEqual(hub['labels'], ROUTED)

    def test_a_runner_on_the_pool_only_loses_its_pin(self):
        for pinned in (True, False):
            with self.subTest(pinned=pinned):
                self.given(runners={'ci-shark-1': runner(pool='orbit-ci', pinned=pinned)})
                result, moved, hub = self.move()
                self.assertEqual(result.returncode, 0, result.stderr)
                self.assertFalse(moved['pinned'])
                self.assertFalse(any(row.startswith('gh') for row in self.rows()))


if __name__ == '__main__':
    unittest.main()
