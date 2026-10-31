"""Official SSH key publication followed by own-key verification on native e459."""
import json
import subprocess
import sys

import yaml
from native_harness import ROOT, NativeTestCase


class SshAccessNative(NativeTestCase):
    native_timeout = 120

    def setUp(self):
        super().setUp()
        self.copy_behaviors('operations/node-bootstrap')
        self.cli('extension', 'pull', '@swamp/ssh@2026.10.23.1')
        private = self.repo / 'bootstrap-key'
        subprocess.run(['ssh-keygen', '-q', '-t', 'ed25519', '-N', '', '-f', str(private)], check=True)
        value = private.read_text()
        private.unlink()
        self.secrets = (value,)
        self.cli('vault', 'create', 'local_encryption', 'orbit-control-inputs')
        self.cli('vault', 'put', 'orbit-control-inputs', 'bootstrap-identity-node-14', input=value)
        self.audit = self.repo / 'calls.jsonl'
        self.failure = self.repo / 'fail-own-key'
        self.own = self.repo / 'own-identity'
        self.own.write_text('synthetic own identity file')
        self.own.chmod(0o600)
        fixture = self.repo / 'fixture-ssh'
        fixture.write_text('#!' + sys.executable + '\n' + '''
import ast,json,os,sys
from pathlib import Path
args=sys.argv[1:]
own=OWN in args
control=next(value for value in args if value.startswith('ControlPath='))
if own:
    prior=json.loads(Path(AUDIT).read_text().splitlines()[0])
    assert control != prior['control_path']
    assert 'IdentitiesOnly=yes' in args
    assert args[-1] == "'/usr/bin/true'", args
    with Path(AUDIT).open('a') as stream: stream.write(json.dumps({'action':'verify','own_identity':True,'control_path':control})+'\\n')
    raise SystemExit(1 if Path(FAILURE).exists() else 0)
assert os.environ.get('SSH_AUTH_SOCK') or ('-i' in args and args[args.index('-i')+1] != OWN)
body=sys.stdin.read()
tree=ast.parse(body)
node=next(node for node in tree.body if isinstance(node,ast.Assign) and isinstance(node.targets[0],ast.Name) and node.targets[0].id=='BOOTSTRAP_INPUT')
data=json.loads(ast.literal_eval(node.value.args[0]))
with Path(AUDIT).open('a') as stream: stream.write(json.dumps({'action':'authorize','data':data,'control_path':control})+'\\n')
print(json.dumps({'authorized':True,'changed':True}))
'''.replace('OWN', repr(str(self.own))).replace('AUDIT', repr(str(self.audit))).replace('FAILURE', repr(str(self.failure))))
        fixture.chmod(0o700)
        workflow = yaml.safe_load((ROOT / 'workflows/workflow-operation-node-ssh-access-converge.yaml').read_text())
        for job in workflow['jobs']:
            self.assertEqual(job.pop('target'), 'orbit-node-209')
            self.assertEqual(job.pop('labels'), {'node': '209'})
            for step in job['steps']:
                self.assertEqual(step.pop('target'), 'orbit-node-209')
                self.assertEqual(step.pop('labels'), {'node': '209'})
                if step['task'].get('behaviorType') == '@swamp/ssh':
                    step['task']['globalArgs']['sshBinary'] = str(fixture)
        (self.repo / 'workflows/workflow-operation-node-ssh-access-converge.yaml').write_text(yaml.safe_dump(workflow, sort_keys=False))
        self.input = {'operation_id': 1001, 'node_id': 9, 'kind': 'node.ssh_access_converge',
                      'intent': {'control_node_id': 209, 'identity_file': str(self.own), 'public_key': 'ssh-ed25519 AAAA approved-public-key',
                                 'target': {'name': 'target', 'host': 'dev.invalid', 'user': 'orbit', 'port': 22,
                                            'host_keys': ['ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIGaNG9xwGkyymXqkaSEKl6+u5VLBHPbi0ga0ya7sdCtG']}},
                      'execution_secrets': {'bootstrap_identity': {'vault': 'orbit-control-inputs', 'key': 'bootstrap-identity-node-14'}}}

    def run_access(self, failure=False):
        if failure:
            self.failure.touch()
        self.cli('workflow', 'validate', 'operation-node-ssh-access-converge')
        run = json.loads(self.cli('workflow', 'run', 'operation-node-ssh-access-converge', '--input', json.dumps(self.input), success=not failure).stdout)
        return json.loads(self.cli('workflow', 'history', 'get', run['id']).stdout)

    def test_bootstrap_copy_only_publishes_the_key_then_own_identity_verifies(self):
        history = self.run_access()
        self.assertEqual(history['status'], 'succeeded')
        steps = history['jobs'][0]['steps']
        self.assertEqual(json.loads(steps[-1]['outputs']['stdout']), {'ssh_access_ready': True})
        self.assertEqual([step['name'] for step in steps], ['authorize_assets', 'authorize', 'verify-own-key', 'result'])
        calls = [json.loads(line) for line in self.audit.read_text().splitlines()]
        self.assertNotEqual(calls[0].pop('control_path'), calls[1].pop('control_path'))
        self.assertEqual(calls, [{'action': 'authorize', 'data': {'user': 'orbit', 'public_key': self.input['intent']['public_key']}},
                                 {'action': 'verify', 'own_identity': True}])
        self.assert_private_absent(json.dumps(history))

    def test_failed_own_identity_does_not_claim_readiness(self):
        history = self.run_access(failure=True)
        self.assertEqual(history['status'], 'failed')
        self.assertEqual(history['jobs'][0]['steps'][1]['status'], 'succeeded')
        self.assertEqual(history['jobs'][0]['steps'][2]['status'], 'failed')
        self.assertEqual(history['jobs'][0]['steps'][-1]['status'], 'skipped')
        self.assert_private_absent(json.dumps(history))


if __name__ == '__main__':
    import unittest
    unittest.main()
