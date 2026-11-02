"""Check that the rollout published the admitted CLI and left the pinned observer running, from 209 only."""
import json
import os
import subprocess

operation = json.load(open(os.environ['PROOF_OPERATION_FILE']))
assert operation['status'] == 'succeeded', operation.get('error_code')
admitted = operation['input']['nodes'][0]
node = operation['result']['nodes'][0]
version = admitted['release']['version']
assert node['ok'] and node['step'] == 'verify' and node['error_code'] is None, node
assert node['cli_version'] == version and node['agent_version'] == admitted['agent']['version'], node


def target(*argv):
    return subprocess.check_output(['incus', 'exec', os.environ['PROOF_TARGET'], '--', *argv], text=True).strip()


assert target('readlink', '/usr/local/bin/orbit') == f'orbit-{version}'
assert target('sha256sum', '/usr/local/bin/orbit-agent').split()[0] == admitted['agent']['sha256']
pid = target('systemctl', 'show', '--property=MainPID', '--value', 'orbit-agent.service')
assert target('sha256sum', f'/proc/{pid}/exe').split()[0] == admitted['agent']['sha256']
assert target('systemctl', 'is-active', 'orbit-agent.service') == 'active'
print(f'parity: Orbit {version} linked; pinned observer {admitted["agent"]["version"]} runs on an active unit')
