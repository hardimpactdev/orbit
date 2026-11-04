"""Public Gateway checks shared by role proof cases."""
import json
import os
import subprocess
from pathlib import Path

ROOT = Path(__file__).resolve().parents[3]


def gateway(method, path, body=None, key=None):
    result = subprocess.run(['runuser', '-u', 'orbit', '--', 'php',
                             str(ROOT / 'tools/swamp-proof-guest/gateway.php'), 'request'],
                            input=json.dumps({'method': method, 'path': path, 'body': body or {},
                                              'caller': '10.231.0.2', 'key': key or 'role-parity-' + os.environ['PROOF_RUN'],
                                              'operation': False}),
                            capture_output=True, text=True, check=True, cwd=ROOT / 'apps/gateway')
    return json.loads(result.stdout.strip().splitlines()[-1])


def doctor(node):
    report = gateway('POST', 'doctor', {'node_id': node, 'families': ['role', 'firewall']})['response']['data']
    summary = report['summary']
    print(f'Doctor Node {node}: role/firewall drift={summary["drift"]}, unverifiable={summary["unverifiable"]}')
    return summary['drift'] == 0 and summary['unverifiable'] == 0
