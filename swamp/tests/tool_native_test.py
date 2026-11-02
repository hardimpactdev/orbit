"""Native Tool graphs: official SSH argv and cfgmgmt resources, with effects held at the transport boundary."""
import json
import re
import sys
import unittest

import yaml
from native_harness import ROOT, NativeTestCase

SSH = {'identity_file': '/fixture', 'target': {'host': 'node.invalid', 'port': 22, 'user': 'orbit', 'host_keys': ['ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIGaNG9xwGkyymXqkaSEKl6+u5VLBHPbi0ga0ya7sdCtG']}}
TRANSPORT = r'''
import json, shlex, sys
from pathlib import Path
p=Path(STATE); state=json.loads(p.read_text()); argv=shlex.split(sys.argv[-1])
with Path(AUDIT).open('a') as out: out.write(json.dumps(argv)+'\n')
code=0
if 'gpg' in argv:
    print('pub:::::::::\nfpr:::::::::'+('B'*40 if state.get('bad_key') else 'A'*40)+':')
elif '/usr/bin/dpkg-query' in argv:
    if state.get('installed'): print('hello\tinstall ok installed\t'+state['installed'])
    else: sys.stderr.write('dpkg-query: no packages found matching hello\n'); code=1
elif '/usr/bin/apt-cache' in argv: print('hello:\n  Candidate: '+state['candidate'])
elif '--simulate' in argv:
    print('Remv hello [1.0]\n'+('Remv other [1.0]\n' if state.get('unsafe') else ''))
elif 'install' in argv:
    if state.get('no_write'): pass
    elif state.get('fail_write'): code=1
    else: state['installed']=argv[-1].split('=')[1]
elif '--remove' in argv: state['installed']=None
elif '--version' in argv: print('apt 3.0')
p.write_text(json.dumps(state)); sys.exit(code)
'''
CONFIG = '''import { z } from "npm:zod@4.4.3";
export const behavior = {
 type:"@fixture/config", version:"2026.11.02.1", globalArguments:z.record(z.string(),z.unknown()),
 resources:{state:{schema:z.record(z.string(),z.unknown()),lifetime:"1d",garbageCollection:10}},
 methods:Object.fromEntries(["check","apply"].map(method=>[method,{description:"Transport fixture",arguments:z.object({}),execute:async(_,ctx)=>{
 const g=ctx.globalArgs, s=JSON.parse(Deno.readTextFileSync(STATE));
 const installed=Boolean(s.installed), compliant=g.ensure==="absent" ? !installed : installed;
 const value={status:compliant?"compliant":"non_compliant",packages:g.packages ? [{name:g.packages[0],installed,version:s.installed}] : []};
 return {dataHandles:[await ctx.writeResource("state",g.nodeHost,value)]}; }}]))
};'''


class ToolNative(NativeTestCase):
    native_timeout = 120

    def setUp(self):
        super().setUp()
        self.copy_behaviors('operations/tools', 'operations/node-bootstrap')
        self.cli('extension', 'pull', '@swamp/ssh@2026.10.23.1')
        self.state, self.audit = self.repo / 'state.json', self.repo / 'audit.jsonl'
        fixture = self.repo / 'fixture-ssh'
        fixture.write_text('#!' + sys.executable + '\n' + TRANSPORT.replace('STATE', repr(str(self.state))).replace('AUDIT', repr(str(self.audit))))
        fixture.chmod(0o700)
        (self.repo / 'extensions/behaviors/fixture.ts').write_text(CONFIG.replace('STATE', json.dumps(str(self.state))))
        for name in ('write-209', 'remove-209'):
            path = f'workflows/workflow-operation-tool-{name}.yaml'
            workflow = yaml.safe_load((ROOT / path).read_text())
            for step in workflow['jobs'][0]['steps']:
                self.assertEqual(step.pop('labels'), {'role': 'swamp'})
                task = step['task']
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
        decision = steps.get('decide', {}).get('outputs', {})
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

    def test_a_successful_command_without_the_package_fails_the_native_end_assert(self):
        run, _ = self.run_kind(success=False, state={'no_write': True})
        steps = {step['name']: step for step in run['jobs'][0]['steps']}
        self.assertEqual(steps['Install the package']['status'], 'succeeded')
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
        run, result = self.run_kind(repository=repository, repository_given=True)
        self.assertEqual(result['outcome'], 'applied')
        self.assertEqual(next(s for s in run['jobs'][0]['steps'] if s['name']=='Add the signed apt source')['status'], 'succeeded')

    def test_mismatched_key_fails_native_assert_before_publishing(self):
        repository = {'name':'vendor','uris':['https://packages.example.test/apt'],'suites':['stable'],'components':['main'],'signing_key':{'url':'https://packages.example.test/key.asc','fingerprint':'A'*40}}
        run, result = self.run_kind(success=False, state={'bad_key':True}, repository=repository, repository_given=True)
        self.assertEqual(result['error_code'], 'tool.repository_key_mismatch')
        self.assertIsNone(json.loads(self.state.read_text())['installed'])
        self.assertEqual(next(s for s in run['jobs'][0]['steps'] if s['name']=='Publish the signing key')['status'], 'skipped')


if __name__ == '__main__': unittest.main()
