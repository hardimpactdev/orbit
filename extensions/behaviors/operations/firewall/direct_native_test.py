"""Pinned native direct Firewall DAG and checkpoint recovery; no fleet changes."""
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[4]
SWAMP = os.environ.get('SWAMP_BIN', 'swamp')

class DirectFirewallNative(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='firewall-direct-native-')
        self.addCleanup(self.temp.cleanup)
        self.repo = Path(self.temp.name)
        shutil.copy(ROOT / '.swamp.yaml', self.repo)
        behavior = self.repo / 'extensions/behaviors'
        for family in ['automatic-deploy', 'operation-runner', 'operations/_lib', 'operations/firewall']:
            shutil.copytree(ROOT / 'extensions/behaviors' / family, behavior / family)
        direct = behavior / 'operations/firewall/direct.ts'
        direct.write_text('import {fixtureHttp, fixtureRule} from "./direct_native_fixture.ts";\n' + direct.read_text().replace('...directFirewall()', '...directFirewall(() => fixtureHttp, fixtureRule)'))
        (self.repo / 'workflows').mkdir()
        source = ROOT / 'workflows/workflow-operation-firewall-direct.yaml'
        # Unit DAG proof only: live worker placement is a separate canary gate.
        text = '\n'.join(line for line in source.read_text().splitlines() if 'labels:' not in line and not line.strip().startswith('target:')) + '\n'
        (self.repo / 'workflows' / source.name).write_text(text)
        self.state = {'operation':{'id':17,'node_id':209,'kind':'firewall.allow','input':{'node_id':209,'name':'canary','action':'allow','source':'192.0.2.100','protocol':'tcp','port':'45678'},'status':'queued','result':None,'cancel_requested':False},'calls':[],'effects':0,'bound':True}
        self.save()

    def save(self):
        (self.repo/'native-firewall.json').write_text(json.dumps(self.state))

    def read(self):
        return json.loads((self.repo/'native-firewall.json').read_text())

    def cli(self, *args, success=True):
        env = dict(os.environ, SWAMP_NO_UPDATE_CHECK='1', SWAMP_NO_TELEMETRY='1')
        for key in ('SWAMP_SERVER_URL','SWAMP_SERVE_URL','SWAMP_SERVER_TOKEN','SWAMP_SERVER_TOKEN_FILE','SWAMP_REPO_DIR','SWAMP_CA_CERT'):
            env.pop(key,None)
        result = subprocess.run([SWAMP,*args,'--json'],cwd=self.repo,env=env,text=True,capture_output=True,timeout=50)
        self.assertEqual(result.returncode == 0, success, result.stdout + result.stderr)
        return result

    def run_workflow(self, success=True):
        result = self.cli('workflow','run','operation-firewall-direct','--input','operation_id=17','--input','node_id=209','--input','kind='+self.state['operation']['kind'],success=success)
        self.run_id = json.JSONDecoder().raw_decode(result.stdout.lstrip())[0]['id']

    def test_each_kind_uses_the_same_direct_dag_without_executor_calls(self):
        for kind in ('firewall.allow','firewall.deny','firewall.remove'):
            self.state['operation']['kind'] = kind
            self.state['operation']['status'] = 'queued'
            self.state['operation']['input']['action'] = 'deny' if kind.endswith('deny') else 'allow'
            self.save()
            self.run_workflow()
            state = self.read()
            self.assertEqual(state['operation']['status'],'succeeded')
            self.assertEqual(state['effects'],1)
            self.assertTrue(all('/direct-operations/' in p for p in state['calls']))
            self.assertEqual(state['input']['node_id'],209)

    def test_lost_projection_resumes_without_repeating_the_node_action(self):
        self.state['loseProjection'] = True
        self.save()
        self.run_workflow(success=False)
        self.cli('workflow','resume','operation-firewall-direct','--run',self.run_id,'--from','apply')
        self.assertEqual(self.read()['effects'],1)
        self.assertEqual(self.read()['operation']['status'],'succeeded')

    def test_uncertain_node_action_is_reconciled_by_convergent_native_resume(self):
        self.state['loseNode'] = True
        self.save()
        self.run_workflow(success=False)
        self.cli('workflow','resume','operation-firewall-direct','--run',self.run_id,'--from','apply')
        self.assertEqual(self.read()['effects'],2)
        self.assertEqual(self.read()['operation']['status'],'succeeded')

    def test_first_read_waits_for_gateway_binding_without_a_local_retry(self):
        self.state['bound'] = False
        self.save()
        self.run_workflow(success=False)
        self.assertEqual(self.read()['effects'],0)
        self.state = self.read()
        self.state['bound'] = True
        self.save()
        self.cli('workflow','resume','operation-firewall-direct','--run',self.run_id,'--from','apply')
        self.assertEqual(self.read()['effects'],1)

    def test_migration_pool_refuses_a_foreign_node_before_dispatch(self):
        self.cli('workflow','run','operation-firewall-direct','--input','operation_id=17','--input','node_id=210','--input','kind=firewall.allow',success=False)
        state = self.read()
        self.assertEqual(state['effects'], 0)
        self.assertEqual(state['calls'], [])

if __name__ == '__main__':
    unittest.main()
