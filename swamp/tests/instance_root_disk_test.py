"""The root disk device's one rule: no `size` on a btrfs pool, the intent's size on every other pool."""
import json
import subprocess
import sys
import unittest
from pathlib import Path

SOURCE = Path(__file__).resolve().parents[2] / 'swamp/ansible/instance_root_disk.py'
INTENT = {'type': 'disk', 'path': '/', 'pool': 'orbit-instances', 'size': '40GiB'}


def device(driver):
    """The device as every writer runs the shipped file on the Node."""
    done = subprocess.run([sys.executable, '-I', '-c', SOURCE.read_text(), json.dumps(INTENT), driver], capture_output=True, text=True,
                          check=True)
    return json.loads(done.stdout)


class RootDiskTest(unittest.TestCase):
    def test_a_btrfs_pool_gets_no_root_size(self):
        self.assertEqual(device('btrfs'), {'type': 'disk', 'path': '/', 'pool': 'orbit-instances'})

    def test_a_zfs_pool_keeps_the_intents_root_size(self):
        self.assertEqual(device('zfs'), INTENT)


if __name__ == '__main__':
    unittest.main()
