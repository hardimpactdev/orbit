"""Run the credential monitor in an isolated official Swamp repository with fixture native lists."""
import json
import shlex
import unittest

import yaml
from native_harness import ROOT, NativeTestCase

FIXTURES = {
    'access': [{'name': 'orbit-node-209-access', 'state': 'active', 'expiresAt': '2999-01-01T00:00:00Z'}],
    'enrollment': [],
    'club': [{'name': 'orbit-monorepo-ci', 'enabled': True, 'expiresAt': None}],
}


class CredentialMonitorNative(NativeTestCase):
    native_timeout = 120

    def test_report_step_classifies_the_read_steps_output(self):
        self.copy_behaviors('operations/node-worker-assets')
        workflow = yaml.safe_load((ROOT / 'workflows/workflow-swamp-credential-monitor.yaml').read_text())
        workflow.pop('trigger')
        for job in workflow['jobs']:
            for step in job['steps']:
                self.assertEqual(step.pop('labels'), {'role': 'swamp'})
                inputs = step['task']['inputs']
                if step['name'] in FIXTURES:
                    self.assertRegex(inputs['run'], r'^(SWAMP_API_KEY_FILE=\S+ )?timeout 30 /home/orbit/\.local/bin/swamp (access|worker|auth) token list ')
                    inputs['run'] = 'printf %s ' + shlex.quote(json.dumps(FIXTURES[step['name']]))
                elif step['name'] == 'certificate':
                    self.assertTrue(inputs['run'].startswith('timeout 30 openssl x509 -enddate -noout -in '))
                    inputs['run'] = "printf 'notAfter=Nov  7 20:56:22 2999 GMT\\n'"
        (self.repo / 'workflows/workflow-fixture.yaml').write_text(yaml.safe_dump(workflow, sort_keys=False))
        self.cli('workflow', 'validate', workflow['name'])
        run = json.loads(self.cli('workflow', 'run', workflow['name']).stdout)
        self.assertEqual(run['status'], 'succeeded')
        history = json.loads(self.cli('workflow', 'history', 'get', run['id']).stdout)
        check = next(step for step in history['jobs'][0]['steps'] if step['name'] == 'check')
        self.assertEqual([(alert['name'], alert['reason']) for alert in check['outputs']['alerts']],
                         [('orbit-node-209', 'missing')])


if __name__ == '__main__':
    unittest.main()
