"""The refresh admits the converges of the due nested Nodes together and polls all their Operations in one read
(instance-fleet-converge-node.yml). A read that is not whole, such as a cut first response while curl exits 0, is polled again,
never parsed; a Node whose converge failed fails the refresh with its reason and records no phases."""
import json
import os
import shutil
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

ANSIBLE = Path(__file__).resolve().parents[1] / 'ansible'
TASKS = ANSIBLE / 'tasks/instance-fleet-converge-node.yml'
COMMAND = shutil.which('ansible-playbook') or str(Path(sys.executable).parent / 'ansible-playbook')
NEEDS_ANSIBLE = unittest.skipUnless(shutil.which('ansible-playbook') or (Path(sys.executable).parent / 'ansible-playbook').exists(),
                                    'ansible-playbook is not installed')

# A stand-in for the nested Gateway behind curl. An admission answers Operation <node id>00. Each poll takes the next line of
# $STUB_DIR/polls: a JSON list with one [body, exit code] per URL, written as curl writes them with `-w '\n%{exitcode}\n'`;
# curl's own exit code is the last transfer's. The last line repeats.
CURL = r"""#!/usr/bin/env python3
import json, os, re, sys
stub = os.environ['STUB_DIR']
args = sys.argv[1:]
urls = [arg for arg in args if arg.startswith('https://')]
with open(os.path.join(stub, 'calls.log'), 'a') as log:
    log.write(' '.join(args) + '\n')
if '-X' in args:
    node = re.search(r'/nodes/(\d+)/converge$', urls[0]).group(1)
    print(json.dumps({'data': {'id': node + '00'}}))
    sys.exit(0)
polls = open(os.path.join(stub, 'polls')).read().splitlines()
count = os.path.join(stub, 'count')
index = int(open(count).read()) if os.path.exists(count) else 0
open(count, 'w').write(str(index + 1))
answers = json.loads(polls[min(index, len(polls) - 1)])
assert len(answers) == len(urls), (answers, urls)
for body, code in answers:
    sys.stdout.write(body + '\n' + str(code) + '\n')
sys.exit(answers[-1][1])
"""


def operation(node, status):
    return json.dumps({'data': {'id': f'{node}00', 'status': status,
                                'result': {'changed': 2, 'phases': [{'name': 'input', 'status': 'ran'},
                                                                    {'name': 'roles', 'status': 'ran'}]}}})


@NEEDS_ANSIBLE
class FleetConvergePollTest(unittest.TestCase):
    def setUp(self):
        self.folder = Path(tempfile.mkdtemp())
        self.addCleanup(shutil.rmtree, self.folder)
        (self.folder / 'curl').write_text(CURL)
        (self.folder / 'curl').chmod(0o755)

    def converge(self, polls, seconds=(900, 1800)):
        """Run the converge of nested-app-prod (7) and nested-app-dev (8) on this machine; returns the exit code, the output and
        the facts the run left."""
        (self.folder / 'polls').write_text(''.join(json.dumps(poll) + '\n' for poll in polls))
        facts = self.folder / 'facts.json'
        # The counts start as play variables: the run's set_fact adds to them, which an extra variable would override.
        play = [{'hosts': 'outer', 'gather_facts': False, 'vars': {'fleet_converge_changed': 0, 'fleet_converge_changed_roles': []}, 'tasks': [
            {'ansible.builtin.include_tasks': str(TASKS)},
            {'ansible.builtin.copy': {'dest': str(facts), 'content': '{{ {"phases": fleet_converge_phases | default({}),'
                                      ' "changed": fleet_converge_changed, "roles": fleet_converge_changed_roles} | to_json }}'}},
        ]}]
        (self.folder / 'play.yml').write_text(json.dumps(play))
        (self.folder / 'inventory.yml').write_text(json.dumps({'all': {'hosts': {'outer': {
            'ansible_connection': 'local', 'ansible_python_interpreter': sys.executable}}}}))
        variables = {
            'fleet_api': 'https://hub.orbit.test/api/v1', 'fleet_curl': [str(self.folder / 'curl')], 'fleet_ready_delay': 0,
            'fleet_converge_nodes': [{'id': 7, 'name': 'app-prod', 'body': '{"base":false}', 'seconds': seconds[0]},
                                     {'id': 8, 'name': 'app-dev', 'body': '{"base":false}', 'seconds': seconds[1]}],
        }
        result = subprocess.run([COMMAND, '-i', str(self.folder / 'inventory.yml'), '-e', json.dumps(variables), str(self.folder / 'play.yml')],
                                check=False, capture_output=True, text=True, timeout=120, env={
                                    **os.environ, 'STUB_DIR': str(self.folder), 'ANSIBLE_CONFIG': str(ANSIBLE / 'ansible.cfg'),
                                    'ANSIBLE_FORCE_COLOR': '0'})
        return result.returncode, result.stdout + result.stderr, json.loads(facts.read_text()) if facts.exists() else None

    def polls(self):
        return int((self.folder / 'count').read_text())

    def test_a_cut_first_response_is_polled_again(self):
        code, output, facts = self.converge([
            # The first body is cut (curl 18) while the last transfer exits 0, and so does curl.
            [['{"data":{"id":"700","sta', 18], [operation(8, 'running'), 0]],
            # The first transfer exits 0 with a body that is no JSON object.
            [['<html>bad gateway', 0], [operation(8, 'running'), 0]],
            # The first transfer drops before any body.
            [['', 28], [operation(8, 'succeeded'), 0]],
            [[operation(7, 'running'), 0], [operation(8, 'succeeded'), 0]],
            [[operation(7, 'succeeded'), 0], [operation(8, 'succeeded'), 0]],
        ])
        self.assertEqual(code, 0, output)
        self.assertEqual(self.polls(), 5, output)
        self.assertEqual(facts, {'phases': {'app-prod': ['roles'], 'app-dev': ['roles']}, 'changed': 4, 'roles': ['app-prod', 'app-dev']})

    def test_a_failed_node_fails_the_refresh_with_its_reason_and_records_no_phases(self):
        failed = json.dumps({'data': {'id': '800', 'status': 'failed', 'error_code': 'node.converge_failed', 'result': None}})
        code, output, facts = self.converge([
            [[operation(7, 'running'), 0], [failed, 0]],
            [[operation(7, 'succeeded'), 0], [failed, 0]],
        ])
        self.assertNotEqual(code, 0, output)
        # The poll waited for nested-app-prod to end too, then named only nested-app-dev.
        self.assertEqual(self.polls(), 2, output)
        self.assertIn('The converge of the nested Node app-dev (Operation 800) ended failed with node.converge_failed.', output)
        self.assertNotIn('Node app-prod', output)
        # The run stops at the check: no Node's phases are recorded, the failed one's least of all.
        self.assertIsNone(facts, output)

    def test_a_read_that_never_becomes_whole_fails_with_no_status(self):
        code, output, _ = self.converge([[['{"data":{"id":"700","sta', 18], [operation(8, 'succeeded'), 0]]], seconds=(10, 10))
        self.assertNotEqual(code, 0, output)
        # Two retries after the first read, by the largest bound of 10 seconds.
        self.assertEqual(self.polls(), 3, output)
        self.assertIn('The converge of the nested Node app-prod (Operation 700) ended with no status.', output)
        self.assertIn('The converge of the nested Node app-dev (Operation 800) ended with no status.', output)


if __name__ == '__main__':
    unittest.main()
