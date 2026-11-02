"""metrics.enable and metrics.disable on a real Node: enable leaves Prometheus and Grafana running with the stored login and
the role active; disable removes both containers and the configuration but keeps the two data volumes (no purge)."""
import json
import os
import subprocess
import sys

mode = sys.argv[1]
with open(os.environ['PROOF_OPERATION_FILE']) as source:
    operation = json.load(source)


def node(*argv):
    return subprocess.run(['incus', 'exec', 'metrics', '--', *argv], capture_output=True, text=True, check=False).stdout.strip()


def check(condition, message):
    if not condition:
        sys.exit(f'parity: {message}')


check(operation['status'] == 'succeeded', f'the Operation is {operation["status"]} ({operation.get("error_code")})')
running = node('docker', 'ps', '--format', '{{.Names}}').split()
volumes = node('docker', 'volume', 'ls', '--quiet').split()
if mode == 'enable':
    check((operation.get('result') or {}).get('status') == 'active', f'the role is active: {operation.get("result")}')
    check({'orbit-metrics-prometheus', 'orbit-metrics-grafana'} <= set(running), f'both containers run: {running}')
    check(node('curl', '-fs', '-o', '/dev/null', '-w', '%{http_code}', 'http://127.0.0.1:9090/-/ready') == '200', 'Prometheus is ready')
    check(node('stat', '-c', '%a', '/etc/orbit/metrics/grafana/admin-password') == '400', 'the Grafana password file is mode 0400')
else:
    check((operation.get('result') or {}).get('status') == 'removed', f'the role is removed: {operation.get("result")}')
    check(not [n for n in running if n.startswith('orbit-metrics-')], f'no Metrics container runs: {running}')
    check(node('sh', '-c', 'test -e /etc/orbit/metrics && echo present') == '', 'the configuration is gone')
    check({'orbit-metrics-prometheus-data', 'orbit-metrics-grafana-data'} <= set(volumes), f'both data volumes stay: {volumes}')
print(f'parity: metrics.role {mode}')
