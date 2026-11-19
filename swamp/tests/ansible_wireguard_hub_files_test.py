"""The one-read check of the hub's files and its source files (docs/reference/node-provisioning.md#check-many-files-in-one-read):
only the files that differ are copied, and a failed check copies every file."""
import json
import runpy
import unittest
from pathlib import Path
from typing import ClassVar

import yaml

SUPPORT = runpy.run_path(str(Path(__file__).parent / "_support/wireguard_hub_lan.py"))
ANSIBLE = SUPPORT["ANSIBLE"]
LIVE = SUPPORT["LIVE"]
RIGHT = SUPPORT["RIGHT"]


class WireGuardHubFilesTest(SUPPORT["HubLanHarness"]):
    def test_drifted_source_files_alone_are_rewritten_and_restart_the_unit(self):
        installed = {**self.INSTALLED, 'etc/orbit-hub-source.service': '[Unit]\nDescription=old\n'}
        code, output, calls = self.source(LIVE, RIGHT, installed=installed, modes={'etc/hub-source': 0o600})
        self.assertEqual(code, 0, output)
        write = output.split('TASK [Write the source script and unit]')[1].split('TASK [')[0]
        self.assertEqual(write.count('changed: '), 2, write)
        self.assertIn('etc/hub-source)', write)
        self.assertIn('etc/orbit-hub-source.service)', write)
        # The copy loops over the drifted files alone; the matching networkd file is not copied again.
        self.assertNotIn('ok: [node] => (item=', write)
        self.assertIn('systemctl daemon-reload', calls)
        self.assertIn('restart orbit-hub-source.service', calls)
        self.assertEqual(self.left['etc/orbit-hub-source.service'], '[Unit]\n')

    def test_a_failed_file_check_rewrites_every_source_file(self):
        installed = {**self.INSTALLED, 'bin/python3': '#!/bin/sh\nexit 1\n'}
        code, output, _ = self.source(LIVE, RIGHT, installed=installed)
        self.assertEqual(code, 0, output)
        write = output.split('TASK [Write the source script and unit]')[1].split('TASK [')[0]
        self.assertEqual(write.count('(item='), 3, write)

    def hub_files(self, files, modes=None):
        """Run the hub's file group, without the prune and filter imports, against files under the test folder."""
        block = next(task for task in yaml.safe_load((ANSIBLE / 'tasks/node-converge-hub.yml').read_text())
                     if task['name'] == 'Reconcile the hub files')
        block['block'] = [task for task in block['block'] if task.get('ansible.builtin.import_tasks') == 'managed-files-check.yml'
                          or 'ansible.builtin.copy' in task]
        loaded = json.loads(json.dumps([block]).replace('/etc/', '@DIR@/etc/'))
        hub_plan = {'config': '[Interface]\n', 'peers': [{'node_id': 3, 'file': '@DIR@/etc/wireguard/orbit-peers.d/3.conf', 'content': '[Peer]\n'}]}
        return self.run_tasks('node-converge-hub.yml', {'hub_plan': hub_plan}, files, loaded=loaded, modes=modes)

    HUB_FILES: ClassVar[dict] = {'etc/wireguard/orbit.conf': '[Interface]\n', 'etc/wireguard/orbit-peers.d/3.conf': '[Peer]\n',
                                 'etc/sysctl.d/99-orbit-forwarding.conf': 'net.ipv4.ip_forward = 1\n'}

    HUB_MODES: ClassVar[dict] = {'etc/wireguard/orbit.conf': 0o600, 'etc/wireguard/orbit-peers.d/3.conf': 0o600}

    def test_matching_hub_files_are_not_copied_again(self):
        code, output, _ = self.hub_files(self.HUB_FILES, self.HUB_MODES)
        self.assertEqual(code, 0, output)
        self.assertIn('changed=0', output)
        self.assertNotIn('(item=', output)

    def test_only_drifted_hub_files_are_copied(self):
        files = {**self.HUB_FILES, 'etc/wireguard/orbit-peers.d/3.conf': '[Peer]\n# drift\n'}
        code, output, _ = self.hub_files(files, {**self.HUB_MODES, 'etc/sysctl.d/99-orbit-forwarding.conf': 0o600})
        self.assertEqual(code, 0, output)
        self.assertIn('changed=2', output)
        # The matching configuration is skipped, which this callback does not print.
        self.assertNotIn('TASK [Reconcile the recorded hub configuration]', output)
        self.assertEqual(self.left['etc/wireguard/orbit-peers.d/3.conf'], '[Peer]\n')
        self.assertEqual(self.left['etc/sysctl.d/99-orbit-forwarding.conf'], 'net.ipv4.ip_forward = 1\n')


if __name__ == '__main__':
    unittest.main()
