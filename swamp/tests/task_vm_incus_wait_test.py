"""The Incus start wait is one bounded command: it waits while the VM is Stopped or Starting, then prints the observation."""
import json
import os
import subprocess
import tempfile
import unittest
from pathlib import Path

import yaml

ROOT = Path(__file__).resolve().parents[1]


def wait_argv():
    workflow = yaml.safe_load((ROOT / 'workflows/workflow-task-vm-provider-power.yaml').read_text())
    argv = next(step for step in workflow['jobs'][0]['steps'] if step['name'] == 'incus-after')['task']['inputs']['argv']
    start = argv.index('"/bin/sh", "-c", "') + len('"/bin/sh", "-c", "')
    return json.loads('"' + argv[start:argv.index('", "sh", inputs.intent.vm.project')] + '"')


class IncusStartWait(unittest.TestCase):
    def run_wait(self, states):
        with tempfile.TemporaryDirectory() as directory:
            fake, count = Path(directory) / 'sudo', Path(directory) / 'count'
            fake.write_text('#!/bin/sh\nn=$(cat ' + str(count) + ' 2>/dev/null || echo 0); echo $((n+1)) > ' + str(count) + '\n'
                            'set -- ' + ' '.join(states) + '\nshift $(( n < $# ? n : $# - 1 ))\n'
                            'printf \'[{"name":"tvm-42","type":"virtual-machine","status":"%s"}]\\n\' "$1"\n')
            fake.chmod(0o755)
            script = wait_argv().replace('/usr/bin/sudo', str(fake))
            result = subprocess.run(['timeout', '20', '/bin/sh', '-c', script, 'sh', 'tasks', 'tvm-42'],
                                    capture_output=True, text=True, env={**os.environ}, check=False)
            return result, int(count.read_text())

    def test_stopped_and_starting_wait_until_running_then_print_one_observation(self):
        result, reads = self.run_wait(['Stopped', 'Starting', 'Running'])
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(json.loads(result.stdout)[0]['status'], 'Running')
        self.assertEqual(reads, 4)

    def test_an_unsupported_state_stops_waiting_and_reports_it(self):
        result, reads = self.run_wait(['Error'])
        self.assertEqual(json.loads(result.stdout)[0]['status'], 'Error')
        self.assertEqual(reads, 2)

    def test_the_wait_is_bounded(self):
        workflow = (ROOT / 'workflows/workflow-task-vm-provider-power.yaml').read_text()
        self.assertIn('["timeout", "120", "/bin/sh", "-c", "while s=', workflow)


if __name__ == '__main__':
    unittest.main()
