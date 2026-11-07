"""The fleet build waits end as soon as a nested container is not running, and use the tool's own retry."""
import importlib.util
import json
import os
import shutil
import sqlite3
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

import yaml

ANSIBLE = Path(__file__).resolve().parents[1] / 'ansible'
PLAYBOOK = ANSIBLE / 'task-vm-fleet-wait.yml'
# The wait itself: the playbook imports it, and so does the fleet build of the converge.
WAIT = ANSIBLE / 'tasks/task-vm-nested-wait.yml'
spec = importlib.util.spec_from_file_location('build_vars', ANSIBLE / 'build_vars.py')
builder = importlib.util.module_from_spec(spec)
spec.loader.exec_module(builder)
COMMAND = shutil.which('ansible-playbook') or str(Path(sys.executable).parent / 'ansible-playbook')
GUESTS = {'gateway': 'nested-gateway', 'app-dev': 'nested-app-dev', 'app-prod': 'nested-app-prod'}
ALL_RUNNING = ''.join(f'{name},RUNNING\n' for name in GUESTS.values())
# The stand-in for `incus`: `list` prints the prepared table, and each `exec` prints the next prepared answer (the last repeats).
STUB = """#!/bin/sh
if [ "$1" = list ]; then cat "$STUB_DIR/list.csv"; exit 0; fi
n=$(cat "$STUB_DIR/count" 2>/dev/null || echo 0); n=$((n + 1)); echo "$n" > "$STUB_DIR/count"
total=$(wc -l < "$STUB_DIR/exec.txt"); line=$(sed -n "$( [ "$n" -gt "$total" ] && echo "$total" || echo "$n" )p" "$STUB_DIR/exec.txt")
if [ "$line" = NOT_RUNNING ]; then echo "Error: Instance is not running" >&2; exit 1; fi
echo "$line"
"""

NEEDS_ANSIBLE = unittest.skipUnless(shutil.which('ansible-playbook') or (Path(sys.executable).parent / 'ansible-playbook').exists(),
                                    'ansible-playbook is not installed')


class TaskVmFleetWaitTest(unittest.TestCase):
    def test_the_waits_use_the_tools_own_retry_and_no_shell(self):
        tasks = {task['name']: task for task in yaml.safe_load(WAIT.read_text())[0]['block']}
        for name in ('Wait for the Operation of the nested Gateway to end', 'Wait for Doctor of the nested Gateway'):
            task = tasks[name]
            self.assertEqual({'until', 'retries', 'delay'} <= set(task), True, name)
            self.assertIn('Instance is not running', task['until'], name)
            self.assertNotIn('shell', task)

    def test_the_fleet_build_and_readiness_intents_are_reached_with_their_pinned_host_key(self):
        intent = {'ssh_execution': {'identity_file': '/role/identity', 'target': {
            'host': '10.252.7.9', 'port': 22, 'user': 'orbit', 'host_keys': ['ssh-ed25519 vm']}}, 'fleet': {'guests': GUESTS}}
        for kind in ('task_vm.fleet_build', 'task_vm.nested_ready'):
            with self.subTest(kind=kind), tempfile.TemporaryDirectory() as folder:
                folder = Path(folder)
                (folder / 'intent.json').write_text(json.dumps(intent))
                builder.build(folder, kind)
                host = json.loads((folder / 'inventory.yml').read_text())['all']['children']['task_vm']['hosts']['vm']
                self.assertEqual(host['ansible_host'], '10.252.7.9')
                self.assertEqual((folder / 'known_hosts').read_text(), '10.252.7.9 ssh-ed25519 vm\n')

    def wait(self, answers, listing=ALL_RUNNING, **variables):
        """Run the playbook on this machine with the stand-in `incus`; returns the exit code, the output and the number of checks."""
        with tempfile.TemporaryDirectory() as folder:
            folder = Path(folder)
            (folder / 'bin').mkdir()
            (folder / 'bin' / 'incus').write_text(STUB)
            (folder / 'bin' / 'incus').chmod(0o755)
            (folder / 'exec.txt').write_text(''.join(answer + '\n' for answer in answers))
            (folder / 'list.csv').write_text(listing)
            (folder / 'inventory.yml').write_text(json.dumps({'all': {'children': {'task_vm': {'hosts': {'vm': {
                'ansible_connection': 'local', 'ansible_python_interpreter': sys.executable}}}}}}))
            extra = {'intent': {'fleet': {'guests': GUESTS}}, 'wait_delay': 1, **variables}
            result = subprocess.run([COMMAND, '-i', str(folder / 'inventory.yml'), '-e', json.dumps(extra), str(PLAYBOOK)], check=False,
                                    capture_output=True, text=True, timeout=120, env={
                                        **os.environ, 'PATH': f"{folder / 'bin'}:{os.environ['PATH']}", 'STUB_DIR': str(folder),
                                        'ANSIBLE_CONFIG': str(ANSIBLE / 'ansible.cfg'), 'ANSIBLE_FORCE_COLOR': '0'})
            count = int((folder / 'count').read_text()) if (folder / 'count').exists() else 0
            return result.returncode, result.stdout + result.stderr, count

    def test_the_reason_query_reads_the_failed_step_and_error_code_of_the_operation(self):
        tasks = {task['name']: task for task in yaml.safe_load(WAIT.read_text())[0]['block']}
        query = tasks['Read why the Operation did not succeed']['ansible.builtin.command']['argv'][-1].replace('{{ wait_operation | int }}', '7')
        database = sqlite3.connect(':memory:')
        database.execute('create table operations (id integer, status text, failed_step text, error_code text)')
        database.executemany('insert into operations values (?, ?, ?, ?)', [
            (7, 'failed', 'Enroll app-dev', 'task_vm_enroll_failed'), (8, 'cancelled', None, None)])
        self.assertEqual(database.execute(query).fetchone()[0], 'at Enroll app-dev code task_vm_enroll_failed')
        self.assertEqual(database.execute(query.replace('= 7', '= 8')).fetchone()[0], '')

    @NEEDS_ANSIBLE
    def test_an_operation_that_ends_succeeded_passes_after_its_checks(self):
        code, output, checks = self.wait(['running', 'running', 'succeeded'], wait_kind='operation', wait_operation=7, wait_seconds=10)
        self.assertEqual(code, 0, output)
        self.assertEqual(checks, 3)

    @NEEDS_ANSIBLE
    def test_a_nested_vm_that_is_not_running_ends_the_wait_at_once_and_is_named(self):
        listing = 'nested-gateway,STOPPED\nnested-app-dev,RUNNING\nnested-app-prod,RUNNING\n'
        code, output, checks = self.wait(['NOT_RUNNING'], listing, wait_kind='operation', wait_operation=7, wait_seconds=600)
        self.assertNotEqual(code, 0)
        self.assertEqual(checks, 1)
        self.assertIn('A nested container is not running: nested-gateway', output)

    @NEEDS_ANSIBLE
    def test_an_operation_that_ends_failed_fails_the_wait(self):
        code, output, checks = self.wait(['failed', 'at Enroll app-dev code task_vm_enroll_failed'], wait_kind='operation', wait_operation=7, wait_seconds=10)
        self.assertNotEqual(code, 0)
        self.assertEqual(checks, 2)
        self.assertIn('ended failed at Enroll app-dev code task_vm_enroll_failed', output)

    @NEEDS_ANSIBLE
    def test_an_operation_that_does_not_end_fails_when_the_checks_run_out(self):
        code, _output, checks = self.wait(['running'], wait_kind='operation', wait_operation=7, wait_seconds=3)
        self.assertNotEqual(code, 0)
        # The first check and three retries.
        self.assertEqual(checks, 4)

    @NEEDS_ANSIBLE
    def test_an_operation_that_is_cancelled_or_interrupted_ends_the_wait_and_fails_it(self):
        for status in ('cancelled', 'interrupted'):
            code, output, checks = self.wait([status, ''], wait_kind='operation', wait_operation=7, wait_seconds=10)
            self.assertNotEqual(code, 0, status)
            self.assertEqual(checks, 2, status)
            self.assertIn(f'ended {status}', output)

    @NEEDS_ANSIBLE
    def test_doctor_passes_when_it_turns_healthy(self):
        code, output, checks = self.wait(['{"data":{"healthy":false}}', '{"data":{"healthy":true}}'], wait_kind='doctor', wait_seconds=10)
        self.assertEqual(code, 0, output)
        self.assertEqual(checks, 2)

    @NEEDS_ANSIBLE
    def test_doctor_stops_at_once_when_the_nested_gateway_is_not_running(self):
        listing = 'nested-gateway,STOPPED\nnested-app-dev,RUNNING\nnested-app-prod,RUNNING\n'
        code, output, checks = self.wait(['NOT_RUNNING'], listing, wait_kind='doctor', wait_seconds=600)
        self.assertNotEqual(code, 0)
        self.assertEqual(checks, 1)
        self.assertIn('A nested container is not running: nested-gateway', output)

    @NEEDS_ANSIBLE
    def test_doctor_does_not_pass_while_a_nested_app_vm_is_not_running(self):
        listing = 'nested-gateway,RUNNING\nnested-app-dev,STOPPED\nnested-app-prod,RUNNING\n'
        code, output, _ = self.wait(['{"data":{"healthy":true}}'], listing, wait_kind='doctor', wait_seconds=10)
        self.assertNotEqual(code, 0)
        self.assertIn('nested-app-dev', output)

    @NEEDS_ANSIBLE
    def test_doctor_does_not_pass_on_a_healthy_node_while_the_fleet_is_not(self):
        answer = '{"data":{"healthy":false,"nodes":[{"healthy":true}]}}'
        code, _output, checks = self.wait([answer], wait_kind='doctor', wait_seconds=3)
        self.assertNotEqual(code, 0)
        self.assertEqual(checks, 4)


if __name__ == '__main__':
    unittest.main()
