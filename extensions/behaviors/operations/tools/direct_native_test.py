"""Pinned native direct Tool DAG and checkpoint recovery; no fleet changes."""
import json
from pathlib import Path
import unittest
import sys

ROOT = Path(__file__).resolve().parents[4]
sys.path.insert(0, str(ROOT / 'tests'))
from native_harness import NativeTestCase

class DirectToolNative(NativeTestCase):
    native_timeout = 50

    def setUp(self):
        super().setUp()
        behavior = self.repo / 'extensions/behaviors'
        self.copy_behaviors('automatic-deploy', 'operation-runner', 'operations/tools', 'operations/_lib')
        direct = behavior / 'operations/tools/direct.ts'
        direct.write_text('import {fixtureHttp, fixtureTool} from "./direct_native_fixture.ts";\n' + direct.read_text().replace('...directTool()', '...directTool(() => fixtureHttp, fixtureTool)'))
        for filename in ('workflow-operation-tool-direct.yaml', 'workflow-operation-tool-install-direct.yaml'):
            source = ROOT / 'workflows' / filename
            # Fixture proof only; live exact worker placement is a separate canary gate.
            text = '\n'.join(line for line in source.read_text().splitlines() if 'labels:' not in line and not line.strip().startswith('target:')) + '\n'
            (self.repo / 'workflows' / source.name).write_text(text)
        self.state = {'operation':{'id':17,'node_id':209,'kind':'tool.install','input':{'node_id':209,'manager':'apt','package':'jq','version_constraint':None,'user':'orbit','agent_sha256':'a'*64,'platform':'linux','architecture':'x86_64','tool_id':None},'status':'queued','result':None,'cancel_requested':False},'calls':[],'effects':0,'commands':[],'installed':None}
        self.save()

    def save(self):
        (self.repo/'native-tools-direct.json').write_text(json.dumps(self.state))

    def read(self):
        return json.loads((self.repo/'native-tools-direct.json').read_text())

    def run_workflow(self, success=True):
        workflow = 'operation-tool-install-direct' if self.state['operation']['kind'] == 'tool.install' else 'operation-tool-direct'
        result = self.cli('workflow','run',workflow,'--input','operation_id=17','--input','node_id='+str(self.state['operation']['node_id']),'--input','kind='+self.state['operation']['kind'],success=success)
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
        self.cli('workflow','resume','operation-tool-install-direct' if self.state['operation']['kind'] == 'tool.install' else 'operation-tool-direct','--run',self.run_id,'--from','apply')
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
            self.cli('workflow','resume','operation-tool-install-direct' if self.state['operation']['kind'] == 'tool.install' else 'operation-tool-direct','--run',self.run_id,'--from','apply')
            self.assertEqual(self.read()['effects'],1)
            self.assertEqual(self.read()['operation']['status'],'succeeded')

    def test_foreign_binding_refuses_until_gateway_corrects_root(self):
        self.state['unbound'] = True
        self.save()
        self.run_workflow(success=False)
        self.state = self.read()
        self.assertEqual(self.state['effects'], 0)
        self.assertEqual(self.state['commands'], [])
        self.state['unbound'] = False
        self.save()
        self.cli('workflow','resume','operation-tool-install-direct' if self.state['operation']['kind'] == 'tool.install' else 'operation-tool-direct','--run',self.run_id,'--from','apply')
        self.assertEqual(self.read()['effects'], 1)
        self.assertEqual(self.read()['operation']['status'], 'succeeded')

    def test_migration_pool_refuses_a_foreign_node_before_dispatch(self):
        self.cli('workflow','run','operation-tool-direct','--input','operation_id=17','--input','node_id=210','--input','kind=tool.update',success=False)
        state = self.read()
        self.assertEqual(state['effects'], 0)
        self.assertEqual(state['calls'], [])


    def test_install_uses_an_admitted_non209_node(self):
        self.state['operation']['node_id'] = 14
        self.state['operation']['input']['node_id'] = 14
        self.save()
        self.run_workflow()
        self.assertEqual(self.read()['operation']['status'], 'succeeded')
        self.assertEqual(self.read()['effects'], 1)

    def test_old_workflow_refuses_install_before_gateway_or_node_work(self):
        self.cli('workflow','run','operation-tool-direct','--input','operation_id=17','--input','node_id=209','--input','kind=tool.install',success=False)
        self.assertEqual(self.read()['calls'], [])
        self.assertEqual(self.read()['effects'], 0)

if __name__ == '__main__':
    unittest.main()

