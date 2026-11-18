"""The network pid files of the nested fleet, run as shipped on scratch folders.

- swamp/ansible/instance_fleet_runtime.py: the refresh's clear of stopped nested containers before `base`. `incus` is a stub on PATH:
  `incus query /1.0/instances/<name>` answers the status and root pool that `STATUS_<name>` and `POOL_<name>` name.
- swamp/ansible/files/incus-network-pid-heal.py: a fleet Node's heal before its Incus starts, on a scratch networks folder, a
  scratch `/proc` and a scratch boot ID.

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
HEAL = ANSIBLE / 'files/incus-network-pid-heal.py'
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
        self.disk('nested-app-prod').mkdir(parents=True)

        result = self.run_script('nested-gateway', 'nested-app-dev', 'nested-app-prod')

        self.assertEqual(result, {'removed': ['nested-app-dev/orbitinst0', 'nested-app-dev/other0'], 'skipped': [], 'unsafe': []})
        self.assertFalse(empty.exists())
        self.assertFalse(stale.exists())
        self.assertTrue(leases.exists())
        self.assertEqual(raw.read_text(), 'port=0\n')
        self.assertEqual(self.run_script('nested-app-dev'), {'removed': [], 'skipped': [], 'unsafe': []})

    def test_only_the_named_containers_are_cleared(self):
        kept = write(self.disk('nested-app-prod') / NETWORKS / 'orbitinst0/dnsmasq.pid')
        self.disk('nested-app-dev').mkdir(parents=True)
        self.assertEqual(self.run_script('nested-app-dev'), {'removed': [], 'skipped': [], 'unsafe': []})
        self.assertTrue(kept.exists())

    def test_a_frozen_or_running_container_keeps_its_file_and_is_named(self):
        frozen = write(self.disk('nested-app-dev') / NETWORKS / 'orbitinst0/dnsmasq.pid', 'pid: 757\n')
        running = write(self.disk('nested-app-prod') / NETWORKS / 'orbitinst0/dnsmasq.pid', 'pid: 758\n')
        result = self.run_script('nested-app-dev', 'nested-app-prod', STATUS_nested_app_dev='Frozen', STATUS_nested_app_prod='Running')
        self.assertEqual(result, {'removed': [], 'skipped': ['nested-app-dev', 'nested-app-prod'], 'unsafe': []})
        self.assertTrue(frozen.exists())
        self.assertTrue(running.exists())

    def test_only_the_root_pool_of_the_container_is_cleared(self):
        other_pool = write(self.disk('nested-app-dev', 'old') / NETWORKS / 'orbitinst0/dnsmasq.pid')
        own = write(self.disk('nested-app-dev', 'inner') / NETWORKS / 'orbitinst0/dnsmasq.pid')
        self.assertEqual(self.run_script('nested-app-dev'), {'removed': ['nested-app-dev/orbitinst0'], 'skipped': [], 'unsafe': []})
        self.assertFalse(own.exists())
        self.assertTrue(other_pool.exists())

    def test_a_link_on_the_way_makes_the_disk_unsafe_and_nothing_outside_is_touched(self):
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
                         {'removed': ['nested-gateway/orbitinst0'], 'skipped': [], 'unsafe': ['nested-app-dev', 'nested-app-prod']})
        self.assertTrue(target.exists())
        self.assertTrue(through_folder.exists())
        self.assertFalse(os.path.lexists(linked / 'dnsmasq.pid'))

    def test_a_later_unsafe_entry_keeps_the_report_of_what_went(self):
        networks = self.disk('nested-app-dev') / NETWORKS
        cleared = write(networks / 'aaa0/dnsmasq.pid')
        os.symlink(self.folder, networks / 'zzz0')
        self.assertEqual(self.run_script('nested-app-dev'),
                         {'removed': ['nested-app-dev/aaa0'], 'skipped': [], 'unsafe': ['nested-app-dev']})
        self.assertFalse(cleared.exists())

    def test_a_missing_or_linked_root_disk_is_unsafe(self):
        elsewhere = self.folder / 'elsewhere-rootfs'
        write(elsewhere / NETWORKS / 'orbitinst0/dnsmasq.pid')
        (self.pools / 'inner/containers/nested-app-prod').mkdir(parents=True)
        os.symlink(elsewhere, self.pools / 'inner/containers/nested-app-prod/rootfs')
        self.assertEqual(self.run_script('nested-app-dev', 'nested-app-prod'),
                         {'removed': [], 'skipped': [], 'unsafe': ['nested-app-dev', 'nested-app-prod']})
        self.assertTrue((elsewhere / NETWORKS / 'orbitinst0/dnsmasq.pid').exists())


BOOT = '20d9f9ee-6d10-4997-bf71-7cd558d8c898'
PID = 757
START = 79850479


def incus_record(**change):
    """The pid record Incus writes for orbitinst0's live dnsmasq (757), with `change` applied to its top-level lines."""
    fields = {'name': 'dnsmasq', 'args': '[--keep-in-foreground, --strict-order, --bind-interfaces, --except-interface=lo,\n'
              '  --pid-file=, --no-ping, --interface=orbitinst0]', 'apparmor': '""', 'cwd': '""', 'pid': str(PID),
              'start_time': str(START), 'boot_id': BOOT, 'uid': '0', 'gid': '0', 'set_groups': 'false', 'sysprocattr': 'null'}
    fields.update(change)
    return ''.join(f'{key}: {value}\n' for key, value in fields.items() if value is not None)


class NetworkPidHealTest(unittest.TestCase):
    """One network, orbitinst0, and one live dnsmasq of it (757): each negative case changes one thing."""

    def setUp(self):
        self.folder = Path(tempfile.mkdtemp())
        self.addCleanup(subprocess.run, ['rm', '-rf', str(self.folder)])
        self.networks = self.folder / 'networks'
        self.proc = self.folder / 'proc'
        self.boot = write(self.folder / 'boot_id', BOOT + '\n')
        self.process()

    def process(self, comm='dnsmasq', start=START, interface='--interface=orbitinst0'):
        write(self.proc / str(PID) / 'comm', comm + '\n')
        write(self.proc / str(PID) / 'stat', f'{PID} ({comm}) S 1 {PID} {PID} 0 -1 4194560 ' + ' '.join(['0'] * 12) + f' {start} 4096 0\n')
        write(self.proc / str(PID) / 'cmdline', ''.join(arg + '\0' for arg in ['dnsmasq', '--keep-in-foreground', interface, '--listen-address=10.253.1.1']))

    def pid_file(self, content):
        return write(self.networks / 'orbitinst0/dnsmasq.pid', content)

    def heal(self):
        done = subprocess.run([sys.executable, '-I', str(HEAL), str(self.networks), str(self.proc), str(self.boot)],
                              capture_output=True, text=True)
        self.assertEqual(done.returncode, 0, done.stderr)
        return done.stdout.splitlines()

    def test_the_record_of_the_live_dnsmasq_of_its_network_stays(self):
        live = self.pid_file(incus_record())
        self.assertEqual(self.heal(), [])
        self.assertEqual(live.read_text(), incus_record())

    def test_a_record_that_fails_one_check_goes(self):
        cases = {
            'empty': '',
            'invalid yaml': incus_record(sysprocattr='['),
            'no mapping': '- name: dnsmasq\n',
            'other name': incus_record(name='other'),
            'pid not an integer': incus_record(pid='"757"'),
            'stale start_time': incus_record(start_time='stale'),
            'other start_time': incus_record(start_time='1'),
            'no start_time': incus_record(start_time=None),
            'other boot': incus_record(boot_id='00000000-0000-0000-0000-000000000000'),
            'dead pid': incus_record(pid='4242'),
            'not utf-8': '\udcff',
        }
        for case, content in cases.items():
            with self.subTest(case=case):
                path = self.networks / 'orbitinst0/dnsmasq.pid'
                path.parent.mkdir(parents=True, exist_ok=True)
                path.write_bytes(content.encode('utf-8', 'surrogateescape'))
                self.assertEqual(self.heal(), ['removed orbitinst0'])
                self.assertFalse(path.exists())

    def test_a_process_that_fails_one_check_loses_the_record(self):
        for case, process in {'not dnsmasq': {'comm': 'sleep'}, 'started later': {'start': START + 1},
                              'other interface': {'interface': '--interface=orbitinst01'},
                              'pattern only': {'interface': '--interface=orbitinstX'}}.items():
            with self.subTest(case=case):
                self.process(**process)
                self.pid_file(incus_record())
                self.assertEqual(self.heal(), ['removed orbitinst0'])
        self.process()
        self.pid_file(incus_record().replace('orbitinst0', 'orbit.nst0'))
        (self.networks / 'orbitinst0').rename(self.networks / 'orbit.nst0')
        self.assertEqual(self.heal(), ['removed orbit.nst0'])

    def test_a_file_it_cannot_read_goes(self):
        unreadable = self.pid_file(incus_record())
        unreadable.chmod(0)
        self.assertEqual(self.heal(), ['removed orbitinst0'])
        self.assertFalse(unreadable.exists())

    def test_no_link_is_followed(self):
        outside = write(self.folder / 'outside/orbitinst0/dnsmasq.pid')
        self.networks.mkdir()
        os.symlink(outside.parent, self.networks / 'linked0')
        (self.networks / 'orbitinst0').mkdir()
        record = write(self.folder / 'outside/record', incus_record())
        os.symlink(record, self.networks / 'orbitinst0/dnsmasq.pid')
        self.assertEqual(self.heal(), ['removed orbitinst0'])
        self.assertTrue(outside.exists())
        self.assertEqual(record.read_text(), incus_record())
        self.assertFalse(os.path.lexists(self.networks / 'orbitinst0/dnsmasq.pid'))

    def test_a_linked_networks_folder_is_left_alone(self):
        outside = write(self.folder / 'outside/orbitinst0/dnsmasq.pid')
        os.symlink(outside.parent.parent, self.networks)
        self.assertEqual(self.heal(), [])
        self.assertTrue(outside.exists())

    def test_no_networks_folder_changes_nothing(self):
        self.assertEqual(self.heal(), [])

    def test_the_fleet_start_sends_the_heal_to_each_nested_instance_host(self):
        tasks = (ANSIBLE / 'tasks/instance-fleet-start.yml').read_text()
        self.assertIn("lookup('ansible.builtin.file', playbook_dir ~ '/files/incus-network-pid-heal.py')", tasks)
        self.assertIn("'ensure'", tasks)
        self.assertTrue(HEAL.read_text().startswith('#!/usr/bin/python3 -I\n'))


if __name__ == '__main__':
    unittest.main()
