"""Pinned native credential proof: no secret in the whole disposable Swamp tree."""
import json
import os
from pathlib import Path
import secrets
import shutil
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[4]
SWAMP = os.environ.get('SWAMP_BIN', 'swamp')
BEHAVIOR = '''import {z} from "npm:zod@4.4.3";
import {GatewayOperation} from "./operations/_lib/direct.ts";
export const behavior = {
 type: "@nckrtl/github-token-proof", version: "2026.10.30.1",
 resources: {safe: {description:"Safe public result",schema:z.record(z.string(),z.unknown()),lifetime:"infinite",garbageCollection:1}},
 methods: {execute: {description:"Exercise private credentials without persistence",
 arguments:z.object({run_id:z.string().uuid(),mode:z.string()}),
 execute:async(args:{run_id:string;mode:string},ctx:{writeResource:(spec:string,name:string,data:Record<string,unknown>)=>Promise<unknown>})=>{
  const token=Deno.env.get("ORBIT_CREDENTIAL_PROOF_SENTINEL")!;
  let revocations=0;
  const gateway=new GatewayOperation((method,path,body)=>{if(method!=="GET"||path!=="/api/v1/direct-operations/71/private-material?run_id="+args.run_id||body!==undefined)throw new Error("private read mismatch");return Promise.resolve({status:200,body:{data:{github:{repository:"hardimpactdev/orbit",permissions:{contents:"write",pull_requests:"write"},token,expires_at:new Date(Date.now()+3600000).toISOString()}}}});},{operation_id:71,node_id:209,kind:"proof.apply",run_id:args.run_id},undefined,undefined,(url,init)=>{
   if(url!=="https://api.github.com/installation/token"||init?.method!=="DELETE"||new Headers(init.headers).get("Authorization")!=="Bearer "+token)throw new Error("invalid revoke");
   revocations++;if(args.mode==="revoke-throws")throw new Error(token);return Promise.resolve(new Response(args.mode==="revoke-failed"?token:null,{status:args.mode==="revoke-failed"?503:204}));
  });
  let result;
  try { result=await gateway.withGitHubToken((secret)=>{
   if(args.mode==="return-secret")return Promise.resolve({token:secret});
   if(args.mode==="throw-secret")throw new Error(secret);
   if(args.mode==="serialize-secret")return Promise.resolve({toJSON(){throw new Error(secret)}});
   if(args.mode==="stateful"){
    let n=0;return Promise.resolve({toJSON(){return ++n===1?{completed:true}:{token:secret}}});
   }
   return Promise.resolve({completed:true});
  }); } finally { if(revocations!==1)throw new Error("revoke missing"); }
  return {dataHandles:[await ctx.writeResource("safe","result",result)]};
 }}}
};
'''
WORKFLOW = '''id: 8321bb7a-35c0-4470-9eb5-24aaf8ad5815
name: credential-proof
vaults: []
inputs:
  properties:
    mode: {type: string}
  required: [mode]
jobs:
  - name: proof
    steps:
      - name: mint
        writes: true
        task:
          type: behavior
          behaviorType: '@nckrtl/github-token-proof'
          behaviorName: credential-proof
          methodName: execute
          inputs:
            mode: '${{ inputs.mode }}'
            run_id: '${{ run.id }}'
'''


class GitHubTokenNative(unittest.TestCase):
    def test_native_outputs_errors_and_history_never_persist_credential_bytes(self):
        with tempfile.TemporaryDirectory(prefix='orbit-credential-native-') as directory:
            repo = Path(directory)
            shutil.copy(ROOT / '.swamp.yaml', repo)
            behavior = repo / 'extensions/behaviors'
            for family in ['automatic-deploy', 'operations/_lib']:
                shutil.copytree(ROOT / 'extensions/behaviors' / family, behavior / family)
            (behavior / 'credential-proof.ts').write_text(BEHAVIOR)
            (repo / 'workflows').mkdir()
            (repo / 'workflows/workflow-credential-proof.yaml').write_text(WORKFLOW)
            sentinel = 'ghs_' + secrets.token_hex(24)
            env = dict(os.environ, SWAMP_NO_UPDATE_CHECK='1', SWAMP_NO_TELEMETRY='1',
                       ORBIT_CREDENTIAL_PROOF_SENTINEL=sentinel)
            for key in ('SWAMP_SERVER_URL', 'SWAMP_SERVE_URL', 'SWAMP_SERVER_TOKEN',
                        'SWAMP_SERVER_TOKEN_FILE', 'SWAMP_REPO_DIR', 'SWAMP_CA_CERT', 'SWAMP_EXTENSIONS_DIR'):
                env.pop(key, None)
            describe = subprocess.run([SWAMP, 'behavior', 'type', 'describe', '@nckrtl/github-token-proof', '--json'], cwd=repo, env=env, capture_output=True, timeout=60)
            self.assertEqual(describe.returncode, 0, (describe.stdout + describe.stderr).decode(errors='replace').replace(sentinel, '[synthetic credential]'))
            for mode in ['success', 'return-secret', 'throw-secret', 'serialize-secret', 'stateful', 'revoke-failed', 'revoke-throws']:
                with self.subTest(mode=mode):
                    result = subprocess.run([SWAMP, 'workflow', 'run', 'credential-proof',
                                             '--input', 'mode=' + mode, '--json'], cwd=repo, env=env,
                                            capture_output=True, timeout=60)
                    self.assertEqual(result.returncode == 0, mode in ['success', 'stateful', 'revoke-failed', 'revoke-throws'],
                                     'native credential fixture outcome mismatch: ' + (result.stdout + result.stderr).decode(errors='replace').replace(sentinel, '[synthetic credential]')[-3000:])
                    self.assertNotIn(sentinel.encode(), result.stdout + result.stderr)
                    files = [path for path in repo.rglob('*') if path.is_file()]
                    self.assertGreater(len(files), 20)
                    for path in files:
                        self.assertNotIn(sentinel.encode(), path.read_bytes(),
                                         'credential persisted at ' + str(path.relative_to(repo)))
            outputs = [p for p in repo.rglob('*') if p.is_file() and 'data' in p.parts]
            self.assertTrue(any(b'"completed"' in p.read_bytes() for p in outputs),
                            'positive safe output was not persisted')


if __name__ == '__main__':
    unittest.main()
