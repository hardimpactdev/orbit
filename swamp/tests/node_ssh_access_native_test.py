"""node.ssh_access_converge on native e459: the Orbit block is written and read back; lines outside it stay."""
import json
import subprocess
import sys

import yaml

from native_harness import ROOT, NativeTestCase

FIXTURE = '''#!PYTHON
import os, shlex, subprocess, sys
argv = shlex.split(sys.argv[-1])
argv = argv[argv.index('timeout'):] if 'timeout' in argv else argv[argv.index('/usr/bin/printf'):]
raise SystemExit(subprocess.run(argv, env={**os.environ, 'HOME': FAKE_HOME}).returncode)
'''
OPERATORS = ['ssh-ed25519 AAAA nick@mini', '# kept comment', 'ssh-rsa BBBB ops@laptop']


class SshAccessNative(NativeTestCase):
    native_timeout = 120

    def setUp(self):
        super().setUp()
        self.copy_behaviors('operations/node-scripts', 'operations/fleet-recipe')
        self.cli('extension', 'pull', '@swamp/ssh@2026.10.23.1')
        private = self.repo / 'access-key'
        subprocess.run(['ssh-keygen', '-q', '-t', 'ed25519', '-N', '', '-f', str(private)], check=True)
        value = private.read_text()
        private.unlink()
        self.secrets = (value,)
        self.cli('vault', 'create', 'local_encryption', 'orbit-control-inputs')
        self.cli('vault', 'put', 'orbit-control-inputs', 'bootstrap-identity-node-14', input=value)
        self.home = self.repo / 'home'
        (self.home / '.ssh').mkdir(parents=True, mode=0o700)
        self.keys = self.home / '.ssh/authorized_keys'
        fixture = self.repo / 'fixture-ssh'
        fixture.write_text(FIXTURE.replace('PYTHON', sys.executable).replace('FAKE_HOME', repr(str(self.home))))
        fixture.chmod(0o700)
        workflow = yaml.safe_load((ROOT / 'workflows/workflow-operation-node-ssh-access-converge.yaml').read_text())
        for job in workflow['jobs']:
            self.assertEqual(job.pop('labels'), {'role': 'swamp'})
            for step in job['steps']:
                self.assertEqual(step.pop('labels'), {'role': 'swamp'})
                if step['task']['behaviorType'] == '@swamp/ssh':
                    step['task']['globalArgs']['sshBinary'] = str(fixture)
        (self.repo / 'workflows/workflow-operation-node-ssh-access-converge.yaml').write_text(yaml.safe_dump(workflow, sort_keys=False))
        self.input = {'operation_id': 1001, 'node_id': 9, 'kind': 'node.ssh_access_converge',
                      'intent': {'identity_file': str(fixture), 'keys': ['ssh-ed25519 SWAMP swamp'],
                                 'target': {'host': 'dev.invalid', 'user': 'orbit', 'port': 22,
                                            'host_keys': ['ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIGaNG9xwGkyymXqkaSEKl6+u5VLBHPbi0ga0ya7sdCtG']}},
                      'execution_secrets': {'bootstrap_identity': {'vault': 'orbit-control-inputs', 'key': 'bootstrap-identity-node-14'}}}

    def run_access(self, success=True):
        self.cli('workflow', 'validate', 'operation-node-ssh-access-converge')
        reply = self.cli('workflow', 'run', 'operation-node-ssh-access-converge', '--input', json.dumps(self.input), success=success)
        run = json.JSONDecoder().raw_decode(reply.stdout.lstrip())[0]['id']
        return {s['name']: s for j in json.loads(self.cli('workflow', 'history', 'get', run).stdout)['jobs'] for s in j['steps']}

    def test_writes_the_block_keeps_operator_keys_and_the_login_is_the_result(self):
        self.keys.write_text('\n'.join(OPERATORS) + '\n')
        steps = self.run_access()
        self.assertEqual(json.loads(steps['Log in with Swamp identity']['outputs']['stdout']), {'ssh_access_ready': True})
        self.assertEqual(self.keys.read_text().splitlines(),
                         [*OPERATORS, '# BEGIN Orbit managed keys', 'ssh-ed25519 SWAMP swamp', '# END Orbit managed keys'])

    def test_broken_markers_write_nothing_and_skip_the_login(self):
        text = '\n'.join([*OPERATORS, '# BEGIN Orbit managed keys']) + '\n'
        self.keys.write_text(text)
        steps = self.run_access(success=False)
        self.assertEqual(steps['Write the Orbit block']['status'], 'failed')
        self.assertNotEqual(steps.get('Log in with Swamp identity', {}).get('status'), 'succeeded')
        self.assertEqual(self.keys.read_text(), text)


if __name__ == '__main__':
    import unittest
    unittest.main()
