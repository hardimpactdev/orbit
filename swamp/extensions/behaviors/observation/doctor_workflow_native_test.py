"""Exercise focused Doctor exec steps through the real offline native DAG."""
import json
import shutil
import sys
import unittest
from pathlib import Path

import yaml

ROOT = Path(__file__).resolve().parents[3]
sys.path.insert(0, str(ROOT / 'tests'))
from native_harness import NativeTestCase


def reply(raw):
    return json.JSONDecoder().raw_decode(raw.lstrip())[0]


class DoctorWorkflowNative(NativeTestCase):
    native_timeout = 120

    def setUp(self):
        super().setUp()
        self.copy_behaviors('observation', 'operations/firewall')
        dest = self.repo / '.swamp/pulled-extensions/@swamp/ssh'
        dest.parent.mkdir(parents=True)
        shutil.copytree(ROOT / '.swamp/pulled-extensions/@swamp/ssh', dest)
        index = self.repo / 'extensions/models/upstream_extensions.json'
        index.parent.mkdir(parents=True)
        index.write_text(json.dumps({'@swamp/ssh': json.loads((ROOT / 'extensions/models/upstream_extensions.json').read_text())['@swamp/ssh']}))
        self.state = self.repo / 'fixture-state.json'
        self.state.write_text(json.dumps({'deny': None, 'hang': None}))
        fake = self.repo / 'fixture-ssh'
        fake.write_text('#!' + sys.executable + '\n' + r'''import json,shlex,sys
from pathlib import Path
if '-M' in sys.argv or '-O' in sys.argv:sys.exit(0)
args=shlex.split(sys.argv[-1])
state=json.loads(Path(STATE).read_text())
if state['deny'] and any('@10.44.0.9' in arg for arg in sys.argv):sys.exit(255)
# A stopped task VM: the session never answers, so only the read bound can end it.
if state.get('hang') and any('@10.44.0.9' in arg for arg in sys.argv):
 import time;time.sleep(3600)
if args[:3]==['sudo','-n','--']:args=args[3:]
if args[:2]==['uname','-sm']:print('Linux x86_64')
elif args[:2]==['df','-Pk']:print('Filesystem 1024-blocks Used Available Capacity Mounted on\n/dev/root 10000000 1000000 9000000 10% /')
elif args[0]=='systemctl':print('LoadState=loaded\nActiveState=active\nSubState=running\nNRestarts=49')
elif args[:2]==['python3','-']:
 # Execute the actual packaged one-read log script. It cannot invoke host commands.
 exec(sys.stdin.read(),{'__name__':'__main__'})
else:raise RuntimeError('Unexpected native command '+repr(args))
'''.replace('STATE', repr(str(self.state))))
        fake.chmod(0o700)
        key = self.repo / 'fixture-key'
        key.write_text('fixture key')
        key.chmod(0o600)
        workflow = yaml.safe_load((ROOT / 'workflows/workflow-operation-doctor-inspect.yaml').read_text())
        for job in workflow['jobs']:
            self.assertEqual(job.pop('labels'), {'role': 'swamp'})
            for step in job['steps']:
                if step['task']['behaviorType'] == '@swamp/ssh':
                    args = step['task']['globalArgs']
                    args['sshBinary'] = str(fake)
                    args['transport']['identityFile'] = str(key)
        (self.repo / 'workflows/workflow-operation-doctor-inspect.yaml').write_text(yaml.safe_dump(workflow, sort_keys=False))
        self.public = {'operation_id': 7, 'node_id': 14, 'kind': 'doctor.inspect', 'intent': {'nodes': [self.node(14), self.node(9)], 'families': ['node'], 'collect_problems': False}}

    @staticmethod
    def node(node_id, **overrides):
        return {'id': node_id, 'name': f'dev-{node_id}', 'platform': 'linux', 'architecture': 'x86_64', 'user': 'orbit', 'managed': True, 'parked': False, 'roles': [], 'resources': {}, 'observations': {'freshness': 'fresh', 'valid_until': 9999999999},
                'ssh': {'host': f'10.44.0.{node_id}', 'user': 'orbit', 'port': 22, 'host_keys': ['ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIGaNG9xwGkyymXqkaSEKl6+u5VLBHPbi0ga0ya7sdCtG']}, **overrides}

    def run_doctor(self):
        self.cli('workflow', 'validate', 'operation-doctor-inspect')
        run = reply(self.cli('workflow', 'run', 'operation-doctor-inspect', '--input', json.dumps(self.public)).stdout)
        history = reply(self.cli('workflow', 'history', 'get', run['id']).stdout)
        self.assertEqual(history['status'], 'succeeded', json.dumps(history))
        step = next(s for j in history['jobs'] if j['name'] == 'report' for s in j['steps'] if s['name'] == 'result')
        return json.loads(step['outputs']['stdout']), history

    def test_individual_native_execs_compare_two_nodes_without_script_copy_or_private_input(self):
        result, history = self.run_doctor()
        self.assertTrue(result['report']['healthy'])
        reads = next(j for j in history['jobs'] if j['name'] == 'reads')['steps']
        self.assertEqual(len(reads), 6)
        self.assertTrue(all(s['status'] == 'succeeded' for s in reads))
        self.assertEqual(result['report']['summary']['checks'], 6)
        self.assertNotIn('execution_secrets', self.public)

    def test_failed_ssh_is_unverifiable_and_other_node_stays_healthy(self):
        self.state.write_text(json.dumps({'deny': 9}))
        result, _ = self.run_doctor()
        self.assertFalse(result['report']['healthy'])
        self.assertTrue(result['report']['nodes'][0]['healthy'])
        self.assertEqual(result['report']['nodes'][1]['families'][0]['status'], 'unverifiable')

    def test_stalled_node_fails_within_its_read_bound_and_other_node_stays_healthy(self):
        import time
        self.native_timeout = 150
        self.state.write_text(json.dumps({'deny': None, 'hang': 9}))
        started = time.monotonic()
        result, history = self.run_doctor()
        self.assertLess(time.monotonic() - started, 140)
        self.assertTrue(result['report']['nodes'][0]['healthy'])
        self.assertEqual(result['report']['nodes'][1]['families'][0]['status'], 'unverifiable')
        reads = next(j for j in history['jobs'] if j['name'] == 'reads')['steps']
        self.assertEqual(sorted(s['status'] for s in reads), ['failed'] * 3 + ['succeeded'] * 3)

    def test_parked_and_unmanaged_nodes_do_not_dispatch(self):
        self.public['intent']['nodes'][0]['parked'] = True
        self.public['intent']['nodes'][1]['managed'] = False
        result, history = self.run_doctor()
        self.assertEqual(result['report']['summary']['checks'], 0)
        self.assertEqual(next(j for j in history['jobs'] if j['name'] == 'reads')['steps'], [])

    def test_single_log_script_resolves_actual_registered_asset_and_returns_cursor_only(self):
        # Files are outside native history; only cursor metadata may leave the reader.
        with self.subTest('first pass ignores historic messages'):
            logs = self.repo / 'fixture-logs'
            logs.mkdir()
            (logs / 'laravel.log').write_text('[2026-11-01 01:00:00] testing.ERROR: private log content\n')
            self.public['intent'].update(collect_problems=True, logs={'log_directory': str(logs), 'base_directory': '/gateway', 'cursor': None})
            result, history = self.run_doctor()
            self.assertIn('logs', result, json.dumps(next(j for j in history['jobs'] if j['name'] == 'logs')))
            self.assertEqual(result['logs']['signals'], [])
            self.assertEqual(result['logs']['cursor']['offset'], (logs / 'laravel.log').stat().st_size)
            self.assertNotIn('private log content', json.dumps(history))


if __name__ == '__main__':
    unittest.main()
