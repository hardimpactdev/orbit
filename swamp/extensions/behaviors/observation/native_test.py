"""Validate native Doctor and read-only observation at their real placement."""
import json
import shutil
import sys
import unittest
import yaml
from pathlib import Path

ROOT = Path(__file__).resolve().parents[3]
sys.path.insert(0, str(ROOT / 'tests'))
from native_harness import NativeTestCase


class ObservationNativeTest(NativeTestCase):
    def setUp(self):
        super().setUp()
        self.copy_behaviors('observation')
        dest = self.repo / '.swamp/pulled-extensions/@swamp/ssh'
        dest.parent.mkdir(parents=True)
        shutil.copytree(ROOT / '.swamp/pulled-extensions/@swamp/ssh', dest)
        index = self.repo / 'extensions/models/upstream_extensions.json'
        index.parent.mkdir(parents=True)
        index.write_text(json.dumps({'@swamp/ssh': json.loads((ROOT / 'extensions/models/upstream_extensions.json').read_text())['@swamp/ssh']}))
        for name in ('doctor-inspect','observation-maintain'):
            path = ROOT / f'workflows/workflow-operation-{name}.yaml'
            shutil.copy(path, self.repo / 'workflows' / path.name)

        for name in ('context', 'collection', 'horizon'):
            path = ROOT / f'workflows/workflow-observation-{name}.yaml'
            shutil.copy(path, self.repo / 'workflows' / path.name)

    def test_native_schedule_reads_one_published_context_and_returns_typed_result(self):
        context = {'channel': 'horizon', 'revision': 7, 'expectations': 'a' * 64, 'captured_at': '2099-01-01T00:00:00Z',
            'node_id': 209, 'intent': {'nodes': [{'id': 209, 'name': 'fixture', 'platform': 'linux',
            'architecture': 'x86_64', 'user': 'orbit', 'managed': False, 'parked': False,
            'resources': {}}], 'families': ['process'], 'collect_problems': False}}
        from datetime import datetime, timezone
        context['captured_at'] = datetime.now(timezone.utc).isoformat()
        for path in (self.repo / 'workflows').glob('*.yaml'):
            workflow = yaml.safe_load(path.read_text())
            for job in workflow['jobs']:
                job.pop('target', None)
                for step in job['steps']:
                    step.pop('target', None)
            path.write_text(yaml.safe_dump(workflow, sort_keys=False))
        failed = json.JSONDecoder().raw_decode(self.cli('workflow', 'run', 'observation-horizon', success=False).stdout.lstrip())[0]
        self.assertEqual(failed['status'], 'failed')
        for revision in (6, 7, 5):
            self.cli('workflow', 'run', 'observation-context', '--input', json.dumps({**context, 'revision': revision}))
        # One stable record per channel: select reads one bounded version, never the capture history.
        names = json.JSONDecoder().raw_decode(self.cli('data', 'list', 'observation-context-horizon').stdout.lstrip())[0]
        self.assertIn('context', json.dumps(names))
        raw = self.cli('workflow', 'run', 'observation-horizon').stdout
        run = json.JSONDecoder().raw_decode(raw.lstrip())[0]
        raw = self.cli('workflow', 'history', 'get', run['id']).stdout
        history = json.JSONDecoder().raw_decode(raw.lstrip())[0]
        step = next(s for j in history['jobs'] for s in j['steps'] if s['name'] == 'result')
        value = json.loads(step['outputs']['stdout'])
        self.assertEqual(value['revision'], 7)
        self.assertEqual(value['channel'], 'horizon')
        self.assertIn('report', value['observation'])

    def test_native_doctor_and_readonly_observation_validate_with_their_real_placement(self):
        for name in ('operation-doctor-inspect', 'operation-observation-maintain', 'observation-context', 'observation-collection', 'observation-horizon'):
            self.cli('workflow', 'validate', name)


if __name__ == '__main__':
    unittest.main()
