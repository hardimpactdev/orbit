"""Official native asset execution and stable executing-model serialization, scratch only."""
import json
import os
import re
import sys
from pathlib import Path

import yaml

sys.path.insert(0, str(Path(__file__).resolve().parent))
from native_harness import ROOT, NativeTestCase


class HubCommands(NativeTestCase):
    def setUp(self):
        super().setUp()
        model = Path('models/@swamp/ssh/wireguard-hub-1.yaml')
        (self.repo / model).parent.mkdir(parents=True)
        # This domain fixture bypasses SSH; lane21 proves the official transport.
        definition = yaml.safe_load((ROOT / model).read_text())
        definition['type'] = 'command/shell'
        definition.pop('typeVersion', None)
        definition['globalArguments'] = {}
        (self.repo / model).write_text(yaml.safe_dump(definition))

    def test_shipped_asset_removes_only_context_and_returns_public_result(self):
        self.copy_behaviors('operations/node-hub-command')
        script = self.repo / 'extensions/behaviors/operations/node-hub-command/scripts/hub_peer.py'
        source = script.read_text()
        private = 'P' * 43 + '='
        key = 'A' * 43 + '='
        directory = self.repo / 'wireguard'
        directory.mkdir(mode=0o700)
        target = directory / 'orbit.conf'
        target.write_text(f'[Interface]\nAddress = 10.44.0.1/24\nListenPort = 51820\nPrivateKey = {private}\n\n[Peer]\nPublicKey = {key}\nAllowedIPs = 10.44.0.2/32\n')
        target.chmod(0o600)
        source, n = re.subn(r'WG = Path\([\'\"]/etc/wireguard[\'\"]\)', f'WG = Path({str(directory)!r})', source)
        self.assertEqual(n, 1)
        source = source.replace('UID = 0', f'UID = {os.getuid()}')
        source = source.replace('Path("/run/lock/orbit-wireguard-peer.lock")', f'Path({str(self.repo / "wireguard.lock")!r})')
        source = 'import sys\n' + source
        tool = self.repo / 'host-tool.py'
        tool.write_text("import pathlib,sys\nif sys.argv[1]=='/usr/bin/wg-quick':print(pathlib.Path(sys.argv[-1]).read_text())\n")
        source, n = re.subn(r'(subprocess.run\(\s*\[)', r'\1sys.executable, ' + repr(str(tool)) + ', ', source)
        self.assertEqual(n, 3)
        script.write_text(source)
        workflow = yaml.safe_load((ROOT / 'workflows/workflow-node-hub-peer-mutation.yaml').read_text())
        for job in workflow['jobs']:
            job['steps'] = [step for step in job['steps'] if step['name'] != 'transport']
            for step in job['steps']:
                step.pop('target', None)
                step.pop('labels', None)
                if step['name'] == 'mutate':
                    step['dependsOn'] = [{'step': 'assets', 'condition': {'type': 'succeeded'}}]
                    step['task']['behaviorType'] = 'command/shell'
                    step['task']['methodName'] = 'execute'
                    step['task']['inputs'] = {'run': "/usr/bin/python3 - <<'ORBIT_DOMAIN_FIXTURE'\n${{ steps.assets.outputs.script_body }}\nORBIT_DOMAIN_FIXTURE"}
        (self.repo / 'workflows/workflow-node-hub-peer-mutation.yaml').write_text(json.dumps(workflow))
        peer = {'node_id': 2, 'name': 'guest', 'address': '10.44.0.2', 'public_key': key}
        intent = {'schema_version': 1, 'hub_id': 1, 'hub_address': '10.44.0.1/24', 'port': 51820, 'context_node_id': 2,
                  'inventory': [peer], 'before': peer, 'desired': peer}
        self.cli('workflow', 'validate', 'node-hub-peer-mutation')
        run = json.loads(self.cli('workflow', 'run', 'node-hub-peer-mutation', '--input', 'intent:json=' + json.dumps(intent), '--input', 'mode=remove').stdout)
        history = json.loads(self.cli('workflow', 'history', 'get', run['id']).stdout)
        result = json.loads(history['jobs'][0]['steps'][1]['outputs']['stdout'])
        self.assertTrue(result['projected'])
        self.assertNotIn(private, json.dumps(history))
        self.assertNotIn(key, target.read_text())
        self.assertIn(private, target.read_text())
        self.assertEqual(sorted(p.name for p in directory.iterdir()), ['orbit.conf'])

    def test_two_native_parent_jobs_share_the_executing_model_lock(self):
        command = self.repo / 'serialized.py'
        command.write_text('''from pathlib import Path
root=Path(__file__).parent
busy=root/'busy'
busy.mkdir()
try:
    sum(i*i for i in range(3000000))
    with (root/'calls').open('a') as stream:stream.write('applied\\n')
finally:busy.rmdir()
print('{"projected":true}')
''')
        step = {'name': 'mutate', 'writes': True, 'task': {'type': 'behavior', 'behaviorType': 'command/shell',
                'behaviorName': 'wireguard-hub-1', 'methodName': 'execute', 'inputs': {'run': '/usr/bin/python3 ' + str(command)}}}
        workflow = {'id': '2a709157-1b38-41c4-bf10-88b4be314ccc', 'name': 'hub-serialization',
                    'jobs': [{'name': name, 'steps': [step]} for name in ['parent_remove', 'parent_network']]}
        (self.repo / 'workflows/workflow-hub-serialization.yaml').write_text(json.dumps(workflow))
        self.cli('workflow', 'run', 'hub-serialization')
        self.assertEqual((self.repo / 'calls').read_text(), 'applied\napplied\n')
