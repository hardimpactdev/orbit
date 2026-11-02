"""Native enrollment trust and Mac branch through the unmodified official SSH extension."""
import base64
import hashlib
import json
import shutil
import subprocess

import yaml
from native_harness import ROOT, NativeTestCase


class NodeEnrollmentNative(NativeTestCase):
    native_timeout = 120

    def setUp(self):
        super().setUp()
        inventory = json.loads((ROOT / 'extensions/models/upstream_extensions.json').read_text())
        for package in ('@swamp/ssh', '@swamp/cfgmgmt'):
            self.cli('extension', 'pull', package + '@' + inventory[package]['version'])
        self.copy_behaviors('operations/node-enrollment-plan', 'operations/node-bootstrap')
        tools = self.repo / 'tools'
        tools.mkdir()
        ssh = tools / 'ssh'
        shutil.copy(ROOT / 'tests/fixtures/node-enrollment/ssh.py', ssh)
        ssh.chmod(0o700)
        identity = self.repo / 'identity'
        subprocess.run(['ssh-keygen', '-q', '-t', 'ed25519', '-N', '', '-f', str(identity)], check=True)
        public = identity.with_suffix('.pub').read_text().strip()
        private = identity.read_text()
        identity.unlink()
        self.secrets = (private,)
        self.cli('vault', 'create', 'local_encryption', 'orbit-control-inputs')
        self.cli('vault', 'put', 'orbit-control-inputs', 'bootstrap-identity-node-701', input=private)
        self.native_env.update(FIXTURE_PUBLIC_KEY=public, FIXTURE_PLATFORM='Darwin arm64', FIXTURE_ADDRESS='10.44.0.42')
        fingerprint = 'SHA256:' + base64.b64encode(hashlib.sha256(base64.b64decode(public.split()[1])).digest()).decode().rstrip('=')
        self.input = {'operation_id': 9001, 'node_id': 701, 'kind': 'node.add',
                      'execution_secrets': {'bootstrap_identity': {'vault': 'orbit-control-inputs', 'key': 'bootstrap-identity-node-701'}},
                      'intent': {'platform': 'macos', 'expected_architecture': None, 'managed_user': 'mac', 'address': '10.44.0.42',
                                 'bootstrap': {'host': 'mac.example', 'port': 22, 'user': 'mac', 'fingerprint': fingerprint},
                                 'roles': [], 'cli': None, 'dns': [], 'private_dns': [], 'packages': [], 'authorized_keys': [],
                                 'sudo': {'path': '', 'candidate': '', 'content': ''},
                                 'agent': {'url': '', 'sha256': '', 'unit': '', 'files': []},
                                 'network': {'is_hub': False, 'address': '', 'retired_address': None, 'files': [], 'hubs': [], 'hub_files': [], 'trust_argv': []},
                                 'public_ssh_argv': [], 'known_hosts_path': '/synthetic/known_hosts',
                                 'control_ssh': {'identity_file': '/synthetic/control', 'target': {'host': 'control.example', 'port': 22, 'user': 'orbit', 'host_keys': [public]}},
                                 'identity': {'identity_file': '/synthetic/control', 'public_key': public}}}
        workflow = yaml.safe_load((ROOT / 'workflows/workflow-operation-node-add.yaml').read_text())
        for job in workflow['jobs']:
            job.pop('labels', None)
            for step in job['steps']:
                step.pop('labels', None)
                task = step['task']
                if task.get('behaviorType') == '@swamp/ssh':
                    task['globalArgs']['sshBinary'] = str(ssh)
        (self.repo / 'workflows/workflow-node-add.yaml').write_text(yaml.safe_dump(workflow, sort_keys=False))

    def run_node(self, success=True):
        result = json.loads(self.cli('workflow', 'run', 'operation-node-add', '--input', json.dumps(self.input), success=success).stdout)
        history = json.loads(self.cli('workflow', 'history', 'get', result['id']).stdout)
        self.last_history = history
        return {step['name']: step for job in history['jobs'] for step in job['steps']}

    def test_mac_enrollment_checks_existing_tunnel_without_linux_mutations(self):
        steps = self.run_node()
        self.assertEqual(steps['Check the Node']['status'], 'succeeded')
        self.assertEqual(json.loads(steps['Bind the Node identity']['outputs']['stdout'])['architecture'], 'arm64')
        self.assertEqual(steps['Inspect the Mac tunnel-0']['status'], 'succeeded')
        self.assertNotIn('Install base packages-0', steps)
        self.assertNotIn('Write the observer secret-0', steps)

    def test_unapproved_host_key_stops_before_any_authenticated_observation(self):
        self.input['intent']['bootstrap']['fingerprint'] = 'SHA256:unapproved'
        steps = self.run_node(success=False)
        self.assertEqual(steps['Require approved host key']['status'], 'failed')
        self.assertEqual(steps['Read the platform']['status'], 'skipped')
        self.assertNotEqual(steps['Check the Node']['status'], 'succeeded')

    def test_missing_existing_mac_tunnel_refuses_activation(self):
        self.native_env['FIXTURE_ADDRESS'] = '10.44.0.99'
        steps = self.run_node(success=False)
        self.assertEqual(steps['Check the Node']['status'], 'failed')

    def test_unsupported_linux_architecture_stops_before_bootstrap_mutations(self):
        self.input['intent']['platform'] = 'linux'
        self.input['intent']['network'].update(other_public_keys=[], required_public_key=None)
        self.native_env['FIXTURE_PLATFORM'] = 'Linux aarch64'
        steps = self.run_node(success=False)
        self.assertEqual(steps['Bind the Node identity']['status'], 'failed')
        self.assertNotEqual(steps.get('Install base packages-0', {}).get('status'), 'succeeded')


if __name__ == '__main__':
    import unittest
    unittest.main()
