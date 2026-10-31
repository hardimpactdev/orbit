"""Hub domain action through the unmodified official SSH extension, scratch only."""
import json
import os
import re
import shutil
import subprocess
import sys
from pathlib import Path

import yaml
from native_harness import ROOT, NativeTestCase


class OfficialHubSsh(NativeTestCase):
    native_timeout = 120

    def test_forward_compensation_and_terminal_stdout(self):
        self.cli('extension', 'pull', '@swamp/ssh@2026.10.23.1')
        self.copy_behaviors('operations/node-hub-command')
        fixture = self.repo / 'fixture'
        fixture.mkdir()
        key = fixture / 'existing-key'
        key.write_text('synthetic SSH fixture identity')
        key.chmod(0o600)
        calls = fixture / 'calls.jsonl'
        ssh = fixture / 'ssh'
        ssh.write_text('#!' + sys.executable + '\n' + '''import json,shlex,subprocess,sys
from pathlib import Path
args=sys.argv[1:]
body=sys.stdin.buffer.read()
with Path(CALLS).open('a') as stream:
    stream.write(json.dumps({'argv':args,'stdin':body.decode()})+'\\n')
argv=shlex.split(args[-1])
if argv[:3]==['sudo','-n','--']:
    argv=argv[3:]
result=subprocess.run(argv,input=body,capture_output=True)
sys.stdout.buffer.write(result.stdout)
sys.stderr.buffer.write(result.stderr)
raise SystemExit(result.returncode)
'''.replace('CALLS', repr(str(calls))))
        ssh.chmod(0o755)
        model_path = Path('models/@swamp/ssh/wireguard-hub-1.yaml')
        model = yaml.safe_load((ROOT / model_path).read_text())
        self.assertEqual(model['type'], '@swamp/ssh')
        model['globalArguments']['transport']['identityFile'] = str(key)
        (self.repo / model_path).parent.mkdir(parents=True)
        (self.repo / model_path).write_text(yaml.safe_dump(model))

        private = 'P' * 43 + '='
        peer_key = 'A' * 43 + '='
        newer_key = 'B' * 43 + '='
        wireguard = fixture / 'wireguard'
        wireguard.mkdir(mode=0o700)
        config = wireguard / 'orbit.conf'
        initial = f'[Interface]\nAddress = 10.44.0.1/24\nListenPort = 51820\nPrivateKey = {private}\n\n[Peer]\nPublicKey = {peer_key}\nAllowedIPs = 10.44.0.2/32\n'
        config.write_text(initial)
        config.chmod(0o600)
        script = self.repo / 'extensions/behaviors/operations/node-hub-command/scripts/hub_peer.py'
        source = script.read_text()
        source, count = re.subn(r'WG = Path\([\'\"]/etc/wireguard[\'\"]\)', f'WG = Path({str(wireguard)!r})', source)
        self.assertEqual(count, 1)
        source = source.replace('UID = 0', f'UID = {os.getuid()}')
        source = source.replace('Path("/run/lock/orbit-wireguard-peer.lock")', f'Path({str(fixture / "tool.lock")!r})')
        tool = fixture / 'host-tool.py'
        tool.write_text("import pathlib,sys\nif sys.argv[1]=='/usr/bin/wg-quick':print(pathlib.Path(sys.argv[-1]).read_text())\n")
        source = 'import sys\n' + source
        source, count = re.subn(r'(subprocess.run\(\s*\[)', r'\1sys.executable, ' + repr(str(tool)) + ', ', source)
        self.assertEqual(count, 3)
        script.write_text(source)
        deno = os.environ.get("DENO_BIN", shutil.which("deno") or "deno")
        module = (ROOT / "extensions/behaviors/operations/node-hub-command/steps.ts").as_uri()
        code = "import {hubPeerSteps} from " + json.dumps(module) + ";console.log(JSON.stringify(hubPeerSteps('mutation','inputs.intent','${{ inputs.mode }}')));"
        steps = json.loads(subprocess.run([deno, "eval", code], check=True, capture_output=True, text=True).stdout)
        workflow = {"id": "a06a254a-6dd2-4f2e-af76-7b432101e89f", "name": "node-hub-peer-mutation",
                    "inputs": {"type": "object", "properties": {"intent": {"type": "object"}, "mode": {"type": "string"}}},
                    "jobs": [{"name": "hub", "steps": steps}]}
        for step in steps:
            self.assertEqual((step.pop('target'), step.pop('labels')), ('orbit-node-209', {'node': '209'}))
            if step['task']['behaviorType'] == '@swamp/ssh':
                self.assertTrue(step['writes'])
                step['task']['globalArgs']['sshBinary'] = str(ssh)
        (self.repo / 'workflows/workflow-node-hub-peer-mutation.yaml').write_text(yaml.safe_dump(workflow, sort_keys=False))
        self.cli('workflow', 'validate', 'node-hub-peer-mutation')
        peer = {'node_id': 2, 'name': 'guest', 'address': '10.44.0.2', 'public_key': peer_key}
        intent = {'schema_version': 1, 'hub_id': 1, 'hub_address': '10.44.0.1/24', 'port': 51820,
                  'context_node_id': 2, 'inventory': [peer], 'before': peer, 'desired': peer}
        removed = json.loads(self.cli('workflow', 'run', 'node-hub-peer-mutation', '--input',
                                     'intent:json=' + json.dumps(intent), '--input', 'mode=remove').stdout)
        self.assertEqual(removed['status'], 'succeeded')
        removed = json.loads(self.cli('workflow', 'history', 'get', removed['id']).stdout)
        result = json.loads(removed['jobs'][0]['steps'][1]['outputs']['stdout'])
        self.assertTrue(result['projected'])
        self.assertFalse(result['restored'])
        readiness = next(step for step in removed['jobs'][0]['steps'] if step['name'] == 'mutation')
        self.assertEqual(readiness['outputs'], {'schema_version': 1, 'hub_id': 1, 'status': 'succeeded', 'projected': True})
        self.assertNotIn(peer_key, config.read_text())
        # A newer peer appears after admission. Compensation must retain it.
        with config.open('a') as stream:
            stream.write(f'\n[Peer]\nPublicKey = {newer_key}\nAllowedIPs = 10.44.0.3/32\n')
        restored = json.loads(self.cli('workflow', 'run', 'node-hub-peer-mutation', '--input',
                                      'intent:json=' + json.dumps(intent), '--input', 'mode=restore-remove').stdout)
        self.assertEqual(restored['status'], 'succeeded')
        restored = json.loads(self.cli('workflow', 'history', 'get', restored['id']).stdout)
        self.assertTrue(json.loads(restored['jobs'][0]['steps'][1]['outputs']['stdout'])['restored'])
        self.assertIn(peer_key, config.read_text())
        self.assertIn(newer_key, config.read_text())
        self.assertNotIn(private, json.dumps([removed, restored]))
        self.assertIn(private, config.read_text())
        records = [json.loads(line) for line in calls.read_text().splitlines()]
        self.assertEqual(len(records), 2)
        self.assertTrue(all(row['argv'][-1] == 'sudo -n -- python3 -' for row in records))
        self.assertTrue(all('HUB_ACTION_JSON' in row['stdin'] for row in records))
        self.assertTrue(all('HUB_ACTION_JSON' not in ' '.join(row['argv']) for row in records))
        evidence = os.environ.get('HUB_OFFICIAL_SSH_EVIDENCE')
        if evidence:
            Path(evidence).write_text(json.dumps({'package': '@swamp/ssh@2026.10.23.1',
                'runs': {name: run['id'] for name, run in [('remove', removed), ('restore', restored)]},
                'fresh_peer_preserved': True, 'fleet_execution_proven': False}, indent=2) + '\n')
