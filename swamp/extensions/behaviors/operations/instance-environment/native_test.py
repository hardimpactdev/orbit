"""The real native environment root, replacing only Gateway I/O and host effects."""
import json
from pathlib import Path
import sys
import unittest
import yaml

ROOT = Path(__file__).resolve().parents[4]
sys.path.insert(0, str(ROOT / 'tests'))
from native_harness import NativeTestCase


class EnvironmentNative(NativeTestCase):
    def setUp(self):
        super().setUp()
        self.copy_behaviors('automatic-deploy', 'operations')
        root = self.repo / 'extensions/behaviors/operations/instance-environment/mod.ts'
        root.write_text('import {fixtureHttp, fixtureSync, fixtureRefresh} from "./fixtures/native.ts";\n' + root.read_text().replace(
            'export const behavior = environmentRoot();',
            'export const behavior = environmentRoot(() => fixtureHttp, fixtureSync, fixtureRefresh);'))
        source = ROOT / 'workflows/workflow-operation-instance-environment.yaml'
        workflow = yaml.safe_load(source.read_text())
        for job in workflow['jobs']:
            self.assertEqual(job.pop('labels'), {'node': '${{ string(inputs.node_id) }}'})
            self.assertEqual(job.pop('labels'), {'node': '${{ string(inputs.node_id) }}'})
        (self.repo / 'workflows' / source.name).write_text(yaml.safe_dump(workflow, sort_keys=False))
        secret = '-'.join(('private', 'environment', 'fixture', 'value'))
        self.native_env['INSTANCE_ENV_SECRET'] = secret
        self.secrets = (secret,)
        self.save({'status': 'queued', 'result': None, 'events': []})

    def save(self, state):
        (self.repo / 'environment-fixture.json').write_text(json.dumps(state))

    def read(self):
        return json.loads((self.repo / 'environment-fixture.json').read_text())

    def run_environment(self, success=True):
        return self.cli('workflow', 'run', 'operation-instance-environment', '--input', 'operation_id=7',
                        '--input', 'node_id=9', '--input', 'kind=instance.sync_environment', success=success)

    def test_production_refresh_runs_in_one_root_after_environment_write(self):
        self.cli('workflow', 'validate', 'operation-instance-environment')
        self.run_environment()
        state = self.read()
        self.assertEqual(state['status'], 'succeeded')
        self.assertEqual(state['result']['restarted_process_ids'], [4])
        self.assertEqual(state['result']['runtime_refresh'], 'config_cache_and_opcache_refreshed')
        self.assertEqual(state['events'], ['environment', 'config', 'php', 'process.control', 'process.status'])

    def test_failed_config_build_terminalizes_without_opcache_or_process_work(self):
        self.save({'status': 'queued', 'result': None, 'events': [], 'fail': 'config'})
        self.run_environment(success=False)
        state = self.read()
        self.assertEqual(state['status'], 'failed')
        self.assertEqual(state['error_code'], 'env.config_cache_refresh_failed')
        self.assertEqual(state['result']['failed_step'], 'config_cache')
        self.assertEqual(state['events'], ['environment', 'config'])


if __name__ == '__main__':
    unittest.main()
