"""Disposable native DAG using the unmodified official SSH extension's binary seam."""
import json
import os
import shutil
import sys
import unittest
from pathlib import Path

import yaml

ROOT = Path(__file__).resolve().parents[4]
sys.path.insert(0, str(ROOT / 'tests'))
from native_harness import NativeTestCase


class FirewallInspectionNative(NativeTestCase):
    def setUp(self):
        super().setUp()
        folder = self.repo / 'extensions/behaviors/operations/firewall'
        folder.mkdir(parents=True)
        shutil.copy(ROOT / 'extensions/behaviors/operations/firewall/inspection-result.ts', folder)
        self.cli('extension', 'pull', '@swamp/ssh@2026.10.23.1')
        self.audit = self.repo / 'ssh-audit'
        self.fixture = self.repo / 'ufw-output'
        self.fixture.write_text('Status: active\n[1] 443/tcp ALLOW IN 192.0.2.1 # owned\n')
        fake = self.repo / 'fake-ssh'
        fake.write_text('#!' + sys.executable + '\n' + '''import os,sys
from pathlib import Path
if any(x in sys.argv for x in ['-O','-N','-MNf','-M']):raise SystemExit(0)
assert '/usr/sbin/ufw' in sys.argv[-1] and 'numbered' in sys.argv[-1]
with Path(os.environ['SSH_AUDIT']).open('a') as f:f.write(sys.argv[-1]+'\\n')
print(Path(os.environ['UFW_OUTPUT']).read_text(),end='')
raise SystemExit(int(os.environ.get('SSH_EXIT','0')))
''')
        fake.chmod(0o755)
        self.native_env = {'SSH_AUDIT': str(self.audit), 'UFW_OUTPUT': str(self.fixture), 'SSH_EXIT': '0'}
        source = (ROOT / 'workflows/workflow-operation-firewall-inspect.yaml').read_text()
        (self.repo / 'workflows/workflow-operation-firewall-inspect.yaml').write_text(source)
        self.cli('workflow', 'validate', 'operation-firewall-inspect')
        workflow = yaml.safe_load(source)
        self.assertEqual(workflow.pop('target'), 'orbit-node-209')
        for job in workflow['jobs']:
            for step in job['steps']:
                self.assertEqual(step.pop('target'), 'orbit-node-209')
                self.assertNotIn('labels', step)
                if step['name'] == 'read':
                    step['task']['globalArgs']['sshBinary'] = str(fake)
                    self.assertEqual(step['task']['inputs']['argv'], ['/usr/sbin/ufw', 'status', 'numbered'])
        (self.repo / 'workflows/workflow-operation-firewall-inspect.yaml').write_text(yaml.safe_dump(workflow))
        self.intent = {'ssh': {'identity_file': '/fixture/key', 'target': {'host': '10.44.0.9', 'user': 'orbit', 'port': 22,
            'host_keys': ['ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIAEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEB']}}, 'expected': []}

    def run_inspection(self, success=True):
        self.cli('workflow', 'validate', 'operation-firewall-inspect')
        result = self.cli('workflow', 'run', 'operation-firewall-inspect', '--input', 'operation_id=17',
            '--input', 'node_id=9', '--input', 'kind=firewall.inspect', '--input', 'intent='+json.dumps(self.intent),
            '--input', 'execution_secrets={}', success=success)
        history = json.loads(self.cli('workflow', 'history', 'get', json.loads(result.stdout)['id']).stdout)
        evidence = os.environ.get('FIREWALL_INSPECTION_NATIVE_EVIDENCE')
        if evidence:
            Path(evidence).mkdir(parents=True, exist_ok=True)
            Path(evidence, history['id'] + '.json').write_text(json.dumps(history, indent=2))
        return history

    def test_one_official_ssh_read_produces_native_snapshot(self):
        history = self.run_inspection()
        output = json.loads(history['jobs'][0]['steps'][-1]['outputs']['stdout'])
        self.assertEqual(history['status'], 'succeeded')
        self.assertEqual(output['backend'], 'active')
        self.assertEqual(output['live'][0]['comment'], 'owned')
        self.assertEqual(output['comparison']['live'][0]['match'], 'unmanaged')
        self.assertEqual(len(self.audit.read_text().splitlines()), 1)
        # The real native result is retained in the fixture history by the shared harness.
        self.assertNotIn('gateway', self.audit.read_text())

    def test_ssh_failure_does_not_publish_a_successful_comparison(self):
        self.native_env['SSH_EXIT'] = '17'
        history = self.run_inspection(success=False)
        self.assertEqual(history['status'], 'failed')
        self.assertEqual(len(self.audit.read_text().splitlines()), 1)

    def test_malformed_read_fails_in_the_typed_result_step(self):
        self.fixture.write_text('Status: active\nnot a firewall row\n')
        history = self.run_inspection(success=False)
        self.assertEqual(history['jobs'][0]['steps'][-1]['status'], 'failed')
        self.assertEqual(len(self.audit.read_text().splitlines()), 1)


if __name__ == '__main__':
    unittest.main()
