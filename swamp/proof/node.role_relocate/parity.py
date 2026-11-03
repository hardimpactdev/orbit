"""node.role_relocate on real Nodes: Metrics serves from peer, and its runtime is gone from the metrics Node."""
import json
import os
import subprocess
import sys

with open(os.environ['PROOF_OPERATION_FILE']) as source:
    operation = json.load(source)


def node(name, *argv):
    return subprocess.run(['incus', 'exec', name, '--', *argv], capture_output=True, text=True, check=False)


def check(condition, message):
    if not condition:
        sys.exit(f'parity: {message}')


check(operation['status'] == 'succeeded', f'the Operation is {operation["status"]} ({operation.get("error_code")})')
intent = operation['input']['intent']
check(intent['role'] == 'metrics', 'the fixture moves the metrics role')
check(operation['result'].get('relocated') is True and operation['result'].get('node_id') == intent['target_node_id'],
      'the Gateway records the move to the target')
running = "{{.State.Running}}"
for container in ('orbit-metrics-prometheus', 'orbit-metrics-grafana'):
    check(node('peer', 'docker', 'inspect', '--format', running, container).stdout.strip() == 'true', f'{container} runs on peer')
    check(node('metrics', 'docker', 'inspect', container).returncode != 0, f'{container} is gone from the metrics Node')
check(node('peer', 'curl', '--silent', '--fail', '--output', '/dev/null', 'http://127.0.0.1:9090/-/ready').returncode == 0,
      'Prometheus answers on peer')
check(node('metrics', 'test', '-e', '/etc/orbit/metrics/prometheus.yml').returncode != 0, 'the metrics Node keeps no Prometheus configuration')
print(f'parity: node.role_relocate metrics {intent["source_node_id"]} -> {intent["target_node_id"]}')
