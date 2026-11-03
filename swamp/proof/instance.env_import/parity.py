"""env:import contract on the target: the Node file read once through the vault, the new key stored, no value
in native history, no staged copy left on the Node or on 209, the Node file unchanged, and a value-free result."""
import base64
import glob
import json
import os
import re
import subprocess
import sys
from pathlib import Path

operation = json.loads(Path(os.environ['PROOF_OPERATION_FILE']).read_text())
result = operation['result']
base = operation['input']['direct_import']['base']
outputs = Path(os.environ['PROOF_OUTPUTS']).read_text()
run = os.environ['PROOF_RUN']


def target(*argv):
    return subprocess.run(['incus', 'exec', os.environ['PROOF_TARGET'], '--', *argv], capture_output=True, text=True, check=False)


def check(condition, message):
    if not condition:
        sys.exit('parity: ' + message)


content = target('cat', base + '/.env').stdout
sentinel = next((line.partition('=')[2].strip('"') for line in content.splitlines() if line.startswith('PROOF_IMPORTED=')), '')
check(re.fullmatch('[a-f0-9]{64}', sentinel) is not None, 'the Node generated its private canary')
check(sentinel not in outputs and base64.b64encode(sentinel.encode()).decode() not in outputs, 'a file value reached native history')
check(set(result) == {'instance_id', 'operation', 'changed', 'key_count'}, 'the result holds only public fields')
check(result['operation'] == 'import' and result['changed'] is True and result['key_count'] >= 2, 'the new file key changed stored configuration')
check(target('sha256sum', base + '/.env').stdout == target('cat', '/tmp/orbit-env-before-' + run).stdout, 'the Node file stays byte-identical')
reply = subprocess.run(['runuser', '-u', 'orbit', '--', 'env', 'HOME=/tmp', 'XDG_CONFIG_HOME=/tmp', 'php', 'artisan', 'tinker', '--execute',
    "$i=App\\Models\\Instance::findOrFail(" + str(operation['target_id']) + "); echo json_encode(['laravel'=>$i->source_is_laravel,'routes'=>$i->routes->where('status',App\\Domain\\Routes\\RouteStatus::Active)->count(),'imported'=>$i->environmentValues()->where('env_key','PROOF_IMPORTED')->sole()->env_value]);"],
    cwd='apps/gateway', capture_output=True, text=True, check=True, timeout=30)
stored = json.loads(reply.stdout.strip())
check(stored == {'laravel': True, 'routes': 1, 'imported': sentinel}, 'the Laravel Instance has its Route and exact imported value')
check(target('sh', '-c', 'ls /tmp/orbit-env-import-* 2>/dev/null').stdout == '', 'no staged copy stays on the Node')
check(glob.glob('/tmp/orbit-env-import-*') == [], 'no copy stays on 209')
print('parity: Laravel Route fixture imports exact private value; source unchanged; no temporary copies or value in native history')
