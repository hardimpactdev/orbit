"""Execute the read-only observation DAG with unmodified official SSH and harmless hosts."""
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


class MaintenanceNativeTest(NativeTestCase):
    native_timeout = 120

    def setUp(self):
        super().setUp()
        self.copy_behaviors('observation')
        dest = self.repo / '.swamp/pulled-extensions/@swamp/ssh'
        dest.parent.mkdir(parents=True)
        shutil.copytree(ROOT / '.swamp/pulled-extensions/@swamp/ssh', dest)
        index = self.repo / 'extensions/models/upstream_extensions.json'
        index.parent.mkdir(parents=True)
        index.write_text(json.dumps({'@swamp/ssh': json.loads((ROOT / 'extensions/models/upstream_extensions.json').read_text())['@swamp/ssh']}))
        self.state = self.repo / 'state.json'
        self.state.write_text(json.dumps({'deny': False}))
        self.commands = self.repo / 'commands.jsonl'
        fake = self.repo / 'fixture-ssh'
        fake.write_text('#!' + sys.executable + '\n' + r'''import json,shlex,sys
from pathlib import Path
if '-M' in sys.argv or '-O' in sys.argv:sys.exit(0)
args=shlex.split(sys.argv[-1])
with Path(COMMANDS).open('a') as f:f.write(json.dumps(args)+'\n')
if json.loads(Path(STATE).read_text())['deny']:sys.exit(255)
if args==['cat','/proc/uptime','/proc/loadavg']:print('123.50 456.25\n0.20 1.30 2.40 1/800 37')
elif args==['systemctl','show','--property=MemoryCurrent,CPUUsageNSec,TasksCurrent','--','orbit-process-7-horizon.service']:print('MemoryCurrent=1000\nCPUUsageNSec=2000\nTasksCurrent=3')
else:raise RuntimeError('Unexpected native effect '+repr(args))
'''.replace('STATE', repr(str(self.state))).replace('COMMANDS', repr(str(self.commands))))
        fake.chmod(0o700)
        key = self.repo / 'fixture-key'
        key.write_text('fixture key')
        key.chmod(0o600)
        workflow = yaml.safe_load((ROOT / 'workflows/workflow-operation-observation-maintain.yaml').read_text())
        for job in workflow['jobs']:
            self.assertEqual(job.pop('target'), 'orbit-node-209')
            for step in job['steps']:
                self.assertFalse(step['writes'])
                if step['task']['behaviorType'] == '@swamp/ssh':
                    args = step['task']['globalArgs']
                    args['sshBinary'] = str(fake)
                    args['transport']['identityFile'] = str(key)
        (self.repo / 'workflows/workflow-operation-observation-maintain.yaml').write_text(yaml.safe_dump(workflow, sort_keys=False))
        self.public = {'operation_id': 7, 'node_id': 14, 'kind': 'observation.maintain', 'intent': {
            'routine': 'maintenance', 'tasks_enabled': True, 'evaluated_at': '2026-10-31T12:00:00Z',
            'activity_seconds': 900, 'batch_hours': 24, 'failed_hours': 168,
            'horizon_unit': 'orbit-process-7-horizon.service', 'activities': [], 'batches': [], 'failed': [],
            'ssh': {'host': '10.44.0.14', 'user': 'orbit', 'port': 22, 'host_keys': ['ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIGaNG9xwGkyymXqkaSEKl6+u5VLBHPbi0ga0ya7sdCtG']}}}

    def run_observation(self):
        self.cli('workflow', 'validate', 'operation-observation-maintain')
        run = reply(self.cli('workflow', 'run', 'operation-observation-maintain', '--input', json.dumps(self.public)).stdout)
        history = reply(self.cli('workflow', 'history', 'get', run['id']).stdout)
        self.assertEqual(history['status'], 'succeeded', json.dumps(history))
        step = next(s for j in history['jobs'] if j['name'] == 'report' for s in j['steps'] if s['name'] == 'result')
        return json.loads(step['outputs']['stdout']), history

    def test_two_native_reads_emit_one_typed_result_without_mutating_commands(self):
        result, history = self.run_observation()
        self.assertEqual(result['uptime_seconds'], 123.5, json.dumps(history['jobs']))
        self.assertEqual(result['horizon_metrics'], {'memory_bytes': 1000, 'cpu_nanoseconds': 2000, 'tasks': 3})
        self.assertEqual(result['unavailable'], [])
        self.assertEqual(len(self.commands.read_text().splitlines()), 2)
        self.assertEqual(len(next(j for j in history['jobs'] if j['name'] == 'reads')['steps']), 2)

    def test_failed_ssh_remains_unavailable_and_does_not_discard_eligibility(self):
        self.state.write_text(json.dumps({'deny': True}))
        self.public['intent']['activities'] = [{'id': 1, 'created_at': '2026-01-01T00:00:00Z'}]
        result, _ = self.run_observation()
        self.assertEqual(result['eligible']['activities'], 1)
        self.assertEqual(result['unavailable'], ['host_metrics', 'horizon_metrics'])
        self.assertIsNone(result['uptime_seconds'])

    def test_retention_is_pure_metadata_and_dispatches_no_host_commands(self):
        self.public['intent'].update(routine='retention', ssh=None)
        result, _ = self.run_observation()
        self.assertEqual(result['unavailable'], [])
        self.assertFalse(self.commands.exists())
        self.assertIsNone(result['horizon_metrics'])


if __name__ == '__main__':
    unittest.main()
