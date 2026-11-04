#!/usr/bin/env bash
# A real candidate CLI proof, not fixture seeding. Every effect is inspected and removed.
set -euo pipefail
python3 - <<'PY'
import json
import os
from pathlib import Path
import subprocess

result = json.loads(Path(os.environ['PROOF_RESULT']).read_text())
nodes = result['topology']['construction']['nodes']
operator = nodes['operator']['instance']
evidence = Path(os.environ['PROOF_EVIDENCE'])

def cli(label, *args):
    command = ['incus', 'exec', operator, '--', 'sh', '-c',
        'cd /; exec sudo -u orbit env HOME=/home/orbit ORBIT_HOME=/home/orbit/.orbit /home/orbit/orbit/apps/cli/orbit "$@"', 'sh', *args, '--json']
    run = subprocess.run(command, capture_output=True, timeout=180)
    (evidence / ('smoke-' + label + '.json')).write_bytes(run.stdout)
    if run.returncode:
        raise RuntimeError(label + ' failed: ' + run.stdout.decode() + run.stderr.decode())
    return json.loads(run.stdout)

listed = cli('nodes', 'node:list')
assert {node['name'] for node in listed['nodes']} == set(nodes)
node_id = next(node['id'] for node in listed['nodes'] if node['name'] == 'app-dev')
options = ['--node=' + str(node_id), '--from=192.0.2.89', '--protocol=tcp']
cli('allow', 'firewall:allow', '43189', *options, '--label=proof3189-smoke')
live = subprocess.check_output(['incus', 'exec', nodes['app-dev']['instance'], '--', 'ufw', 'status', 'numbered'], timeout=30).decode()
assert '43189/tcp' in live and '192.0.2.89' in live, live
(evidence / 'smoke-ufw-present.txt').write_text(live)
cli('remove', 'firewall:remove', '43189', *options, '--yes')
live = subprocess.check_output(['incus', 'exec', nodes['app-dev']['instance'], '--', 'ufw', 'status', 'numbered'], timeout=30).decode()
assert '43189' not in live, live
(evidence / 'smoke-ufw-absent.txt').write_text(live)
print(json.dumps({'passed': True, 'node': node_id, 'transport': 'candidate CLI HTTP -> native firewall SSH', 'fixture_identity': True}))
PY
