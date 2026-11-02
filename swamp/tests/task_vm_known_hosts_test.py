"""Task VMs reuse addresses: enrollment pins the guest's observed key for its address, and destroy forgets it."""
import os
import subprocess
import tempfile
import unittest
from pathlib import Path

import yaml

ROOT = Path(__file__).resolve().parents[1]
OLD = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIGaNG9xwGkyymXqkaSEKl6+u5VLBHPbi0ga0ya7sdCtG'
NEW = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIJBLJu/2IaglMhHEKUH6cwtP0rPJz+E9BUcJ/AVFJqx+'
OTHER = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOlS8xvUK3QXeFKFBC3whbWYKkjw4O9fuXv9AWPvUS0T'


def script(workflow, name):
    value = yaml.safe_load((ROOT / f'workflows/workflow-{workflow}.yaml').read_text())
    return next(step for job in value['jobs'] for step in job['steps'] if step['name'] == name)['task']['inputs']['run']


class KnownHosts(unittest.TestCase):
    def run_step(self, workflow, name, home, **env):
        result = subprocess.run(['sh', '-c', script(workflow, name)], capture_output=True, text=True, check=False,
                                env={**os.environ, 'HOME': str(home), **env})
        self.assertEqual(result.returncode, 0, result.stderr)

    def test_enrollment_replaces_a_stale_key_and_keeps_other_hosts(self):
        with tempfile.TemporaryDirectory() as home:
            hosts = Path(home, '.ssh/known_hosts')
            hosts.parent.mkdir(mode=0o700)
            hosts.write_text(f'10.44.0.133 {OLD}\n10.44.0.2 {OTHER}\n')
            self.run_step('task-vm-enrollment', 'Pin the guest host key', home, ORBIT_ADDRESS='10.44.0.133', ORBIT_LINES=f'10.44.0.133 {NEW}')
            lines = hosts.read_text().splitlines()
            self.assertIn(f'10.44.0.133 {NEW}', lines)
            self.assertNotIn(f'10.44.0.133 {OLD}', lines)
            self.assertIn(f'10.44.0.2 {OTHER}', lines)

    def test_destroy_forgets_only_that_address(self):
        with tempfile.TemporaryDirectory() as home:
            hosts = Path(home, '.ssh/known_hosts')
            hosts.parent.mkdir(mode=0o700)
            hosts.write_text(f'10.44.0.133 {NEW}\n10.44.0.2 {OTHER}\n')
            self.run_step('task-vm-destroy', 'Forget the guest host key', home, ORBIT_ADDRESS='10.44.0.133')
            self.assertEqual(hosts.read_text().splitlines(), [f'10.44.0.2 {OTHER}'])


if __name__ == '__main__':
    unittest.main()
