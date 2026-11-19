"""Move a runner onto the role's pool only while it runs no job, keeping its running state."""
import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest

SETUP = Path(__file__).with_name('setup.sh')

# An Incus that holds one runner as a JSON file and logs each call.
INCUS = '''#!/usr/bin/env python3
import json, os, sys
state_path = os.environ['MOVE_STATE']
state = json.load(open(state_path))
args = sys.argv[1:]
open(os.environ['MOVE_LOG'], 'a').write(' '.join(args) + '\\n')
def save():
    json.dump(state, open(state_path, 'w'))
if args[0] == 'query':
    root = {'type': 'disk', 'path': '/', 'pool': state['pool']}
    print(json.dumps({'expanded_devices': {'root': root}, 'devices': {'root': root} if state['pinned'] else {}}))
elif args[0] == 'list':
    print(state['status'])
elif args[0] == 'exec':
    sys.exit(state['worker'])
elif args[0] == 'stop':
    state['status'] = 'STOPPED'; save()
elif args[0] == 'start':
    state['status'] = 'RUNNING'; save()
elif args[0] == 'move':
    assert state['status'] == 'STOPPED' and args[-2:] == ['--storage', 'orbit-ci'], args
    state['pool'] = 'orbit-ci'; save()
elif args[:4] == ['config', 'device', 'remove', 'ci-sabre-1']:
    state['pinned'] = False; save()
else:
    sys.exit(2)
'''


class MoveTest(unittest.TestCase):
    def run_move(self, **runner):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            (root / 'incus').write_text(INCUS)
            (root / 'incus').chmod(0o755)
            state = root / 'state.json'
            state.write_text(json.dumps({'pool': 'default', 'pinned': True, 'status': 'RUNNING', 'worker': 1, **runner}))
            log = root / 'log'
            log.touch()
            environment = {**os.environ, 'PATH': f'{root}:{os.environ["PATH"]}',
                           'MOVE_STATE': str(state), 'MOVE_LOG': str(log)}
            result = subprocess.run(['bash', str(SETUP), 'move', 'ci-sabre-1'], env=environment,
                                    capture_output=True, text=True)
            return result, json.loads(state.read_text()), [row.split()[0] for row in log.read_text().splitlines()]

    def test_moves_an_idle_running_runner_and_starts_it_again(self):
        result, state, calls = self.run_move()
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(state, {'pool': 'orbit-ci', 'pinned': False, 'status': 'RUNNING', 'worker': 1})
        self.assertEqual(calls, ['query', 'list', 'exec', 'stop', 'move', 'query', 'start', 'config'])

    def test_moves_a_stopped_runner_without_starting_it(self):
        result, state, calls = self.run_move(status='STOPPED', worker=255)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(state['status'], 'STOPPED')
        self.assertEqual(state['pool'], 'orbit-ci')
        self.assertNotIn('exec', calls)

    def test_refuses_a_runner_with_a_job_or_an_unreadable_state_without_change(self):
        for worker in (0, 255):
            with self.subTest(worker=worker):
                result, state, calls = self.run_move(worker=worker)
                self.assertEqual(result.returncode, 75)
                self.assertIn('move it when idle', result.stderr)
                self.assertEqual(state['pool'], 'default')
                self.assertEqual(state['status'], 'RUNNING')
                self.assertEqual(calls, ['query', 'list', 'exec'])

    def test_a_runner_on_the_pool_only_loses_its_pin(self):
        for pinned, expected in ((True, ['query', 'config']), (False, ['query'])):
            with self.subTest(pinned=pinned):
                result, state, calls = self.run_move(pool='orbit-ci', pinned=pinned)
                self.assertEqual(result.returncode, 0, result.stderr)
                self.assertEqual(calls, expected)
                self.assertFalse(state['pinned'])


if __name__ == '__main__':
    unittest.main()
