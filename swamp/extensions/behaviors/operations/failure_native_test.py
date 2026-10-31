"""Real pinned DAG failure reporting; no network, credentials or fleet effects."""
import json
from pathlib import Path
import sys
import unittest
ROOT=Path(__file__).resolve().parents[3]
sys.path.insert(0,str(ROOT/'tests'))
from native_harness import NativeTestCase

class NativeFailureTest(NativeTestCase):
    def setUp(self):
        super().setUp()
        self.copy_behaviors('automatic-deploy','operations/_lib')
        behavior=self.repo/'extensions/behaviors/operations';behavior.mkdir(exist_ok=True)
        source=(ROOT/'extensions/behaviors/operations/failure.ts').read_text()
        fixture='''async function fixtureHttp(method: string,path: string,body?: unknown) {
 const calls=JSON.parse(await Deno.readTextFile('calls.json'));calls.push({method,path,body});
 await Deno.writeTextFile('calls.json',JSON.stringify(calls));
 return {status:200,body:{data:{status:'failed'}}};
}
'''
        (behavior/'failure.ts').write_text(source.replace('export const behavior = nativeFailure();',fixture+'export const behavior = nativeFailure(() => fixtureHttp);'))
        (self.repo/'calls.json').write_text('[]')
        (behavior/'fixture-failure.ts').write_text("import {z} from 'npm:zod@4.4.3';export const behavior={type:'@fixture/rejected',version:'2026.10.31.1',methods:{execute:{description:'Reject before effect',arguments:z.object({}),execute:()=>{throw new Error('fixture pre-effect failure');}}}};")
        workflow='''id: 40bb011c-5ff5-444c-a7af-a0c55a9b6409
name: native-failure-fixture
inputs:
  properties:
    operation_id: {type: integer}
    node_id: {type: integer}
    kind: {type: string}
  required: [operation_id,node_id,kind]
jobs:
  - name: effect
    steps:
      - name: rejected-before-dispatch
        task:
          type: behavior
          behaviorType: '@fixture/rejected'
          behaviorName: missing
          methodName: execute
          inputs: {}
  - name: terminal-failure
    dependsOn:
      - job: effect
        condition: {type: failed}
    steps:
      - name: report
        task:
          type: behavior
          behaviorType: '@nckrtl/operation-failure'
          behaviorName: root-failure
          methodName: report
          inputs:
            operation_id: '${{ inputs.operation_id }}'
            node_id: '${{ inputs.node_id }}'
            kind: '${{ inputs.kind }}'
            run_id: '${{ run.id }}'
'''
        (self.repo/'workflows/workflow-native-failure-fixture.yaml').write_text(workflow)

    def test_rejected_family_before_behavior_still_reports_same_root(self):
        run=self.cli('workflow','run','native-failure-fixture','--input','operation_id=71','--input','node_id=9','--input','kind=sample.apply',success=False)
        calls=json.loads((self.repo/'calls.json').read_text())
        self.assertEqual(len(calls),1,run.stdout+run.stderr)
        self.assertEqual(calls[0]['path'],'/api/v1/direct-operations/71/failure')
        self.assertEqual(calls[0]['method'],'POST')
        self.assertEqual(list(calls[0]['body']),['run_id'])
        self.assertEqual(len(calls[0]['body']['run_id']),36)

if __name__=='__main__':unittest.main()
