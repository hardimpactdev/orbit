"""Real placed-command cleanup source with local scratch and native resume."""
import json
import shutil
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


class DatabaseCleanupNative(NativeTestCase):
    def setUp(self):
        super().setUp()
        self.directory = Path('/tmp/orbit-sqlite-clone-' + uuid.uuid4().hex)
        self.directory.mkdir(mode=0o700)
        self.snapshot = self.directory / 'snapshot.sqlite'
        self.snapshot.write_text('disposable private database bytes')
        self.snapshot.chmod(0o600)
        self.addCleanup(lambda: shutil.rmtree(self.directory, ignore_errors=True))
        self.public = {'operation_id': 17, 'node_id': 9, 'kind': 'database.sqlite_cleanup',
                       'intent': {'variant': 'clone', 'directory': str(self.directory)}, 'execution_secrets': {}}
        self.workflow = yaml.safe_load((ROOT / 'workflows/workflow-operation-database-cleanup.yaml').read_text())
        for job in self.workflow['jobs']:
            job.pop('target', None)
            job.pop('labels', None)
            for step in job['steps']:
                self.assertIn('inputs.node_id', step.pop('target'))
                self.assertIn('inputs.node_id', step.pop('labels')['node'])
        self.save()

    def save(self):
        (self.repo / 'workflows/workflow-operation-database-cleanup.yaml').write_text(yaml.safe_dump(self.workflow, sort_keys=False))

    def run_cleanup(self, success=True):
        self.cli('workflow', 'validate', 'operation-database-cleanup')
        return reply(self.cli('workflow', 'run', 'operation-database-cleanup',
                              '--input', json.dumps(self.public), success=success).stdout)

    def test_real_cleanup_removes_only_owned_scratch_without_any_gateway(self):
        run = self.run_cleanup()
        self.assertEqual(run['status'], 'succeeded')
        self.assertFalse(self.directory.exists())
        self.assertEqual(json.loads(reply(self.cli('workflow', 'history', 'get', run['id']).stdout)['jobs'][0]['steps'][2]['outputs']['stdout']), {'backend': 'absent'})

    def test_refusal_keeps_snapshot_and_native_run_failed(self):
        self.directory.chmod(0o755)
        run = self.run_cleanup(False)
        self.assertEqual(run['status'], 'failed')
        self.assertTrue(self.snapshot.exists())
        self.assertEqual(json.loads(reply(self.cli('workflow', 'history', 'get', run['id']).stdout)['jobs'][0]['steps'][2]['outputs']['stdout']),
                         {'error_code': 'database.sqlite_cleanup_failed'})

    def test_repaired_refusal_resumes_failed_action_and_its_result(self):
        self.directory.chmod(0o755)
        run = self.run_cleanup(False)
        self.directory.chmod(0o700)
        resumed = reply(self.cli('workflow', 'resume', 'operation-database-cleanup', '--run', run['id']).stdout)
        self.assertEqual(resumed['status'], 'succeeded')
        self.assertFalse(self.directory.exists())
        history = reply(self.cli('workflow', 'history', 'get', run['id']).stdout)
        self.assertEqual(json.loads(history['jobs'][0]['steps'][2]['outputs']['stdout']), {'backend': 'absent'})

    def test_result_only_resume_does_not_repeat_completed_cleanup(self):
        fail = self.repo / 'delivery-failure'
        fail.touch()
        self.workflow['jobs'][0]['steps'].append({
            'name': 'fixture-delivery',
            'dependsOn': [{'step': 'accept-result', 'condition': {'type': 'succeeded'}}],
            'task': {'type': 'behavior', 'behaviorType': 'command/shell',
                     'behaviorName': 'fixture-delivery-${{ run.id }}', 'methodName': 'execute',
                     'inputs': {'run': 'test ! -f "' + str(fail) + '"'}}})
        self.save()
        run = self.run_cleanup(False)
        self.assertFalse(self.directory.exists())
        self.directory.mkdir(mode=0o700)
        self.snapshot.write_text('new scratch must survive completed action resume')
        fail.unlink()
        resumed = reply(self.cli('workflow', 'resume', 'operation-database-cleanup', '--run', run['id']).stdout)
        self.assertEqual(resumed['status'], 'succeeded')
        self.assertTrue(self.snapshot.exists())

    def test_missing_scratch_is_a_successful_convergent_cleanup(self):
        shutil.rmtree(self.directory)
        self.assertEqual(self.run_cleanup()['status'], 'succeeded')


if __name__ == '__main__':
    unittest.main()
