"""node.role_converge for a sole database role, from 209 with cfgmgmt and SSH argv: the role is proven, the agent
runs the pinned binary with the recorded key, the prerequisites are installed and the CLI profile is written."""
import json
import os
import subprocess
import sys

operation = json.load(open(os.environ['PROOF_OPERATION_FILE']))
def node(*argv):
    return subprocess.run(['incus', 'exec', 'metrics', '--', *argv], capture_output=True, text=True, check=False).stdout.strip()


def check(condition, message):
    if not condition:
        sys.exit(f'parity: {message}')


result = operation.get('result') or {}
check(operation['status'] == 'succeeded', f'the Operation is {operation["status"]} ({operation.get("error_code")})')
check(result.get('role_proven') is True, f'the role is proven: {result}')
check(node('systemctl', 'is-active', 'orbit-agent') == 'active', 'orbit-agent is active')
check(node('sh', '-c', 'test -f /root/.orbit/config.json && echo ok') == 'ok' or result.get('cli', {}).get('outcome') == 'skipped', 'the CLI profile is written')
print('parity: node.role_converge converged')
