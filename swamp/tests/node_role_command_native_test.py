"""Native role prerequisite composition; official SSH uses its documented binary test seam."""
import json
import os
import shutil
import subprocess
import sys

import yaml
from native_harness import ROOT, NativeTestCase


class RoleCommandNative(NativeTestCase):
    native_timeout = 120

    def setUp(self):
        super().setUp()
        self.copy_behaviors('operations/node-bootstrap', 'operations/node-role-command')
        self.cli('extension', 'pull', '@swamp/ssh@2026.10.23.1')
        self.audit = self.repo / 'calls.jsonl'
        binary = self.repo / 'fixture-ssh'
        binary.write_text('#!' + sys.executable + '''
import ast,json,sys
from pathlib import Path
command=' '.join(sys.argv[1:])
body=sys.stdin.read()
if 'getent' in command or '-gn' in command:
    action='account' if 'getent' in command else 'group'
    with Path(AUDIT).open('a') as stream: stream.write(json.dumps({'action':action})+'\\n')
    print('orbit:x:1000:1000::/home/orbit:/bin/bash' if action=='account' else 'orbit')
    raise SystemExit(0)
tree=ast.parse(body)
fields={node.targets[0].id:ast.literal_eval(node.value.args[0]) for node in tree.body
        if isinstance(node,ast.Assign) and isinstance(node.targets[0],ast.Name)
        and node.targets[0].id in ('ROLE_INPUT','BOOTSTRAP_INPUT')}
data=json.loads(next(iter(fields.values())))
action='authorized' if 'public_key' in data else 'packages'
with Path(AUDIT).open('a') as stream: stream.write(json.dumps({'action':action,'data':data})+'\\n')
result={'packages':{'packages_installed':True},'authorized':{'authorized':True,'changed':False}}[action]
print(json.dumps(result))
'''.replace('AUDIT', repr(str(self.audit))))
        binary.chmod(0o700)
        identity = self.repo / 'identity'
        identity.write_text('synthetic unused identity')
        globals_ = {'name': 'role-prerequisite-fixture', 'sshBinary': str(binary),
            'transport': {'kind': 'ssh', 'user': 'orbit', 'identityFile': str(identity),
                'hostKeys': ['ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIGaNG9xwGkyymXqkaSEKl6+u5VLBHPbi0ga0ya7sdCtG']},
            'hosts': [{'name': 'target', 'address': 'dev.invalid'}]}
        self.globals = globals_
        source = (ROOT / 'extensions/behaviors/operations/node-role-command/steps.ts').as_uri()
        program = ('import {roleAccountSteps as account,rolePackageSteps as packages} from ' + json.dumps(source) + ';' +
            'const execution=' + json.dumps(globals_) + ';console.log(JSON.stringify([' +
            '...account("account","inputs.user",execution),' +
            '...packages("packages","inputs.prerequisites",execution,"account")]));')
        deno = os.environ.get('DENO_BIN', shutil.which('deno') or '/tmp/lane4-deno-2.9.8/deno')
        steps = json.loads(subprocess.run([deno, 'eval', program], check=True, capture_output=True, text=True).stdout)
        for step in steps:
            self.assertEqual(step.pop('labels'), {'role': 'swamp'})
        workflow = {'id': '9444280d-02bc-4cfa-b3b1-9538b840b3de', 'name': 'role-prerequisite-fixture',
                    'inputs': {'type': 'object'}, 'jobs': [{'name': 'role', 'steps': steps}]}
        (self.repo / 'workflows/workflow-role-prerequisite-fixture.yaml').write_text(yaml.safe_dump(workflow, sort_keys=False))
        self.input = {'user': 'orbit', 'prerequisites': {'packages': ['openssl'], 'releases': ['resolute']}}

    def test_account_is_read_with_two_commands_before_packages(self):
        self.cli('workflow', 'validate', 'role-prerequisite-fixture')
        run = json.loads(self.cli('workflow', 'run', 'role-prerequisite-fixture', '--input', json.dumps(self.input)).stdout)
        history = json.loads(self.cli('workflow', 'history', 'get', run['id']).stdout)
        self.assertEqual(history['status'], 'succeeded')
        steps = {step['name']: step for step in history['jobs'][0]['steps']}
        self.assertEqual(steps['account']['outputs'], {'user': 'orbit', 'group': 'orbit', 'home': '/home/orbit'})
        calls = [json.loads(row) for row in self.audit.read_text().splitlines()]
        self.assertEqual([row['action'] for row in calls], ['account', 'group', 'packages'])
        self.assertEqual(calls[-1]['data'], {'packages': ['openssl'], 'releases': ['resolute']})

    def test_tracked_authorized_key_action_uses_the_admitted_public_key(self):
        source = (ROOT / 'extensions/behaviors/operations/node-role-command/steps.ts').as_uri()
        program = ('import {authorizedKeySteps} from ' + json.dumps(source) + ';console.log(JSON.stringify(' +
                   'authorizedKeySteps("authorize","inputs.user","inputs.public_key",' + json.dumps(self.globals) + ')));')
        deno = os.environ.get('DENO_BIN', shutil.which('deno') or '/tmp/lane4-deno-2.9.8/deno')
        steps = json.loads(subprocess.run([deno, 'eval', program], capture_output=True, text=True, check=True).stdout)
        for step in steps:
            self.assertEqual(step.pop('labels'), {'role': 'swamp'})
        workflow = {'id': 'af4cba59-b8aa-4fe9-b0c2-d08a28536de5', 'name': 'tracked-key-fixture',
                    'inputs': {'type': 'object'}, 'jobs': [{'name': 'key', 'steps': steps}]}
        (self.repo / 'workflows/workflow-tracked-key-fixture.yaml').write_text(yaml.safe_dump(workflow, sort_keys=False))
        public_key = 'ssh-ed25519 AAAA public-tracked-key'
        self.cli('workflow', 'validate', 'tracked-key-fixture')
        run = json.loads(self.cli('workflow', 'run', 'tracked-key-fixture', '--input',
                                 json.dumps({'user': 'orbit', 'public_key': public_key})).stdout)
        self.assertEqual(run['status'], 'succeeded')
        calls = [json.loads(row) for row in self.audit.read_text().splitlines()]
        self.assertEqual(calls, [{'action': 'authorized', 'data': {'user': 'orbit', 'public_key': public_key}}])
