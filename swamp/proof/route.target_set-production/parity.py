"""A real production pool change: both workload probes ran before the Router switched."""
import json
import os
import subprocess
from pathlib import Path

operation = json.loads(Path(os.environ['PROOF_OPERATION_FILE']).read_text())
assert operation['kind'] == 'route.target_set' and operation['status'] == 'succeeded'
assert operation['result']['ready'] is True, operation['result']
plan = operation['input']['route_target']['plan']
assert len(plan['probes']) == 2, plan['probes']
assert plan['probe_ssh']['control_node_id'] == 209
assert len(plan['router']['site_nodes']) == 1
router_id = str(plan['router']['site_nodes'][0])
assert plan['nodes'][router_id]['host'] == '10.231.0.11'
expected = next(site['content'] for site in plan['router']['caddy'] if str(site['node_id']) == router_id)
actual = subprocess.check_output(['incus', 'exec', 'metrics', '--', 'cat', '/etc/caddy/Caddyfile'], text=True, timeout=30)
assert actual == expected
assert '10.231.0.10:443' in actual and '10.231.0.12:443' in actual
for name in ('target', 'peer', 'metrics'):
    subprocess.run(['incus', 'exec', name, '--', 'systemctl', 'is-active', '--quiet', 'caddy'], check=True, timeout=30)
    subprocess.run(['incus', 'exec', name, '--', 'caddy', 'validate', '--config', '/etc/caddy/Caddyfile', '--adapter', 'caddyfile'], check=True, timeout=60)
print('Production pool adds the peer after two required TLS probe phases; the Router file equals the captured pool and all Caddy services remain active.')

for node in ('target', 'peer'):
    composer = json.loads(subprocess.check_output(['incus','exec',node,'--','cat','/home/orbit-app-9009/releases/initial/composer.json'],text=True,timeout=30))
    assert 'laravel/framework' in composer['require']
    subprocess.run(['incus','exec',node,'--','systemctl','is-active','--quiet','orbit-orbit-app-9009-php8.5-fpm.service'],check=True,timeout=30)
