"""Legacy server closure keeps its own native fixture while SQL user creation moves."""
import json
import sys
import unittest
import uuid
from pathlib import Path

import yaml

ROOT=Path(__file__).resolve().parents[4]
sys.path.insert(0,str(ROOT/'tests'))
from native_harness import NativeTestCase


class ServerCloseNative(NativeTestCase):
    def setUp(self):
        super().setUp()
        self.copy_behaviors('automatic-deploy','operations/_lib','operations/database')
        failure=self.repo/'extensions/behaviors/operations/failure.ts'
        failure.write_text('import { fixtureHttp } from "./database/server_close_native_fixture.ts";\n'+(ROOT/'extensions/behaviors/operations/failure.ts').read_text().replace('export const behavior = nativeFailure();','export const behavior = nativeFailure(() => fixtureHttp);'))
        module=self.repo/'extensions/behaviors/operations/database/server_close.ts'
        module.write_text('import { fixtureHttp, fixtureConnect } from "./server_close_native_fixture.ts";\n'+module.read_text().replace('...serverClose()','...serverClose(() => fixtureHttp, fixtureConnect)'))
        workflow=yaml.safe_load((ROOT/'workflows/workflow-operation-database-server-close.yaml').read_text())
        workflow.pop('target',None);workflow.pop('labels',None)
        for job in workflow['jobs']:
            job.pop('target',None);job.pop('labels',None)
            for step in job['steps']:step.pop('target',None);step.pop('labels',None)
        (self.repo/'workflows/workflow-operation-database-server-close.yaml').write_text(yaml.safe_dump(workflow,sort_keys=False))
        self.password='private-close-'+uuid.uuid4().hex;self.secrets=(self.password,)
        self.fixture={'operation':{'id':17,'kind':'database_server.close','node_id':209,'status':'queued','result':None,'cancel_requested':False,
            'input':{'host':'10.44.0.209','port':6379,'default_open':True}},'material':{'password':self.password},'calls':[],'effects':0}
        self.file=self.repo/'private-user-fixture.json'

    def assert_private_absent(self,*outputs):
        # The fake HTTP source intentionally owns this one private fixture file.
        # Keep the shared scan over stdout and every native/cache/source artifact.
        fixture=self.repo/'private-user-fixture.json'
        if not fixture.exists():return super().assert_private_absent(*outputs)
        saved=fixture.read_bytes();fixture.unlink()
        try:super().assert_private_absent(*outputs)
        finally:fixture.write_bytes(saved)

    def run_close(self,success=True):
        self.file.write_text(json.dumps(self.fixture))
        self.cli('workflow','validate','operation-database-server-close')
        result=self.cli('workflow','run','operation-database-server-close','--input','operation_id=17','--input','node_id=209','--input','kind=database_server.close',success=success)
        self.assert_private_absent(result.stdout,result.stderr)
        return json.JSONDecoder().raw_decode(result.stdout.lstrip())[0]

    def test_closure_projects_safe_result_and_drops_private_material(self):
        self.run_close();final=json.loads(self.file.read_text())
        self.assertEqual(final['effects'],1);self.assertEqual(final['operation']['result'],{'backend':'active'})

    def test_invalid_private_material_or_private_exception_stays_bounded(self):
        self.fixture['material']['password']={self.password:'private-value'}
        self.run_close(False);self.assertEqual(json.loads(self.file.read_text())['effects'],0)
        self.fixture['material']={'password':self.password};self.fixture['failEffect']=True
        self.run_close(False);self.assertEqual(json.loads(self.file.read_text())['effects'],1)

    def test_terminal_projection_resume_does_not_repeat_closure(self):
        self.fixture['loseProjection']=True;run=self.run_close(False)
        self.assertEqual(json.loads(self.file.read_text())['effects'],1)
        self.cli('workflow','resume','operation-database-server-close','--run',run['id'])
        final=json.loads(self.file.read_text());self.assertEqual(final['effects'],1)
        self.assertEqual(final['operation']['status'],'succeeded')


if __name__=='__main__':unittest.main()
