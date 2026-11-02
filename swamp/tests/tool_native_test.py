"""Native Tool graphs: official SSH argv and cfgmgmt resources, with effects held at the transport boundary."""
import json
import os
import re
import sys
import unittest

import yaml
from native_harness import ROOT, NativeTestCase

SSH = {'identity_file': '/fixture', 'target': {'host': 'node.invalid', 'port': 22, 'user': 'orbit', 'host_keys': ['ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIGaNG9xwGkyymXqkaSEKl6+u5VLBHPbi0ga0ya7sdCtG']}}
TRANSPORT = r'''
import json, os, shlex, sys
from pathlib import Path
p=Path(STATE); state=json.loads(p.read_text()); argv=shlex.split(sys.argv[-1])
with Path(AUDIT).open('a') as out: out.write(json.dumps(argv)+'\n')
code=0; changed=False
if any(part.endswith('/bin/brew') for part in argv):
    if 'info' in argv:
        key='a'*64
        print(json.dumps({'formulae':[{'name':'hello','full_name':'hello','tap':'homebrew/core','disabled':False,'installed':[{'version':state['installed'],'installed_on_request':True}] if state.get('installed') else [],'versions':{'stable':'2.0','bottle':True},'bottle':{'stable':{'files':{'x86_64_linux':{'sha256':key,'url':'https://ghcr.io/v2/homebrew/core/hello/sha256:'+key}}}}}]}))
    elif 'uninstall' in argv: state['installed']=None; changed=True
    elif 'install' in argv or 'upgrade' in argv: state['installed']='2.0'; changed=True
    elif '--version' in argv: print('Homebrew 4.0')
elif '/usr/bin/dpkg-query' in argv:
    if state.get('installed'): print('hello\tinstall ok installed\t'+state['installed'])
    else: sys.stderr.write('dpkg-query: no packages found matching hello\n'); code=1
elif '/usr/bin/apt-cache' in argv:
    if not state.get('requires_refresh') or state.get('refreshed'): print('hello:\n  Candidate: '+state['candidate'])
elif '/usr/bin/apt-get' in argv and 'update' in argv: state['refreshed']=True; changed=True
elif '--simulate' in argv:
    print('Remv hello [1.0]\n'+('Remv other [1.0]\n' if state.get('unsafe') else ''))
elif 'install' in argv:
    changed=True
    if state.get('no_write'): pass
    elif state.get('fail_write'): code=1
    else: state['installed']=argv[-1].split('=')[1]
elif '--remove' in argv: state['installed']=None; changed=True
elif '--version' in argv: print('apt 3.0')
if changed:
    pending=p.with_suffix('.'+str(os.getpid())); pending.write_text(json.dumps(state)); pending.replace(p)
sys.exit(code)
'''
WORKER = r'''
import json, os, sys
from pathlib import Path
state=json.loads(Path(STATE).read_text())
key=Path(os.environ['ORBIT_KEY_FILE'])
if Path(sys.argv[0]).name == 'curl':
    key.parent.mkdir(parents=True,exist_ok=True); key.with_suffix('.asc').write_text('fixture key')
elif '--show-keys' in sys.argv:
    print('pub:::::::::\nfpr:::::::::'+('B'*40 if state.get('bad_key') else 'A'*40)+':')
else: key.with_suffix('.gpg').write_bytes(b'\x99fixture binary key')
'''
CONFIG = '''import { z } from "npm:zod@4.4.3";
export const behavior = {
 type:"@fixture/config", version:"2026.11.02.1", globalArguments:z.record(z.string(),z.unknown()),
 resources:{state:{schema:z.record(z.string(),z.unknown()),lifetime:"1d",garbageCollection:10}},
 methods:Object.fromEntries(["check","apply","refresh"].map(method=>[method,{description:"Transport fixture",arguments:z.object({}),execute:async(_,ctx)=>{
 const g=ctx.globalArgs, s=JSON.parse(Deno.readTextFileSync(STATE));
 Deno.writeTextFileSync(AUDIT,JSON.stringify(["cfgmgmt",g.path ?? "package"])+"\\n",{append:true});
 const installed=Boolean(s.installed), compliant=g.ensure==="absent" ? !installed : installed;
 const value={status:compliant?"compliant":"non_compliant",packages:g.packages ? [{name:g.packages[0],installed,version:s.installed}] : []};
 return {dataHandles:[await ctx.writeResource("state",g.nodeHost,value)]}; }}]))
};'''


class ToolNative(NativeTestCase):
    native_timeout = 120

    def setUp(self):
        NativeTestCase.setUp(self)
        self.copy_behaviors('operations/tools', 'operations/node-bootstrap')
        self.cli('extension', 'pull', '@swamp/ssh@2026.10.23.1')
        self.state, self.audit = self.repo / 'state.json', self.repo / 'audit.jsonl'
        fixture = self.repo / 'fixture-ssh'
        fixture.write_text('#!' + sys.executable + '\n' + TRANSPORT.replace('STATE', repr(str(self.state))).replace('AUDIT', repr(str(self.audit))))
        fixture.chmod(0o700)
        worker = self.repo / 'worker-bin'
        worker.mkdir()
        for name in ('curl', 'gpg'):
            command = worker / name
            command.write_text('#!' + sys.executable + '\n' + WORKER.replace('STATE', repr(str(self.state))))
            command.chmod(0o700)
        self.native_env['PATH'] = str(worker) + ':' + os.environ['PATH']
        (self.repo / 'extensions/behaviors/fixture.ts').write_text(CONFIG.replace('STATE', json.dumps(str(self.state))).replace('AUDIT', json.dumps(str(self.audit))))
        for name in ('write-209', 'remove-209'):
            path = f'workflows/workflow-operation-tool-{name}.yaml'
            workflow = yaml.safe_load((ROOT / path).read_text().replace('/tmp/orbit-tool-keys/', str(self.repo / 'worker-keys') + '/'))
            for step in workflow['jobs'][0]['steps']:
                self.assertEqual(step.pop('labels'), {'role': 'swamp'})
                task = step['task']
                if step['name'] == 'Set up the Tool Manager': self.assertFalse(task['inputs']['report'])
                if task.get('type') != 'behavior': continue
                task['behaviorType'] = re.sub(r'@swamp/cfgmgmt/[a-z_]+', '@fixture/config', task['behaviorType'])
                if task['behaviorType'].startswith('@swamp/cfgmgmt/'): task['behaviorType'] = '@fixture/config'
                if 'globalArgs' in task:
                    g = task['globalArgs']
                    if isinstance(g, str): task['globalArgs'] = g.replace('"transport":', '"sshBinary":'+json.dumps(str(fixture))+',"transport":')
                    elif 'transport' in g: g['sshBinary'] = str(fixture)
            (self.repo / path).write_text(yaml.safe_dump(workflow, sort_keys=False))

    def run_kind(self, kind='tool.install', success=True, state=None, **intent):
        self.state.write_text(json.dumps({'installed': None, 'candidate': '2.0-1', **(state or {})}))
        inputs = {'operation_id': 1, 'node_id': 9, 'kind': kind, 'execution_secrets': {}, 'ssh': SSH,
                  'intent': {'kind': kind, 'node_id': 9, 'manager': 'apt', 'package': 'hello', 'user': 'orbit', 'platform': 'linux', 'architecture': 'x86_64', 'tool_id': None, 'setup': None, 'repository': None, 'repository_given': False, 'remove_repository': False, 'manager_ready': True, **intent}}
        name = 'operation-tool-' + ('remove' if kind == 'tool.remove' else 'write') + '-209'
        self.cli('workflow', 'validate', name)
        run = json.loads(self.cli('workflow', 'run', name, '--stdin', input=json.dumps(inputs), success=success).stdout)
        run = json.loads(self.cli('workflow', 'history', 'get', run['id']).stdout)
        steps = {s['name']: s for s in run['jobs'][0]['steps']}
        self.assertNotIn('result', steps)
        decision = steps.get('Choose package action', {}).get('outputs', {})
        observed = steps.get('Decode package state', {}).get('outputs', {})
        result = {**decision, **observed}
        if kind == 'tool.remove' and run['status'] == 'succeeded': result['outcome'] = 'applied'
        if steps.get('Assert the key fingerprint', {}).get('status') == 'failed': result['error_code'] = 'tool.repository_key_mismatch'
        return run, result

    def test_install_uses_native_argv_and_records_the_checked_version(self):
        run, result = self.run_kind()
        self.assertEqual((run['status'], result['installed_version']), ('succeeded', '2.0-1'))
        calls = [json.loads(line) for line in self.audit.read_text().splitlines()]
        self.assertTrue(any('--no-remove' in argv and 'DPkg::Lock::Timeout=300' in argv for argv in calls))
        self.assertFalse(any('-c' in argv or any('.py' in part for part in argv) for argv in calls))

    def test_homebrew_shares_the_native_package_write_and_remove_paths(self):
        for kind, installed in [('tool.install', None), ('tool.remove', '2.0')]:
            with self.subTest(kind=kind):
                run, result = self.run_kind(kind, state={'installed': installed}, manager='brew', installed_version=installed)
                self.assertEqual(run['status'], 'succeeded')
                self.assertEqual(json.loads(self.state.read_text())['installed'], '2.0' if kind == 'tool.install' else None)
                self.assertEqual(result['outcome'], 'applied')

    def test_a_successful_command_without_the_package_fails_the_native_end_assert(self):
        run, _ = self.run_kind(success=False, state={'no_write': True})
        steps = {step['name']: step for step in run['jobs'][0]['steps']}
        self.assertEqual(steps['Apply the package']['status'], 'succeeded')
        self.assertEqual(steps['Assert package state']['status'], 'failed')
        self.assertIsNone(json.loads(self.state.read_text())['installed'])

    def test_constraint_refusal_writes_nothing(self):
        _, result = self.run_kind(success=False, version_constraint='^1.0')
        self.assertEqual(result['error_code'], 'tool.version_constraint_blocked')
        self.assertIsNone(json.loads(self.state.read_text())['installed'])

    def test_update_skips_a_current_or_blocked_candidate(self):
        for constraint, candidate, outcome in [(None, '1.0', 'unchanged'), ('^1.0', '2.0', 'blocked_by_constraint')]:
            _, result = self.run_kind('tool.update', state={'installed': '1.0', 'candidate': candidate}, installed_version='1.0', tool_id=1, version_constraint=constraint)
            self.assertEqual(result['outcome'], outcome)
            self.assertEqual(json.loads(self.state.read_text())['installed'], '1.0')

    def test_remove_refuses_a_wide_plan_and_proves_absence(self):
        _, result = self.run_kind('tool.remove', state={'installed': '1.0'}, installed_version='1.0', tool_id=1)
        self.assertEqual(result['outcome'], 'applied')
        self.assertIsNone(json.loads(self.state.read_text())['installed'])
        _, result = self.run_kind('tool.remove', success=False, state={'installed': '1.0', 'unsafe': True}, installed_version='1.0', tool_id=1)
        self.assertEqual(result['error_code'], 'tool.removal_plan_unsafe')
        self.assertEqual(json.loads(self.state.read_text())['installed'], '1.0')

    def test_verified_key_reaches_native_source_publication(self):
        repository = {'name':'vendor','uris':['https://packages.example.test/apt'],'suites':['stable'],'components':['main'],'signing_key':{'url':'https://packages.example.test/key.asc','fingerprint':'A'*40}}
        run, result = self.run_kind(state={'requires_refresh': True}, repository=repository, repository_given=True)
        self.assertEqual(result['outcome'], 'applied')
        self.assertEqual(next(s for s in run['jobs'][0]['steps'] if s['name']=='Write the apt source')['status'], 'succeeded')
        self.assertTrue(json.loads(self.state.read_text())['refreshed'])

    def test_mismatched_key_fails_native_assert_before_publishing(self):
        repository = {'name':'vendor','uris':['https://packages.example.test/apt'],'suites':['stable'],'components':['main'],'signing_key':{'url':'https://packages.example.test/key.asc','fingerprint':'A'*40}}
        run, result = self.run_kind(success=False, state={'bad_key':True}, repository=repository, repository_given=True)
        self.assertEqual(result['error_code'], 'tool.repository_key_mismatch')
        self.assertIsNone(json.loads(self.state.read_text())['installed'])
        self.assertEqual(next(s for s in run['jobs'][0]['steps'] if s['name']=='Publish the signing key')['status'], 'skipped')
        self.assertFalse(self.audit.exists(), 'A mismatched key must not contact the Node, even for cleanup or manager setup')
        self.assertFalse(list((self.repo / 'worker-keys').glob('*')), 'Local staged keys must be cleaned after refusal')


if __name__ == '__main__': unittest.main()


class ToolManagerNative(NativeTestCase):
    setUp = ToolNative.setUp
    def run_manager(self, missing=False, fail=False):
        self.state.write_text(json.dumps({'installed': None, 'candidate': '2.0', 'manager_missing': missing, 'fail_setup': fail}))
        workflow = yaml.safe_load((ROOT / 'workflows/workflow-operation-tool-manager-setup.yaml').read_text())
        for step in workflow['jobs'][0]['steps']:
            step.pop('labels', None)
            task = step['task']
            if task.get('behaviorType') == '@swamp/ssh':
                task['globalArgs']['sshBinary'] = str(self.repo / 'manager-ssh')
            elif task.get('behaviorType', '').startswith('@swamp/cfgmgmt/'):
                task['behaviorType'] = '@fixture/config'
        command = self.repo / 'manager-ssh'
        code = TRANSPORT.replace("if any(part.endswith('/bin/brew') for part in argv):", "if '--version' in argv and state.get('manager_missing'):\n    code=127\nelif '/usr/bin/python3' in argv:\n    code=1 if state.get('fail_setup') else 0\n    if code==0: state['manager_missing']=False; changed=True\nelif any(part.endswith('/bin/brew') for part in argv):")
        command.write_text('#!' + sys.executable + '\n' + code.replace('STATE', repr(str(self.state))).replace('AUDIT', repr(str(self.audit))))
        command.chmod(0o700)
        (self.repo / 'workflows/workflow-operation-tool-manager-setup.yaml').write_text(yaml.safe_dump(workflow, sort_keys=False))
        inputs = {'operation_id': 1, 'node_id': 9, 'ssh': SSH, 'intent': {'manager': 'brew', 'user': 'orbit', 'platform': 'linux', 'architecture': 'x86_64',
                  'setup': {'manager': 'homebrew', 'input': {'user': 'orbit', 'revision': 'a'*40, 'version': '4.0.0'}, 'apt': None, 'packages': []}}}
        self.cli('workflow', 'validate', workflow['name'])
        run = json.loads(self.cli('workflow', 'run', workflow['name'], '--stdin', input=json.dumps(inputs), success=not fail).stdout)
        return json.loads(self.cli('workflow', 'history', 'get', run['id']).stdout)

    def test_ready_manager_runs_only_one_native_version_command(self):
        self.assertEqual(self.run_manager()['status'], 'succeeded')
        calls = [json.loads(line) for line in self.audit.read_text().splitlines()]
        self.assertEqual(len(calls), 1)
        self.assertIn('--version', calls[0])

    def test_first_use_runs_the_pinned_installer_then_checks_readiness(self):
        self.assertEqual(self.run_manager(missing=True)['status'], 'succeeded')
        self.assertFalse(json.loads(self.state.read_text())['manager_missing'])
        calls = [json.loads(line) for line in self.audit.read_text().splitlines()]
        self.assertEqual(len(calls), 3)
        self.assertIn('/usr/bin/python3', calls[1])
        self.assertIn('--version', calls[2])

    def test_failed_pinned_install_fails_without_a_readiness_retry(self):
        self.assertEqual(self.run_manager(missing=True, fail=True)['status'], 'failed')
        self.assertTrue(json.loads(self.state.read_text())['manager_missing'])
