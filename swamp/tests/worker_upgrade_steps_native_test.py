"""Official native DAG execution with harmless filesystem/command fixtures only."""
import json
import os
import shutil
import subprocess
import unittest
from pathlib import Path

import worker_upgrade_steps_test as fixture
import yaml


class NativeWorkerUpgradeStepsTest(unittest.TestCase):
    def setUp(self):
        self.host = fixture.WorkerUpgradeStepsTest(methodName='runTest')
        self.host.setUp()
        self.addCleanup(self.host.doCleanups)
        self.repo = self.host.root / 'native'
        (self.repo / 'workflows').mkdir(parents=True)
        shutil.copy(fixture.ROOT / '.swamp.yaml', self.repo)
        self.workflow = self.host.workflow
        # Only this isolated fixture uses loopback execution. Production placement
        # remains exact orbit-node-N; these runs claim no fleet/SSH proxy proof.
        for job in self.workflow['jobs']:
            job.pop('target', None)
            job.pop('labels', None)
            for step in job['steps']:
                step.pop('target', None)
                step.pop('labels', None)
                inputs = step['task']['inputs']
                inputs['run'] = self.host.fixture_script(step['name'])
                inputs['env'].update(SUDO_UID=str(os.getuid()), FIXTURE_ROOT=str(self.host.root))
        (self.repo / 'workflows/workflow-node-worker-upgrade.yaml').write_text(yaml.safe_dump(self.workflow, sort_keys=False))
        self.binary = os.environ['SWAMP_BIN']

    def cli(self, *args, success=True):
        env = {key: value for key, value in os.environ.items() if not key.startswith('SWAMP_')}
        env.update(SWAMP_NO_TELEMETRY='1', SWAMP_NO_UPDATE_CHECK='1',
                   SWAMP_CONFIG_DIR=os.environ.get('SWAMP_CONFIG_DIR', str(Path.home() / '.config/swamp')),
                   SWAMP_API_KEY_FILE=os.environ.get('SWAMP_API_KEY_FILE', str(Path.home() / '.config/swamp/api-key')))
        p = subprocess.run([self.binary, *args, '--json'], cwd=self.repo, env=env,
                           capture_output=True, text=True, timeout=90, check=False)
        self.assertEqual(p.returncode == 0, success, p.stdout + p.stderr)
        for index, char in enumerate(p.stdout):
            if char in '{[':
                try:
                    return json.loads(p.stdout[index:])
                except ValueError:
                    pass
        self.fail(p.stdout + p.stderr)

    def run_workflow(self, *, success=True):
        return self.cli('workflow', 'run', 'node-worker-upgrade', '--input',
                        json.dumps(self.host.inputs), success=success)

    def test_native_success_and_current_digest_guard(self):
        self.cli('workflow', 'validate', 'node-worker-upgrade')
        result = self.run_workflow()
        self.assertEqual(result['status'], 'succeeded', result)
        history = self.cli('workflow', 'history', 'get', result['id'])
        steps = history['jobs'][0]['steps']
        final = json.loads(next(step for step in steps if step['name'] == 'result')['outputs']['stdout'])
        self.assertTrue(final['changed'])
        for private in ('preserved-enrollment', 'preserved-server'):
            self.assertNotIn(private, json.dumps(history))
        self.assertEqual(self.host.binary.read_bytes(), self.host.new)
        self.host.inputs['expected_current_sha256'] = self.host.sha(self.host.new)
        self.host.path('/restarts').unlink()
        second = self.run_workflow()
        self.assertEqual(second['status'], 'succeeded', second)
        unchanged = self.cli('workflow', 'history', 'get', second['id'])
        final = json.loads(next(step for step in unchanged['jobs'][0]['steps']
                                if step['name'] == 'result')['outputs']['stdout'])
        self.assertFalse(final['changed'])
        self.assertFalse(self.host.path('/restarts').exists())
        evidence = os.environ.get('WORKER_UPGRADE_NATIVE_EVIDENCE')
        if evidence:
            Path(evidence).write_text(json.dumps({'changed': history, 'unchanged': unchanged}, indent=2) + '\n')

    def test_native_inputs_refuse_logical_proxy_ids(self):
        for node in (1, 18):
            self.host.inputs['node_id'] = node
            self.run_workflow(success=False)
        self.assertEqual(self.host.binary.read_bytes(), self.host.old)
        self.assertFalse(self.host.path('/restarts').exists())
        self.assertFalse(list(self.host.path('/usr/local/bin').glob('.orbit-swamp-*')))

    def test_native_resume_recovers_a_lost_publication_acknowledgement(self):
        self.host.file('/lose-ack', b'once')
        publish = next(step for step in self.workflow['jobs'][0]['steps']
                       if step['name'] == 'publish')
        publish['task']['inputs']['run'] += (
            '\nif [ -e "$FIXTURE_ROOT/lose-ack" ]; then\n'
            '  rm -- "$FIXTURE_ROOT/lose-ack"\n'
            '  exit 1\nfi\n')
        (self.repo / 'workflows/workflow-node-worker-upgrade.yaml').write_text(
            yaml.safe_dump(self.workflow, sort_keys=False))
        failed = self.run_workflow(success=False)
        self.assertEqual(failed['status'], 'failed')
        self.assertEqual(self.host.binary.read_bytes(), self.host.new)
        self.assertFalse(self.host.path('/restarts').exists())
        resumed = self.cli('workflow', 'resume', 'node-worker-upgrade', '--run', failed['id'])
        self.assertEqual(resumed['status'], 'succeeded', resumed)
        self.assertTrue(self.host.path('/restarts').exists())
        self.assertEqual(self.host.binary.read_bytes(), self.host.new)

    def test_native_failure_never_publishes_or_restarts(self):
        self.host.path('/download').write_bytes(b'bad artifact')
        result = self.run_workflow(success=False)
        self.assertEqual(result['status'], 'failed', result)
        history = self.cli('workflow', 'history', 'get', result['id'])
        steps = {step['name']: step for step in history['jobs'][0]['steps']}
        self.assertEqual(steps['verify-artifact']['status'], 'failed')
        self.assertEqual(steps['publish']['status'], 'skipped')
        self.assertEqual(self.host.binary.read_bytes(), self.host.old)
        self.assertFalse(self.host.path('/restarts').exists())


if __name__ == '__main__':
    unittest.main()
