"""Run the unchanged DAG in pinned Swamp with a disposable gh command boundary."""
import importlib.util
import json
import os
import secrets
from pathlib import Path
import shutil
import sys
import unittest

import yaml

ROOT = Path(__file__).resolve().parents[4]
sys.path.insert(0, str(ROOT / 'tests'))
from native_harness import NativeTestCase
spec = importlib.util.spec_from_file_location('evidence', ROOT / 'proof/tasks.dependency_merge/evidence.py')
fixture = importlib.util.module_from_spec(spec)
spec.loader.exec_module(fixture)


class DependencyMergeNativeTest(NativeTestCase):
    native_timeout = 120

    def setUp(self):
        super().setUp()
        self.copy_behaviors('operations/dependency-merge')
        lib=self.repo / 'extensions/behaviors/_lib'
        lib.mkdir()
        shutil.copy(ROOT / 'extensions/behaviors/_lib/versions.ts',lib)
        self.state = self.repo / 'github.json'
        self.state.write_text(json.dumps({'rows': fixture.evidence(), 'calls': [], 'lost_reply': False}))
        tools = self.repo / 'tools'
        tools.mkdir()
        gh = tools / 'gh'
        gh.write_text('#!' + sys.executable + '\n' + '''import json,os,sys
from pathlib import Path
path=Path(os.environ['FIXTURE_STATE']);data=json.loads(path.read_text());args=sys.argv[1:]
assert args[0]=='api' and os.environ['GH_TOKEN'] not in ' '.join(args)
method='GET';endpoint=None;i=1
while i<len(args):
    if args[i]=='--method':method=args[i+1];i+=2
    elif args[i]=='--input':
        body=json.loads(Path(args[i+1]).read_text());assert body=={'sha':'a'*40,'merge_method':'squash'};i+=2
    elif args[i].startswith('--'):i+=1
    else:endpoint='/'+args[i].lstrip('/');i+=1
data['calls'].append([method,endpoint]);path.write_text(json.dumps(data))
if method=='DELETE':raise SystemExit(0)
if method=='PUT':
    assert os.environ['GH_TOKEN']==os.environ['FIXTURE_WRITE']
    pr=data['rows']['/repos/hardimpactdev/orbit/pulls/3'];pr['merged']=True;pr['merge_commit_sha']='c'*40
    path.write_text(json.dumps(data))
    if data['lost_reply']:raise SystemExit(1)
    print(json.dumps({'merged':True,'sha':'c'*40}))
else:
    assert os.environ['GH_TOKEN']==os.environ['FIXTURE_READ']
    print(json.dumps(data['rows'][endpoint]))
''')
        gh.chmod(0o755)
        self.native_env.update({'PATH': str(tools) + ':' + os.environ['PATH'], 'FIXTURE_STATE': str(self.state), 'FIXTURE_READ': secrets.token_urlsafe(32), 'FIXTURE_WRITE': secrets.token_urlsafe(32)})
        self.secrets = (self.native_env['FIXTURE_READ'], self.native_env['FIXTURE_WRITE'])
        flow = yaml.safe_load((ROOT / 'workflows/workflow-operation-dependency-merge.yaml').read_text())
        def rewrite(value):
            if isinstance(value, str):
                value = value.replace('/tmp/orbit-dependency-merge-', str(self.repo / 'scratch-'))
                if value.startswith('${{ vault.get('):
                    return '${{ env.FIXTURE_WRITE }}' if 'write_token' in value else '${{ env.FIXTURE_READ }}'
                return value
            if isinstance(value, list):return [rewrite(v) for v in value]
            if isinstance(value, dict):return {k:rewrite(v) for k,v in value.items()}
            return value
        flow = rewrite(flow)
        for job in flow['jobs']:
            self.assertEqual(job.pop('labels'), {'role': 'swamp'})
            for step in job['steps']:self.assertEqual(step.pop('labels'), {'role': 'swamp'})
        (self.repo / 'workflows/workflow-merge.yaml').write_text(yaml.safe_dump(flow, sort_keys=False))
        self.inputs = {'operation_id':7, 'node_id':209, 'kind':'tasks.dependency_merge', 'intent':{
            'node_id':209, 'project_id':20, 'group_id':91, 'publish_operation_id':8, 'repository':'hardimpactdev/orbit', 'default_branch':'main', 'smoke_workflow':'.github/workflows/production-smoke.yml', 'pr_number':3, 'head_sha':fixture.HEAD},
            'execution_secrets': {name:{'vault':'orbit-control-inputs', 'key':'dependency-merge-'+scope+'-12345678-1234-4123-8123-123456789012'} for name,scope in [('read_token','read'),('write_token','write')]}}

    def run_merge(self, success=True):
        self.cli('workflow', 'validate', 'operation-dependency-merge')
        run = json.loads(self.cli('workflow','run','operation-dependency-merge','--stdin',input=json.dumps(self.inputs),success=success).stdout)
        history = json.loads(self.cli('workflow','history','get',run['id']).stdout)
        self.assertEqual(list(self.repo.glob('scratch-*')), [])
        data = json.loads(self.state.read_text())
        self.assertEqual(sum(c[0]=='DELETE' for c in data['calls']), 2)
        return history, data

    def test_merge_and_native_receipt(self):
        history,data = self.run_merge()
        self.assertEqual(sum(c[0]=='PUT' for c in data['calls']),1)
        step = next(s for j in history['jobs'] for s in j['steps'] if s['name']=='Read merge result')
        self.assertEqual(json.loads(step['outputs']['stdout']), {'merged':True,'head_sha':fixture.HEAD,'merge_sha':fixture.MERGE})

    def test_major_change_refuses_and_revokes(self):
        self.state.write_text(json.dumps({'rows':fixture.evidence(case='major'),'calls':[]}))
        history,data = self.run_merge(False)
        self.assertFalse(any(c[0]=='PUT' for c in data['calls']))
        self.assertIn('dependency_merge.major_bump',json.dumps(history))

    def test_pending_checks_refuse_before_contents(self):
        self.state.write_text(json.dumps({'rows':fixture.evidence(case='checks'),'calls':[]}))
        _,data = self.run_merge(False)
        self.assertFalse(any('/contents/' in c[1] or c[0]=='PUT' for c in data['calls']))

    def test_already_merged_skips_reads_and_put(self):
        self.state.write_text(json.dumps({'rows':fixture.evidence(case='merged'),'calls':[]}))
        _,data = self.run_merge()
        self.assertEqual([c[0] for c in data['calls']],['GET','DELETE','DELETE'])

    def test_lost_reply_reconciles_on_fresh_run(self):
        data=json.loads(self.state.read_text());data['lost_reply']=True;self.state.write_text(json.dumps(data))
        self.run_merge(False)
        data=json.loads(self.state.read_text());data['calls']=[];self.state.write_text(json.dumps(data))
        _,data=self.run_merge()
        self.assertFalse(any(c[0]=='PUT' for c in data['calls']))


if __name__=='__main__':unittest.main()
