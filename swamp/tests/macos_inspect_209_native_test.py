"""Pinned native Mac inspection uses only official SSH observations, with no guest worker."""
import json
import os
import shutil
import subprocess

import yaml
from native_harness import ROOT, NativeTestCase


class MacInspection209Native(NativeTestCase):
    native_timeout = 120

    def setUp(self):
        super().setUp()
        inventory = json.loads((ROOT / 'extensions/models/upstream_extensions.json').read_text())
        self.cli('extension', 'pull', '@swamp/ssh@' + inventory['@swamp/ssh']['version'])
        destination = self.repo / 'extensions/behaviors'
        destination.mkdir(parents=True, exist_ok=True)
        shutil.copy(ROOT / 'extensions/behaviors/operations/node-bootstrap/macos_result.ts', destination)
        source = yaml.safe_load((ROOT / 'workflows/workflow-operation-node-macos-inspect.yaml').read_text())
        for job in source['jobs']:
            self.assertEqual(job.pop('labels'), {'role': 'swamp'})
            for step in job['steps']:
                self.assertEqual(step.pop('labels'), {'role': 'swamp'})
        (self.repo / 'workflows/workflow-operation-node-macos-inspect.yaml').write_text(yaml.safe_dump(source, sort_keys=False))
        key = self.repo / 'key'
        subprocess.run(['ssh-keygen', '-q', '-t', 'ed25519', '-N', '', '-f', str(key)], check=True)
        private, public = key.read_text(), key.with_suffix('.pub').read_text().strip()
        key.unlink()
        key.with_suffix('.pub').unlink()
        self.secrets = (private,)
        self.cli('vault', 'create', 'local_encryption', 'orbit-control-inputs')
        self.cli('vault', 'put', 'orbit-control-inputs', 'bootstrap-identity-node-14', input=private)
        tools = self.repo / 'tools'
        tools.mkdir()
        self.audit = self.repo / 'calls'
        fake = tools / 'ssh'
        fake.write_text('''#!/usr/bin/env python3
import os,sys
command=sys.argv[-1]
with open(os.environ['AUDIT'],'a') as f:f.write(command+'\\n')
if 'uname' in command:print(os.environ.get('MAC_PLATFORM','Darwin arm64'))
elif 'ifconfig' in command:print('utun0:\\n\\tinet '+os.environ.get('MAC_ADDRESS','10.44.0.12')+' netmask 0xffffffff')
else:sys.exit(99)
''')
        fake.chmod(0o755)
        self.native_env.update(PATH=str(tools) + ':' + os.environ['PATH'], AUDIT=str(self.audit))
        self.inputs = {'operation_id': 1, 'node_id': 14, 'kind': 'node.macos_inspect',
                       'intent': {'bootstrap': {'node_id': 12, 'expected_architecture': 'arm64', 'address': '10.44.0.12',
                           'target': {'host': 'admitted-mac', 'port': 2222, 'user': 'mac-user', 'host_keys': [public]}}},
                       'execution_secrets': {'bootstrap_identity': {'vault': 'orbit-control-inputs', 'key': 'bootstrap-identity-node-14'}}}

    def run_inspection(self, success=True):
        self.cli('workflow', 'validate', 'operation-node-macos-inspect')
        return self.cli('workflow', 'run', 'operation-node-macos-inspect', '--stdin', input=json.dumps(self.inputs), success=success)

    def test_only_reads_the_admitted_mac_and_keeps_the_private_key_out_of_history(self):
        reply = self.run_inspection()
        run = json.JSONDecoder().raw_decode(reply.stdout[reply.stdout.index('{'):])[0]
        history = self.cli('workflow', 'history', 'get', run['id'])
        self.assertIn('bootstrap-macos-12', history.stdout)
        calls = self.audit.read_text()
        self.assertIn('uname', calls)
        self.assertIn('ifconfig', calls)
        self.assertNotIn('orbit-agent', calls)
        self.assertNotIn('id -', calls)

    def test_missing_tunnel_address_fails_the_native_end_check(self):
        self.native_env['MAC_ADDRESS'] = '10.44.0.120'
        reply = self.run_inspection(success=False)
        self.assertIn('The Mac must have its admitted WireGuard address', reply.stdout + reply.stderr)
