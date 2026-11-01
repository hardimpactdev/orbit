"""Pinned official SSH test seam shared by Database native DAG cases."""
import json
import shutil
import sys
from pathlib import Path
import yaml
from native_harness import NativeTestCase

ROOT = Path(__file__).resolve().parents[1]

def reply(raw):
    return json.JSONDecoder().raw_decode(raw.lstrip())[0]

class DatabaseCommands(NativeTestCase):
    native_timeout = 180

    def prepare_commands(self, names):
        shutil.copytree(ROOT / 'extensions/behaviors/operations/database-server-commands', self.repo / 'extensions/behaviors/operations/database-server-commands', ignore=shutil.ignore_patterns('__pycache__', '*.pyc'))
        target = self.repo / 'extensions/behaviors/operations/database'
        target.mkdir()
        for module in ('create_input.ts', 'destroy_input.ts'):
            shutil.copy(ROOT / 'extensions/behaviors/operations/database' / module, target)
        source = ROOT / '.swamp/pulled-extensions/@swamp/ssh'
        dest = self.repo / '.swamp/pulled-extensions/@swamp/ssh'
        dest.parent.mkdir(parents=True)
        shutil.copytree(source, dest)
        index = self.repo / 'extensions/models/upstream_extensions.json'
        index.parent.mkdir(parents=True)
        index.write_text(json.dumps({'@swamp/ssh': json.loads((ROOT / 'extensions/models/upstream_extensions.json').read_text())['@swamp/ssh']}))
        self.state = self.repo / 'state.json'
        self.calls_file = self.repo / 'calls.jsonl'
        self.calls_file.touch()
        self.state.write_text(json.dumps({'fail': '', 'code': 'database.server_admin_failed'}))
        fake = self.repo / 'fixture-ssh'
        fake.write_text('#!' + sys.executable + '\n' + r'''import json,os,shlex,sys
from pathlib import Path
if '-M' in sys.argv or '-O' in sys.argv:sys.exit(0)
args=shlex.split(sys.argv[-1]);scripts=[Path(a).stem for a in args if a.endswith('.py')]
if not scripts:sys.exit(0)
m=json.load(sys.stdin);assert m['password'] not in repr(args)
assert 'payload' not in m or m['payload']['password'] not in repr(args)
v=json.loads(Path(STATE).read_text());action=args[-4] if scripts[0]=='destroy' else scripts[0]
item=args[-2] if scripts[0]=='destroy' else json.loads(args[-1])
fd=os.open(CALLS,os.O_WRONLY|os.O_APPEND);os.write(fd,(json.dumps({'action':action,'item':item})+'\n').encode());os.close(fd)
if v.get('transport_fail')==action:sys.exit(255)
if v.get('empty_output')==action:sys.exit(0)
if v['fail']==action and (not v.get('fail_item') or v['fail_item']==item):print(json.dumps({'error_code':v['code']}));sys.exit(1)
print(json.dumps({'databases':v.get('inventory',[])} if action=='inventory' else {'ready':True}))
'''.replace('STATE', repr(str(self.state))).replace('CALLS', repr(str(self.calls_file))))
        fake.chmod(0o700)
        scp = self.repo / 'fixture-scp'
        scp.write_text('#!/bin/sh\nexit 0\n')
        scp.chmod(0o700)
        key = self.repo / 'fixture-key'
        key.write_text('fixture key')
        key.chmod(0o600)
        self.workflows = {}
        for name in names:
            value = yaml.safe_load((ROOT / 'workflows' / name).read_text())
            for job in value['jobs']:
                job.pop('target')
                self.assertNotIn('labels', job)
                for step in job['steps']:
                    self.assertEqual(step.pop('target'), 'orbit-node-209')
                    self.assertNotIn('labels', step)
                    if step['task'].get('behaviorType') == '@swamp/ssh':
                        args = step['task']['globalArgs']
                        args['sshBinary'] = str(fake)
                        args['scpBinary'] = str(scp)
                        args['transport']['identityFile'] = str(key)
            self.workflows[name] = value
        self.save()

    def save(self):
        for name, value in self.workflows.items():
            (self.repo / 'workflows' / name).write_text(yaml.safe_dump(value, sort_keys=False))

    def history(self, run):
        return reply(self.cli('workflow', 'history', 'get', run['id']).stdout)

    def outcome(self, run):
        step = next(s for s in self.history(run)['jobs'][-1]['steps'] if s['name'] == 'result')
        return json.loads(step['outputs']['stdout'])

    def calls(self):
        return [json.loads(line) for line in self.calls_file.read_text().splitlines()]

    def fail(self, action, code='database.server_admin_failed'):
        state = json.loads(self.state.read_text())
        state.update(fail=action, code=code)
        self.state.write_text(json.dumps(state))
