"""Execute the standalone selector with pinned Swamp in an isolated datastore."""
import json

import yaml
from native_harness import ROOT, NativeTestCase


class TaskVmReaperNativeTest(NativeTestCase):
    native_timeout = 60

    def test_proof_age_and_claimed_orphan_selected_but_warm_pool_kept(self):
        self.copy_behaviors('operations/task-vm-reaper')
        workflow = yaml.safe_load((ROOT / 'workflows/workflow-task-vm-reaper.yaml').read_text())
        self.assertEqual(workflow['queueTimeout'], 15)
        self.assertEqual(workflow['vaults'], [])
        workflow.pop('trigger')
        job = workflow['jobs'][0]
        self.assertEqual(job.pop('target'), 'orbit-node-209')
        self.assertNotIn('labels', job)
        self.assertEqual(len(job['steps']), 1)
        (self.repo / 'workflows/workflow-task-vm-reaper.yaml').write_text(yaml.safe_dump(workflow, sort_keys=False))
        facts = {'task_vm_id': 17, 'provider_id': 'tvm-17', 'name': 'tvm-17', 'group_id': 23,
                 'host_node_id': 12, 'node_id': 42, 'claimed_at': '2026-01-01T00:00:00Z',
                 'group_live': True, 'progress_at': '2099-01-01T00:00:00Z',
                 'assistance_requested_at': None, 'project_id': 62, 'group_created_at': '2026-01-01T00:00:00Z'}
        inputs = {'expectations_hash': 'a' * 64, 'expectations': {'proof_project_id': 62, 'vms': [
            facts,
            {**facts, 'task_vm_id': 18, 'group_id': None, 'project_id': None,
             'group_live': False, 'group_created_at': None},
            {**facts, 'task_vm_id': 19, 'group_id': None, 'group_live': False,
             'claimed_at': None, 'group_created_at': None},
            {**facts, 'task_vm_id': 20, 'project_id': 5},
            {**facts, 'task_vm_id': 21, 'group_created_at': '2099-01-01T00:00:00Z',
             'progress_at': '2026-01-01T00:00:00Z', 'assistance_requested_at': '2026-01-01T00:00:00Z'},
        ]}}
        source = self.repo / 'input.json'
        source.write_text(json.dumps(inputs))
        self.cli('workflow', 'validate', workflow['name'])
        measurements = []
        for _ in range(3):
            run = json.loads(self.cli('workflow', 'run', workflow['name'], '--input-file', str(source)).stdout)
            history = json.loads(self.cli('workflow', 'history', 'get', run['id']).stdout)
            self.assertEqual(history['status'], 'succeeded')
            result = json.loads(history['jobs'][0]['steps'][0]['outputs']['stdout'])
            self.assertEqual([(r['task_vm_id'], r['reason']) for r in result['requests']],
                             [(17, 'proof_age'), (18, 'no_live_group')])
            self.assertEqual(result['expectations_hash'], inputs['expectations_hash'])
            self.assertEqual(result['requests'][0]['group_created_at'], facts['group_created_at'])
            measurements.append({'run_id': run['id'], 'started_at': history['startedAt'],
                                 'completed_at': history['completedAt'], 'status': history['status'],
                                 'phase': 'select', 'phase_ms': history['jobs'][0]['steps'][0]['duration']})
        print(json.dumps({'native_selection_runs': measurements}, sort_keys=True))
