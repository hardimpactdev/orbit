"""Native script assets and independent cleanup branches without Gateway callbacks."""
import json
import os
import pwd
import sys
import unittest
import uuid
from pathlib import Path

import yaml

ROOT = Path(__file__).resolve().parents[4]
sys.path.insert(0, str(ROOT / 'tests'))
from native_harness import NativeTestCase


def reply(raw):
    return json.JSONDecoder().raw_decode(raw.lstrip())[0]


class SqliteAbandonNative(NativeTestCase):
    def setUp(self):
        super().setUp()
        self.copy_behaviors('operations/database/sqlite-cleanup')
        owner = pwd.getpwuid(os.geteuid()).pw_name
        identity = uuid.uuid4().hex * 2
        self.database = self.repo / 'installed.sqlite'
        self.database.write_text('installed database must survive abandon')
        source = {'variant': 'seed', 'node_id': 9, 'target_instance_id': 42, 'base': str(self.repo),
                  'source_path': str(self.repo / 'source.sqlite'), 'source_user': owner, 'transport_user': owner,
                  'operation': identity, 'state_dir': '/var/lib/orbit/app-instance-sqlite-seeds/source-41-target-42-' + identity,
                  'snapshot_path': '/tmp/orbit-sqlite-' + identity + '.sqlite'}
        target = {'node_id': 17, 'target_instance_id': 42, 'base': str(self.repo), 'relative_path': 'installed.sqlite',
                  'target_user': owner, 'transport_user': owner, 'operation': identity, 'environment': 'development',
                  'state_dir': '/var/lib/orbit/app-instance-sqlite-seeds/target-42-' + identity}
        self.public = {'operation_id': 17, 'node_id': 17, 'kind': 'database.sqlite_seed_abandon',
                       'intent': {'source': source, 'target': target}, 'execution_secrets': {}}
        self.workflow = yaml.safe_load((ROOT / 'workflows/workflow-operation-database-sqlite-seed-abandon.yaml').read_text())
        for job in self.workflow['jobs']:
            job.pop('target', None)
            job.pop('labels', None)
            for step in job['steps']:
                expression = 'inputs.intent.target.node_id' if step['name'] in ('target', 'target_assets') else (
                    'inputs.intent.source.node_id' if step['name'] in ('source', 'source_assets') else 'inputs.node_id')
                self.assertIn(expression, step.pop('target'))
                self.assertIn(expression, step.pop('labels')['node'])
        self.save()

    def save(self):
        (self.repo / 'workflows/workflow-operation-database-sqlite-seed-abandon.yaml').write_text(
            yaml.safe_dump(self.workflow, sort_keys=False))

    def run_abandon(self, success=True):
        self.cli('workflow', 'validate', 'operation-database-sqlite-seed-abandon')
        return reply(self.cli('workflow', 'run', 'operation-database-sqlite-seed-abandon',
                              '--input', json.dumps(self.public), success=success).stdout)

    def history(self, run):
        return {step['name']: step for step in reply(self.cli('workflow', 'history', 'get', run['id']).stdout)['jobs'][0]['steps']}

    def test_native_metadata_resolves_script_files_and_missing_owned_state_is_clean(self):
        run = self.run_abandon()
        steps = self.history(run)
        self.assertEqual(run['status'], 'succeeded')
        self.assertTrue(steps['target_assets']['outputs']['script'].endswith('/scripts/target_abandon.py'))
        self.assertTrue(steps['source_assets']['outputs']['script'].endswith('/scripts/source_cleanup.py'))
        self.assertEqual(json.loads(steps['result']['outputs']['stdout']), {'backend': 'absent'})
        self.assertEqual(self.database.read_text(), 'installed database must survive abandon')

    def test_target_failure_does_not_skip_source_cleanup(self):
        self.workflow['jobs'][0]['steps'][1]['task']['inputs']['run'] = 'exit 1'
        self.save()
        run = self.run_abandon(False)
        steps = self.history(run)
        self.assertEqual(steps['target']['status'], 'failed')
        self.assertEqual(steps['source']['status'], 'succeeded')
        self.assertEqual(json.loads(steps['result']['outputs']['stdout']), {'error_code': 'database.sqlite_native_failed'})
        self.assertTrue(self.database.exists())

    def test_source_failure_cannot_project_success_after_clean_target(self):
        self.workflow['jobs'][0]['steps'][3]['task']['inputs']['run'] = 'exit 1'
        self.save()
        run = self.run_abandon(False)
        steps = self.history(run)
        self.assertEqual(steps['target']['status'], 'succeeded')
        self.assertEqual(steps['source']['status'], 'failed')
        self.assertEqual(run['status'], 'failed')
        self.assertTrue(self.database.exists())

    def test_delivery_only_resume_does_not_repeat_cleanups(self):
        counter = self.repo / 'target-action-count'
        fail = self.repo / 'delivery-failure'
        fail.touch()
        action = self.workflow['jobs'][0]['steps'][1]['task']['inputs']
        action['run'] = 'printf x >> "' + str(counter) + '"\n' + action['run']
        self.workflow['jobs'][0]['steps'].append({
            'name': 'fixture_delivery', 'dependsOn': [{'step': 'accept_result', 'condition': {'type': 'succeeded'}}],
            'task': {'type': 'behavior', 'behaviorType': 'command/shell', 'behaviorName': 'fixture-delivery-${{ run.id }}',
                     'methodName': 'execute', 'inputs': {'run': 'test ! -e "' + str(fail) + '"'}}})
        self.save()
        run = self.run_abandon(False)
        fail.unlink()
        self.assertEqual(reply(self.cli('workflow', 'resume', 'operation-database-sqlite-seed-abandon', '--run', run['id']).stdout)['status'], 'succeeded')
        self.assertEqual(counter.read_text(), 'x')
        self.assertTrue(self.database.exists())


if __name__ == '__main__':
    unittest.main()
