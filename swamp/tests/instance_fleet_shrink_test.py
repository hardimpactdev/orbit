"""The shrink of the nested root disks (swamp/ansible/instance_fleet_shrink.py), run as shipped on scratch root disks.

Each nested container is a folder shaped as the inner Incus's pool holds it, with rust-coreutils as Ubuntu ships it: one binary and its
links, here three, and empty locale files that share one digest but are separate files in the package. In the first nested fleet the
links were separate copies. `incus` and `duperemove` are stubs on PATH: `incus query` answers the pool of each container, and
`duperemove` records its arguments.
"""

import hashlib
import json
import os
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

SCRIPT = Path(__file__).resolve().parents[1] / 'ansible/instance_fleet_shrink.py'
NAMES = ['nested-gateway', 'nested-app-dev', 'nested-app-prod']
BINARY = b'\x7fELF multicall coreutils ' * 4096
LINKS = ['usr/lib/cargo/bin/coreutils/ls', 'usr/lib/cargo/bin/coreutils/cat', 'usr/lib/cargo/bin/coreutils/yes']
LOCALES = ['usr/share/coreutils/locales/ls/nl.ftl', 'usr/share/coreutils/locales/cat/nl.ftl']


class FleetShrinkTest(unittest.TestCase):
    def setUp(self):
        self.folder = Path(tempfile.mkdtemp())
        self.addCleanup(subprocess.run, ['rm', '-rf', str(self.folder)])
        self.pools = self.folder / 'storage-pools'
        self.record = self.folder / 'orbit/fleet-shrink.json'
        self.bin = self.folder / 'bin'
        self.bin.mkdir()
        self.duperemove_log = self.folder / 'duperemove.log'
        self.driver = 'btrfs'
        for name in NAMES:
            self.machine(name)
        self.stub('incus', 'case "$2" in /1.0/instances/*) echo \'{"expanded_devices": {"root": {"pool": "inner"}}}\';;\n'
                  '/1.0/storage-pools/inner) echo "{\\"driver\\": \\"$DRIVER\\"}";; *) exit 1;; esac\n')
        self.stub('duperemove', f'echo "$@" >> {self.duperemove_log}\n')

    def stub(self, name, body):
        path = self.bin / name
        path.write_text('#!/bin/sh\n' + body)
        path.chmod(0o755)

    def disk(self, name):
        return self.pools / 'inner/containers' / name / 'rootfs'

    def machine(self, name):
        disk = self.disk(name)
        lines = []
        for relative, content in [('usr/bin/coreutils', BINARY), *[(link, BINARY) for link in LINKS], *[(locale, b'') for locale in LOCALES]]:
            path = disk / relative
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_bytes(content)
            path.chmod(0o755 if content else 0o644)
            lines.append(f'{hashlib.md5(content).hexdigest()}  {relative}')
        info = disk / 'var/lib/dpkg/info'
        info.mkdir(parents=True)
        (info / 'rust-coreutils.md5sums').write_text('\n'.join(lines) + '\n')
        (disk / 'var/lib/dpkg/status').write_text('Package: rust-coreutils\nStatus: install ok installed\nVersion: 0.10.0-1ubuntu2\n')

    def shrink(self, mode, path=None):
        done = subprocess.run([sys.executable, '-I', '-c', SCRIPT.read_text(), mode, str(self.record), str(self.pools), *NAMES], capture_output=True, text=True,
                              check=False, env={'PATH': path or f'{self.bin}:/usr/bin:/bin', 'DRIVER': self.driver})
        self.assertEqual(done.returncode, 0, done.stderr)
        return json.loads(done.stdout.splitlines()[-1])

    def links(self, name, relative='usr/bin/coreutils'):
        return (self.disk(name) / relative).stat().st_nlink

    def test_the_shipped_links_are_linked_again_and_a_second_run_changes_nothing(self):
        self.assertEqual([self.links(name) for name in NAMES], [1, 1, 1])
        self.assertEqual(self.shrink('check'), {'due': True, 'reason': 'record'})
        self.assertEqual(self.shrink('run'), {'shrink': 'ran', 'relinked': 9, 'dedupe': 'ran'})
        for name in NAMES:
            disk = self.disk(name)
            self.assertEqual(self.links(name), 4)
            self.assertEqual({(disk / link).stat().st_ino for link in LINKS}, {(disk / 'usr/bin/coreutils').stat().st_ino})
            self.assertEqual((disk / LINKS[0]).read_bytes(), BINARY)
            self.assertEqual(oct((disk / LINKS[0]).stat().st_mode & 0o7777), '0o755')
            # The empty locale files share a digest but are separate files in the package: they stay separate.
            self.assertEqual([self.links(name, locale) for locale in LOCALES], [1, 1])
            self.assertEqual(sorted(path.name for path in (disk / 'usr/lib/cargo/bin/coreutils').iterdir()), ['cat', 'ls', 'yes'])
        self.assertEqual(self.duperemove_log.read_text().split(), ['-d', '-r', '-q', *[str(self.disk(name)) for name in NAMES]])
        # Nothing changed since: the check is current and a forced run relinks nothing.
        self.assertEqual(self.shrink('check'), {'due': False, 'reason': 'current'})
        self.assertEqual(self.shrink('run')['relinked'], 0)

    def test_a_file_that_differs_from_the_shipped_one_is_never_replaced(self):
        changed = self.disk('nested-app-dev') / LINKS[1]
        changed.write_bytes(BINARY[:-1] + b'!')
        owned = self.disk('nested-app-dev') / LINKS[2]
        os.chmod(owned, 0o700)
        self.assertEqual(self.shrink('run')['relinked'], 7)
        self.assertEqual([changed.stat().st_nlink, owned.stat().st_nlink, self.links('nested-app-dev')], [1, 1, 2])
        self.assertEqual(changed.read_bytes()[-1:], b'!')

    def test_without_duperemove_only_the_relink_runs_and_the_shrink_stays_due(self):
        bare = self.folder / 'bare'
        bare.mkdir()
        (bare / 'incus').symlink_to(self.bin / 'incus')
        for tool in ['sh', 'echo']:
            (bare / tool).symlink_to(f'/bin/{tool}')
        self.assertEqual(self.shrink('run', path=str(bare)), {'shrink': 'relinked', 'relinked': 9, 'dedupe': 'missing'})
        self.assertFalse(self.record.exists())
        self.assertEqual(self.shrink('check'), {'due': True, 'reason': 'record'})

    def test_a_changed_package_makes_it_due_and_a_directory_pool_skips_the_dedupe(self):
        self.driver = 'dir'
        self.assertEqual(self.shrink('run'), {'shrink': 'ran', 'relinked': 9, 'dedupe': 'not_btrfs'})
        self.assertFalse(self.duperemove_log.exists())
        status = self.disk('nested-app-prod') / 'var/lib/dpkg/status'
        status.write_text(status.read_text() + '\nPackage: htop\nStatus: install ok installed\n')
        self.assertEqual(self.shrink('check'), {'due': True, 'reason': 'packages'})

    def test_a_failed_dedupe_fails_the_run_writes_no_record_and_stays_due(self):
        self.stub('duperemove', 'exit 1\n')
        self.assertEqual(self.shrink('run'), {'shrink': 'failed', 'relinked': 9, 'dedupe': 'failed'})
        self.assertFalse(self.record.exists())
        self.assertEqual(self.shrink('check'), {'due': True, 'reason': 'record'})

    def test_a_check_that_cannot_read_is_not_due_and_a_failed_run_writes_no_record(self):
        self.stub('incus', 'exit 1\n')
        self.assertEqual(self.shrink('check'), {'due': False, 'reason': 'unreadable'})
        self.assertEqual(self.shrink('run'), {'shrink': 'failed', 'relinked': 0, 'dedupe': 'failed'})
        self.assertFalse(self.record.exists())


if __name__ == '__main__':
    unittest.main()
