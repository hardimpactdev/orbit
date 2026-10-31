"""Native shared writer with the real local storage primitive on disposable paths."""
import hashlib
import json
import os
from pathlib import Path
import pwd
import sys
import unittest

ROOT = Path(__file__).resolve().parents[4]
sys.path.insert(0, str(ROOT / 'tests'))
from native_harness import NativeTestCase

class SettingsNative(NativeTestCase):
    native_timeout = 45

    def setUp(self):
        super().setUp()
        self.copy_behaviors('automatic-deploy', 'operation-runner', 'operations/node', 'operations/_lib')
        self.native_env = {'SWAMP_NO_INBOX_NOTICE': '1'}
        target = self.repo / 'extensions/behaviors'
        module = target / 'operations/node/settings.ts'
        module.write_text(module.read_text().replace('export const behavior = { ...settings(), type: "@nckrtl/node-settings" };', '''export const behavior = settings(async (args, signal) => {
          const state = JSON.parse(await Deno.readTextFile("projection.json"));
          state.actions++;
          await Deno.writeTextFile("projection.json", JSON.stringify(state));
          return prepareStorage(args, signal);
        }, () => async (method, path, body) => {
          const state = JSON.parse(await Deno.readTextFile("projection.json"));
          if (!state.bound || (state.root && !(method === "GET" ? path.endsWith(state.root) : body.run_id === state.root))) return {status:409, body:{}};
          if (method === "GET") return {status:200, body:{data:state.operation}};
          state.calls.push({path, body});
          if (body.status === "succeeded" && state.lose) { state.lose = false; await Deno.writeTextFile("projection.json", JSON.stringify(state)); throw new Error("lost response"); }
          state.operation.status = body.status;
          state.operation.result = body.result ?? null;
          await Deno.writeTextFile("projection.json", JSON.stringify(state));
          return {status:200, body:{data:state.operation}};
        });'''))
        source = ROOT / 'workflows/workflow-operation-node-settings.yaml'
        # Local effects run only in scratch; shipped source retains Node placement.
        (self.repo / 'workflows' / source.name).write_text('\n'.join(line for line in source.read_text().splitlines() if not line.strip().startswith(('target:', 'labels:', 'node:'))) + '\n')
        self.apps = self.repo / 'apps'
        self.state = {'calls': [], 'lose': False, 'bound': True, 'actions': 0, 'operation': {
            'id':7, 'node_id':209, 'kind':'node.settings', 'status':'queued', 'result':None, 'cancel_requested':False,
            'input': {'agent_pin': hashlib.sha256(Path('/usr/local/bin/orbit-agent').read_bytes()).hexdigest(),
            'storage': {'root': str(self.apps), 'user': pwd.getpwuid(os.getuid()).pw_name,
                'protected_roots': ['/etc'], 'managed_checkouts': [], 'create_missing': True}}}}
        self.save()

    def save(self):
        (self.repo/'projection.json').write_text(json.dumps(self.state))

    def read(self):
        return json.loads((self.repo/'projection.json').read_text())

    def run_workflow(self, success=True):
        self.cli('workflow', 'validate', 'operation-node-settings')
        result = self.cli('workflow', 'run', 'operation-node-settings', '--input', json.dumps({'operation_id': 7, 'node_id': self.state['operation']['node_id'], 'kind': 'node.settings'}), success=success)
        return json.JSONDecoder().raw_decode(result.stdout.lstrip())[0]['id']

    def test_creates_and_projects_real_apps_root(self):
        self.run_workflow()
        self.assertTrue(self.apps.is_dir())
        self.assertEqual(self.read()['calls'][-1]['body']['result'], {'prepared': True})
        self.assertEqual(self.read()['operation']['status'], 'succeeded')

    def test_shipped_workflow_evaluates_exact_node_placement_including_209(self):
        source = ROOT / 'workflows/workflow-operation-node-settings.yaml'
        (self.repo / 'workflows' / source.name).write_text(source.read_text())
        for node_id in [209, 9]:
            with self.subTest(node_id=node_id):
                result = self.cli('workflow', 'evaluate', 'operation-node-settings', '--input',
                                  json.dumps({'operation_id': 7, 'node_id': node_id, 'kind': 'node.settings'}))
                evaluated = json.JSONDecoder().raw_decode(result.stdout.lstrip())[0]
                step = evaluated['jobs'][0]['steps'][0]
                self.assertEqual(step['target'], 'orbit-node-' + str(node_id))
                self.assertEqual(step['labels'], {'node': str(node_id)})
        self.assertEqual(self.read()['actions'], 0)
        self.assertFalse(self.apps.exists())

    def test_real_local_storage_action_accepts_beast_admission_in_scratch_only(self):
        self.state['operation']['node_id'] = 9
        self.save()
        self.run_workflow()
        self.assertTrue(self.apps.is_dir())
        self.assertEqual(self.read()['operation']['status'], 'succeeded')

    def test_native_recovery_repeats_the_convergent_check_after_lost_projection(self):
        self.state['lose'] = True
        self.save()
        run = self.run_workflow(success=False)
        original = self.apps.stat().st_ino
        self.cli('workflow', 'resume', 'operation-node-settings', '--run', run)
        self.assertEqual(self.apps.stat().st_ino, original)
        self.assertEqual(self.read()['actions'], 2)
        self.assertEqual(self.read()['operation']['status'], 'succeeded')

    def test_refusal_preserves_filesystem_and_projects_failure(self):
        self.state['operation']['input']['storage']['create_missing'] = False
        self.save()
        self.run_workflow(success=False)
        self.assertFalse(self.apps.exists())
        self.assertEqual(self.read()['calls'][-1]['body']['error_code'], 'node.settings_root_failed')

    def test_foreign_binding_first_read_has_no_effect_then_gateway_binding_allows_recovery(self):
        self.state['bound'] = False
        self.save()
        run = self.run_workflow(success=False)
        self.assertEqual(self.read()['actions'], 0)
        self.assertFalse(self.apps.exists())
        self.state = self.read()
        # Simulate the Gateway recording its own start response, never a worker binding.
        self.state.update(bound=True, root=run)
        self.save()
        self.cli('workflow', 'resume', 'operation-node-settings', '--run', run)
        self.assertEqual(self.read()['actions'], 1)
        self.assertEqual(self.read()['operation']['status'], 'succeeded')

    def test_wrong_admitted_digest_never_creates_root(self):
        self.state['operation']['input']['agent_pin'] = '0' * 64
        self.save()
        self.run_workflow(success=False)
        self.assertFalse(self.apps.exists())
        self.assertEqual(self.read()['operation']['status'], 'running')
        self.assertFalse(any(c['body']['status'] == 'succeeded' for c in self.read()['calls']))

    def test_symlinked_component_is_refused_without_touching_destination(self):
        real = self.repo / 'real'
        real.mkdir()
        link = self.repo / 'link'
        link.symlink_to(real, target_is_directory=True)
        self.state['operation']['input']['storage']['root'] = str(link / 'apps')
        self.save()
        self.run_workflow(success=False)
        self.assertFalse((real / 'apps').exists())
        self.assertEqual(self.read()['calls'][-1]['body']['error_code'], 'node.settings_root_failed')

if __name__ == '__main__':
    unittest.main()
