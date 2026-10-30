"""Pinned native direct Tool DAG and checkpoint recovery; no fleet changes."""
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[4]
SWAMP = os.environ.get('SWAMP_BIN', 'swamp')

class DirectToolNative(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='tools-direct-native-')
        self.addCleanup(self.temp.cleanup)
        self.repo = Path(self.temp.name)
        shutil.copy(ROOT / '.swamp.yaml', self.repo)
        behavior = self.repo / 'extensions/behaviors'
        for family in ['automatic-deploy', 'operation-runner', 'operations/tools', 'operations/_lib']:
            shutil.copytree(ROOT / 'extensions/behaviors' / family, behavior / family)
        direct = behavior / 'operations/tools/direct.ts'
        direct.write_text('import {fixtureHttp, fixtureTool} from "./direct_native_fixture.ts";\n' + direct.read_text().replace('...directTool()', '...directTool(() => fixtureHttp, fixtureTool)'))
        (self.repo / 'workflows').mkdir()
        source = ROOT / 'workflows/workflow-operation-tool-direct.yaml'
        # Unit DAG proof only: live worker placement is a separate canary gate.
        text = '\n'.join(line for line in source.read_text().splitlines() if 'labels:' not in line and not line.strip().startswith('target:')) + '\n'
        (self.repo / 'workflows' / source.name).write_text(text)
        self.state = {'operation':{'id':17,'node_id':9,'kind':'tool.install','input':{'node_id':9,'manager':'apt','package':'jq','version_constraint':None,'user':'orbit','agent_sha256':'a'*64,'platform':'linux','architecture':'x86_64','tool_id':None},'status':'queued','result':None,'cancel_requested':False},'calls':[],'effects':0,'commands':[],'installed':None}
        self.save()

    def save(self):
        (self.repo/'native-tools-direct.json').write_text(json.dumps(self.state))

    def read(self):
        return json.loads((self.repo/'native-tools-direct.json').read_text())

    def cli(self, *args, success=True):
        env = dict(os.environ, SWAMP_NO_UPDATE_CHECK='1', SWAMP_NO_TELEMETRY='1')
        for key in ('SWAMP_SERVER_URL','SWAMP_SERVE_URL','SWAMP_SERVER_TOKEN','SWAMP_SERVER_TOKEN_FILE','SWAMP_REPO_DIR','SWAMP_CA_CERT'):
            env.pop(key,None)
        result = subprocess.run([SWAMP,*args,'--json'],cwd=self.repo,env=env,text=True,capture_output=True,timeout=50)
        self.assertEqual(result.returncode == 0, success, result.stdout + result.stderr)
        return result

    def run_workflow(self, success=True):
        result = self.cli('workflow','run','operation-tool-direct','--input','operation_id=17','--input','node_id=9','--input','kind='+self.state['operation']['kind'],success=success)
        self.run_id = json.JSONDecoder().raw_decode(result.stdout.lstrip())[0]['id']

    def test_each_kind_uses_the_same_direct_dag_without_executor_calls(self):
        for kind in ('tool.install','tool.update','tool.remove','tool.adopt','tool.manager_converge'):
            self.state['operation']['kind'] = kind
            self.state['operation']['status'] = 'queued'
            self.state['installed'] = None if kind in ('tool.install','tool.manager_converge') else '1.0.0'
            self.state['operation']['input']['tool_id'] = None if kind == 'tool.install' else 1
            self.save()
            self.run_workflow()
            state = self.read()
            self.assertEqual(state['operation']['status'],'succeeded')
            self.assertEqual(state['effects'],0 if kind in ('tool.adopt','tool.manager_converge') else 1)
            self.assertTrue(all('/direct-operations/' in p for p in state['calls']))
            self.assertFalse(any('operation-executor' in p for p in state['calls']))

    def test_lost_projection_resumes_without_repeating_the_node_action(self):
        self.state['loseProjection'] = True
        self.save()
        self.run_workflow(success=False)
        self.cli('workflow','resume','operation-tool-direct','--run',self.run_id,'--from','apply')
        self.assertEqual(self.read()['effects'],1)
        self.assertEqual(self.read()['operation']['status'],'succeeded')

    def test_uncertain_node_action_is_reconciled_by_native_resume(self):
        for kind in ('tool.install','tool.update','tool.remove'):
            self.state['operation']['kind'] = kind
            self.state['operation']['status'] = 'queued'
            self.state['operation']['input']['tool_id'] = None if kind == 'tool.install' else 1
            self.state['installed'] = None if kind == 'tool.install' else '1.0.0'
            self.state['loseNode'] = True
            self.save()
            self.run_workflow(success=False)
            self.cli('workflow','resume','operation-tool-direct','--run',self.run_id,'--from','apply')
            self.assertEqual(self.read()['effects'],1)
            self.assertEqual(self.read()['operation']['status'],'succeeded')

    def test_first_read_refuses_until_gateway_binds_root_start(self):
        self.state['unbound'] = True
        self.save()
        self.run_workflow(success=False)
        self.state = self.read()
        self.assertEqual(self.state['effects'], 0)
        self.assertEqual(self.state['commands'], [])
        self.state['unbound'] = False
        self.save()
        self.cli('workflow','resume','operation-tool-direct','--run',self.run_id,'--from','apply')
        self.assertEqual(self.read()['effects'], 1)
        self.assertEqual(self.read()['operation']['status'], 'succeeded')

if __name__ == '__main__':
    unittest.main()

