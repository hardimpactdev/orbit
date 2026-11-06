"""A task VM opens its firewall to the nested bridge for DHCP, DNS and routing, and to nothing else."""
import json
import os
import shutil
import subprocess
import tempfile
import unittest
from pathlib import Path

import yaml

ROOT = Path(__file__).resolve().parents[2]
ANSIBLE = ROOT / 'swamp/ansible'
# A stand-in for community.general.ufw: it records the rule it was asked for.
STUB = '''from ansible.module_utils.basic import AnsibleModule


def main():
    module = AnsibleModule(argument_spec={key: {'type': 'raw'} for key in (
        'rule', 'direction', 'interface', 'interface_in', 'proto', 'to_port', 'route', 'comment')})
    with open(__import__('os').environ['RULES'], 'a') as handle:
        handle.write(__import__('json').dumps(module.params, sort_keys=True) + '\\n')
    module.exit_json(changed=True)


main()
'''


class TaskVmNestedFirewallTest(unittest.TestCase):
    def test_the_role_includes_it_in_the_tools_phase_after_incus(self):
        tools = yaml.safe_load((ANSIBLE / 'tasks/task-vm-tools.yml').read_text())
        names = [task['name'] for task in tools]
        self.assertLess(names.index('Initialise Incus'), names.index('Open the firewall to the nested bridge'))
        self.assertEqual(tools[names.index('Open the firewall to the nested bridge')]['ansible.builtin.import_tasks'],
                         'task-vm-nested-firewall.yml')

    def rules(self, nfs):
        folder = Path(tempfile.mkdtemp())
        self.addCleanup(shutil.rmtree, folder, True)
        modules = folder / 'collections/ansible_collections/community/general/plugins/modules'
        modules.mkdir(parents=True)
        (modules / 'ufw.py').write_text(STUB)
        play = [{'hosts': 'localhost', 'gather_facts': False, 'vars': {'intent': {'nfs': nfs}},
                 'tasks': [{'ansible.builtin.import_tasks': str(ANSIBLE / 'tasks/task-vm-nested-firewall.yml')}]}]
        (folder / 'play.yml').write_text(yaml.safe_dump(play))
        environment = {**os.environ, 'RULES': str(folder / 'rules'), 'ANSIBLE_COLLECTIONS_PATH': str(folder / 'collections'),
                       'ANSIBLE_HOME': str(folder / 'home'), 'ANSIBLE_LOCAL_TEMP': str(folder / 'tmp')}
        subprocess.run(['ansible-playbook', '-i', 'localhost,', '-c', 'local', str(folder / 'play.yml')],
                       check=True, capture_output=True, env=environment)
        path = folder / 'rules'
        return [json.loads(line) for line in path.read_text().splitlines()] if path.exists() else []

    @unittest.skipUnless(shutil.which('ansible-playbook'), 'ansible-playbook is not installed')
    def test_the_bridge_gets_dhcp_dns_the_package_cache_and_a_way_out_only(self):
        rules = self.rules({'checkout': '/home/orbit/c', 'subnet': '10.232.2.0/24', 'interface': 'nested'})
        ports = sorted((rule['proto'], rule['to_port']) for rule in rules if not rule['route'])
        self.assertEqual(ports, [('tcp', '3142'), ('tcp', '53'), ('udp', '53'), ('udp', '67')])
        self.assertTrue(all(rule['interface'] == 'nested' and rule['direction'] == 'in' for rule in rules if not rule['route']))
        routed = [rule for rule in rules if rule['route']]
        self.assertEqual(len(routed), 1)
        self.assertEqual(routed[0]['interface_in'], 'nested')

    @unittest.skipUnless(shutil.which('ansible-playbook'), 'ansible-playbook is not installed')
    def test_an_image_opens_nothing(self):
        self.assertEqual(self.rules(None), [])


if __name__ == '__main__':
    unittest.main()
