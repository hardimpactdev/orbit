"""Real native schedule/DAG, with isolated Gateway and Incus boundaries; no fleet effects."""
import datetime
import json
from pathlib import Path
import sys
import unittest
import yaml

ROOT = Path(__file__).resolve().parents[4]
sys.path.insert(0, str(ROOT / 'tests'))
from native_harness import NativeTestCase


class ImageReaperNative(NativeTestCase):
    def setUp(self):
        super().setUp()
        self.copy_behaviors('automatic-deploy', 'node-worker',
                            'operations/_lib', 'operations/node', 'operations/task-vms')
        module = self.repo / 'extensions/behaviors/operations/task-vms/image_reaper.ts'
        module.write_text('import { fixtureHttp, fixtureSystem, fixtureIdentity } from "./image_reaper_native_fixture.ts";\n' + module.read_text().replace('...imageReaper()', '...imageReaper(() => fixtureHttp, fixtureSystem, fixtureIdentity)'))
        workflow_path = ROOT / 'workflows/workflow-task-vm-image-reaper.yaml'
        workflow = yaml.safe_load(workflow_path.read_text())
        self.assertEqual(workflow['trigger']['schedule'], '0 */30 * * * *')
        discovery = workflow['jobs'][0]['steps'][0]
        self.assertEqual(discovery['target'], 'swamp209-migration-a')
        self.assertEqual(discovery['labels'], {'pool': 'migration', 'node': '209'})
        host = workflow['jobs'][1]['steps'][0]
        self.assertEqual(host['target'], 'orbit-node-${{ self.host.node_id }}')
        self.assertEqual(host['labels'], {'node': '${{ string(self.host.node_id) }}'})
        for job in workflow['jobs']:
            for step in job['steps']:
                step.pop('target')
                step.pop('labels')
        (self.repo / 'workflows' / workflow_path.name).write_text(yaml.safe_dump(workflow, sort_keys=False))
        created = (datetime.datetime.now(datetime.timezone.utc) - datetime.timedelta(hours=3)).isoformat()
        self.state = {'api': [], 'commands': [], 'vms': {}}
        for node in (9, 13):
            self.state['vms'][str(node)] = {f'tvm-image-{build}': {
                'name': f'tvm-image-{build}', 'status': 'Stopped',
                'type': 'virtual-machine', 'created_at': created,
                'expanded_devices': {'root': {'type': 'disk', 'path': '/', 'pool': 'orbit-tasks'}},
            } for build in (node, node + 1)}
        self.save()

    def save(self):
        (self.repo / 'image-reaper-fixture.json').write_text(json.dumps(self.state))

    def test_actual_native_schedule_runs_each_host_and_reports_only_unreferenced_deletions(self):
        self.cli('workflow', 'validate', 'task-vm-image-reaper')
        self.cli('workflow', 'run', 'task-vm-image-reaper')
        state = json.loads((self.repo / 'image-reaper-fixture.json').read_text())
        self.assertEqual([(c['node_id'], c['args'][-1]) for c in state['commands'] if c['args'][0] == 'delete'],
                         [(9, 'tvm-image-9'), (13, 'tvm-image-13')])
        self.assertEqual(list(state['vms']['9']), ['tvm-image-10'])
        self.assertEqual(list(state['vms']['13']), ['tvm-image-14'])
        reports = []
        for path in (self.repo / '.swamp/data').rglob('raw'):
            try:
                value = json.loads(path.read_text())
            except (ValueError, UnicodeError):
                continue
            if isinstance(value, dict) and value.get('storage_removed') is True:
                reports.append(value)
        self.assertEqual(sorted(r['name'] for r in reports), ['tvm-image-13', 'tvm-image-9'])

    def test_unavailable_gateway_fails_without_any_instance_effect(self):
        self.state['unavailable'] = True
        self.save()
        self.cli('workflow', 'run', 'task-vm-image-reaper', success=False)
        state = json.loads((self.repo / 'image-reaper-fixture.json').read_text())
        self.assertEqual(state['commands'], [])


if __name__ == '__main__':
    unittest.main()
