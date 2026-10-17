"""task-vms.build-topology-seed against a fake `incus` that logs each call and the standard input it received."""
import json
import os
import tempfile
import unittest
from pathlib import Path

from harness import execute, result

FINGERPRINT = 'ab' * 32
VOLUME = 'orbit-topology-seed-' + FINGERPRINT[:12] + '-new'
TEMPLATE = {'name': 'node-template', 'type': 'virtual-machine', 'status': 'Stopped', 'profiles': [],
            'devices': {'root': {'type': 'disk', 'path': '/', 'pool': 'topology-seed'}}}

# Each call goes to $STATE/calls as one line, followed by `stdin=<bytes>`: no call may read the program's input.
INCUS = '''#!/bin/bash
printf '%s\\n' "$*" >> "$STATE/calls"
printf 'stdin=%s\\n' "$(wc -c | tr -d ' ')" >> "$STATE/calls"
shift 2
case "$*" in
  'storage volume show '*) [ -f "$STATE/volume" ] ;;
  'storage volume create '*) touch "$STATE/volume" ;;
  'storage volume delete '*) rm -f "$STATE/volume" ;;
  'image export '*) [ -f "$STATE/no-archive" ] || : > "$4.tar" ;;
  *' -- test -b '*) [ ! -f "$STATE/no-device" ] ;;
  *' -- incus list --format json') cat "$STATE/instances" ;;
  *' -- incus storage create '*) [ ! -f "$STATE/fail-pool" ] || { echo 'Error: no btrfs' >&2; exit 1; } ;;
esac
'''


class BuildTopologySeedTest(unittest.TestCase):
    def setUp(self):
        directory = tempfile.TemporaryDirectory(prefix='orbit-topology-seed-')
        self.addCleanup(directory.cleanup)
        self.state = Path(directory.name)
        (self.state / 'bin').mkdir()
        incus = self.state / 'bin' / 'incus'
        incus.write_text(INCUS)
        incus.chmod(0o755)
        (self.state / 'instances').write_text(json.dumps([TEMPLATE]))
        self.environment = {'STATE': str(self.state), 'PATH': f'{self.state / "bin"}:{os.environ["PATH"]}'}
        self.payload = {'project': 'orbit-tasks', 'pool': 'orbit-tasks', 'vm': 'tvm-image-7-smoke', 'device': 'topology-seed',
                        'volume': VOLUME, 'size': '45GiB', 'fingerprint': FINGERPRINT}

    def calls(self):
        lines = (self.state / 'calls').read_text().splitlines()
        self.assertEqual({line for line in lines[1::2]}, {'stdin=0'}, 'an incus call read the program input')
        return [line.removeprefix('--project orbit-tasks ') for line in lines[0::2]]

    def test_builds_the_template_in_the_smoke_vm_on_the_new_volume(self):
        outcome, data = result('task-vms.build-topology-seed', self.payload, environment=self.environment)

        self.assertEqual(outcome, 'built')
        self.assertEqual(set(data['seconds']), {'volume', 'copy', 'template'})
        calls = self.calls()
        self.assertEqual(calls[:3], [
            f'storage volume show orbit-tasks {VOLUME}',
            f'storage volume create orbit-tasks {VOLUME} --type=block size=45GiB',
            f'config device add tvm-image-7-smoke topology-seed disk pool=orbit-tasks source={VOLUME}',
        ])
        self.assertTrue(calls[3].startswith(f'image export {FINGERPRINT} /var/tmp/orbit-topology-seed.'))
        self.assertRegex(calls[4], r'^file push /var/tmp/orbit-topology-seed\.[^ ]+/base\.tar '
                                   r'tvm-image-7-smoke/var/tmp/orbit-topology-seed\.tar --mode=0600$')
        guest = 'exec tvm-image-7-smoke -- '
        self.assertEqual(calls[5:], [
            guest + 'test -b /dev/disk/by-id/scsi-0QEMU_QEMU_HARDDISK_incus_topology--seed',
            guest + 'incus storage create topology-seed btrfs source=/dev/disk/by-id/scsi-0QEMU_QEMU_HARDDISK_incus_topology--seed',
            guest + 'incus image import /var/tmp/orbit-topology-seed.tar',
            guest + 'rm -f -- /var/tmp/orbit-topology-seed.tar',
            guest + f'incus init {FINGERPRINT} node-template --vm --no-profiles --storage topology-seed',
            guest + f'incus image delete {FINGERPRINT}',
            guest + 'incus list --format json',
            guest + 'systemctl stop incus.socket incus-user.socket incus.service incus-user.service',
            guest + 'sync',
        ])
        self.assertEqual(list(Path('/var/tmp').glob('orbit-topology-seed.*/base.tar')), [])

    def test_deletes_the_volume_of_an_earlier_run_first(self):
        (self.state / 'volume').touch()

        result('task-vms.build-topology-seed', self.payload, environment=self.environment)

        self.assertEqual(self.calls()[:3], [
            f'storage volume show orbit-tasks {VOLUME}',
            f'storage volume delete orbit-tasks {VOLUME}',
            f'storage volume create orbit-tasks {VOLUME} --type=block size=45GiB',
        ])

    def test_fails_without_a_result_when_a_step_fails(self):
        cases = {
            'fail-pool': 'incus exec tvm-image-7-smoke -- incus storage create',
            'no-archive': 'did not export as one archive',
        }
        for marker, log in cases.items():
            with self.subTest(marker):
                (self.state / marker).touch()
                completed = execute('task-vms.build-topology-seed', self.payload, environment=self.environment)
                (self.state / marker).unlink()
                self.assertNotEqual(completed.returncode, 0)
                self.assertEqual(completed.stdout, b'')
                self.assertIn(log, completed.stderr.decode())

    def test_fails_when_the_disk_never_appears_in_the_vm(self):
        (self.state / 'no-device').touch()

        completed = execute('task-vms.build-topology-seed', self.payload, environment=self.environment,
                            replace={'DEVICE_SECONDS = 60': 'DEVICE_SECONDS = 0'})

        self.assertNotEqual(completed.returncode, 0)
        self.assertIn('The disk did not appear as /dev/disk/by-id/scsi-0QEMU_QEMU_HARDDISK_incus_topology--seed',
                      completed.stderr.decode())

    def test_refuses_a_template_that_is_not_the_one_stopped_vm_without_profiles(self):
        for change in ({'status': 'Running'}, {'profiles': ['default']},
                       {'devices': {'root': {'type': 'disk', 'path': '/', 'pool': 'default'}}}):
            with self.subTest(change):
                (self.state / 'instances').write_text(json.dumps([{**TEMPLATE, **change}]))
                completed = execute('task-vms.build-topology-seed', self.payload, environment=self.environment)
                self.assertNotEqual(completed.returncode, 0)
                self.assertIn('is not the one stopped VM', completed.stderr.decode())

    def test_refuses_invalid_input_before_any_call(self):
        # A volume without the build suffix, such as a finished seed or a VM's clone, is never deleted.
        for key, value in (('volume', 'orbit-topology-seed-abababababab'), ('volume', 'tvm-5-topology-seed'),
                           ('volume', 'orbit topology seed-new'), ('fingerprint', 'AB' * 32), ('device', 'Topology-Seed'),
                           ('vm', '-smoke'), ('size', '45G'), ('project', None)):
            with self.subTest(key):
                completed = execute('task-vms.build-topology-seed', {**self.payload, key: value},
                                    environment=self.environment)
                self.assertNotEqual(completed.returncode, 0)
                self.assertFalse((self.state / 'calls').exists())


if __name__ == '__main__':
    unittest.main()
