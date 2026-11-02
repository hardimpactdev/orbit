"""The production probe uses the admitted host and a vault key, without a Gateway callback."""
import json
import os
import shutil
import subprocess

import yaml
from native_harness import ROOT, NativeTestCase


class BootstrapProbe209Native(NativeTestCase):
    native_timeout = 120

    def test_probe_from_control_worker_uses_admitted_target_and_keeps_key_private(self):
        inventory = json.loads((ROOT / 'extensions/models/upstream_extensions.json').read_text())
        self.cli('extension', 'pull', '@swamp/ssh@' + inventory['@swamp/ssh']['version'])
        destination = self.repo / 'extensions/behaviors'
        destination.mkdir(parents=True, exist_ok=True)
        shutil.copy(ROOT / 'extensions/behaviors/operations/node-bootstrap/probe_result.ts', destination)
        source = yaml.safe_load((ROOT / 'workflows/workflow-operation-node-bootstrap-probe.yaml').read_text())
        for job in source['jobs']:
            self.assertEqual(job.pop('labels'), {'role': 'swamp'})
            for step in job['steps']:
                self.assertEqual(step.pop('labels'), {'role': 'swamp'})
        (self.repo / 'workflows/workflow-operation-node-bootstrap-probe.yaml').write_text(yaml.safe_dump(source, sort_keys=False))
        key = self.repo / 'key'
        subprocess.run(['ssh-keygen', '-q', '-t', 'ed25519', '-N', '', '-f', str(key)], check=True, capture_output=True)
        private = key.read_text()
        public = key.with_suffix('.pub').read_text().strip()
        key.unlink()
        key.with_suffix('.pub').unlink()
        self.secrets = (private,)
        self.cli('vault', 'create', 'local_encryption', 'orbit-control-inputs')
        self.cli('vault', 'put', 'orbit-control-inputs', 'bootstrap-identity-node-14', input=private)
        tools = self.repo / 'tools'
        tools.mkdir()
        audit = self.repo / 'ssh-calls'
        fake = tools / 'ssh'
        fake.write_text('''#!/usr/bin/env python3
import os,sys
with open(os.environ['AUDIT'],'a') as f:f.write(' '.join(sys.argv[1:])+'\\n')
if 'uname' in sys.argv[-1]:print('Linux x86_64')
elif 'ssh_host' in sys.argv[-1]:print(os.environ['HOST_PUBLIC_KEY'])
''')
        fake.chmod(0o755)
        self.native_env.update(PATH=str(tools) + ':' + os.environ['PATH'], AUDIT=str(audit), HOST_PUBLIC_KEY=public)
        inputs = {'operation_id': 1, 'node_id': 14, 'kind': 'node.bootstrap_probe',
                  'intent': {'bootstrap': {'platform': 'linux', 'expected_architecture': 'x86_64',
                      'target': {'host': 'admitted-target', 'port': 2222, 'user': 'bootstrap', 'host_keys': [public]}}},
                  'execution_secrets': {'bootstrap_identity': {'vault': 'orbit-control-inputs', 'key': 'bootstrap-identity-node-14'}}}
        self.cli('workflow', 'validate', 'operation-node-bootstrap-probe')
        result = self.cli('workflow', 'run', 'operation-node-bootstrap-probe', '--stdin', input=json.dumps(inputs))
        decoder = json.JSONDecoder()
        run = decoder.raw_decode(result.stdout[result.stdout.index('{'):])[0]
        history = self.cli('workflow', 'history', 'get', run['id'])
        self.assertIn('"probed":true', history.stdout.replace('\\"', '"').replace(' ', ''))
        self.assertIn('bootstrap@admitted-target', audit.read_text())
        self.assertNotIn('gateway.orbit', audit.read_text())
