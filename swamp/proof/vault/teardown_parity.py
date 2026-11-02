"""Check Gateway records and actual disposable Vaultwarden items after teardown."""
import json
import os
import shutil
import subprocess
import sys
import tempfile
from pathlib import Path

operation = json.loads(os.environ['PROOF_OPERATION'])
keep = len(sys.argv) > 1 and sys.argv[1] == 'keep'
assert operation['kind'] == 'vault.teardown' and operation['status'] == 'succeeded'
assert operation['result'] == {'outcome': 'succeeded', 'data': {'backend': 'absent'}}
assert bool(operation.get('swamp_run_id')) is not keep
assert not list(Path('/tmp').glob('orbit-vault-teardown.*'))
state = Path('/home/orbit/.orbit/swamp-proof')
up = json.loads((state / 'up.json').read_text())
source = Path(__file__).resolve().parents[3]
reply = subprocess.run(['runuser', '-u', 'orbit', '--', 'php', str(source / 'tools/swamp-proof-guest/gateway.php'), 'request'], input=json.dumps({'method': 'GET', 'path': 'vault', 'caller': up['operator_address'], 'seconds': 30, 'operation': False, 'key': 'proof-' + os.environ['PROOF_RUN'] + '-vault-status'}), text=True, capture_output=True, check=True, timeout=60, cwd=source / 'apps/gateway')
status = json.loads(reply.stdout.strip().splitlines()[-1])['response']['data']
assert status['configured'] is False and status['items'] == 0
config = json.loads((state / 'vault-setup/account.json').read_text())
folder = tempfile.mkdtemp(prefix='vault-proof-check-')
env = dict(os.environ, BITWARDENCLI_APPDATA_DIR=folder, BW_NOINTERACTION='true', NODE_EXTRA_CA_CERTS='/usr/local/share/ca-certificates/orbit-root.crt')

def bw(*args, **extra):
    return subprocess.run(['timeout', '60', 'npx', '--yes', '--package=@bitwarden/cli@2026.6.0', 'bw', *args], env={**env, **extra}, text=True, capture_output=True, check=True, timeout=65).stdout

try:
    bw('config', 'server', config['url'])
    bw('login', '--apikey', BW_CLIENTID=config['client_id'], BW_CLIENTSECRET=config['client_secret'])
    env['BW_SESSION'] = bw('unlock', '--passwordenv', 'BW_PASSWORD', '--raw', BW_PASSWORD=config['master_password']).strip()
    bw('sync')
    items = json.loads(bw('list', 'items', '--organizationid', config['organization_id'], '--collectionid', config['collection_id']))
    trash = json.loads(bw('list', 'items', '--trash', '--organizationid', config['organization_id'], '--collectionid', config['collection_id']))
    if keep:
        assert len(items) == 1 and not trash
    else:
        ledger = operation['input']['intent']['ledger']
        assert len(ledger) == 1
        ids = {row['id'] for row in items + trash}
        assert all(row['cipher_id'] not in ids for row in ledger)
finally:
    try:
        bw('logout')
    finally:
        shutil.rmtree(folder)
print('Gateway setup and ledger cleared; disposable Vaultwarden item ' + ('retained without a run.' if keep else 'permanently deleted by native CLI.'))
