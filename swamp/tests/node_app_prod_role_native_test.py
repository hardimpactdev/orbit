"""AppProd phase ordering on official native SSH with an audited binary fixture."""
import json
import os
import subprocess

import yaml

from native_harness import ROOT, NativeTestCase
from node_database_role_native_test import DatabaseRoleNative


class AppProdRoleNative(NativeTestCase):
    native_timeout = 120
    run_role = DatabaseRoleNative.run_role
    ingress = DatabaseRoleNative.ingress

    def setUp(self):
        DatabaseRoleNative.setUp(self)
        self.ingress()
        intent = self.input['intent']
        intent.update(role='app-prod', php={'versions': ['8.5']}, javascript={'fixture': True})
        intent['footprint']['payloads']['php-runtime'] = {'configurations': [{'version': '8.5'}]}
        intent['footprint']['artifacts'].append({'name': 'php-runtime', 'required': True})

    def test_prerequisites_php_javascript_caddy_and_runtime_proof(self):
        _, result = self.run_role()
        self.assertTrue(result['role_proven'])
        self.assertTrue(result['publication_proven'])
        self.assertTrue(result['footprint']['footprint_proven'])
        calls = [json.loads(row)['action'] for row in self.audit.read_text().splitlines()]
        for before, after in [('app_prerequisites', 'php_build'), ('php_build', 'javascript'), ('javascript', 'caddy_ordering'), ('caddy_ordering', 'caddy_write')]:
            self.assertLess(calls.index(before), calls.index(after))
        self.assertNotIn('packages', calls)
        self.assertIn('php_runtime', calls)

    def test_prepared_tools_suppress_package_and_javascript_materializers(self):
        source = ROOT / 'extensions/behaviors/operations/node-role-converge-command'
        program = 'import {roleWorkflow} from ' + json.dumps((source / 'workflows.ts').as_uri()) + ';'
        program += 'import {roleConvergeSteps} from ' + json.dumps((source / 'root_steps.ts').as_uri()) + ';'
        program += '''const f=roleWorkflow();
const globals=f.jobs[0].steps.find(s=>s.task.behaviorType==="@swamp/ssh").task.globalArgs;
f.jobs[0].steps=roleConvergeSteps("","inputs.intent",globals,undefined,undefined,{preparedToolsExpr:'({"ready":true,"node_id":5,"packages":["docker.io"]})'});
console.log(JSON.stringify(f));'''
        result = subprocess.run([os.environ['DENO_BIN'], 'eval', '--no-check', program], check=True, capture_output=True, text=True)
        flow = json.loads(result.stdout)
        flow.pop('target')
        for job in flow['jobs']:
            for step in job['steps']:
                self.assertEqual(step.pop('target'), 'orbit-node-209')
                if step['task'].get('behaviorType') == '@swamp/ssh':
                    step['task']['globalArgs'].update(sshBinary=str(self.repo / 'fixture-ssh'), scpBinary=str(self.repo / 'fixture-scp'))
        (self.repo / 'workflows/workflow-node-role-converge.yaml').write_text(yaml.safe_dump(flow, sort_keys=False))
        _, result = self.run_role()
        self.assertTrue(result['role_proven'])
        calls = [json.loads(row)['action'] for row in self.audit.read_text().splitlines()]
        self.assertNotIn('packages', calls)
        self.assertNotIn('javascript', calls)
        self.assertIn('app_prerequisites', calls)
        self.assertIn('php_build', calls)
        self.assertIn('caddy_ordering', calls)

    def test_php_failure_stops_javascript_and_publication(self):
        self.failure.write_text('php_build')
        _, result = self.run_role(False)
        self.assertFalse(result['role_proven'])
        calls = [json.loads(row)['action'] for row in self.audit.read_text().splitlines()]
        for action in ['javascript', 'caddy_ordering', 'caddy_write', 'firewall', 'php_runtime']:
            self.assertNotIn(action, calls)

    def test_php_runtime_refusal_is_a_failed_footprint_follow_up(self):
        self.failure.write_text('php_runtime_refusal')
        _, result = self.run_role()
        self.assertTrue(result['role_proven'])
        self.assertFalse(result['footprint']['footprint_proven'])
        self.assertEqual(result['footprint']['failed_artifact'], 'php-runtime')
        self.assertIn('role-footprint:node.footprint_failed', result['follow_up'])
