"""The network pid files of the nested fleet, run as shipped on scratch folders.

- swamp/ansible/instance_fleet_runtime.py: the refresh's clear of stopped nested containers before `base`. `incus` is a stub on PATH:
  `incus query /1.0/instances/<name>` answers the status and root pool that `STATUS_<name>` and `POOL_<name>` name.
- swamp/ansible/files/incus-network-pid-heal.sh: a fleet Node's heal before its Incus starts, on a scratch networks folder and a
  scratch `/proc`.

nested-app-dev holds the empty `dnsmasq.pid` of `orbitinst0` that a copy of a running fleet left: at the next start Incus failed with
`Could not read pid file`.
"""

import json
import os
import re
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

ANSIBLE = Path(__file__).resolve().parents[1] / 'ansible'
SCRIPT = ANSIBLE / 'instance_fleet_runtime.py'
HEAL = ANSIBLE / 'files/incus-network-pid-heal.sh'
NETWORKS = 'var/lib/incus/networks'


def write(path, content=''):
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(content)
    return path


class FleetRuntimeTest(unittest.TestCase):
    def setUp(self):
        self.folder = Path(tempfile.mkdtemp())
        self.addCleanup(subprocess.run, ['rm', '-rf', str(self.folder)])
        self.pools = self.folder / 'storage-pools'
        self.bin = self.folder / 'bin'
        self.bin.mkdir()
        stub = self.bin / 'incus'
        stub.write_text('#!/bin/sh\nname=${2#/1.0/instances/}\nkey=$(echo "$name" | tr - _)\n'
                        'eval status=\\${STATUS_$key:-Stopped} pool=\\${POOL_$key:-inner}\n'
                        'echo "{\\"status\\": \\"$status\\", \\"expanded_devices\\": {\\"root\\": {\\"pool\\": \\"$pool\\"}}}"\n')
        stub.chmod(0o755)
        self.env = {'PATH': f'{self.bin}:/usr/bin:/bin'}

    def disk(self, name, pool='inner'):
        return self.pools / pool / 'containers' / name / 'rootfs'

    def run_script(self, *names, **env):
        done = subprocess.run([sys.executable, '-I', '-c', SCRIPT.read_text(), str(self.pools), *names],
                              capture_output=True, text=True, check=True, env={**self.env, **env})
        return json.loads(done.stdout.strip().splitlines()[-1])

    def test_an_empty_or_stale_pid_file_goes_and_the_network_state_stays(self):
        networks = self.disk('nested-app-dev') / NETWORKS
        empty = write(networks / 'orbitinst0/dnsmasq.pid')
        stale = write(networks / 'other0/dnsmasq.pid', 'pid: 4242\n')
        leases = write(networks / 'orbitinst0/dnsmasq.leases')
        raw = write(networks / 'orbitinst0/dnsmasq.raw', 'port=0\n')
        self.disk('nested-gateway').mkdir(parents=True)

        result = self.run_script('nested-gateway', 'nested-app-dev', 'nested-app-prod')

        self.assertEqual(result, {'removed': ['nested-app-dev/orbitinst0', 'nested-app-dev/other0'], 'skipped': []})
        self.assertFalse(empty.exists())
        self.assertFalse(stale.exists())
        self.assertTrue(leases.exists())
        self.assertEqual(raw.read_text(), 'port=0\n')
        self.assertEqual(self.run_script('nested-app-dev'), {'removed': [], 'skipped': []})

    def test_only_the_named_containers_are_cleared(self):
        kept = write(self.disk('nested-app-prod') / NETWORKS / 'orbitinst0/dnsmasq.pid')
        self.assertEqual(self.run_script('nested-app-dev'), {'removed': [], 'skipped': []})
        self.assertTrue(kept.exists())

    def test_a_frozen_or_running_container_keeps_its_file_and_is_named(self):
        frozen = write(self.disk('nested-app-dev') / NETWORKS / 'orbitinst0/dnsmasq.pid', 'pid: 757\n')
        running = write(self.disk('nested-app-prod') / NETWORKS / 'orbitinst0/dnsmasq.pid', 'pid: 758\n')
        result = self.run_script('nested-app-dev', 'nested-app-prod', STATUS_nested_app_dev='Frozen', STATUS_nested_app_prod='Running')
        self.assertEqual(result, {'removed': [], 'skipped': ['nested-app-dev', 'nested-app-prod']})
        self.assertTrue(frozen.exists())
        self.assertTrue(running.exists())

    def test_only_the_root_pool_of_the_container_is_cleared(self):
        other_pool = write(self.disk('nested-app-dev', 'old') / NETWORKS / 'orbitinst0/dnsmasq.pid')
        own = write(self.disk('nested-app-dev', 'inner') / NETWORKS / 'orbitinst0/dnsmasq.pid')
        self.assertEqual(self.run_script('nested-app-dev'), {'removed': ['nested-app-dev/orbitinst0'], 'skipped': []})
        self.assertFalse(own.exists())
        self.assertTrue(other_pool.exists())

    def test_no_path_leaves_the_root_disk_through_a_symlink(self):
        outside = self.folder / 'outside'
        target = write(outside / 'orbitinst0/dnsmasq.pid')
        through_folder = write(self.folder / 'elsewhere/networks/orbitinst0/dnsmasq.pid')
        disk = self.disk('nested-app-dev')
        (disk / 'var/lib/incus').mkdir(parents=True)
        os.symlink(outside, disk / NETWORKS)
        other = self.disk('nested-app-prod')
        (other / NETWORKS).mkdir(parents=True)
        os.symlink(through_folder.parent, other / NETWORKS / 'orbitinst0')
        linked = self.disk('nested-gateway') / NETWORKS / 'orbitinst0'
        linked.mkdir(parents=True)
        os.symlink(target, linked / 'dnsmasq.pid')

        self.assertEqual(self.run_script('nested-gateway', 'nested-app-dev', 'nested-app-prod'),
                         {'removed': ['nested-gateway/orbitinst0'], 'skipped': []})
        self.assertTrue(target.exists())
        self.assertTrue(through_folder.exists())
        self.assertFalse(os.path.lexists(linked / 'dnsmasq.pid'))


class NetworkPidHealTest(unittest.TestCase):
    def setUp(self):
        self.folder = Path(tempfile.mkdtemp())
        self.addCleanup(subprocess.run, ['rm', '-rf', str(self.folder)])
        self.networks = self.folder / 'networks'
        self.proc = self.folder / 'proc'

    def process(self, pid, *args):
        write(self.proc / str(pid) / 'cmdline', ''.join(arg + '\0' for arg in args))

    def heal(self):
        done = subprocess.run(['sh', str(HEAL), str(self.networks), str(self.proc)], capture_output=True, text=True)
        self.assertEqual(done.returncode, 0, done.stderr)
        return done.stdout.splitlines()

    def test_an_empty_file_or_one_without_a_live_dnsmasq_of_its_network_goes(self):
        empty = write(self.networks / 'orbitinst0/dnsmasq.pid')
        dead = write(self.networks / 'dead0/dnsmasq.pid', 'name: dnsmasq\npid: 4242\n')
        # A live dnsmasq, but of another network: its PID was reused, so the file is stale.
        self.process(5000, 'dnsmasq', '--interface=other0')
        reused = write(self.networks / 'reused0/dnsmasq.pid', 'name: dnsmasq\npid: 5000\n')
        leases = write(self.networks / 'orbitinst0/dnsmasq.leases')

        self.assertEqual(self.heal(), ['removed dead0', 'removed orbitinst0', 'removed reused0'])
        for path in (empty, dead, reused):
            self.assertFalse(path.exists(), path)
        self.assertTrue(leases.exists())

    def test_the_file_of_a_live_dnsmasq_of_its_network_stays(self):
        self.process(757, 'dnsmasq', '--keep-in-foreground', '--interface=orbitinst0', '--listen-address=10.253.1.1')
        live = write(self.networks / 'orbitinst0/dnsmasq.pid', 'name: dnsmasq\napparmor: ""\npid: 757\nuid: 0\n')
        self.assertEqual(self.heal(), [])
        self.assertTrue(live.exists())

    def test_no_networks_folder_changes_nothing(self):
        self.assertEqual(self.heal(), [])

    def test_the_incus_drop_in_of_a_fleet_node_runs_the_placed_heal_before_incus(self):
        tasks = (ANSIBLE / 'tasks/instance-fleet-node.yml').read_text()
        placed = re.search(r"dest: (\S+)\n    content: \"\{\{ lookup\('ansible.builtin.file', playbook_dir ~ '/files/incus-network-pid-heal.sh'\) \}\}", tasks)
        self.assertIsNotNone(placed)
        self.assertIn(f'      ExecStartPre={placed.group(1)}\n', tasks)
        self.assertIn('mode: "0755"', tasks[placed.end():placed.end() + 120])


if __name__ == '__main__':
    unittest.main()
