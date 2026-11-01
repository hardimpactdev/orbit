"""Execute selector and official SSH stat reads in an isolated native datastore."""
import json
import shutil
import sys
from datetime import UTC, datetime, timedelta

import yaml
from native_harness import ROOT, NativeTestCase


class TaskVmReaperNativeTest(NativeTestCase):
    native_timeout = 120

    def test_proof_topology_and_orphan_rules_keep_fresh_failed_reads_and_warm_pool(self):
        self.copy_behaviors('operations/task-vm-reaper')
        policy = self.repo / 'extensions/behaviors/operations/task-vm-lifecycle'
        policy.mkdir()
        shutil.copy(ROOT / 'extensions/behaviors/operations/task-vm-lifecycle/input.ts', policy)
        self.cli('extension', 'pull', '@swamp/ssh@2026.10.23.1')
        now = datetime.now(UTC)
        fresh_epoch = int((now - timedelta(minutes=1)).timestamp())
        audit = self.repo / 'ssh-calls.jsonl'
        binary = self.repo / 'fixture-ssh'
        binary.write_text('#!' + sys.executable + '\n' + f'''import json,sys
from pathlib import Path
if '-M' in sys.argv or '-O' in sys.argv: raise SystemExit(0)
with Path({str(audit)!r}).open('a') as stream: stream.write(json.dumps(sys.argv[1:])+'\\n')
assert '/usr/bin/stat' in sys.argv[-1] and '/var/lib/orbit/swamp-proof/last-run' in sys.argv[-1]
if any('192.0.2.22' in arg for arg in sys.argv): print({fresh_epoch}); raise SystemExit(0)
if any('192.0.2.23' in arg for arg in sys.argv):
 print("stat: cannot statx '/var/lib/orbit/swamp-proof/last-run': No such file or directory", file=sys.stderr); raise SystemExit(1)
print('Permission denied (publickey)', file=sys.stderr); raise SystemExit(255)
''')
        binary.chmod(0o755)
        workflow = yaml.safe_load((ROOT / 'workflows/workflow-task-vm-reaper.yaml').read_text())
        self.assertEqual(workflow['queueTimeout'], 15)
        self.assertEqual(workflow['trigger']['schedule'], '20 */5 * * * *')
        self.assertEqual(workflow['vaults'], [])
        workflow.pop('trigger')
        for job in workflow['jobs']:
            self.assertEqual(job.pop('target'), 'orbit-node-209')
            self.assertNotIn('labels', job)
            for step in job['steps']:
                if step['task'].get('behaviorType') == '@swamp/ssh':
                    step['task']['globalArgs']['sshBinary'] = str(binary)
        (self.repo / 'workflows/workflow-task-vm-reaper.yaml').write_text(yaml.safe_dump(workflow, sort_keys=False))
        facts = {'task_vm_id': 17, 'provider_id': 'tvm-17', 'name': 'tvm-17', 'group_id': 23,
                 'group_title': 'proof node.role_add app-dev@v1', 'ssh': None,
                 'host_node_id': 12, 'node_id': 42, 'claimed_at': '2026-01-01T00:00:00Z',
                 'group_live': True, 'progress_at': '2099-01-01T00:00:00Z',
                 'assistance_requested_at': None, 'project_id': 62, 'group_created_at': '2026-01-01T00:00:00Z'}
        vms = [facts,
               {**facts, 'task_vm_id': 18, 'group_id': None, 'project_id': None, 'group_live': False, 'group_title': None, 'group_created_at': None},
               {**facts, 'task_vm_id': 19, 'group_id': None, 'group_live': False, 'claimed_at': None, 'group_created_at': None},
               {**facts, 'task_vm_id': 20, 'project_id': 5},
               {**facts, 'task_vm_id': 21, 'group_created_at': '2099-01-01T00:00:00Z', 'progress_at': '2026-01-01T00:00:00Z'}]
        for node in (22, 23, 24, 25):
            vms.append({**facts, 'task_vm_id': node, 'node_id': node, 'group_title': 'topology app-dev@v1 lane21',
                        'group_created_at': '2026-01-01T00:00:00Z' if node == 24 else (now-timedelta(hours=2)).isoformat().replace('+00:00', 'Z'),
                        'ssh': {'control_node_id': 209, 'identity_file': '/fixture/identity', 'public_key': 'ssh-ed25519 fixture',
                                'target': {'name': 'target', 'host': '192.0.2.'+str(node), 'user': 'orbit', 'port': 22,
                                           'host_keys': ['ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIGaNG9xwGkyymXqkaSEKl6+u5VLBHPbi0ga0ya7sdCtG']}}})
        inputs = {'expectations_hash': 'a' * 64, 'expectations': {'proof_project_id': 62, 'vms': vms}}
        source = self.repo / 'input.json'
        source.write_text(json.dumps(inputs))
        self.cli('workflow', 'validate', workflow['name'])
        measurements = []
        for _ in range(3):
            run = json.loads(self.cli('workflow', 'run', workflow['name'], '--input-file', str(source)).stdout)
            history = json.loads(self.cli('workflow', 'history', 'get', run['id']).stdout)
            self.assertEqual(history['status'], 'succeeded', json.dumps(history))
            steps = {step['name']: step for job in history['jobs'] for step in job['steps']}
            result = json.loads(steps['result']['outputs']['stdout'])
            self.assertEqual([(r['task_vm_id'], r['reason']) for r in result['requests']],
                             [(17, 'proof_age'), (18, 'no_live_group'), (23, 'topology_idle'), (24, 'topology_max_age')], json.dumps(history))
            self.assertEqual(result['expectations_hash'], inputs['expectations_hash'])
            measurements.append({'run_id': run['id'], 'started_at': history['startedAt'], 'completed_at': history['completedAt'],
                                 'status': history['status'], 'phases': [{ 'name': name, 'ms': step.get('duration'), 'status': step['status']}
                                                                      for name, step in steps.items()]})
        calls = [json.loads(line) for line in audit.read_text().splitlines()]
        self.assertEqual(len(calls), 12)
        print(json.dumps({'native_selection_runs': measurements}, sort_keys=True))
