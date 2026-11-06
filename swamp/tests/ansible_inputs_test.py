"""Check the private Ansible input contract without contacting Nodes."""
import importlib.util
import json
import os
import tempfile
import unittest
from pathlib import Path

spec = importlib.util.spec_from_file_location('build_vars', Path(__file__).resolve().parents[1] / 'ansible/build_vars.py')
builder = importlib.util.module_from_spec(spec)
spec.loader.exec_module(builder)


class AnsibleInputTest(unittest.TestCase):
    def test_admitted_identity_and_secret_file_custody(self):
        previous = os.umask(0o022)
        self.addCleanup(os.umask, previous)
        with tempfile.TemporaryDirectory() as directory:
            folder = Path(directory)
            intent = {
                'node_id': 12,
                'ssh': {'identity_file': '/role/identity', 'target': {
                    'host': '192.0.2.12', 'port': 2222, 'user': 'orbit', 'host_keys': ['ssh-ed25519 pinned'],
                }},
                'publications': [], 'exporters': [], 'prometheus': None, 'runtime': {'analytics': None},
                'configuration': '{{ upstream_template }}', 'setting': 'on',
            }
            (folder / 'intent.json').write_text(json.dumps(intent))
            (folder / 'plans.json').write_text('{}')
            (folder / 'secret-metrics_password').write_text('private fixture value')
            builder.build(folder, 'node.role_add')
            host = json.loads((folder / 'inventory.yml').read_text())['all']['hosts']['n12']
            self.assertEqual(host['ansible_ssh_private_key_file'], '/role/identity')
            self.assertEqual(host['ansible_port'], 2222)
            self.assertEqual((folder / 'known_hosts').read_text(), '[192.0.2.12]:2222 ssh-ed25519 pinned\n')
            self.assertFalse((folder / 'secret-metrics_password').exists())
            text = (folder / 'vars.yml').read_text()
            self.assertIn('!unsafe "{{ upstream_template }}"', text)
            self.assertIn('"setting": "on"', text)
            self.assertIn('"metrics_password": "private fixture value"', text)
            for name in ['inventory.yml', 'vars.yml', 'known_hosts']:
                self.assertEqual((folder / name).stat().st_mode & 0o777, 0o600)

    def test_metrics_container_plan_preserves_the_admitted_state(self):
        with tempfile.TemporaryDirectory() as directory:
            folder = Path(directory)
            service = {
                'name': 'prometheus', 'image': 'prometheus@sha256:pinned',
                'check': {'restart': 'unless-stopped', 'volumes': ['data:/data'], 'ports': []},
                'volume_args': ['volume', 'create', '--label', 'orbit.role=metrics', 'data'],
                'argv': ['docker', 'run', '--label', 'orbit.role=metrics', '--env', 'VALUE=a=b',
                         '--log-driver', 'json-file', '--log-opt', 'max-size=10m', '--network', 'host',
                         '--health-cmd', 'wget localhost:9090', '--health-start-period', '30s',
                         '--health-interval', '10s', '--health-retries', '3', '--',
                         'prometheus@sha256:pinned', '--config.file=/etc/prometheus.yml'],
            }
            intent = {
                'node_id': 12, 'ssh': {'identity_file': '/key', 'target': {
                    'host': '192.0.2.12', 'port': 22, 'user': 'orbit', 'host_keys': ['ssh-ed25519 pinned']}},
                'publications': [], 'exporters': [], 'prometheus': None,
                'runtime': {'analytics': None, 'metrics': {'plan': {'services': [service]}}},
            }
            (folder / 'intent.json').write_text(json.dumps(intent))
            (folder / 'plans.json').write_text('{}')
            builder.build(folder, 'node.role_add')
            container = json.loads((folder / 'vars.yml').read_text())['containers'][0]
            self.assertEqual(container['check'], service['check'])
            self.assertEqual(container['env'], {'VALUE': 'a=b'})
            self.assertEqual(container['labels'], {'orbit.role': 'metrics'})
            self.assertEqual(container['volume'], {'name': 'data', 'labels': {'orbit.role': 'metrics'}})
            self.assertEqual(container['command'], ['--config.file=/etc/prometheus.yml'])
            self.assertEqual(container['healthcheck']['retries'], 3)
            self.assertEqual(container['log_options'], {'max-size': '10m'})

    def test_a_tool_manager_setup_reaches_its_node_with_the_admitted_host_keys(self):
        with tempfile.TemporaryDirectory() as directory:
            folder = Path(directory)
            intent = {'ssh': {'identity_file': '/key', 'target': {
                'host': '192.0.2.12', 'port': 2222, 'user': 'orbit', 'host_keys': ['ssh-ed25519 pinned']}},
                'plan': {'files': [{'content': '{{ kept }}'}]}}
            (folder / 'intent.json').write_text(json.dumps(intent))
            builder.build(folder, 'tool.manager_setup')
            inventory = json.loads((folder / 'inventory.yml').read_text())['all']['children']['manager_node']['hosts']['tm-node']
            self.assertEqual((inventory['ansible_host'], inventory['ansible_port'], inventory['ansible_user']), ('192.0.2.12', 2222, 'orbit'))
            self.assertEqual((folder / 'known_hosts').read_text(), '[192.0.2.12]:2222 ssh-ed25519 pinned\n')
            self.assertIn('!unsafe "{{ kept }}"', (folder / 'vars.yml').read_text())


if __name__ == '__main__':
    unittest.main()
