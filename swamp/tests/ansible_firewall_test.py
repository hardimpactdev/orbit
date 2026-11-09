"""Evaluate the shipped firewall comparisons with the pinned Ansible engine."""
import json
import os
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

import yaml

TASKS = Path(__file__).resolve().parents[1] / 'ansible/tasks/firewall.yml'


class AnsibleFirewallTest(unittest.TestCase):
    def test_changed_shapes_and_order_select_only_owned_indices(self):
        tasks = yaml.safe_load(TASKS.read_text())
        comparison = [task for task in tasks if 'ansible.builtin.set_fact' in task]
        removal = next(task for task in tasks if task['name'] == 'Remove only changed or misplaced owned rules')
        commands = [
            ['ufw', 'allow', 'in', 'on', 'orbit', 'to', '10.231.0.11', 'comment', 'orbit:wireguard-members'],
            ['ufw', 'insert', '1', 'deny', 'in', 'on', 'orbit', 'proto', 'tcp', 'from', 'any', 'to',
             '10.231.0.11', 'port', '3000', 'comment', 'orbit:metrics-grafana-isolation'],
            ['ufw', 'insert', '1', 'allow', 'in', 'on', 'orbit', 'proto', 'tcp', 'from', '10.231.0.1', 'to',
             '10.231.0.11', 'port', '3000', 'comment', 'orbit:metrics-grafana-upstream'],
        ]
        allow = "ufw allow in on orbit from 10.231.0.1 to 10.231.0.11 port 3000 proto tcp comment 'orbit:metrics-grafana-upstream'"
        deny = "ufw deny in on orbit to 10.231.0.11 port 3000 proto tcp comment 'orbit:metrics-grafana-isolation'"
        cases = [
            ('unchanged', [allow, deny], ['upstream', 'isolation', 'unrelated', 'members'], []),
            ('wrong port', [allow, deny.replace('3000', '3001')], ['upstream', 'isolation', 'unrelated', 'members'], [2, 1]),
            ('reversed pair', [allow, deny], ['isolation', 'upstream', 'unrelated', 'members'], [2, 1]),
            ('below trust', [allow, deny], ['members', 'unrelated', 'upstream', 'isolation'], [4, 3]),
        ]
        plays = []
        for name, added, ordered, expected in cases:
            comments = {'upstream': 'metrics-grafana-upstream', 'isolation': 'metrics-grafana-isolation',
                        'members': 'wireguard-members', 'unrelated': 'unrelated-rule'}
            numbered = '\n'.join(f'[ {index}] rule # orbit:{comments[item]}' for index, item in enumerate(ordered, 1))
            plays.append({
                'name': name, 'hosts': 'localhost', 'gather_facts': False,
                'vars': {'firewall_commands': commands, 'intent': {'firewall': {'desired': commands}},
                         'firewall_added': {'stdout_lines': added}, 'firewall_numbered': {'stdout': numbered},
                         'expected_indices': expected},
                'tasks': [*comparison,
                          {'ansible.builtin.set_fact': {'repair_indices': removal['loop']}},
                          {'ansible.builtin.assert': {'that': 'repair_indices == expected_indices'}}],
            })
        with tempfile.TemporaryDirectory() as directory:
            playbook = Path(directory) / 'comparison.yml'
            playbook.write_text(json.dumps(plays))
            config = Path(directory) / 'ansible.cfg'
            config.write_text('[defaults]\n')
            result = subprocess.run(
                [str(Path(sys.executable).with_name('ansible-playbook')), '-i', 'localhost,', '-c', 'local', str(playbook)],
                capture_output=True, text=True, check=False,
                env={**os.environ, 'ANSIBLE_CONFIG': str(config), 'ANSIBLE_NOCOLOR': '1'},
            )
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)

    def test_node_allows_move_with_their_pair_and_strays_go(self):
        tasks = yaml.safe_load(TASKS.read_text())
        comparison = [task for task in tasks if 'ansible.builtin.set_fact' in task]
        removal = next(task for task in tasks if task['name'] == 'Remove only changed or misplaced owned rules')
        guard = ['in', 'on', 'orbit', 'proto', 'tcp']
        commands = [
            ['ufw', 'allow', 'in', 'on', 'orbit', 'to', '10.44.0.3', 'comment', 'orbit:wireguard-members'],
            ['ufw', 'insert', '1', 'deny', *guard, 'from', 'any', 'to', '10.44.0.3', 'port', '8318', 'comment', 'orbit:ai-router-task-guard-deny'],
            ['ufw', 'insert', '1', 'allow', *guard, 'from', '10.44.0.128/25', 'to', '10.44.0.3', 'port', '8318', 'comment', 'orbit:ai-router-task-guard'],
            ['ufw', 'insert', '1', 'allow', *guard, 'from', '10.44.0.7', 'to', '10.44.0.3', 'port', '8318', 'comment', 'orbit:ai-router-task-guard-node-7'],
        ]
        added = [
            "ufw allow in on orbit from 10.44.0.128/25 to 10.44.0.3 port 8318 proto tcp comment 'orbit:ai-router-task-guard'",
            "ufw deny in on orbit to 10.44.0.3 port 8318 proto tcp comment 'orbit:ai-router-task-guard-deny'",
            "ufw allow in on orbit from 10.44.0.7 to 10.44.0.3 port 8318 proto tcp comment 'orbit:ai-router-task-guard-node-7'",
        ]
        comments = {'allow': 'ai-router-task-guard', 'deny': 'ai-router-task-guard-deny', 'node7': 'ai-router-task-guard-node-7',
                    'node9': 'ai-router-task-guard-node-9', 'members': 'wireguard-members'}
        cases = [
            ('in order', ['node7', 'allow', 'deny', 'members'], []),
            ('Node allow below the deny', ['allow', 'deny', 'node7', 'members'], [3, 2, 1]),
            ('pair re-placed with its Node allow', ['node7', 'deny', 'allow', 'members'], [3, 2, 1]),
            ('stray Node allow', ['node9', 'node7', 'allow', 'deny', 'members'], [1]),
        ]
        plays = []
        for name, ordered, expected in cases:
            numbered = '\n'.join(f'[ {index}] rule # orbit:{comments[item]}' for index, item in enumerate(ordered, 1))
            plays.append({
                'name': name, 'hosts': 'localhost', 'gather_facts': False,
                'vars': {'firewall_commands': commands, 'intent': {'firewall': {'desired': commands}},
                         'firewall_added': {'stdout_lines': added}, 'firewall_numbered': {'stdout': numbered},
                         'expected_indices': expected},
                'tasks': [*comparison,
                          {'ansible.builtin.set_fact': {'repair_indices': removal['loop']}},
                          {'ansible.builtin.assert': {'that': 'repair_indices == expected_indices'}}],
            })
        with tempfile.TemporaryDirectory() as directory:
            playbook = Path(directory) / 'comparison.yml'
            playbook.write_text(json.dumps(plays))
            config = Path(directory) / 'ansible.cfg'
            config.write_text('[defaults]\n')
            result = subprocess.run(
                [str(Path(sys.executable).with_name('ansible-playbook')), '-i', 'localhost,', '-c', 'local', str(playbook)],
                capture_output=True, text=True, check=False,
                env={**os.environ, 'ANSIBLE_CONFIG': str(config), 'ANSIBLE_NOCOLOR': '1'},
            )
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)


if __name__ == '__main__':
    unittest.main()
