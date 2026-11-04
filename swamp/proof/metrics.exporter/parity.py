"""metrics.exporter_enable and _disable on real Nodes: Prometheus on the Metrics Node reloads in place (the container is
not restarted) and lists the exporter Node as a target only after enable; disable removes the package."""
import json
import os
import subprocess
import sys

mode = sys.argv[1]
with open(os.environ['PROOF_OPERATION_FILE']) as source:
    operation = json.load(source)


def node(name, *argv):
    return subprocess.run(['incus', 'exec', name, '--', *argv], capture_output=True, text=True, check=False).stdout.strip()


def check(condition, message):
    if not condition:
        sys.exit(f'parity: {message}')


check(operation['status'] == 'succeeded', f'the Operation is {operation["status"]} ({operation.get("error_code")})')
commands = operation['input']['service']['firewall_commands']
check(len(commands) == 6, 'the Gateway captures both exporters and both service rule pairs')
for command, port, comment in zip(commands[:2], ['9100', '9102'], ['orbit:metrics-node-exporter', 'orbit:metrics-cadvisor']):
    prefix = ['ufw'] if mode == 'enable' else ['ufw', '--force', 'delete']
    check(command == [*prefix, 'allow', 'in', 'on', 'orbit', 'proto', 'tcp', 'from', operation['input']['metrics_address'],
                      'to', operation['input']['exporter_address'], 'port', port, 'comment', comment],
          f'the canonical {comment} command retains its complete identity')
address = operation['input']['exporter_address']
targets = node('target', 'curl', '--silent', '--fail', 'http://127.0.0.1:9090/api/v1/targets?state=active')
listed = f'"{address}:9100"' in targets
metrics = node('target', 'curl', '--silent', '--fail', 'http://127.0.0.1:9090/metrics')


def gauge(name):
    line = next((row for row in metrics.splitlines() if row.startswith(name + ' ')), '')
    check(line != '', f'Prometheus reports {name}')
    return float(line.split()[1])


# A reload, not a restart: the last successful configuration load came after the process started.
check(gauge('prometheus_config_last_reload_successful') == 1, 'the last configuration reload succeeded')
check(gauge('prometheus_config_last_reload_success_timestamp_seconds') > gauge('process_start_time_seconds') + 1,
      'Prometheus reloaded its configuration without a restart')
if mode == 'enable':
    check(listed, f'Prometheus lists {address}:9100')
    check(node('metrics', 'systemctl', 'is-active', 'prometheus-node-exporter') == 'active', 'the node exporter runs')
    check(node('metrics', 'systemctl', 'is-active', 'orbit-cadvisor') == 'active', 'cAdvisor runs')
else:
    check(not listed, f'Prometheus no longer lists {address}:9100')
    check(node('metrics', 'sh', '-c', 'dpkg-query -W -f=\'${Status}\' prometheus-node-exporter 2>/dev/null') != 'install ok installed', 'the package is removed')
    check(node('metrics', 'sh', '-c', 'test -e /usr/local/bin/orbit-cadvisor && echo present') == '', 'the cAdvisor binary is removed')
print(f'parity: metrics.exporter {mode} {address}')
