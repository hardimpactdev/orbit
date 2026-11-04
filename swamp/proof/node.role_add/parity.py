"""Role-owned resources work through the real Gateway and native workflow."""
import json
import os
import re
import subprocess
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

from common import doctor, gateway

operation = json.loads(Path(os.environ['PROOF_OPERATION_FILE']).read_text())
target, role = sys.argv[1:3]


def run(*argv):
    prefix = [] if target == 'gateway' else ['incus', 'exec', target, '--']
    return subprocess.run([*prefix, *argv], capture_output=True, text=True, check=False)


def check(ok, message):
    if not ok:
        sys.exit('parity: ' + message)


mode = sys.argv[3:]
if mode == ['corrupt-mirror']:
    package = operation['input']['native_role']['caddy_package']
    check(operation['status'] == 'failed', 'corrupt mirror fails the Operation')
    check('/proof-corrupt-mirror/' in package['urls'][0], 'known corrupt mirror is admitted')
    check(len(package['urls']) == 2 and package['urls'][1].startswith('https://github.com/caddyserver/caddy/'),
          'reachable upstream remains admitted')
    state = Path('/home/orbit/.orbit/swamp-proof')
    query = ('workflowRunId == ' + json.dumps(operation['swamp_run_id']) +
             ' && jobName == "prerequisites" && stepName == "Apply role prerequisites"' +
             ' && name == "result" && version >= 0')
    result = subprocess.run([
        'runuser', '-u', 'orbit', '--', 'env', 'HOME=/home/orbit',
        'SWAMP_HOME=' + str(state / 'home'), 'SWAMP_CONFIG_DIR=' + str(state / 'config'),
        'SWAMP_API_KEY_FILE=/home/orbit/.config/swamp/club-api-key',
        '/usr/local/bin/swamp', 'data', 'query', query, '--select', 'content', '--single',
        '--repo-dir', str(Path(__file__).resolve().parents[2]),
        '--server', 'wss://swamp.orbit:443', '--token-file', str(state / 'credentials/operator'),
        '--ca-cert', '/home/orbit/.orbit/ca/root.pem', '--json',
    ], capture_output=True, text=True, check=True, timeout=30)
    # Failed shell outputs are absent from step history; the exact run's durable result remains queryable.
    outputs = json.loads(result.stdout)
    check(outputs['exitCode'] == 2, 'the real Ansible phase failed')

    def texts(value):
        if isinstance(value, str):
            yield value
        elif isinstance(value, dict):
            for child in value.values():
                yield from texts(child)
        elif isinstance(value, list):
            for child in value:
                yield from texts(child)

    failures = []
    for text in texts(outputs):
        for match in re.finditer(r'=>\s*(\{)', text):
            try:
                result, _ = json.JSONDecoder().raw_decode(text[match.start(1):])
            except ValueError:
                continue
            if result.get('dest') == package['path'] and result.get('msg') == 'Artifact SHA-256 mismatch':
                failures.append(result)
    check(len(failures) == 1, 'one real Ansible Caddy download failure is retained')
    failure = failures[0]
    check(failure['attempts'] == [{'url': package['urls'][0], 'exit_code': 0, 'checksum_valid': False}],
          'mirror transferred, real SHA-256 failed, upstream was never attempted')
    check(failure['published'] is False, 'corrupt bytes were never published')
    check(run('test', '-e', package['path']).returncode == 1, 'destination remains absent')
    staging = run('find', str(Path(package['path']).parent), '-maxdepth', '1',
                  '-name', Path(package['path']).name + '.artifact-*', '-print')
    check(staging.returncode == 0 and not staging.stdout.strip(), 'artifact staging was removed')
    check(not Path('/tmp/orbit-ansible-' + operation['swamp_run_id']).exists(), 'private run folder removed')
    print('parity: corrupt mirror rejected by SHA-256; no upstream or publication; staging removed')
    sys.exit(0)

if role == 'swamp':
    installed = run('/opt/orbit/ansible/venv/bin/python', '-c', 'import ansible; print(ansible.__version__)')
    pin = (Path(__file__).resolve().parents[2] / 'ansible/requirements.txt').read_text().strip().split('==')[1]
    check(installed.returncode == 0 and installed.stdout.strip() == pin, 'the Swamp role installs pinned Ansible from a cold environment')
    print('parity: Swamp role installed ansible-core ' + pin, flush=True)
if operation['status'] != 'succeeded' and role == 'websocket':
    state = run('systemctl', 'show', 'orbit-websocket.service', '-p', 'Result', '-p', 'ExecMainStatus')
    sys.stderr.write(state.stdout)
    web = operation['input']['native_role']['runtime']['websocket']
    private = run('cat', web['release'] + '/.env').stdout
    logs = run('journalctl', '-u', 'orbit-websocket.service', '--no-pager', '-n', '20').stdout
    for line in private.splitlines():
        if line and not line.startswith('#') and '=' in line:
            value = line.split('=', 1)[1]
            if len(value) >= 6:
                logs = logs.replace(value, '[REDACTED]')
    sys.stderr.write(logs)
check(operation['status'] == 'succeeded', 'native role-add succeeded')
check(operation['result'].get('role_proven') is True, 'the Gateway records the native end check')
intent = operation['input']['native_role']
check(intent['role'] == role, 'the admitted role is preserved')
if mode == ['missing-mirror']:
    check('/proof-missing-deb-mirror/' in intent['caddy_package']['urls'][0], 'missing mirror admitted first')
    check(run('test', '-f', intent['caddy_package']['path']).returncode == 0, 'upstream artifact published')

for service in intent['services']:
    check(run('systemctl', 'is-active', '--quiet', service['name']).returncode == 0, service['name'] + ' is active')
for resource in [*intent['files'], *intent['runtime']['files']]:
    if resource.get('ensure') == 'absent':
        check(run('test', '-e', resource['path']).returncode == 1, 'retired role file is absent')
    else:
        check(run('cat', resource['path']).stdout == resource['content'], 'the admitted public role file is installed')
for resource in intent['runtime']['secret_files']:
    check(run('stat', '-c', '%a', resource['path']).stdout.strip() == '600', 'private runtime file is mode 0600')
if role in ('app-dev', 'app-prod'):
    check(run('bun', '--version').stdout.strip() == intent['bun']['version'], 'Bun matches the recipe')
    if role == 'app-dev':
        check(run('test', '-d', '/home/orbit/apps').returncode == 0, 'the application storage directory exists')
    for php in intent['php']:
        check(run('systemctl', 'is-active', '--quiet', 'php' + php['version'] + '-fpm.service').returncode == 0,
              'the admitted PHP-FPM version serves')
elif role == 'websocket':
    check(run('systemctl', 'is-active', '--quiet', 'orbit-websocket.service').returncode == 0, 'Reverb serves')
    check(run('readlink', intent['runtime']['websocket']['path']).stdout.strip() == intent['runtime']['websocket']['release'],
          'Reverb uses the admitted release')
elif role == 'metrics':
    check(run('docker', 'inspect', '--format', '{{.State.Running}}', 'orbit-metrics-prometheus').stdout.strip() == 'true',
          'Prometheus runs')
    response = run('curl', '--fail', '--silent', 'http://127.0.0.1:9090/api/v1/status/config')
    check(response.returncode == 0, 'Prometheus exposes its active configuration')
    active = json.loads(response.stdout)['data']['yaml']
    response = subprocess.run(['curl', '--max-time', '15', '--fail', '--silent', '--show-error',
                               '--cacert', '/home/orbit/.orbit/ca/root.pem', '--resolve',
                               'metrics.orbit:443:10.231.0.1', 'https://metrics.orbit/api/health'],
                              capture_output=True, text=True, check=False)
    check(response.returncode == 0, 'the published Metrics HTTPS route serves Grafana: ' + response.stderr)
    for exporter in intent['exporters']:
        if exporter['enabled']:
            address = exporter['ssh_execution']['target']['host']
            check(address + ':9100' in active, 'Prometheus loaded the selected exporter target')
    commands_by_node = {intent['node_id']: intent['firewall']['desired']}
    for exporter in intent['exporters']:
        commands_by_node.setdefault(exporter['exporter_node_id'], []).extend(exporter['service']['firewall_commands'])
    nodes = json.loads(os.environ['PROOF_NODES'])
    for identifier, commands in commands_by_node.items():
        node = next(name for name, value in nodes.items() if value == identifier)
        prefix = [] if node == 'gateway' else ['incus', 'exec', node, '--']
        numbered = subprocess.run([*prefix, 'ufw', 'status', 'numbered'], capture_output=True, text=True, check=True).stdout
        def positions(comment, numbered=numbered):
            return [int(index) for index in re.findall(r'^\[\s*(\d+)\].*# ' + re.escape(comment) + r'\s*$', numbered, re.MULTILINE)]
        trust = positions(intent['firewall']['desired'][0][-1])
        for index, command in enumerate(commands):
            if command[1:4] == ['insert', '1', 'deny']:
                deny, allow = positions(command[-1]), positions(commands[index + 1][-1])
                check(len(allow) == len(deny) == 1 and allow[0] < deny[0] and (not trust or deny[0] < min(trust)),
                      node + ' isolates the service after its specific allow and before broad trust')
        if 'repair-firewall' in sys.argv[3:] and node == 'target':
            added = subprocess.run([*prefix, 'ufw', 'show', 'added'], capture_output=True, text=True, check=True).stdout
            check('proof-kept-exporter-port' in added, 'an unrelated rule on the exporter port survives')
            check('ufw allow from 10.231.0.11 to any port 9100 proto tcp\n' not in added,
                  'only the exact replaced broad exporter tuple is retired')
elif role == 'analytics':
    analytics = intent['runtime']['analytics']
    processes = gateway('GET', 'processes')['response']['data']
    plausible = [item for item in processes if item['name'] == 'plausible' and item['target_id'] == intent['node_id']]
    check(len(plausible) == 1 and plausible[0]['id'] == analytics['process_id'], 'one managed Plausible Process is recorded')
    check(run('curl', '--max-time', '15', '--fail', '--silent', analytics['health_url']).returncode == 0,
          'Plausible serves its health endpoint')
    added = run('ufw', 'show', 'added').stdout.splitlines()
    for rule in intent['firewall']['desired']:
        if 'docker0' in rule:
            matches = [line for line in added if rule[-1] in line]
            check(len(matches) == 1 and ' port ' + rule[rule.index('port') + 1] + ' ' in matches[0],
                  'one owned local storage rule uses the admitted port')
    if 'repair-port' in sys.argv[3:]:
        check(any('proof-kept-other-rule' in line for line in added), 'the unrelated firewall rule survives port repair')
    unchanged = operation['kind'] == 'node.role_converge'
    check(len(analytics['initialize']) == (0 if unchanged else 2), 'only initial admission runs createdb and migrate')
    subprocess.run(['python3', str(Path(__file__).with_name('analytics.py')),
                    'verify' if unchanged else 'capture', operation['input']['_role']['runtime']['installation']['name'], target], check=True)
elif role == 'ci-runner':
    check(run('incus', 'project', 'get', 'orbit-ci', 'restricted').stdout.strip() == 'true', 'CI is restricted')
    check(run('incus', 'network', 'get', 'orbitci0', 'security.acls').stdout.strip() == 'orbit-ci-egress', 'CI egress ACL applies')
    acl = json.loads(run('incus', 'query', '/1.0/network-acls/orbit-ci-egress').stdout)
    check(all(not rule.get(field) for rule in acl['egress'] for field in
              ('source', 'source_port', 'destination_port', 'protocol', 'icmp_type', 'icmp_code')),
          'CI egress rules retain no foreign selector')

if 'instance' in sys.argv[3:]:
    pools = [pool for exporter in intent['exporters'] for pool in exporter['service']['pools']]
    check(bool(pools), 'the Laravel Project exercises a dedicated PHP exporter pool')
    for pool in pools:
        runtime = pool['php_runtime']
        host = next(item['exporter_node_id'] for item in intent['exporters'] if pool in item['service']['pools'])
        node = next(name for name, identifier in json.loads(os.environ['PROOF_NODES']).items() if identifier == host)
        def on(*argv, node=node):
            return subprocess.run(['incus', 'exec', node, '--', *argv], capture_output=True, text=True, check=False)
        metadata = on('stat', '-c', '%U:%G:%a', '/etc/orbit/php-fpm/' + runtime['user'] + '/generated/pool.conf')
        check(metadata.returncode == 0 and metadata.stdout.strip() == 'root:root:644', 'the dedicated pool is root-owned with mode 0644')
        current = on('test', '-e', runtime['home'] + '/current').returncode == 0
        expected = runtime['configuration']['pool' if current else 'initial_pool']
        check(on('cat', '/etc/orbit/php-fpm/' + runtime['user'] + '/generated/pool.conf').stdout == expected,
              'the admitted exporter status pool is installed')
    projects = gateway('GET', 'projects')['response']['data']
    project = next(item for item in projects if item['name'] == 'Role phase web proof')
    instance_run = project['slug'].removeprefix('proof-role-')
    checkout = '/home/orbit/apps/' + project['slug'] + '/default'
    check(subprocess.run(['incus', 'exec', 'target', '--', 'test', '-f', checkout + '/.git/orbit-proof-setup'],
                         check=False).returncode == 0, 'Laravel setup ran')
    database = subprocess.run(['incus', 'exec', 'target', '--', 'python3', '-c',
                               'import sqlite3,sys; print(sqlite3.connect(sys.argv[1]).execute("select count(*) from migrations").fetchone()[0])',
                               checkout + '/database/database.sqlite'], capture_output=True, text=True, check=False)
    check(database.returncode == 0 and int(database.stdout) > 0, 'Laravel migrations ran on the source database')
    domain = 'role-' + instance_run + '.proof.test'
    route = subprocess.run(['curl', '--max-time', '15', '--fail', '--silent', '--show-error',
                            '--cacert', '/home/orbit/.orbit/ca/root.pem', '--resolve',
                            domain + ':443:10.231.0.10', 'https://' + domain + '/up'],
                           capture_output=True, text=True, check=False)
    check(route.returncode == 0, 'the Laravel Route serves: ' + route.stderr)
    print('parity: Laravel setup, database, Route and dedicated exporter pool')
print('parity: node.role_add ' + role)

if 'no-drift' in sys.argv[3:]:
    outputs = json.loads(Path(os.environ['PROOF_OUTPUTS']).read_text())
    recaps = re.findall(r'changed=(\d+)', json.dumps(outputs))
    check(bool(recaps), 'Ansible phase recaps were retained')
    check(all(value == '0' for value in recaps), 'the second run changed no Node state: ' + ','.join(recaps))
    print('parity: all Ansible phases changed=0')

check(not Path('/tmp/orbit-ansible-' + operation['swamp_run_id']).exists(), 'the private Ansible run folder was removed')
# --idempotency-key replays the original admission without another run or Node change.
body = {'role': role, **({'converge_existing': True} if operation['kind'] == 'node.role_converge' else {})}
if role == 'analytics':
    body |= {engine + '_process_id': next(item['id'] for item in processes if item['name'] == 'proof-analytics-' + engine)
             for engine in ['postgres', 'clickhouse']}
replayed = gateway('POST', 'nodes/' + str(intent['node_id']) + '/roles', body, 'proof-' + os.environ['PROOF_RUN'] + '-request')
record = replayed['response']['data']
check((record.get('operation') or record).get('id') == operation['id'], 'the idempotency key returns the same Operation')
print('parity: private inputs removed; original admission replayed')
# Evaluate every participant before failing, so a missing observer cannot hide another Node's drift.
participants = {intent['node_id'], *(item['node_id'] for item in intent['publications']),
                *(item['exporter_node_id'] for item in intent['exporters'])}
if intent['prometheus']:
    participants.add(intent['prometheus']['node_id'])
if intent['runtime']['analytics']:
    participants.add(intent['runtime']['analytics']['clickhouse_node_id'])
families = ['role', 'firewall', *(['process'] if role == 'analytics' else []),
            *(['instance', 'route', 'database_connection'] if 'instance' in sys.argv[3:] else [])]
reports = [doctor(node, families) for node in sorted(participants)]
check(all(reports), 'Doctor requires zero drift and no unverifiable records')
