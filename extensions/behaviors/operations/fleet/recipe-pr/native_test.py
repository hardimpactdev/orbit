"""Real native workflow; disposable Gateway/GitHub transport, no live credential or effect."""
import json
import os
from pathlib import Path
import secrets
import re
import shutil
import subprocess
import sys
import unittest
from datetime import datetime, timedelta, timezone
from zoneinfo import ZoneInfo

ROOT = Path(__file__).resolve().parents[5]
FAMILY = Path(__file__).resolve().parent
sys.path.insert(0, str(ROOT / 'tests'))
from native_harness import NativeTestCase


class RecipePrNative(NativeTestCase):
    def setUp(self):
        super().setUp()
        behaviors = self.repo / 'extensions/behaviors'
        for family in ['automatic-deploy', 'operation-runner', 'operations/_lib']:
            shutil.copytree(ROOT / 'extensions/behaviors' / family, behaviors / family)
        destination = behaviors / 'operations/fleet/recipe-pr'
        shutil.copytree(FAMILY, destination)
        shutil.copy(ROOT / 'extensions/behaviors/operations/fleet/maintenance.ts', destination.parent / 'maintenance.ts')
        direct = destination / 'direct.ts'
        original = 'export const behavior = recipePr();'
        self.assertIn(original, direct.read_text())
        direct.write_text('import {fixtureHttp, fixtureGateway, fixtureAction} from "./native_fixture.ts";\n' +
                          direct.read_text().replace(original,
                            'export const behavior = recipePr(() => fixtureHttp, fixtureGateway, fixtureAction);'))
        workflow = ROOT / 'workflows/workflow-operation-fleet-recipe-pr.yaml'
        # Scratch process only; the shipped workflow retains its exact admitted-Node placement.
        (self.repo / 'workflows' / workflow.name).write_text('\n'.join(
            line for line in workflow.read_text().splitlines()
            if not line.startswith(('target:', 'labels:'))) + '\n')
        self.sentinel = 'ghs_' + secrets.token_hex(24)
        self.native_env = {'SWAMP_NO_INBOX_NOTICE': '1', 'ORBIT_RECIPE_FIXTURE_SENTINEL': self.sentinel}
        self.secrets = (self.sentinel,)
        self.state = {'mode': 'success', 'bound': True, 'calls': [], 'github': [],
                      'mints': 0, 'revokes': 0, 'writes': 0, 'loseProjection': False,
                      'operation': {'id': 17, 'node_id': 14, 'kind': 'fleet.recipe_pr',
                                    'status': 'queued', 'result': None, 'cancel_requested': False,
                                    'input': {'action': 'open', 'variant': 'snapshot',
                                              'date': datetime.now(ZoneInfo('Europe/Amsterdam')).strftime('%Y%m%d'),
                                              'pull_number': 0, 'head_sha': '',
                                              'author': 'orbit-gateway[bot]', 'required_check': 'Required checks',
                                              'deployed_recipe': self.recipe()}}}
        self.save()

    def recipe(self):
        result = subprocess.run([os.environ.get('DENO_BIN', 'deno'), 'eval',
                                 'import {source} from "./extensions/behaviors/operations/fleet/recipe-pr/fixture.ts"; console.log(source());'],
                                cwd=ROOT, capture_output=True, text=True, timeout=30)
        self.assertEqual(result.returncode, 0, result.stderr)
        return result.stdout.strip() + '\n'

    def save(self):
        (self.repo / 'recipe-native.json').write_text(json.dumps(self.state))

    def read(self):
        return json.loads((self.repo / 'recipe-native.json').read_text())

    def run_workflow(self, success=True):
        self.cli('workflow', 'validate', 'operation-fleet-recipe-pr')
        result = self.cli('workflow', 'run', 'operation-fleet-recipe-pr', '--input',
                          '{"operation_id":17,"node_id":14,"kind":"fleet.recipe_pr"}', success=success)
        return json.JSONDecoder().raw_decode(result.stdout.lstrip())[0]['id']

    def scan(self):
        self.assert_private_absent()

    def test_full_native_open_mints_once_writes_then_revokes_and_projects(self):
        self.run_workflow()
        state = self.read()
        self.assertEqual((state['mints'], state['writes'], state['revokes']), (1, 3, 1))
        self.assertEqual(state['operation']['status'], 'succeeded')
        self.assertEqual(state['operation']['result']['url'], 'https://github.com/hardimpactdev/orbit/pull/999')
        self.scan()

    def test_lost_committed_projection_resumes_without_mint_or_effect(self):
        self.state['loseProjection'] = True
        self.save()
        run = self.run_workflow(success=False)
        self.assertEqual(self.read()['operation']['status'], 'succeeded')
        self.cli('workflow', 'resume', 'operation-fleet-recipe-pr', '--run', run)
        state = self.read()
        self.assertEqual((state['mints'], state['writes'], state['revokes']), (1, 3, 1))
        self.scan()

    def test_first_read_refuses_before_binding_then_same_root_resumes(self):
        self.state['bound'] = False
        self.save()
        run = self.run_workflow(success=False)
        self.assertEqual(self.read()['mints'], 0)
        self.assertEqual(self.read()['writes'], 0)
        self.state = self.read()
        self.state.update(bound=True, root=run)
        self.save()
        self.cli('workflow', 'resume', 'operation-fleet-recipe-pr', '--run', run)
        self.assertEqual(self.read()['operation']['status'], 'succeeded')
        self.scan()

    def test_deployment_gate_terminalizes_and_revokes_without_writing(self):
        self.state['mode'] = 'pending-deployment'
        self.save()
        self.run_workflow(success=False)
        state = self.read()
        self.assertEqual(state['operation']['result'], {'error_code': 'fleet.recipe_pending_deployment'})
        self.assertEqual(state['operation']['status'], 'failed')
        self.assertEqual((state['mints'], state['writes'], state['revokes']), (1, 0, 1))
        self.scan()

    def test_wrong_scope_refuses_without_github_effects(self):
        self.state['mode'] = 'wrong-grant'
        self.save()
        self.run_workflow(success=False)
        state = self.read()
        self.assertEqual(state['operation']['result'], {'error_code': 'fleet.recipe_pr_policy_refused'})
        self.assertEqual((state['mints'], state['writes'], state['revokes']), (1, 0, 1))
        self.scan()

    def test_uncertain_write_keeps_native_failure_redacted_without_false_terminal(self):
        self.state['mode'] = 'uncertain-write'
        self.save()
        self.run_workflow(success=False)
        state = self.read()
        self.assertEqual(state['operation']['status'], 'running')
        self.assertEqual((state['mints'], state['writes'], state['revokes']), (1, 1, 1))
        self.scan()

    def test_retry_retains_candidate_after_lost_create_and_25_hour_date_gc(self):
        self.state['mode'] = 'artifacts-lost-create'
        self.state['operation']['input']['variant'] = 'artifacts'
        self.save()
        root = self.run_workflow(success=False)
        state = self.read()
        self.assertEqual((state['candidateReads'], len(state['pulls']), state['writes']), (1, 1, 3))
        # Amsterdam's autumn DST date can last 25 hours. Age the persisted
        # preparation, then let the real native GC decide whether it survives.
        plans = [path for path in self.repo.rglob('metadata.yaml')
                 if re.search(r'^name: recipe-pr-open-17$', path.read_text(), re.M)]
        self.assertEqual(len(plans), 1)
        created = (datetime.now(timezone.utc) - timedelta(hours=25)).isoformat().replace('+00:00', 'Z')
        text, count = re.subn(r'^createdAt:.*$', 'createdAt: "' + created + '"', plans[0].read_text(), flags=re.M)
        self.assertEqual(count, 1)
        plans[0].write_text(text)
        self.cli('data', 'gc', '--force')
        self.assertTrue(plans[0].exists(), 'Native GC discarded a candidate during an admissible 25-hour date')
        state['root'] = root
        self.state = state
        self.save()
        self.cli('workflow', 'resume', 'operation-fleet-recipe-pr', '--run', root)
        state = self.read()
        self.assertEqual((state['candidateReads'], len(state['pulls']), state['writes']), (1, 1, 3))
        self.assertEqual(state['operation']['status'], 'succeeded')
        self.assertEqual((state['mints'], state['revokes']), (2, 2))
        self.scan()

    def test_maintenance_reuses_gateway_intent_across_fresh_native_processes(self):
        self.state['maintenance'] = {'status': 'ready', 'writer': 'direct', 'date': '20261030',
            'base_revision': 'a' * 40, 'recipe_revision': 'b' * 64, 'serving_recipe_revision': 'c' * 64,
            'pulls': [{'number': 1684, 'variant': 'snapshot', 'head_sha': 'd' * 40,
                       'checks': 'passed', 'checks_revision': 'e' * 64, 'policy_revision': 'f' * 64}]}
        self.save()
        (self.repo / 'extensions/behaviors/producer-proof.ts').write_text('''
import {z} from "npm:zod@4.4.3";
import {maintenance} from "./operations/fleet/maintenance.ts";
import type {Context} from "./operation-runner/mod.ts";
import {fixtureHttp} from "./operations/fleet/recipe-pr/native_fixture.ts";
export const behavior = {type:"@nckrtl/recipe-producer-proof",version:"2026.10.30.1",globalArguments:z.object({}),
 methods:{execute:{description:"Exercise the production producer in a disposable native process",
 arguments:z.object({tick:z.string()}),execute:(args:{tick:string},ctx:unknown)=>maintenance(args,ctx as Context,fixtureHttp,new Date(args.tick))}}};
''')
        (self.repo / 'workflows/workflow-producer-proof.yaml').write_text('''id: b335c570-96aa-4724-afbb-60325a073b9b
name: recipe-producer-proof
vaults: []
inputs:
  properties:
    tick: {type: string}
  required: [tick]
jobs:
  - name: producer
    steps:
      - name: maintain
        task:
          type: behavior
          behaviorType: '@nckrtl/recipe-producer-proof'
          behaviorName: recipe-producer-proof
          methodName: execute
          inputs:
            tick: '${{ inputs.tick }}'
''')
        self.cli('workflow', 'validate', 'recipe-producer-proof')
        for minute in ['05', '20', '35']:
            self.cli('workflow', 'run', 'recipe-producer-proof', '--input', 'tick=2026-10-30T00:' + minute + ':00Z')
        state = self.read()
        self.assertEqual(len(state['intentKeys']), 1)
        self.assertEqual(len([call for call in state['calls'] if call['method'] == 'POST']), 3)
        self.assertFalse(any('/api/v1/operations/' in call['path'] for call in state['calls']))
        self.assertEqual((state['mints'], state['writes']), (0, 0))
        self.scan()


if __name__ == '__main__':
    unittest.main()
