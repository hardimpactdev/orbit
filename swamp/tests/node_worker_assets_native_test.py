"""Official native asset/resource delivery and SSH file action; no fleet effects."""
import json
import re
import shutil
import sys
import unittest
import uuid

import yaml
from native_harness import ROOT, NativeTestCase


class WorkerAssets(NativeTestCase):
    native_timeout = 120

    def test_asset_bundle_resource_reaches_official_ssh_stdin(self):
        self.copy_behaviors('operations/node-worker-assets')
        policy = self.repo / 'extensions/behaviors/node-worker'
        policy.mkdir(parents=True)
        shutil.copy(ROOT / 'extensions/behaviors/node-worker/config.ts', policy)
        self.cli('extension', 'pull', '@swamp/ssh@2026.10.23.1')
        example = yaml.safe_load(re.search(r'```yaml\n(.*?)```', (ROOT / 'docs/node-official-ssh.md').read_text(), re.DOTALL).group(1))
        (self.repo / 'workflows/workflow-light-box-example.yaml').write_text(yaml.safe_dump(example))
        self.cli('workflow', 'validate', 'light-box-example')
        fake = self.repo / 'fixture-ssh'
        captured = self.repo / 'checker-public-input'
        fake.write_text('#!' + sys.executable + '\n' + '''import sys
from pathlib import Path
body=sys.stdin.buffer.read()
if '/usr/local/libexec/orbit-node-worker-check' in sys.argv[-1]:
    Path(CAPTURED).write_bytes(body)
print('public file action accepted')
'''.replace('CAPTURED', repr(str(captured))))
        fake.chmod(0o755)
        identity = self.repo / 'synthetic-key'
        identity.write_text('synthetic unused identity')
        identity.chmod(0o600)
        globals_ = {'name': 'worker-assets-fixture', 'sshBinary': str(fake),
                    'transport': {'kind': 'ssh', 'user': 'orbit', 'identityFile': str(identity)},
                    'hosts': [{'name': 'guest', 'address': '192.0.2.12', 'transport': {
                        'hostKeys': ['ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIGaNG9xwGkyymXqkaSEKl6+u5VLBHPbi0ga0ya7sdCtG']}}]}
        workflow = {'id': str(uuid.uuid4()), 'name': 'worker-assets-fixture', 'jobs': [{'name': 'bootstrap', 'steps': [
            {'name': 'assets', 'task': {'type': 'behavior', 'behaviorType': '@nckrtl/node-worker-assets',
                                      'behaviorName': 'worker-assets-209', 'methodName': 'read',
                                      'inputs': {'node_id': 209, 'user': 'orbit'}}},
            {'name': 'publish-checker', 'dependsOn': [{'step': 'assets', 'condition': {'type': 'succeeded'}}],
             'task': {'type': 'behavior', 'behaviorType': '@swamp/ssh', 'behaviorName': 'worker-assets-fixture',
                      'methodName': 'exec', 'globalArgs': globals_,
                      'inputs': {'hosts': 'name:guest', 'sudo': True,
                                 'argv': ['/usr/bin/install', '-m', '0755', '/dev/stdin',
                                          '${{ steps.assets.outputs.checker_path }}'],
                                 'stdin': '${{ steps.assets.outputs.checker }}'}}},
        ]}]}
        (self.repo / 'workflows/workflow-worker-assets-fixture.yaml').write_text(yaml.safe_dump(workflow))
        self.cli('workflow', 'validate', 'worker-assets-fixture')
        run = json.loads(self.cli('workflow', 'run', 'worker-assets-fixture').stdout)
        self.assertEqual(run['status'], 'succeeded')
        history = json.loads(self.cli('workflow', 'history', 'get', run['id']).stdout)
        output = history['jobs'][0]['steps'][0]['outputs']
        self.assertEqual(captured.read_text(), output['checker'])
        self.assertIn('MINIMUM_MEMORY_BYTES = 674311096', output['checker'])
        self.assertIn('ExecStartPre=+/usr/local/libexec/orbit-node-worker-check', output['unit'])
        self.assertIn('LoadCredential=proxy-ssh-identity:/etc/orbit/node-proxy/id_ed25519', output['unit'])
        self.assertTrue(all(s['status'] == 'succeeded' for s in run['jobs'][0]['steps']))


if __name__ == '__main__':
    unittest.main()
