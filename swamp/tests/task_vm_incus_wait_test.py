"""The Incus start wait is Incus's own bounded wait for the guest agent, then one observation."""
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
    def run_wait(self, agent_ok):
        with tempfile.TemporaryDirectory() as directory:
            fake, calls = Path(directory) / 'sudo', Path(directory) / 'calls'
            fake.write_text('#!/bin/sh\necho "$*" >> ' + str(calls) + '\ncase "$*" in *" wait "*) exit ' + ('0' if agent_ok else '1') + ';; esac\n'
                            'printf \'[{"name":"tvm-42","type":"virtual-machine","status":"Running"}]\\n\'\n')
            fake.chmod(0o755)
            result = subprocess.run(['timeout', '20', '/bin/sh', '-c', wait_argv().replace('/usr/bin/sudo', str(fake)), 'sh', 'tasks', 'tvm-42'],
                                    capture_output=True, text=True, env={**os.environ}, check=False)
            return result, calls.read_text().splitlines()

    def test_waits_for_the_guest_agent_then_prints_one_observation(self):
        result, calls = self.run_wait(True)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(json.loads(result.stdout)[0]['status'], 'Running')
        self.assertEqual(calls[0], '-n -- /usr/bin/incus --project tasks wait tvm-42 agent --timeout 120 --interval 1')
        self.assertEqual(len(calls), 2)

    def test_an_agent_that_never_comes_fails_without_an_observation(self):
        result, calls = self.run_wait(False)
        self.assertNotEqual(result.returncode, 0)
        self.assertEqual((result.stdout, len(calls)), ('', 1))

    def test_the_wait_is_bounded(self):
        self.assertIn('["timeout", "130", "/bin/sh", "-c"', (ROOT / 'workflows/workflow-task-vm-provider-power.yaml').read_text())


if __name__ == '__main__':
    unittest.main()
