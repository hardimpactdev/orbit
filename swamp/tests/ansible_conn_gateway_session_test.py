"""The clone's Gateway session: 10 years on the Conn Server, the default on the TypeScript T3 server (docs/reference/conn.md#session-lifetimes)."""
import unittest
from pathlib import Path

import yaml
from jinja2 import Environment

PHASE = Path(__file__).resolve().parents[1] / 'ansible/tasks/instance-container-phase.yml'
AGENT = ['incus', 'exec', 'instance-7', '--', 'runuser', '-u', 'agent', '--']


def task(tasks, name):
    for entry in tasks:
        if entry.get('name') == name:
            return entry
        found = task(entry.get('block', []), name)
        if found is not None:
            return found
    return None


def argv(program, item):
    issue = task(yaml.safe_load(PHASE.read_text()), 'Issue the sessions')
    expression = issue['ansible.builtin.command']['argv'].strip()
    return Environment().compile_expression(expression[2:-2].strip())(
        as_agent=AGENT, item=item, container={'clone': {'t3': {'t3': program}}})


class GatewaySessionTest(unittest.TestCase):
    def test_conn_server_issues_a_ten_year_gateway_session(self):
        self.assertEqual(argv('/opt/orbit-agent/current/agent/bin/conn-server', 'Orbit Gateway')[len(AGENT):],
                         ['/opt/orbit-agent/current/agent/bin/conn-server', 'auth', 'session', 'issue', '--label', 'Orbit Gateway',
                          '--ttl', '3650d', '--token-only'])

    def test_swamp_session_and_typescript_server_keep_the_default(self):
        self.assertNotIn('--ttl', argv('/opt/orbit-agent/current/agent/bin/conn-server', 'Orbit Swamp'))
        self.assertNotIn('--ttl', argv('/opt/orbit-agent/current/agent/bin/t3', 'Orbit Gateway'))


if __name__ == '__main__':
    unittest.main()
