"""node.settings on native e459: official SSH runs the shipped script; the fixture runs its argv on this host."""
import getpass
import json
import os
import sys
import tempfile
import unittest
from pathlib import Path

import yaml

ROOT = Path(__file__).resolve().parents[4]
sys.path.insert(0, str(ROOT / 'tests'))
from native_harness import NativeTestCase  # noqa: E402

FIXTURE = '''#!PYTHON
import shlex, subprocess, sys
argv = shlex.split(sys.argv[-1])
argv = argv[argv.index('timeout'):]
raise SystemExit(subprocess.run(argv).returncode)
'''


class NodeSettingsNative(NativeTestCase):
    native_timeout = 120

    def setUp(self):
        super().setUp()
        self.copy_behaviors('operations/node-scripts')
        self.cli('extension', 'pull', '@swamp/ssh@2026.10.23.1')
        self.base = Path(os.path.realpath(tempfile.mkdtemp(prefix='orbit-apps-')))
        fixture = self.repo / 'fixture-ssh'
        fixture.write_text(FIXTURE.replace('PYTHON', sys.executable))
        fixture.chmod(0o700)
        workflow = yaml.safe_load((ROOT / 'workflows/workflow-operation-node-settings.yaml').read_text())
        for job in workflow['jobs']:
            self.assertEqual(job.pop('labels'), {'role': 'swamp'})
            for step in job['steps']:
                self.assertEqual(step.pop('labels'), {'role': 'swamp'})
                if step['task']['behaviorType'] == '@swamp/ssh':
                    step['task']['globalArgs']['sshBinary'] = str(fixture)
        (self.repo / 'workflows/workflow-operation-node-settings.yaml').write_text(yaml.safe_dump(workflow, sort_keys=False))

    def run_settings(self, root, success=True):
        intent = {'ssh': {'identity_file': str(self.repo / 'fixture-ssh'), 'target': {'host': 'dev.invalid', 'user': 'orbit', 'port': 22,
                  'host_keys': ['ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIGaNG9xwGkyymXqkaSEKl6+u5VLBHPbi0ga0ya7sdCtG']}},
                  'storage': {'root': root, 'user': getpass.getuser(), 'create_missing': True}}
        self.cli('workflow', 'validate', 'operation-node-settings')
        reply = self.cli('workflow', 'run', 'operation-node-settings', '--input', json.dumps(
            {'operation_id': 7, 'node_id': 12, 'kind': 'node.settings', 'intent': intent, 'execution_secrets': {}}), success=success)
        run = json.JSONDecoder().raw_decode(reply.stdout.lstrip())[0]['id']
        return {s['name']: s for j in json.loads(self.cli('workflow', 'history', 'get', run).stdout)['jobs'] for s in j['steps']}

    def test_creates_the_folder_and_the_check_is_the_terminal_result(self):
        steps = self.run_settings(str(self.base / 'srv/apps'))
        self.assertEqual(json.loads(steps['Check owner and mode']['outputs']['stdout'])['prepared'], True)
        self.assertEqual((self.base / 'srv/apps').stat().st_mode & 0o777, 0o755)

    def test_a_refusal_writes_nothing_and_skips_the_check(self):
        (self.base / 'real').mkdir()
        (self.base / 'link').symlink_to(self.base / 'real')
        steps = self.run_settings(str(self.base / 'link/apps'), success=False)
        self.assertEqual(steps['Create the apps folder']['status'], 'failed')
        self.assertNotEqual(steps.get('Check owner and mode', {}).get('status'), 'succeeded')
        self.assertFalse((self.base / 'real/apps').exists())


if __name__ == '__main__':
    unittest.main()
