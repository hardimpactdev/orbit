"""Run the native CLI publication DAG with only SSH and file transports held."""
import json
import yaml
from native_harness import ROOT, NativeTestCase


class CliInstallNative(NativeTestCase):
    def setUp(self):
        super().setUp()
        self.copy_behaviors('operations', 'node-worker', 'automatic-deploy')
        (self.repo/'extensions/behaviors/cli-fixture.ts').write_text('''import {z} from "npm:zod@4.4.3";
export const behavior={type:"@fixture/cli-transport",version:"2026.11.02.1",globalArguments:z.object({nodeHost:z.string(),path:z.string()}).partial(),resources:{output:{schema:z.object({stdout:z.string()}),lifetime:"1d",garbageCollection:10},state:{schema:z.record(z.string(),z.unknown()),lifetime:"1d",garbageCollection:10}},methods:{
exec:{description:"Harmless SSH boundary",arguments:z.object({argv:z.array(z.string())}).passthrough(),execute:async(args,ctx)=>{const data=JSON.parse(Deno.readTextFileSync("fixture.json"));const candidate=args.argv.includes("/usr/local/bin/orbit-0.12.0");Deno.writeTextFileSync("calls.jsonl",JSON.stringify(candidate?"candidate":"read")+"\\n",{append:true,create:true});if(!candidate&&!data.current)throw new Error("CLI missing");if(!candidate&&args.argv.includes("self-update")&&data.stale)throw new Error("self-update missing");const result={stdout:candidate?"Orbit "+(data.bad?"0.11.0":"0.12.0")+"\\n":"Orbit "+data.current+"\\n"};await ctx.writeResource("output","output",result);return result;}},
apply:{description:"Harmless resource boundary",arguments:z.object({}),execute:async(_,ctx)=>{Deno.writeTextFileSync("calls.jsonl",JSON.stringify(ctx.globalArgs.path)+"\\n",{append:true,create:true});if(ctx.globalArgs.path==="/usr/local/bin/orbit-0.12.0"&&JSON.parse(Deno.readTextFileSync("fixture.json")).checksum)throw new Error("Checksum mismatch");return {dataHandles:[await ctx.writeResource("state",ctx.globalArgs.nodeHost,{status:"compliant",changes:[]})]};}}
}};''')
        workflow=yaml.safe_load((ROOT/'workflows/workflow-node-cli-install.yaml').read_text())
        for job in workflow['jobs']:
            job.pop('labels',None)
            for step in job['steps']:
                step.pop('labels',None)
                task=step['task']
                if task.get('behaviorType')=='@swamp/ssh':
                    task['behaviorType']='@fixture/cli-transport'
                    task.pop('globalArgs',None)
                    task['inputs']={'argv':task['inputs']['argv']}
                elif task.get('behaviorType','').startswith('@swamp/cfgmgmt/'):
                    task['behaviorType']='@fixture/cli-transport'
                    task['globalArgs']={key:value for key,value in task['globalArgs'].items() if key in ('nodeHost','path')}
        (self.repo/'workflows/workflow-node-cli-install.yaml').write_text(yaml.safe_dump(workflow,sort_keys=False))
        self.input={'node_id':9,'intent':{'ssh':{'target':{'host':'fixture','user':'orbit','port':22,'host_keys':['synthetic']},'identity_file':'/synthetic'},'cli':{'user':'orbit','profile':'public profile','release':{'version':'0.12.0','url':'https://delivery.test/cli','sha256':'a'*64}}}}

    def execute(self, **state):
        (self.repo/'fixture.json').write_text(json.dumps(state))
        self.cli('workflow','validate','node-cli-install')
        run=json.loads(self.cli('workflow','run','node-cli-install','--input',json.dumps(self.input),success=not(state.get('bad') or state.get('checksum'))).stdout)
        history=json.loads(self.cli('workflow','history','get',run['id']).stdout)
        calls=[json.loads(line) for line in (self.repo/'calls.jsonl').read_text().splitlines()]
        result=next((s.get('outputs') for j in history['jobs'] for s in j['steps'] if s['name']=='result'),None)
        return calls,result

    def test_missing_cli_publishes_after_optional_reads_fail(self):
        calls,result=self.execute(current=None)
        self.assertEqual(result,{'installed':'0.12.0','configured':False,'version':'0.12.0'})
        self.assertLess(calls.index('/usr/local/bin/orbit-0.12.0'),calls.index('candidate'))
        self.assertLess(calls.index('candidate'),calls.index('/usr/local/bin/orbit'))
        self.assertIn('/root/.orbit/config.json',calls)

    def test_newer_cli_is_preserved_and_profile_is_applied(self):
        calls,result=self.execute(current='0.13.0')
        self.assertIsNone(result['installed'])
        self.assertEqual(result['version'],'0.13.0')
        self.assertNotIn('/usr/local/bin/orbit-0.12.0',calls)
        self.assertIn('/root/.orbit/config.json',calls)

    def test_invalid_candidate_never_switches_link_or_profile(self):
        calls,_=self.execute(current=None,bad=True)
        self.assertNotIn('/usr/local/bin/orbit',calls)
        self.assertNotIn('/root/.orbit/config.json',calls)

    def test_checksum_failure_never_runs_candidate_or_switches_link(self):
        calls,_=self.execute(current=None,checksum=True)
        self.assertNotIn('candidate',calls)
        self.assertNotIn('/usr/local/bin/orbit',calls)

    def test_unrecognized_version_is_replaced_by_the_admitted_release(self):
        calls,result=self.execute(current='9.9.9-foreign')
        self.assertEqual(result,{'installed':'0.12.0','configured':False,'version':'0.12.0'})
        self.assertLess(calls.index('candidate'),calls.index('/usr/local/bin/orbit'))
        self.assertIn('/root/.orbit/config.json',calls)

    def test_commit_build_is_preserved(self):
        calls,result=self.execute(current='a'*40)
        self.assertIsNone(result['installed'])
        self.assertEqual(result['version'],'a'*40)
        self.assertNotIn('/usr/local/bin/orbit-0.12.0',calls)

