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
evidence = Path(os.environ['PROOF_EVIDENCE'])
# The outer VM is the operator: its CLI profile points at the nested Gateway over wg-nested.
orbit = Path(os.environ['PROOF_SOURCE']) / 'apps/cli/orbit'
environment = {'PATH': '/usr/local/bin:/usr/bin:/bin', 'HOME': '/home/orbit', 'ORBIT_HOME': '/home/orbit/.orbit'}

def cli(label, *args):
    run = subprocess.run([str(orbit), *args, '--json'], capture_output=True, timeout=180, env=environment, cwd='/')
    (evidence / ('smoke-' + label + '.json')).write_bytes(run.stdout)
    if run.returncode:
        raise RuntimeError(label + ' failed: ' + run.stdout.decode() + run.stderr.decode())
    return json.loads(run.stdout)

listed = cli('nodes', 'node:list')
assert {node['name'] for node in listed['nodes']} == {*nodes, 'operator'}
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
