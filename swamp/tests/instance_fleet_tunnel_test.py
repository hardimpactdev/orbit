"""The operator tunnel's boot files (swamp/ansible/instance_fleet_tunnel.py): written only when they differ, the unit enabled, and a
second run changes nothing."""
import json
import os
import stat
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
SOURCE = ROOT / 'swamp/ansible/instance_fleet_tunnel.py'
FILES = {'script': '#!/bin/sh\nip link set wg-nested up\n', 'unit': '[Service]\nExecStart=/etc/orbit/wg-nested-up\n'}


class FleetTunnelTest(unittest.TestCase):
    def setUp(self):
        self.folder = Path(tempfile.mkdtemp())
        self.bin = self.folder / 'bin'
        self.bin.mkdir()
        # A systemctl double: `is-enabled` answers from a marker file, `enable` makes it, every call is recorded.
        systemctl = self.bin / 'systemctl'
        systemctl.write_text('#!/bin/sh\necho "$*" >> "%s/calls"\n'
                             'case "$1" in is-enabled) [ -e "%s/enabled" ] ;; enable) touch "%s/enabled" ;; esac\n'
                             % (self.folder, self.folder, self.folder))
        systemctl.chmod(0o755)
        self.script = self.folder / 'wg-nested-up'
        self.unit = self.folder / 'orbit-inner-fleet-tunnel.service'

    def boot(self, files=FILES):
        done = subprocess.run([sys.executable, '-I', '-c', SOURCE.read_text(), 'boot', str(self.script), str(self.unit)],
                              input=json.dumps(files), capture_output=True, text=True, check=True,
                              env=dict(os.environ, PATH=f'{self.bin}:{os.environ["PATH"]}'))
        return json.loads(done.stdout)

    def calls(self):
        path = self.folder / 'calls'
        return path.read_text().splitlines() if path.exists() else []

    def test_the_files_are_written_and_the_unit_enabled_once(self):
        self.assertEqual(self.boot(), {'changed': True})
        self.assertEqual(self.script.read_text(), FILES['script'])
        self.assertEqual(stat.S_IMODE(self.script.stat().st_mode), 0o700)
        self.assertEqual(stat.S_IMODE(self.unit.stat().st_mode), 0o644)
        self.assertEqual(self.calls(), ['is-enabled --quiet orbit-inner-fleet-tunnel.service', 'daemon-reload',
                                        'enable --quiet orbit-inner-fleet-tunnel.service'])

        (self.folder / 'calls').unlink()
        self.assertEqual(self.boot(), {'changed': False})
        self.assertEqual(self.calls(), ['is-enabled --quiet orbit-inner-fleet-tunnel.service'])

    def test_a_new_hub_key_rewrites_the_script_and_a_disabled_unit_is_enabled_again(self):
        self.boot()
        (self.folder / 'enabled').unlink()
        (self.folder / 'calls').unlink()

        self.assertEqual(self.boot(), {'changed': True})
        self.assertEqual(self.calls()[-1], 'enable --quiet orbit-inner-fleet-tunnel.service')
        self.assertNotIn('daemon-reload', self.calls())

        self.assertEqual(self.boot({**FILES, 'script': FILES['script'] + 'wg set wg-nested peer new\n'}), {'changed': True})
        self.assertIn('peer new', self.script.read_text())


if __name__ == '__main__':
    unittest.main()
