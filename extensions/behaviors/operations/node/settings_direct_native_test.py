"""Native shared writer with the real local storage primitive on disposable paths."""
import hashlib
import json
import os
from pathlib import Path
import pwd
import grp
import shutil
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[4]
SWAMP = os.environ.get('SWAMP_BIN', '/tmp/swamp-current-bin/swamp')

class SettingsNative(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='orbit-settings-direct-')
        self.addCleanup(self.temp.cleanup)
        self.repo = Path(self.temp.name)
        shutil.copy(ROOT / '.swamp.yaml', self.repo)
        target = self.repo / 'extensions/behaviors'
        for family in ['automatic-deploy', 'operation-runner', 'operations/node', 'operations/_lib']:
            shutil.copytree(ROOT / 'extensions/behaviors' / family, target / family)
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
        (self.repo / 'workflows').mkdir()
        source = ROOT / 'workflows/workflow-operation-node-settings.yaml'
        # Local harness only. Live source retains exact worker placement and unit.
        (self.repo / 'workflows' / source.name).write_text('\n'.join(line for line in source.read_text().splitlines() if not line.startswith('target:')) + '\n')
        self.apps = self.repo / 'apps'
        self.state = {'calls': [], 'lose': False, 'bound': True, 'actions': 0, 'operation': {
            'id':7, 'node_id':9, 'kind':'node.settings', 'status':'queued', 'result':None, 'cancel_requested':False,
            'input': {'agent_pin': hashlib.sha256(Path('/usr/local/bin/orbit-agent').read_bytes()).hexdigest(),
            'storage': {'root': str(self.apps), 'user': pwd.getpwuid(os.getuid()).pw_name,
                'group': grp.getgrgid(os.getgid()).gr_name, 'create_missing': True}}}}
        self.save()

    def save(self):
        (self.repo/'projection.json').write_text(json.dumps(self.state))

    def read(self):
        return json.loads((self.repo/'projection.json').read_text())

    def cli(self, *args, success=True):
        env = {k:v for k,v in os.environ.items() if k not in ['SWAMP_SERVE_URL','SWAMP_SERVER_URL','SWAMP_SERVER_TOKEN','SWAMP_SERVER_TOKEN_FILE','SWAMP_REPO_DIR','SWAMP_CA_CERT']}
        env.update(SWAMP_NO_UPDATE_CHECK='1', SWAMP_NO_TELEMETRY='1', SWAMP_NO_INBOX_NOTICE='1')
        result = subprocess.run([SWAMP, *args, '--json'], cwd=self.repo, env=env, capture_output=True, text=True, timeout=45)
        self.assertEqual(result.returncode == 0, success, result.stdout + result.stderr)
        return result

    def run_workflow(self, success=True):
        self.cli('workflow', 'validate', 'operation-node-settings')
        result = self.cli('workflow', 'run', 'operation-node-settings', '--input', '{"operation_id":7,"node_id":9,"kind":"node.settings"}', success=success)
        return json.JSONDecoder().raw_decode(result.stdout.lstrip())[0]['id']

    def test_creates_and_projects_real_apps_root(self):
        self.run_workflow()
        self.assertTrue(self.apps.is_dir())
        self.assertEqual(self.read()['calls'][-1]['body']['result'], {'prepared': True})
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

    def test_unbound_first_read_has_no_effect_then_gateway_binding_allows_recovery(self):
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

if __name__ == '__main__':
    unittest.main()
