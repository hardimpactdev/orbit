"""env:sync reconciles the path to an attached websocket app in a running container (instance-connection-environment.yml).

The two decision tasks run under the pinned Ansible with the plan and the container's current rules as facts: the egress rules the
ACL edit writes, and the UFW lines the run deletes. Database rules stay add-only; only the rule the plan names for the app stays.
"""
import json
import shutil
import subprocess
import tempfile
import unittest
from pathlib import Path

import yaml

ROOT = Path(__file__).resolve().parents[2]
PLAYBOOK = ROOT / 'swamp/ansible/instance-connection-environment.yml'
TASKS = ('Reconcile the websocket egress rule', 'Find the websocket firewall rules the plan no longer names')
COMMENT = 'orbit:instance-7-websocket'
DATABASE = {'action': 'allow', 'destination': '10.44.0.7/32', 'protocol': 'tcp', 'destination_port': '3306', 'state': 'enabled'}
NODE_SERVICE = {'action': 'allow', 'destination': '10.44.0.3/32', 'protocol': 'tcp', 'destination_port': '6379', 'state': 'enabled',
                'description': 'Orbit Node service:6379'}


def websocket(host):
    return {'egress': {'action': 'allow', 'destination': f'{host}/32', 'protocol': 'tcp', 'destination_port': '8443', 'state': 'enabled',
                       'description': 'Orbit websocket app'},
            'bridge_endpoint': {'host': host, 'port': 8443, 'docker': False},
            'firewall': {'rule': 'allow', 'interface_in': 'orbitinst0', 'from_ip': '10.253.0.2', 'to_ip': host, 'to_port': '8443',
                         'proto': 'tcp', 'comment': COMMENT, 'route': 'yes', 'interface_out': 'orbit'}}


def ufw_line(host):
    return f"ufw route allow in on orbitinst0 out on orbit from 10.253.0.2 to {host} port 8443 proto tcp comment '{COMMENT}'"


class WebSocketReconcileTest(unittest.TestCase):
    def setUp(self):
        self.executable = shutil.which('ansible-playbook')
        self.assertIsNotNone(self.executable, 'Pinned Ansible is required')
        tasks = yaml.safe_load(PLAYBOOK.read_text())[1]['tasks'][0]['block']
        self.tasks = [next(task for task in tasks if task['name'] == name) for name in TASKS]

    def run_tasks(self, plan_websocket, egress, firewall_lines, missing=()):
        plan = {'websocket': plan_websocket, 'websocket_comment': COMMENT}
        with tempfile.TemporaryDirectory() as folder:
            out = Path(folder) / 'out.json'
            play = [{'hosts': 'localhost', 'connection': 'local', 'gather_facts': False, 'vars': {'plan': plan}, 'tasks': [
                {'name': 'Seed', 'ansible.builtin.set_fact': {'connection_acl': {'egress': egress}, 'connection_missing_egress': list(missing),
                                                               'connection_firewall': {'stdout_lines': firewall_lines}}},
                *self.tasks,
                {'name': 'Keep', 'ansible.builtin.copy': {'dest': str(out), 'content': '{{ {"egress": connection_egress, '
                 '"changed": connection_websocket_changed | bool, "stale": connection_stale_firewall} | to_json }}'}}]}]
            path = Path(folder) / 'play.yml'
            path.write_text(yaml.safe_dump(play))
            result = subprocess.run([self.executable, '-i', 'localhost,', str(path)], capture_output=True, text=True, timeout=60)
            self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
            return json.loads(out.read_text())

    def test_an_attached_app_adds_its_rule_once_and_keeps_the_other_rules(self):
        added = self.run_tasks(websocket('10.44.0.3'), [DATABASE, NODE_SERVICE], [])
        self.assertTrue(added['changed'])
        self.assertEqual(added['egress'], [DATABASE, NODE_SERVICE, websocket('10.44.0.3')['egress']])
        self.assertEqual(added['stale'], [])
        again = self.run_tasks(websocket('10.44.0.3'), added['egress'], [ufw_line('10.44.0.3')])
        self.assertFalse(again['changed'])
        self.assertEqual(again['egress'], added['egress'])
        self.assertEqual(again['stale'], [])

    def test_a_removed_app_closes_the_path_and_keeps_every_other_rule(self):
        other = "ufw allow in on orbitinst0 from 10.253.0.2 to 10.44.0.7 port 3306 proto tcp comment 'orbit:instance-7-database'"
        removed = self.run_tasks(None, [DATABASE, websocket('10.44.0.3')['egress'], NODE_SERVICE], [other, ufw_line('10.44.0.3')])
        self.assertTrue(removed['changed'])
        self.assertEqual(removed['egress'], [DATABASE, NODE_SERVICE])
        self.assertEqual(removed['stale'], [ufw_line('10.44.0.3')])

    def test_a_moved_websocket_node_replaces_the_old_address(self):
        moved = self.run_tasks(websocket('10.44.0.20'), [websocket('10.44.0.3')['egress']], [ufw_line('10.44.0.3'), ufw_line('10.44.0.20')])
        self.assertTrue(moved['changed'])
        self.assertEqual(moved['egress'], [websocket('10.44.0.20')['egress']])
        self.assertEqual(moved['stale'], [ufw_line('10.44.0.3')])

    def test_an_instance_without_an_app_changes_nothing(self):
        plain = self.run_tasks(None, [DATABASE], [], missing=[])
        self.assertFalse(plain['changed'])
        self.assertEqual(plain['egress'], [DATABASE])
        self.assertEqual(plain['stale'], [])


if __name__ == '__main__':
    unittest.main()
