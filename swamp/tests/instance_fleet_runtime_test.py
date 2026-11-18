"""The clear of the inner Incus's network runtime files (swamp/ansible/instance_fleet_runtime.py), run as shipped on scratch root disks.

Each nested container is a folder shaped as the inner Incus's pool holds it. nested-app-dev holds the empty `dnsmasq.pid` of
`orbitinst0` that a seed of a running fleet left in its `base`: at the next start Incus failed with `Could not read pid file`.
"""

import json
import os
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

SCRIPT = Path(__file__).resolve().parents[1] / 'ansible/instance_fleet_runtime.py'
NETWORKS = 'var/lib/incus/networks'


class FleetRuntimeTest(unittest.TestCase):
    def setUp(self):
        self.folder = Path(tempfile.mkdtemp())
        self.addCleanup(subprocess.run, ['rm', '-rf', str(self.folder)])
        self.pools = self.folder / 'storage-pools'

    def disk(self, name, pool='inner'):
        return self.pools / pool / 'containers' / name / 'rootfs'

    def write(self, path, content=''):
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(content)
        return path

    def run_script(self, *names):
        done = subprocess.run([sys.executable, '-I', '-c', SCRIPT.read_text(), str(self.pools), *names],
                              capture_output=True, text=True, check=True)
        return json.loads(done.stdout.strip().splitlines()[-1])

    def test_an_empty_or_stale_pid_file_goes_and_the_network_state_stays(self):
        networks = self.disk('nested-app-dev') / NETWORKS
        empty = self.write(networks / 'orbitinst0/dnsmasq.pid')
        stale = self.write(networks / 'other0/dnsmasq.pid', 'pid: 4242\n')
        leases = self.write(networks / 'orbitinst0/dnsmasq.leases')
        raw = self.write(networks / 'orbitinst0/dnsmasq.raw', 'port=0\n')
        self.disk('nested-gateway').mkdir(parents=True)

        result = self.run_script('nested-gateway', 'nested-app-dev', 'nested-app-prod')

        self.assertEqual(result, {'removed': ['nested-app-dev/orbitinst0', 'nested-app-dev/other0']})
        self.assertFalse(empty.exists())
        self.assertFalse(stale.exists())
        self.assertTrue(leases.exists())
        self.assertEqual(raw.read_text(), 'port=0\n')
        self.assertEqual(self.run_script('nested-app-dev'), {'removed': []})

    def test_only_the_named_containers_are_cleared(self):
        kept = self.write(self.disk('nested-app-prod') / NETWORKS / 'orbitinst0/dnsmasq.pid')
        self.assertEqual(self.run_script('nested-app-dev'), {'removed': []})
        self.assertTrue(kept.exists())

    def test_no_path_leaves_the_root_disk_through_a_symlink(self):
        outside = self.folder / 'outside'
        target = self.write(outside / 'orbitinst0/dnsmasq.pid')
        through_folder = self.write(self.folder / 'elsewhere/networks/orbitinst0/dnsmasq.pid')
        disk = self.disk('nested-app-dev')
        (disk / 'var/lib/incus').mkdir(parents=True)
        os.symlink(outside, disk / NETWORKS)
        other = self.disk('nested-app-prod')
        (other / NETWORKS).mkdir(parents=True)
        os.symlink(through_folder.parent, other / NETWORKS / 'orbitinst0')
        linked = self.disk('nested-gateway') / NETWORKS / 'orbitinst0'
        linked.mkdir(parents=True)
        os.symlink(target, linked / 'dnsmasq.pid')

        self.assertEqual(self.run_script('nested-gateway', 'nested-app-dev', 'nested-app-prod'), {'removed': ['nested-gateway/orbitinst0']})
        self.assertTrue(target.exists())
        self.assertTrue(through_folder.exists())
        self.assertFalse(os.path.lexists(linked / 'dnsmasq.pid'))


if __name__ == '__main__':
    unittest.main()
