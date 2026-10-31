"""Native per-Gateway lock, projection resume and whole-scratch private-material regression."""
import json
from pathlib import Path
import shutil
import sys
import unittest
import uuid

ROOT = Path(__file__).resolve().parents[4]
sys.path.insert(0, str(ROOT / 'tests'))
from native_harness import NativeTestCase

class VaultDirectNative(NativeTestCase):
    native_timeout = 50

    def setUp(self):
        super().setUp()
        self.secret = 'private-sentinel-' + uuid.uuid4().hex
        self.secrets = (self.secret,)
        self.native_env['ORBIT_PRIVATE_TEST_SENTINEL'] = self.secret
        self.copy_behaviors('automatic-deploy', 'operation-runner', 'operations/_lib', 'operations/vault')
        p = self.repo/'extensions/behaviors/operations/vault/mod.ts'
        p.write_text('import {fixtureHttp, fixtureVault} from "./native_fixture.ts";\n'+p.read_text().replace('...vaultDirect()', '...vaultDirect(() => fixtureHttp, fixtureVault)'))
        self.workflow = self.repo/'workflows/workflow-operation-vault-direct.yaml'
        self.workflow.write_text('\n'.join(line for line in (ROOT/'workflows/workflow-operation-vault-direct.yaml').read_text().replace('/usr/local/share/ca-certificates/orbit-root.crt','/dev/null').splitlines() if not line.lstrip().startswith(('target:', 'labels:')))+'\n')
        self.state = {'operations': {}, 'effects': 0, 'calls': [], 'loseProjection': False, 'echoPrivate': False}
        self.add_operation(17)
    def add_operation(self, identity, kind='vault.sync'):
        self.state['operations'][str(identity)] = {'id': identity, 'node_id': 9, 'kind': kind, 'status': 'queued', 'input': {'action':kind.split('.')[1]}, 'result': None, 'cancel_requested':False}
        self.save()
    def save(self): (self.repo/'native-vault.json').write_text(json.dumps(self.state))
    def read(self): return json.loads((self.repo/'native-vault.json').read_text())
    def run_workflow(self, identity=17, success=True):
        kind=self.read()['operations'][str(identity)]['kind']
        result=self.cli('workflow','run','operation-vault-direct','--input','operation_id='+str(identity),'--input','node_id=9','--input','kind='+kind,success=success)
        return json.JSONDecoder().raw_decode(result.stdout.lstrip())[0]['id']
    def test_sync_uses_shared_material_and_projects_only_metadata(self):
        self.cli('workflow','validate','operation-vault-direct')
        self.run_workflow()
        self.assertEqual(self.read()['operations']['17']['status'],'succeeded')
        self.assertEqual(self.read()['effects'],1)
    def test_projection_resume_survives_an_interleaved_gateway_operation(self):
        self.state['loseProjection']=True;self.save()
        first=self.run_workflow(success=False)
        self.state=self.read();self.add_operation(18)
        self.run_workflow(18)
        self.cli('workflow','resume','operation-vault-direct','--run',first,'--from','apply')
        self.assertEqual(self.read()['operations']['17']['status'],'succeeded')
        self.assertEqual(self.read()['operations']['18']['status'],'succeeded')
    def test_private_echo_becomes_a_safe_failure_without_a_public_credential(self):
        self.state['echoPrivate']=True;self.save()
        self.run_workflow(success=False)
        self.assertEqual(self.read()['operations']['17']['result'],{'error_code':'vault.result_invalid'})
    def test_exact_worker_placement_refuses_local_fallback_before_material_read(self):
        self.workflow.write_text((ROOT/'workflows/workflow-operation-vault-direct.yaml').read_text().replace('/usr/local/share/ca-certificates/orbit-root.crt','/dev/null'))
        self.run_workflow(success=False)
        self.assertEqual(self.read()['effects'],0)
        self.assertEqual(self.read()['calls'],[])

if __name__=='__main__': unittest.main()
