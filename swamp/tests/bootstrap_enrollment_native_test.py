"""Run the bootstrap composition with unmodified official SSH and a harmless binary seam."""

import base64
import hashlib
import json
import os
import shutil
import subprocess
import sys

import yaml
from native_harness import ROOT, NativeTestCase


class EnrollmentBootstrapNative(NativeTestCase):
    native_timeout = 120

    def setUp(self):
        super().setUp()
        self.copy_behaviors('operations/node-bootstrap')
        self.cli('extension', 'pull', '@swamp/ssh@2026.10.23.1')
        identity = self.repo / 'identity'
        subprocess.run(['ssh-keygen', '-q', '-t', 'ed25519', '-N', '', '-f', str(identity)], check=True)
        self.public_key = identity.with_suffix('.pub').read_text().strip()
        self.fingerprint = 'SHA256:' + base64.b64encode(
            hashlib.sha256(base64.b64decode(self.public_key.split()[1])).digest()).decode().rstrip('=')
        calls = self.repo / 'calls.jsonl'
        fixture = self.repo / 'ssh-fixture'
        fixture.write_text('#!' + sys.executable + '\n' + '''
import ast,json,sys
from pathlib import Path
argv=sys.argv[1:]
body=sys.stdin.read()
with Path(CALLS).open('a') as out: out.write(json.dumps({'argv':argv,'stdin':body})+'\\n')
if '-O' in argv or '-N' in argv or '-MNf' in argv: raise SystemExit(0)
command=argv[-1]
if 'uname' in command: print('Linux x86_64')
elif 'cat ' in command: print(PUBLIC_KEY)
else:
    tree=ast.parse(body)
    assignment=next(node for node in tree.body if isinstance(node,ast.Assign)
                    and isinstance(node.targets[0],ast.Name) and node.targets[0].id=='BOOTSTRAP_INPUT')
    data=json.loads(ast.literal_eval(assignment.value.args[0]))
    if 'Read the candidate account' in body:
        print(json.dumps({'bootstrap_verified':True,'account':{'user':data['user'],'group':'orbit','home':'/home/orbit'}}))
    else: print(json.dumps({'completed':True}))
'''.replace('CALLS', repr(str(calls))).replace('PUBLIC_KEY', repr(self.public_key)))
        fixture.chmod(0o700)
        source = (ROOT / 'extensions/behaviors/operations/node-bootstrap/command_steps.ts').as_uri()
        generator = ('import {bootstrapCommandSteps} from ' + json.dumps(source) + ';' +
                     'console.log(JSON.stringify(bootstrapCommandSteps("bootstrap","inputs.bootstrap",' +
                     json.dumps(json.dumps(str(identity))) + ')));')
        deno = os.environ.get('DENO_BIN', shutil.which('deno') or '/tmp/lane4-deno-2.9.8/deno')
        result = subprocess.run([deno, 'eval', generator], capture_output=True, text=True, check=True)
        steps = json.loads(result.stdout)
        for step in steps:
            self.assertEqual(step.pop('target'), 'orbit-node-209')
            self.assertEqual(step.pop('labels'), {'node': '209'})
            task = step['task']
            if task['behaviorType'] == '@swamp/ssh':
                globals_ = task['globalArgs']
                globals_['sshBinary'] = str(fixture)
                globals_['transport']['identityFile'] = str(identity)
        workflow = {'id': '69b6306c-71c0-4df9-ac3f-fd6a5d7fb0c5', 'name': 'bootstrap-enrollment-fixture',
                    'inputs': {'type': 'object', 'properties': {'operation_id': {'type': 'integer'},
                               'node_id': {'type': 'integer'}, 'bootstrap': {'type': 'object'}},
                               'required': ['operation_id', 'node_id', 'bootstrap']},
                    'jobs': [{'name': 'enrollment', 'steps': steps}]}
        (self.repo / 'workflows/workflow-bootstrap-enrollment-fixture.yaml').write_text(yaml.safe_dump(workflow, sort_keys=False))
        self.calls = calls
        self.input = {'operation_id': 42, 'node_id': 14,
                      'bootstrap': {'node_id': 209, 'platform': 'linux', 'expected_architecture': 'x86_64',
                          'target': {'host': 'dev.invalid', 'port': 22, 'user': 'root',
                                     'fingerprint': self.fingerprint, 'host_keys': []},
                          'managed_user': 'orbit', 'artifacts': {'linux-x86_64': {
                              'url': 'https://example.test/observer', 'sha256': 'a' * 64}},
                          'command': {'user': 'orbit', 'public_key': 'ssh-ed25519 AAAA managed',
                                      'packages': ['sudo'], 'releases': ['resolute'], 'dns_policy': 'preserve'}}}

    def test_official_first_contact_pins_all_mutations_and_verifies_the_candidate(self):
        self.cli('workflow', 'validate', 'bootstrap-enrollment-fixture')
        result = json.loads(self.cli('workflow', 'run', 'bootstrap-enrollment-fixture',
                                     '--input', json.dumps(self.input)).stdout)
        self.assertEqual(result['status'], 'succeeded')
        history = json.loads(self.cli('workflow', 'history', 'get', result['id']).stdout)
        steps = history['jobs'][0]['steps']
        self.assertEqual(len(steps), 15)
        self.assertTrue(all(step['status'] == 'succeeded' for step in steps))
        observed = next(step['outputs'] for step in steps if step['name'] == 'bootstrap_observed')
        self.assertEqual(observed['host_keys'], [self.public_key])
        verified = json.loads(steps[-1]['outputs']['stdout'])
        self.assertTrue(verified['bootstrap_verified'])
        self.assertEqual(verified['account']['user'], 'orbit')
        calls = [json.loads(line) for line in self.calls.read_text().splitlines()]
        scripts = [row for row in calls if 'BOOTSTRAP_INPUT' in row['stdin']]
        self.assertEqual(len(scripts), 6)
        self.assertTrue(all('StrictHostKeyChecking=yes' in row['argv'] for row in scripts))
        self.assertTrue(all('BOOTSTRAP_INPUT' not in ' '.join(row['argv']) for row in scripts))
        self.assertIn('orbit@dev.invalid', scripts[-1]['argv'])

    def test_fingerprint_mismatch_stops_before_any_mutation(self):
        self.input['bootstrap']['target']['fingerprint'] = 'SHA256:' + 'A' * 43
        result = json.loads(self.cli('workflow', 'run', 'bootstrap-enrollment-fixture',
                                     '--input', json.dumps(self.input), success=False).stdout)
        steps = {step['name']: step for step in result['jobs'][0]['steps']}
        self.assertEqual(steps['bootstrap_observed']['status'], 'failed')
        self.assertEqual(steps['bootstrap_packages_assets']['status'], 'skipped')
        calls = [json.loads(line) for line in self.calls.read_text().splitlines()]
        self.assertFalse(any('BOOTSTRAP_INPUT' in row['stdin'] for row in calls))
