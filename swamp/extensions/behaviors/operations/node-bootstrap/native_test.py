"""Real native parent DAGs and private callback handling, without fleet effects."""
import json
from pathlib import Path
import shutil
import sys
import unittest
import uuid
import yaml

ROOT = Path(__file__).resolve().parents[4]
sys.path.insert(0, str(ROOT / 'tests'))
from native_harness import NativeTestCase

KINDS = ('node.bootstrap_install', 'node.bootstrap_host', 'node.bootstrap_verify', 'node.macos_inspect', 'node.prepare_php_packages')

class BootstrapNative(NativeTestCase):
    def setUp(self):
        super().setUp()
        secret='/private/bootstrap-'+uuid.uuid4().hex
        self.native_env['ORBIT_BOOTSTRAP_TEST_IDENTITY']=secret
        self.secrets=(secret,)
        self.copy_behaviors('automatic-deploy','operations/_lib','operations/node-bootstrap','operations/routes','operations/php')
        base=self.repo/'extensions/behaviors/operations'
        direct=base/'node-bootstrap/direct.ts'
        direct.write_text('import {fixtureHttp,fixtureApply,engineRefused} from "./native_fixture.ts";\n'+direct.read_text().replace('export const behavior = nodeBootstrapDirect();','''
const fixture=nodeBootstrapDirect(() => fixtureHttp,fixtureApply);
export const behavior={...fixture,methods:{...fixture.methods,apply:{...fixture.methods.apply,
execute:async (...args:Parameters<typeof fixture.methods.apply.execute>) => {
 if (await engineRefused()) throw new Error("Bootstrap bundle refused before callback");
 return await fixture.methods.apply.execute(...args);
}}}};
'''))
        packages=base/'node-bootstrap/packages.ts'
        packages.write_text('import {fixtureHttp,fixturePackages,engineRefused} from "./native_fixture.ts";\n'+packages.read_text().replace('export const behavior = phpPackagesDirect();','''
const fixture=phpPackagesDirect(() => fixtureHttp,fixturePackages);
export const behavior={...fixture,methods:{...fixture.methods,apply:{...fixture.methods.apply,
execute:async (...args:Parameters<typeof fixture.methods.apply.execute>) => {
 if (await engineRefused()) throw new Error("PHP bundle refused before callback");
 return await fixture.methods.apply.execute(...args);
}}}};
'''))
        for kind in KINDS:
            name='workflow-operation-'+kind.replace('.','-').replace('_','-')+'.yaml'
            workflow=yaml.safe_load((ROOT/'workflows'/name).read_text())
            self.assertEqual([j['name'] for j in workflow['jobs']],['packages' if kind == 'node.prepare_php_packages' else 'bootstrap'])
            for job in workflow['jobs']:
                self.assertEqual(job.pop('target'),'${{ "orbit-node-" + string(inputs.node_id) }}')
                self.assertEqual(job.pop('labels'),{'node':'${{ string(inputs.node_id) }}'})
            (self.repo/'workflows'/name).write_text(yaml.safe_dump(workflow,sort_keys=False))
        self.state={'calls':[],'actions':0,'operation':{'id':17,'node_id':14,'kind':'node.bootstrap_verify','status':'queued','result':None,'cancel_requested':False,
            'input':{'bootstrap':{'schema_version':1,'node_id':209,'platform':'linux','target':{'node_id':209,'host':'192.0.2.209','port':2222,'user':'bootstrap','fingerprint':'SHA256:'+'a'*43},
            'managed_user':'candidate','expected_architecture':'x86_64','address':'10.44.0.209','artifacts':{},'command':{'user':'candidate','public_key':'ssh-ed25519 AAAA','releases':['resolute'],'packages':['curl'],'dns_policy':'preserve'},'firewall':None}}}}

    def run_root(self,kind,success=True):
        self.state['operation'].update(kind=kind,status='queued',result=None)
        self.state['calls']=[];self.state['actions']=0
        (self.repo/'bootstrap-fixture.json').write_text(json.dumps(self.state))
        workflow='operation-'+kind.replace('.','-').replace('_','-')
        result=self.cli('workflow','run',workflow,'--input',json.dumps({'operation_id':17,'node_id':14,'kind':kind}),success=success)
        self.run_id=json.JSONDecoder().raw_decode(result.stdout.lstrip())[0]['id']
        return json.loads((self.repo/'bootstrap-fixture.json').read_text())

    def test_each_parent_has_one_native_root_and_keeps_private_identity_out_of_history(self):
        for kind in KINDS:
            state=self.run_root(kind)
            self.assertEqual(state['operation']['status'],'succeeded')
            self.assertEqual(state['actions'],1)
            self.assertTrue(all('/direct-operations/17' in c['path'] for c in state['calls']))
            self.assertFalse(any('children' in c['path'] or 'executor' in c['path'] for c in state['calls']))

    def test_engine_failure_stays_in_native_history_without_a_worker_callback(self):
        self.state['engineRefused']=True
        for kind in KINDS:
            state=self.run_root(kind,False)
            self.assertEqual(state['operation']['status'],'queued')
            self.assertIsNone(state['operation'].get('error_code'))
            self.assertEqual(state['actions'],0)
            self.assertEqual(state['calls'],[])
            history=json.loads(self.cli('workflow','history','get',self.run_id).stdout)
            self.assertEqual(history['status'],'failed')

    def test_private_failure_is_bounded_and_cannot_enter_native_data(self):
        self.state['privateFailure']=True
        state=self.run_root('node.bootstrap_host',False)
        self.assertEqual(state['operation']['status'],'failed')
        self.assertEqual(state['operation']['result'],{'error_code':'node.bootstrap_failed'})
        self.assertEqual(state['actions'],1)

    def test_lost_terminal_projection_resumes_without_another_action(self):
        self.state['loseProjection']=True
        state=self.run_root('node.bootstrap_install',False)
        self.assertEqual(state['operation']['status'],'succeeded')
        self.cli('workflow','resume','operation-node-bootstrap-install','--run',self.run_id,'--from','apply')
        state=json.loads((self.repo/'bootstrap-fixture.json').read_text())
        self.assertEqual(state['actions'],1)
        self.assertEqual(state['operation']['status'],'succeeded')

if __name__ == '__main__':unittest.main()
