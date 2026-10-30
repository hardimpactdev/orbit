"""Direct SQLite cleanup runs and recovery through the pinned native Swamp workflow."""
import concurrent.futures
import json
from pathlib import Path
import sys
import unittest

ROOT = Path(__file__).resolve().parents[4]
sys.path.insert(0, str(ROOT / 'tests'))
from native_harness import NativeTestCase


class DatabaseCleanupNative(NativeTestCase):
    def setUp(self):
        super().setUp()
        self.copy_behaviors('automatic-deploy', 'operation-runner', 'operations/_lib', 'operations/database')
        module = self.repo / 'extensions/behaviors/operations/database/cleanup.ts'
        module.write_text('import {fixtureHttp, fixtureLocal} from "./cleanup_native_fixture.ts";\n' + module.read_text().replace('export const behavior = cleanup();', 'export const behavior = cleanup(() => fixtureHttp, fixtureLocal);'))
        source = ROOT / 'workflows/workflow-operation-database-cleanup.yaml'
        (self.repo / 'workflows' / source.name).write_text('\n'.join(line for line in source.read_text().splitlines() if not line.startswith(('labels:', 'target:'))) + '\n')
        self.state = {'operation': {'id': 17, 'kind': 'database.sqlite_cleanup', 'node_id': 209, 'status': 'queued', 'result': None, 'cancel_requested': False, 'input': {'variant': 'clone', 'agent_sha256': 'a'*64, 'directory': '/tmp/orbit-sqlite-clone-'+str(17).zfill(32)}}, 'calls': [], 'cleanups': 0}
        self.save()

    def save(self):
        (self.repo / 'native-database-cleanup.json').write_text(json.dumps(self.state))

    def read(self):
        return json.loads((self.repo / 'native-database-cleanup.json').read_text())

    def run_workflow(self, success=True):
        result = self.cli('workflow','run','operation-database-cleanup','--input','operation_id=17','--input','node_id=209','--input','kind='+self.state['operation']['kind'],success=success)
        self.run_id = json.JSONDecoder().raw_decode(result.stdout.lstrip())[0]['id']
        return result

    def test_direct_cleanup_has_no_discovery_claim_heartbeat_or_receipt(self):
        self.run_workflow()
        state=self.read()
        self.assertEqual(state['cleanups'],1)
        self.assertEqual(state['operation']['status'],'succeeded')
        self.assertTrue(all('/direct-operations/' in call for call in state['calls']))
        self.assertFalse(any('claim' in call or 'heartbeat' in call or 'receipts' in call or '/primitive' in call for call in state['calls']))

    def test_native_retry_after_lost_terminal_projection_does_not_repeat_effect(self):
        self.state['loseCompletion']=True
        self.save()
        self.run_workflow(success=False)
        self.cli('workflow','resume','operation-database-cleanup','--run',self.run_id,'--from','cleanup')
        self.assertEqual(self.read()['cleanups'],1)

    def test_native_retry_can_repeat_convergent_cleanup_without_a_gateway_receipt(self):
        self.state['loseCleanup']=True
        self.save()
        self.run_workflow(success=False)
        self.cli('workflow','resume','operation-database-cleanup','--run',self.run_id,'--from','cleanup')
        state=self.read()
        self.assertEqual(state['cleanups'],2)
        self.assertEqual(state['operation']['status'],'succeeded')

    def test_native_behavior_lock_serializes_two_cleanups_on_the_same_node(self):
        first=self.state.pop('operation')
        self.state['operations']={'17':first,'18':dict(first,id=18,input=dict(first['input'],directory='/tmp/orbit-sqlite-clone-'+str(18).zfill(32)))}
        self.state['events']=[]
        self.save()
        def run(operation):
            return self.cli('workflow', 'run', 'operation-database-cleanup', '--input', f'operation_id={operation}', '--input', 'node_id=209', '--input', 'kind=database.sqlite_cleanup')
        with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
            for future in [pool.submit(run, operation) for operation in (17, 18)]:
                future.result(timeout=self.native_timeout)
        events=self.read()['events']
        self.assertIn(events,[['start-17','end-17','start-18','end-18'],['start-18','end-18','start-17','end-17']])


if __name__ == '__main__':
    unittest.main()
