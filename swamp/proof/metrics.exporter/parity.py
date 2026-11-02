"""metrics.exporter_enable and _disable on real Nodes: Prometheus on the Metrics Node reloads in place (the container is
not restarted) and lists the exporter Node as a target only after enable; disable removes the package."""
import json
import os
import subprocess
import sys
from datetime import datetime

mode = sys.argv[1]
operation = json.loads(os.environ['PROOF_OPERATION'])


def node(name, *argv):
    return subprocess.run(['incus', 'exec', name, '--', *argv], capture_output=True, text=True, check=False).stdout.strip()


def check(condition, message):
    if not condition:
        sys.exit(f'parity: {message}')


check(operation['status'] == 'succeeded', f'the Operation is {operation["status"]} ({operation.get("error_code")})')
address = operation['input']['exporter_address']
targets = node('target', 'curl', '--silent', '--fail', 'http://127.0.0.1:9090/api/v1/targets?state=active')
listed = f'"{address}:9100"' in targets
started = node('target', 'docker', 'inspect', '--format', '{{.State.StartedAt}}', 'orbit-metrics-prometheus')
check(started != '', 'Prometheus runs on the Metrics Node')
began = datetime.fromisoformat(operation['started_at'].replace('Z', '+00:00'))
check(datetime.fromisoformat(started[:26].rstrip('Z') + '+00:00') < began, f'Prometheus was reloaded, not restarted ({started})')
if mode == 'enable':
    check(listed, f'Prometheus lists {address}:9100')
    check(node('metrics', 'systemctl', 'is-active', 'prometheus-node-exporter') == 'active', 'the node exporter runs')
    check(node('metrics', 'systemctl', 'is-active', 'orbit-cadvisor') == 'active', 'cAdvisor runs')
else:
    check(not listed, f'Prometheus no longer lists {address}:9100')
    check(node('metrics', 'sh', '-c', 'dpkg-query -W -f=\'${Status}\' prometheus-node-exporter 2>/dev/null') != 'install ok installed', 'the package is removed')
    check(node('metrics', 'sh', '-c', 'test -e /usr/local/bin/orbit-cadvisor && echo present') == '', 'the cAdvisor binary is removed')
print(f'parity: metrics.exporter {mode} {address}')
