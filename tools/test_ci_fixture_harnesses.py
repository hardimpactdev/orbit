"""Fast transport regressions for after-merge fixtures; no network or Ansible bodies."""
import json
import os
from pathlib import Path
import pty
import runpy
import subprocess
import sys
import tempfile
import threading
import unittest

ROOT = Path(__file__).resolve().parents[1]
SSH = ROOT / 'swamp/tests/fixtures/fleet-reboot-ssh.py'


class RebootSshTest(unittest.TestCase):
    def test_synthetic_connection_failure_does_not_wait_for_terminal_input(self):
        with tempfile.TemporaryDirectory() as scratch:
            (Path(scratch) / 'ssh.json').write_text(json.dumps({'calls': 0, 'failures': 1}))
            master, slave = pty.openpty()
            try:
                # Ansible keeps the terminal open until a non-pipelined command exits.
                result = subprocess.run([sys.executable, str(SSH), 'unused'], stdin=slave,
                                        capture_output=True, timeout=3,
                                        env={**os.environ, 'REBOOT_SSH_FIXTURE': scratch})
                self.assertEqual(255, result.returncode)
                self.assertIn(b'Connection timed out', result.stderr)
            finally:
                os.close(slave)
                os.close(master)

    def test_synthetic_connection_failure_drains_a_large_pipelined_module(self):
        with tempfile.TemporaryDirectory() as scratch:
            state = Path(scratch) / 'ssh.json'
            state.write_text(json.dumps({'calls': 0, 'failures': 1}))
            with subprocess.Popen([sys.executable, str(SSH), 'unused'], stdin=subprocess.PIPE,
                                  stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                                  env={**os.environ, 'REBOOT_SSH_FIXTURE': scratch}) as child:
                errors = []
                def write_module():
                    try:
                        child.stdin.write(b'x' * 2_000_000)  # Larger than any pipe buffer; old stand-in closes during this write.
                        child.stdin.close()
                    except OSError as error:
                        errors.append(error)
                writer = threading.Thread(target=write_module)
                writer.start()
                try:
                    child.wait(timeout=10)
                finally:
                    if child.poll() is None:
                        child.kill()
                    writer.join(timeout=10)
                self.assertFalse(writer.is_alive(), 'SSH stand-in left the module writer blocked')
                self.assertEqual([], errors, 'premature pipe closure hides the intended SSH timeout diagnostic')
                self.assertEqual(255, child.returncode)
                self.assertEqual(b'', child.stdout.read())
                self.assertIn(b'Connection timed out', child.stderr.read())
            self.assertEqual(1, json.loads(state.read_text())['calls'])

    def test_success_still_passes_the_module_input_to_the_local_command(self):
        with tempfile.TemporaryDirectory() as scratch:
            (Path(scratch) / 'ssh.json').write_text(json.dumps({'calls': 0, 'failures': 0}))
            result = subprocess.run([sys.executable, str(SSH), 'cat'], input=b'unchanged module input\n',
                                    capture_output=True, timeout=10,
                                    env={**os.environ, 'REBOOT_SSH_FIXTURE': scratch})
            self.assertEqual(0, result.returncode, result.stderr)
            self.assertEqual(b'unchanged module input\n', result.stdout)


class InputClosureTest(unittest.TestCase):
    def test_copied_helpers_select_their_fixture(self):
        api = runpy.run_path(str(ROOT / 'bin/ci-units'))
        budget, fixtures, defaults = api['configuration']()
        for helper, file in [('swamp/ansible/instance_node_services.py', 'swamp/tests/ansible_instance_migrate_test.ts'),
                             ('swamp/ansible/tasks/instance-private-names.yml', 'swamp/tests/ansible_instance_migrate_test.ts'),
                             ('swamp/ansible/library/orbit_migrate_env.py', 'swamp/tests/ansible_instance_migrate_test.ts'),
                             ('swamp/ansible/templates/instance-migrate-schedule.j2', 'swamp/tests/ansible_instance_migrate_test.ts'),
                             ('swamp/extensions/behaviors/operations/processes-schedules/unit-templates.json', 'swamp/tests/ansible_instance_migrate_test.ts'),
                             ('swamp/tests/fixtures/fleet-reboot-ssh.py', 'swamp/tests/fleet_reboot_unreachable_test.ts')]:
            with self.subTest(helper=helper):
                plan = api['plan']([helper], budget, fixtures, defaults)
                selected = {u.split('#')[0] for s in plan['shards'] for u in s['units']}
                selected.update(u.split('#')[0] for u in plan['deferred'])
                selected.update(row['file'] for row in plan.get('excluded', []))
                self.assertIn(file, selected)


if __name__ == '__main__':
    unittest.main()
