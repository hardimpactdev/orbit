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


if __name__ == '__main__':
    unittest.main()
