"""Native enrollment trust and Mac branch through the unmodified official SSH extension."""
import base64
import hashlib
import json
import os
import shutil
import subprocess
import sys

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
                                 'network': {'is_hub': False, 'sync_path': '', 'private_key_file': '', 'sync_body': '', 'address': '', 'retired_address': None, 'files': [], 'hubs': [], 'hub_files': [], 'trust_argv': []},
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

    def test_hub_sync_keeps_the_file_key_without_returning_it_in_run_data(self):
        self.check_peer_sync(hub=True)

    def test_node_sync_keeps_the_file_key_without_returning_it_in_run_data(self):
        self.check_peer_sync(hub=False)

    def check_peer_sync(self, hub):
        private = base64.b64encode(b'enrollment-private-key-sentinel!!').decode()
        key = self.repo / 'hub.key'
        key.write_text(private + '\n')
        config = self.repo / 'hub.sync'
        config.write_text('[Interface]\nListenPort = 51820\n')
        config.chmod(0o600)
        identity = self.repo / 'control.identity'
        identity.write_text('fixture-control-key')
        identity.chmod(0o600)
        tools = self.repo / 'tools'
        (tools / 'ssh').write_text('#!' + sys.executable + '\nimport subprocess,sys\n'
            'if "-N" in sys.argv or "-O" in sys.argv or "-MNf" in sys.argv:sys.exit(0)\n'
            'sys.exit(subprocess.call(["/bin/sh","-c",sys.argv[-1]]))\n')
        scripts = {
            'sudo': 'import subprocess,sys\na=sys.argv[1:];sys.exit(subprocess.call(a[a.index("--")+1:]))\n',
            'wg': 'import os,sys\nfrom pathlib import Path\np=Path(os.environ["HUB_SYNC_STATE"])\n'
                'if sys.argv[1]=="syncconf":\n'
                ' text=Path(sys.argv[3]).read_text();p.write_text("public-hub-key" if "PrivateKey = " in text else "(none)")\n'
                'else:print(p.read_text())\n',
        }
        for name, source in scripts.items():
            path = tools / name
            path.write_text('#!' + sys.executable + '\n' + source)
            path.chmod(0o700)
        self.native_env.update(PATH=str(tools) + ':' + os.environ['PATH'],
                               HUB_SYNC_STATE=str(self.repo / 'hub-state'))
        self.input['intent']['platform'] = 'linux'
        self.input['intent']['network']['hubs'] = [{'ssh': {'identity_file': str(identity), 'target': {
            'host': 'hub.example', 'port': 22, 'user': 'orbit', 'host_keys': [self.native_env['FIXTURE_PUBLIC_KEY']]}},
            'sync_path': str(config), 'private_key_file': str(key), 'public_key': 'public-hub-key',
            'sync_body': 'ListenPort = 51820\n\n[Peer]\nPublicKey = peer-key\nAllowedIPs = 10.44.0.42/32\n'}]
        self.input['intent']['network'].update(sync_path=str(config), private_key_file=str(key),
            sync_body='ListenPort = 51820\n\n[Peer]\nPublicKey = peer-key\nAllowedIPs = 10.44.0.42/32\n')
        workflow = yaml.safe_load((ROOT / 'workflows/workflow-operation-node-add.yaml').read_text())
        selected = {'Stage the hub private key', 'Finish hub configuration', 'Apply the hub peers',
                    'Read the hub public key', 'Check the hub key'} if hub else {
                        'Stage the tunnel key', 'Finish tunnel config', 'Apply the tunnel peers'}
        jobs = []
        for job in workflow['jobs']:
            steps = [s for s in job['steps'] if s['name'] in selected]
            if not steps:
                continue
            job = {'name': job['name'], 'steps': steps}
            if jobs:
                job['dependsOn'] = [{'job': jobs[-1]['name'], 'condition': {'type': 'succeeded'}}]
            for step in steps:
                step.pop('labels', None)
                task = step['task']
                if task.get('behaviorType') == '@swamp/ssh':
                    task['globalArgs']['sshBinary'] = str(tools / 'ssh')
                    task['globalArgs']['transport'].pop('identityContent', None)
                    task['globalArgs']['transport']['identityFile'] = str(identity)
                    task['globalArgs']['hosts'][0]['transport']['hostKeys'] = [self.native_env['FIXTURE_PUBLIC_KEY']]
                    task['inputs']['stdin'] = task['inputs'].get('stdin', '').replace(
                        'steps["Read WireGuard public key-0"].outputs.stdout.trim()', '"peer-key"')
            jobs.append(job)
        workflow.update(name='enrollment-hub-key-test', id='38bd409a-001d-4907-b56b-f385712a6520', jobs=jobs)
        (self.repo / 'workflows/workflow-enrollment-hub.yaml').write_text(yaml.safe_dump(workflow, sort_keys=False))
        result = json.loads(self.cli('workflow', 'run', workflow['name'], '--input', json.dumps(self.input)).stdout)
        history = self.cli('workflow', 'history', 'get', result['id']).stdout
        self.assertIn('PrivateKey = ' + private, config.read_text())
        self.assertIn('AllowedIPs = 10.44.0.42/32', config.read_text())
        self.assertNotIn(private, history)
        for path in (self.repo / '.swamp').rglob('*'):
            if path.is_file():
                self.assertNotIn(private.encode(), path.read_bytes(), str(path))
        self.assertEqual((self.repo / 'hub-state').read_text(), 'public-hub-key')


if __name__ == '__main__':
    import unittest
    unittest.main()
