"""Actual native DAG before Gateway terminal projection; disposable transports and secret scan.

A terminal Gateway Operation forbids further minting; the Gateway regression covers
explicit fresh admission after that boundary. These tests exercise native resume
while the bound Operation still permits minting, not post-terminal reopening.
"""
import json
import shutil
import sys
import unittest
from datetime import datetime
from pathlib import Path
from zoneinfo import ZoneInfo

ROOT = Path(__file__).resolve().parents[5]
FAMILY = Path(__file__).resolve().parent
sys.path.insert(0, str(ROOT / 'tests'))
from native_harness import NativeTestCase


class RecipePrNative(NativeTestCase):
    def setUp(self):
        super().setUp()
        self.secret = 'fixture-ephemeral-' + self.repo.name
        self.secrets = (self.secret,)
        self.native_env = {'ORBIT_RECIPE_FIXTURE_SENTINEL': self.secret}
        behaviors = self.repo / 'extensions/behaviors'
        for family in ['automatic-deploy', 'operations/_lib', 'operations/fleet/recipe-pr']:
            shutil.copytree(ROOT / 'extensions/behaviors' / family, behaviors / family)
        direct = behaviors / 'operations/fleet/recipe-pr/direct.ts'
        direct.write_text('import {fixtureMint,fixtureApi,fixturePlanner,github} from "./native_fixture.ts";\n' + direct.read_text().replace('export const behavior = recipePr();', 'export const behavior = recipePr(fixtureMint,fixtureApi,fixturePlanner,github);'))
        import yaml
        workflow = yaml.safe_load((ROOT / 'workflows/workflow-operation-fleet-recipe-pr.yaml').read_text())
        for job in workflow['jobs']:
            for step in job['steps']:
                step.pop('target')
        (self.repo / 'workflows/workflow-recipe.yaml').write_text(yaml.safe_dump(workflow, sort_keys=False))
        # Fixture source is public JSON; never inspect a fleet credential.
        content = (FAMILY / 'fixture.ts').read_text()
        self.assertIn('fixture', content)
        self.intent = {'action': 'open', 'variant': 'snapshot', 'date': datetime.now(ZoneInfo('Europe/Amsterdam')).strftime('%Y%m%d'), 'pull_number': 0, 'head_sha': '', 'author': 'orbit-gateway[bot]', 'required_check': 'Required checks', 'deployed_recipe': ''}
        # Match the existing public recipe fixture through its actual module.
        import subprocess
        result = subprocess.run(['/fast/worktrees/swamp-lane8/_scratch/tools/deno', 'eval', 'import {source} from "' + str(FAMILY / 'fixture.ts') + '"; console.log(source())'], capture_output=True, text=True, check=True)
        self.intent['deployed_recipe'] = result.stdout.strip() + '\n'
        self.state = {'intent': self.intent, 'mode': 'ok', 'mints': 0, 'revokes': 0, 'writes': 0, 'prepares': 0, 'branches': {}, 'pull': None, 'calls': [], 'github': []}
        self.save()

    def save(self):
        (self.repo / 'recipe-native.json').write_text(json.dumps(self.state))

    def read(self):
        return json.loads((self.repo / 'recipe-native.json').read_text())

    def run_workflow(self, success=True):
        self.cli('workflow', 'validate', 'operation-fleet-recipe-pr')
        result = self.cli('workflow', 'run', 'operation-fleet-recipe-pr', '--input', json.dumps({'operation_id': 17, 'node_id': 209, 'kind': 'fleet.recipe_pr', 'intent': self.intent}), success=success)
        return json.JSONDecoder().raw_decode(result.stdout.lstrip())[0]['id']

    def test_native_open_has_separate_writes_without_intent_or_projection_callbacks(self):
        root = self.run_workflow()
        history = json.loads(self.cli("workflow", "history", "get", root).stdout)
        result = next(step for job in history["jobs"] for step in job["steps"] if step["name"] == "result")
        self.assertEqual(json.loads(result["outputs"]["stdout"])["url"], "https://github.com/hardimpactdev/orbit/pull/999")
        s = self.read()
        self.assertEqual((s['writes'], s['prepares']), (3, 1))
        self.assertEqual((s['mints'], s['revokes']), (4, 4))
        self.assertTrue(all(c['method'] == 'POST' and c['path'].endswith('/github-token') for c in s['calls']))
        self.assertEqual(s['pull']['number'], 999)
        self.assert_private_absent()

    def test_wrong_scope_stops_before_any_github_effect(self):
        self.state['mode'] = 'scope'
        self.save()
        self.run_workflow(success=False)
        s = self.read()
        self.assertEqual((s['mints'], s['revokes'], s['writes']), (1, 1, 0))
        self.assert_private_absent()

    def test_native_resume_keeps_candidate_and_recognizes_uncertain_writes(self):
        for phase, variant in [('branch', 'snapshot'), ('file', 'snapshot'), ('pull', 'snapshot'), ('pull', 'artifacts')]:
            with self.subTest(phase=phase, variant=variant):
                self.intent['variant'] = variant
                self.state.update(lose=phase, mints=0, revokes=0, writes=0, prepares=0, branches={}, pull=None, calls=[], github=[])
                self.save()
                root = self.run_workflow(success=False)
                self.cli('workflow', 'resume', 'operation-fleet-recipe-pr', '--run', root)
                s = self.read()
                self.assertEqual((s['writes'], s['prepares']), (3, 1))
                self.assertEqual(s['mints'], s['revokes'])
                self.assert_private_absent()


if __name__ == '__main__':
    unittest.main()
