"""Actual official models with disposable SSH executable and snapshot seams."""
import json
import os
import sys
import unittest
from pathlib import Path

import yaml

ROOT = Path(__file__).resolve().parents[4]
sys.path.insert(0, str(ROOT / 'tests'))
from native_harness import NativeTestCase

KEY = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIAEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEB'


class DirectFirewallNative(NativeTestCase):
    def setUp(self):
        super().setUp()
        folder = self.repo / 'extensions/behaviors/operations/firewall'
        folder.mkdir(parents=True)
        source = (ROOT / 'extensions/behaviors/operations/firewall/mutation-result.ts').read_text()
        self.assertIn('${userInfo().homedir}/.ssh/known_hosts', source)
        (folder / 'mutation-result.ts').write_text(source.replace('${userInfo().homedir}/.ssh/known_hosts', './known_hosts'))
        self.cli('extension', 'pull', '@swamp/ssh@2026.10.23.1')
        self.cli('extension', 'pull', '@swamp/cfgmgmt@2026.10.27.1')
        self.state = self.repo / 'state.json'
        self.save({'active': True, 'rules': [], 'effects': []})
        (self.repo / 'known_hosts').write_text('10.44.0.9 ' + KEY + '\n')
        binary = self.repo / 'bin'
        binary.mkdir()
        fake = binary / 'ssh'
        fake.write_text('#!' + sys.executable + '\n' + '''import fcntl,json,os,shlex,sys
from pathlib import Path
if any(x in sys.argv for x in ['-O','-N','-MNf','-M']):raise SystemExit(0)
command=sys.argv[-1]
if '__swamp_sudo_ok__' in command:
 print('__swamp_sudo_ok__')
 command=shlex.split(command)[-1].split('exec </dev/null; ',1)[-1]
args=shlex.split(command)
while args and args[0] not in ('ufw','/usr/sbin/ufw'):args.pop(0)
if not args:raise SystemExit('unexpected command: '+command)
args=args[1:]
with Path(os.environ['FIREWALL_STATE']).open('r+') as file:
 fcntl.flock(file,fcntl.LOCK_EX);state=json.load(file)
 if args[0]=='status':
  print('Status: '+('active' if state['active'] else 'inactive'))
  if 'head' not in command:
   for i,row in enumerate(state['rules']):
    prefix=('['+str(i+1)+'] ') if 'numbered' in args else ''
    print(prefix+row['port']+'/'+row['protocol']+' '+row['action'].upper()+' IN '+('Anywhere' if row['source']=='any' else row['source'])+(' # '+row['comment'] if row.get('comment') else ''))
 else:
  if os.environ.get('FIREWALL_FAIL')=='1':raise SystemExit(19)
  state['effects'].append(args);remove='delete' in args
  args=[a for a in args if a not in ('--force','delete','in')];action=args.pop(0)
  if 'from' in args:source=args[args.index('from')+1];port=args[args.index('port')+1];protocol=args[args.index('proto')+1]
  else:source='any';port,protocol=args[0].split('/')
  row=dict(port=port,protocol=protocol,source=source,action=action)
  comment=args[args.index('comment')+1] if 'comment' in args else None
  matches=lambda r:all(r[k]==v for k,v in row.items()) and (comment is None or r.get('comment')==comment)
  if remove:
   for i,r in enumerate(state['rules']):
    if matches(r):state['rules'].pop(i);break
  elif not any(matches(r) for r in state['rules']) and os.environ.get('FIREWALL_LOST_WRITE')!='1':state['rules'].append(row)
  file.seek(0);json.dump(state,file);file.truncate();print('Rule updated')
''')
        fake.chmod(0o755)
        self.native_env = {'PATH': str(binary)+os.pathsep+os.environ['PATH'], 'FIREWALL_STATE': str(self.state)}
        workflow = yaml.safe_load((ROOT / 'workflows/workflow-operation-firewall-direct.yaml').read_text())
        for job in workflow['jobs']:
            for step in job['steps']:
                self.assertEqual(step.pop('target'), 'orbit-node-209')
                self.assertNotIn('labels', step)
        (self.repo / 'workflows/workflow-operation-firewall-direct.yaml').write_text(yaml.safe_dump(workflow))
        self.intent = {'ssh': {'identity_file': '/fixture/key', 'target': {'host': '10.44.0.9', 'user': 'orbit', 'port': 22, 'host_keys': [KEY]}}, 'rule': {'node_id': 9, 'name': 'canary', 'action': 'allow', 'source': 'any', 'port': '45678', 'protocol': 'tcp'}, 'survivors': []}
        self.configure()

    def configure(self, removing=False, ports=(45678,), retained=()):
        rule = self.intent['rule']
        self.kind = 'firewall.remove' if removing else 'firewall.'+rule['action']
        self.intent['ensure'] = 'absent' if removing else 'present'
        self.intent['tuples'] = [{'port': p, 'ensure': 'absent' if removing and p not in retained else 'present'} for p in ports]
        self.intent['legacy_remove'] = ['/usr/sbin/ufw', '--force', 'delete', rule['action'], 'in', 'from', rule['source'], 'to', 'any', 'port', rule['port'], 'proto', rule['protocol'], 'comment', 'orbit:node:9:firewall:canary']

    def save(self, value):
        self.state.write_text(json.dumps(value))

    def read(self):
        return json.loads(self.state.read_text())

    def run_root(self, success=True):
        self.cli('workflow', 'validate', 'operation-firewall-direct')
        completed = self.cli('workflow', 'run', 'operation-firewall-direct', '--input', 'operation_id=17', '--input', 'node_id=9', '--input', 'kind='+self.kind, '--input', 'intent='+json.dumps(self.intent), '--input', 'execution_secrets={}', success=success)
        history = json.loads(self.cli('workflow', 'history', 'get', json.loads(completed.stdout)['id']).stdout)
        if success:
            result = next(step for step in history['jobs'][0]['steps'] if step['name'] == 'result')
            self.assertEqual(json.loads(result['outputs']['stdout']), {'backend': 'absent' if self.intent['ensure'] == 'absent' else 'active'})
        return history

    def test_allow_is_official_and_idempotent(self):
        history = self.run_root()
        self.assertEqual(history['status'], 'succeeded')
        self.assertEqual(len(self.read()['effects']), 1)
        self.run_root()
        self.assertEqual(len(self.read()['effects']), 1)

    def test_deny_and_remove_are_official(self):
        self.intent['rule']['action'] = 'deny'
        self.configure();self.run_root()
        self.configure(removing=True);self.run_root()
        self.assertEqual(self.read()['rules'], [])

    def test_missing_or_wrong_published_pin_refuses_before_effect(self):
        for snapshot in ['', '10.44.0.8 '+KEY+'\n', '10.44.0.9 ssh-ed25519 wrong\n']:
            (self.repo / 'known_hosts').write_text(snapshot)
            self.run_root(success=False)
            self.assertEqual(self.read()['effects'], [])

    def test_explicit_source_uses_source_branch_for_allow_and_remove(self):
        self.intent['rule']['source'] = '192.0.2.0/24'
        self.configure();self.run_root()
        self.assertEqual(self.read()['rules'][0]['source'], '192.0.2.0/24')
        self.configure(removing=True);self.run_root()
        self.assertEqual(self.read()['rules'], [])

    def test_inactive_ufw_never_selects_another_backend(self):
        self.save({'active': False, 'rules': [], 'effects': []})
        self.run_root(success=False)
        self.assertEqual(self.read()['effects'], [])

    def test_failed_or_unobserved_write_cannot_succeed(self):
        self.native_env['FIREWALL_FAIL'] = '1';self.run_root(success=False)
        self.native_env.pop('FIREWALL_FAIL');self.native_env['FIREWALL_LOST_WRITE'] = '1'
        self.run_root(success=False)
        self.assertEqual(self.read()['rules'], [])

    def test_range_removal_preserves_shared_port_and_unrelated_rules(self):
        self.intent['rule']['port'] = '45678:45680'
        self.save({'active': True, 'effects': [], 'rules': [{'port': '45678:45680', 'protocol': 'tcp', 'source': 'any', 'action': 'allow', 'comment': 'orbit:node:9:firewall:canary'}, {'port': '443', 'protocol': 'tcp', 'source': 'any', 'action': 'allow', 'comment': 'operator'}]})
        self.configure(removing=True, ports=(45678,45679,45680), retained=(45679,))
        self.run_root()
        self.assertEqual({r['port'] for r in self.read()['rules']}, {'443', '45679'})
        self.assertEqual(self.read()['rules'][0]['comment'], 'operator')


if __name__ == '__main__':
    unittest.main()
