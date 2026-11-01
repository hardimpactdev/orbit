"""Actual e459 graph + official SSH script delivery, no fleet connections."""
import json
import subprocess
import sys
import unittest

import yaml
from native_harness import ROOT, NativeTestCase


class ToolAdoptNative(NativeTestCase):
    native_timeout = 120

    def setUp(self):
        super().setUp()
        self.copy_behaviors('operations/tools')
        self.cli('extension', 'pull', '@swamp/ssh@2026.10.23.1')
        self.audit = self.repo / 'audit.jsonl'
        self.fail_marker = self.repo / 'fail-version'
        self.own = self.repo / 'own-identity'
        self.own.write_text('synthetic own identity')
        self.own.chmod(0o600)
        fixture = self.repo / 'fixture-ssh'
        fixture.write_text('#!' + sys.executable + '\n' + '''
import ast,json,sys
from pathlib import Path
args=sys.argv[1:]
assert OWN in args and 'IdentitiesOnly=yes' in args
assert 'sudo' not in args[-1]
body=sys.stdin.read()
if not body and 'managed apt repository phase' in args[-1]:
 import shlex
 command=shlex.split(args[-1]);assert command[-2]=='adopt'
 with Path(AUDIT).open('a') as out:out.write(json.dumps({'action':'repository_adopt','manager':'apt','user':'orbit'})+'\\n')
 marker=Path(AUDIT).with_name('repository-error')
 print(json.dumps({'ready':False,'error_code':marker.read_text()} if marker.exists() else {'ready':True}))
 raise SystemExit(0)
tree=ast.parse(body)
node=next(n for n in tree.body if isinstance(n,ast.Assign) and isinstance(n.targets[0],ast.Name) and n.targets[0].id=='QUERY_INPUT')
input=json.loads(ast.literal_eval(node.value))
with Path(AUDIT).open('a') as out:out.write(json.dumps({'action':'query','manager':input['manager'],'user':input['user']})+'\\n')
if Path(FAIL).exists():raise SystemExit(1)
facts={'manager_version':'3.0.0','versions':['1.0.0'],'complete':True,'roots':[input['package']],'os_major':15,'prefix':'/prefix','home':'/home/orbit'}
if input['manager']=='brew':facts['homebrew']={'name':input['package'],'full_name':input['package'],'tap':'homebrew/core','disabled':False,'installed':[{'version':'1.0.0','installed_on_request':True}],'versions':{'bottle':True},'bottle':{'stable':{'files':{'x86_64_linux':{'sha256':'a'*64,'url':'https://ghcr.io/v2/homebrew/core/hello/sha256:'+'a'*64}}}}}
if input['manager']=='brew-cask':facts['homebrew']={'token':input['package'],'full_token':input['package'],'tap':'homebrew/cask','disabled':False,'url':'https://example.test/download','version':'1.0.0','sha256':'b'*64,'artifacts':[{'font':['Font.ttf']}],'variations':{}}
print(json.dumps(facts))
'''.replace('OWN', repr(str(self.own))).replace('AUDIT', repr(str(self.audit))).replace('FAIL', repr(str(self.fail_marker))))
        fixture.chmod(0o700)
        workflow = yaml.safe_load((ROOT / 'workflows/workflow-operation-tool-adopt-209.yaml').read_text())
        for job in workflow['jobs']:
            self.assertEqual(job.pop('target'), 'orbit-node-209')
            self.assertNotIn('labels', job)
            for step in job['steps']:
                self.assertEqual(step.pop('target'), 'orbit-node-209')
                self.assertNotIn('labels', step)
                if step['task'].get('behaviorType') == '@swamp/ssh':
                    transport=step['task']['globalArgs']['transport']
                    self.assertEqual(transport['hostKeys'],'${{ inputs.ssh.target.host_keys }}')
                    step['task']['globalArgs']['sshBinary'] = str(fixture)
        (self.repo/'workflows/workflow-operation-tool-adopt-209.yaml').write_text(yaml.safe_dump(workflow,sort_keys=False))
        self.input = {'operation_id':1001,'node_id':9,'kind':'tool.adopt','execution_secrets':{},
                      'intent':{'node_id':9,'manager':'apt','package':'hello','platform':'linux','architecture':'x86_64','user':'orbit','version_constraint':None,'tool_id':None,'tool_status':None,'installed_version':None},
                      'ssh':{'control_node_id':209,'identity_file':str(self.own),'public_key':'ssh-ed25519 AAAA',
                             'target':{'name':'target','host':'node.invalid','user':'orbit','port':22,'host_keys':['ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIGaNG9xwGkyymXqkaSEKl6+u5VLBHPbi0ga0ya7sdCtG']}}}

    def run_adopt(self,success=True):
        self.cli('workflow','validate','operation-tool-adopt-209')
        run=json.loads(self.cli('workflow','run','operation-tool-adopt-209','--input',json.dumps(self.input),success=success).stdout)
        self.run_id=run['id']
        return json.loads(self.cli('workflow','history','get',self.run_id).stdout)

    def test_closed_registry_reads_through_official_ssh_and_returns_selected_ownership(self):
        for manager in ('apt','composer','vp','brew','brew-cask'):
            self.input['intent'].update(manager=manager,package='vendor/package' if manager=='composer' else 'hello',platform='macos' if manager=='brew-cask' else 'linux')
            history=self.run_adopt()
            self.assertEqual(history['status'],'succeeded')
            result=next(step for step in history['jobs'][0]['steps'] if step['name']=='result')['outputs']
            self.assertEqual(json.loads(result['stdout']),{'manager_version':'3.0.0','installed_version':'1.0.0','outcome':'applied'})
        calls=[json.loads(line) for line in self.audit.read_text().splitlines()]
        self.assertEqual(len(calls),5)
        self.assertTrue(all(row['user']=='orbit' for row in calls))

    def test_failed_query_resumes_same_root_without_repeating_asset_read(self):
        self.fail_marker.touch()
        history=self.run_adopt(success=False)
        self.assertEqual(history['status'],'failed')
        self.fail_marker.unlink()
        self.cli('workflow','resume','operation-tool-adopt-209','--run',self.run_id,'--from','query')
        history=json.loads(self.cli('workflow','history','get',self.run_id).stdout)
        self.assertEqual(history['status'],'succeeded')
        calls=[json.loads(line)['action'] for line in self.audit.read_text().splitlines()]
        self.assertEqual(calls,['query','query'])

    def test_constraint_refusal_fails_native_root_with_bounded_terminal_result(self):
        self.input['intent']['version_constraint']='^2.0'
        history=self.run_adopt(success=False)
        result=next(step for step in history['jobs'][0]['steps'] if step['name']=='result')['outputs']
        self.assertEqual(json.loads(result['stdout'])['error_code'],'tool.installed_version_constraint_violated')
        self.assertFalse(result['ok'])

    def test_repository_adoption_is_read_only_and_requires_matching_public_trust(self):
        self.input['intent']['repository'] = {'name':'vendor','uris':['https://packages.example.test/apt'],'suites':['preview'],'components':['main'],'signing_key':{'url':'https://packages.example.test/key.gpg','fingerprint':'A'*40}}
        self.assertEqual(self.run_adopt()['status'], 'succeeded')
        (self.repo/'repository-error').write_text('tool.repository_key_mismatch')
        history = self.run_adopt(False)
        result = next(step for step in history['jobs'][0]['steps'] if step['name']=='result')['outputs']
        self.assertEqual(json.loads(result['stdout'])['error_code'], 'tool.repository_key_mismatch')
        self.assertEqual(sum(json.loads(line)['action']=='repository_adopt' for line in self.audit.read_text().splitlines()), 2)


if __name__=='__main__':unittest.main()
