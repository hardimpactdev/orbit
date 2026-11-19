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
        self.clean_log = self.folder / 'clean.log'
        self.held = self.folder / 'held'
        self.held.mkdir()
        # `incus exec <name> -- apt-get clean` as apt runs it: it fails while an apt run holds the archives' lock, else empties them.
        self.stub('incus', f'if [ "$1" = exec ]; then echo "$@" >> {self.clean_log}; [ -e "{self.held}/$2" ] && exit 100\n'
                  f'find "{self.pools}/inner/containers/$2/rootfs/var/cache/apt/archives" -name "*.deb" -delete; exit 0; fi\n'
                  'case "$2" in /1.0/instances/*) echo \'{"expanded_devices": {"root": {"pool": "inner"}}}\';;\n'
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
        archives = disk / 'var/cache/apt/archives'
        (archives / 'partial').mkdir(parents=True)
        for name in ['acl_2.3.2-2_amd64.deb', 'partial/caddy_2.11.7_amd64.deb', 'lock']:
            (archives / name).write_bytes(b'x' * 1000)

    def shrink(self, mode, path=None):
        done = subprocess.run([sys.executable, '-I', '-c', SCRIPT.read_text(), mode, str(self.record), str(self.pools), *NAMES], capture_output=True, text=True,
                              check=False, env={**os.environ, 'PATH': path or f'{self.bin}:/usr/bin:/bin', 'DRIVER': self.driver})
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
        # The shrink ran after the last `base`: `base` is due once, and once retaken the check is current.
        self.assertEqual(self.shrink('check'), {'due': True, 'reason': 'shrunk'})
        self.assertEqual(self.shrink('based'), {'based': True})
        self.assertEqual(self.shrink('check'), {'due': False, 'reason': 'current'})
        # Nothing changed since: a forced run relinks nothing.
        self.assertEqual(self.shrink('run')['relinked'], 0)

    def test_a_base_retaken_after_a_package_changed_is_not_recorded_as_holding_the_shrink(self):
        self.shrink('run')
        status = self.disk('nested-app-dev') / 'var/lib/dpkg/status'
        status.write_text(status.read_text() + '\nPackage: btrfs-progs\nStatus: install ok installed\n')
        self.assertEqual(self.shrink('based'), {'based': False})
        self.assertEqual(self.shrink('check'), {'due': True, 'reason': 'packages'})

    def test_a_record_of_a_stopped_base_without_the_base_key_is_current(self):
        self.shrink('run')
        recorded = json.loads(self.record.read_text())
        del recorded['base']
        self.record.write_text(json.dumps(recorded))
        self.assertEqual(self.shrink('check'), {'due': False, 'reason': 'current'})

    def test_the_package_files_in_apt_archives_go_and_the_lock_stays(self):
        self.shrink('run')
        for name in NAMES:
            archives = self.disk(name) / 'var/cache/apt/archives'
            self.assertEqual(sorted(str(path.relative_to(archives)) for path in archives.rglob('*')), ['lock', 'partial'])

    def test_each_nested_apt_cleans_its_own_archives_and_a_held_lock_keeps_them(self):
        # apt-get on nested-app-dev holds the archives' lock between its download and dpkg's unpack: its clean fails and its files stay.
        (self.held / 'nested-app-dev').touch()
        done = subprocess.run([sys.executable, '-I', '-c', SCRIPT.read_text(), 'run', str(self.record), str(self.pools), *NAMES], capture_output=True,
                              text=True, check=False, env={**os.environ, 'PATH': f'{self.bin}:/usr/bin:/bin', 'DRIVER': self.driver})
        self.assertEqual(json.loads(done.stdout.splitlines()[-1])['shrink'], 'ran')
        self.assertIn('nested-app-dev: apt-get clean exited 100', done.stderr)
        self.assertEqual(self.clean_log.read_text().splitlines(), [f'exec {name} -- apt-get clean' for name in NAMES])
        self.assertEqual([len(list((self.disk(name) / 'var/cache/apt/archives').rglob('*.deb'))) for name in NAMES], [0, 2, 0])

    def outer(self, relative):
        """A file outside every root disk, on the same device, with the bytes, owner and mode of the shipped binary."""
        path = self.folder / 'outer' / relative
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_bytes(BINARY)
        path.chmod(0o755)
        return path

    def listed(self, name, *relatives):
        """Adds paths with the shipped binary's digest to the machine's rust-coreutils.md5sums."""
        md5sums = self.disk(name) / 'var/lib/dpkg/info/rust-coreutils.md5sums'
        md5sums.write_text(md5sums.read_text() + ''.join(f'{hashlib.md5(BINARY).hexdigest()}  {relative}\n' for relative in relatives))

    def run_on_a_directory_pool(self):
        self.driver = 'dir'
        done = subprocess.run([sys.executable, '-I', '-c', SCRIPT.read_text(), 'run', str(self.record), str(self.pools), *NAMES], capture_output=True,
                              text=True, check=False, env={**os.environ, 'PATH': f'{self.bin}:/usr/bin:/bin', 'DRIVER': self.driver})
        self.assertEqual(done.returncode, 0, done.stderr)
        return json.loads(done.stdout.splitlines()[-1]), done.stderr

    def assertUnlinked(self, path):
        self.assertEqual((path.lstat().st_nlink, path.read_bytes()), (1, BINARY), path)

    def test_an_absolute_path_in_the_md5sums_never_reaches_a_file_outside_the_disk(self):
        target = self.outer('absolute')
        self.listed('nested-app-dev', str(target))
        result, errors = self.run_on_a_directory_pool()
        self.assertUnlinked(target)
        self.assertEqual(result, {'shrink': 'ran', 'relinked': 9, 'dedupe': 'not_btrfs'})
        self.assertIn('nested-app-dev: skipped 1 paths', errors)

    def test_a_path_that_starts_with_two_slashes_is_absolute_too(self):
        target = self.outer('two-slashes')
        self.listed('nested-app-dev', '/' + str(target))
        result, errors = self.run_on_a_directory_pool()
        self.assertUnlinked(target)
        self.assertEqual(result['relinked'], 9)
        self.assertIn('nested-app-dev: skipped 1 paths', errors)

    def test_a_path_that_cannot_be_linked_is_skipped_and_the_others_are_linked(self):
        disk = self.disk('nested-app-dev')
        (disk / 'usr/lib/cargo/bin/coreutils/.orbit-relink-ls').mkdir()
        # A name that the temporary's prefix makes too long.
        long = 'usr/lib/cargo/bin/coreutils/' + 'x' * 250
        (disk / long).write_bytes(BINARY)
        (disk / long).chmod(0o755)
        self.listed('nested-app-dev', long)
        # A line that is no UTF-8 and a path with a NUL byte are two more paths that are not there.
        md5sums = disk / 'var/lib/dpkg/info/rust-coreutils.md5sums'
        digest = hashlib.md5(BINARY).hexdigest().encode()
        md5sums.write_bytes(md5sums.read_bytes() + digest + b'  usr/bin/\xff\n' + digest + b'  usr/bin/a\x00b\n')
        result, errors = self.run_on_a_directory_pool()
        self.assertEqual(result, {'shrink': 'ran', 'relinked': 8, 'dedupe': 'not_btrfs'})
        self.assertIn('nested-app-dev: skipped 4 paths', errors)
        self.assertTrue((disk / 'usr/lib/cargo/bin/coreutils/.orbit-relink-ls').is_dir())
        self.assertEqual(self.links('nested-app-dev'), 3)

    def test_a_dotdot_path_in_the_md5sums_never_reaches_a_file_outside_the_disk(self):
        target = self.outer('dotdot')
        self.listed('nested-app-dev', os.path.relpath(target, self.disk('nested-app-dev')))
        # A `..` part that stays inside the disk is skipped too: one rule, no resolving.
        self.listed('nested-app-prod', 'usr/lib/../lib/cargo/bin/coreutils/ls')
        result, errors = self.run_on_a_directory_pool()
        self.assertEqual(result['relinked'], 9)
        self.assertUnlinked(target)
        self.assertEqual(self.links('nested-app-dev'), 4)
        self.assertIn('nested-app-prod: skipped 1 paths', errors)

    def test_a_symlinked_folder_in_a_listed_path_is_not_followed(self):
        target = self.outer('folder/ls')
        (self.disk('nested-app-dev') / 'usr/lib/cargo/bin/outer').symlink_to(target.parent)
        self.listed('nested-app-dev', 'usr/lib/cargo/bin/outer/ls')
        self.assertEqual(self.run_on_a_directory_pool()[0]['relinked'], 9)
        self.assertUnlinked(target)

    def test_a_symlinked_folder_of_the_shipped_file_links_nothing_to_a_file_outside_the_disk(self):
        target = self.outer('bin/coreutils')
        disk = self.disk('nested-app-dev')
        subprocess.run(['rm', '-rf', str(disk / 'usr/bin')], check=True)
        (disk / 'usr/bin').symlink_to(target.parent)
        self.assertEqual(self.run_on_a_directory_pool()[0]['relinked'], 6)
        self.assertUnlinked(target)
        for link in LINKS:
            self.assertUnlinked(disk / link)

    def test_a_symlinked_md5sums_or_dpkg_status_is_not_read(self):
        outer = self.folder / 'outer'
        outer.mkdir()
        info = self.disk('nested-app-dev') / 'var/lib/dpkg/info/rust-coreutils.md5sums'
        (outer / 'md5sums').write_bytes(info.read_bytes())
        info.unlink()
        info.symlink_to(outer / 'md5sums')
        self.assertEqual(self.run_on_a_directory_pool()[0]['relinked'], 6)
        status = self.disk('nested-app-prod') / 'var/lib/dpkg/status'
        (outer / 'status').write_bytes(status.read_bytes())
        status.unlink()
        status.symlink_to(outer / 'status')
        self.assertEqual(self.shrink('check'), {'due': False, 'reason': 'unreadable'})

    def test_a_symlinked_root_disk_fails_the_run_and_reaches_nothing_outside_the_pool(self):
        disk = self.disk('nested-app-prod')
        moved = self.folder / 'outer/rootfs'
        moved.parent.mkdir()
        disk.rename(moved)
        disk.symlink_to(moved)
        self.assertEqual(self.shrink('check'), {'due': False, 'reason': 'unreadable'})
        self.assertEqual(self.shrink('based'), {'based': False})
        self.assertEqual(self.shrink('run'), {'shrink': 'failed', 'relinked': 0, 'dedupe': 'failed'})
        self.assertFalse(self.duperemove_log.exists())
        self.assertEqual((moved / 'usr/bin/coreutils').stat().st_nlink, 1)
        self.assertEqual(len(list((moved / 'var/cache/apt/archives').rglob('*.deb'))), 2)

    def test_a_leftover_temporary_link_goes_and_its_path_is_linked(self):
        # A run stopped between its link and its rename leaves the temporary.
        folder = self.disk('nested-app-dev') / 'usr/lib/cargo/bin/coreutils'
        os.link(self.disk('nested-app-dev') / 'usr/bin/coreutils', folder / '.orbit-relink-ls')
        self.assertEqual(self.shrink('run')['relinked'], 9)
        self.assertFalse(os.path.lexists(folder / '.orbit-relink-ls'))
        self.assertEqual(self.links('nested-app-dev'), 4)

    def test_a_record_that_is_no_object_is_no_record(self):
        self.record.parent.mkdir(parents=True)
        self.record.write_text('[]')
        self.assertEqual(self.shrink('check'), {'due': True, 'reason': 'record'})

    def test_a_record_of_an_older_rule_is_due_once(self):
        self.shrink('run')
        recorded = json.loads(self.record.read_text())
        self.record.write_text(json.dumps({**recorded, 'rule': '1'}))
        self.assertEqual(self.shrink('check'), {'due': True, 'reason': 'record'})
        self.shrink('run')
        self.shrink('based')
        self.assertEqual(self.shrink('check'), {'due': False, 'reason': 'current'})

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
        (bare / 'timeout').symlink_to('/usr/bin/timeout')
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

    def test_a_package_that_changes_during_the_run_writes_no_record(self):
        status = self.disk('nested-app-dev') / 'var/lib/dpkg/status'
        self.stub('duperemove', f'echo "Package: htop" >> {status}\n')
        self.assertEqual(self.shrink('run')['shrink'], 'ran')
        self.assertFalse(self.record.exists())
        self.assertEqual(self.shrink('check'), {'due': True, 'reason': 'record'})

    def test_a_check_that_cannot_read_is_not_due_and_a_failed_run_writes_no_record(self):
        self.stub('incus', 'exit 1\n')
        self.assertEqual(self.shrink('check'), {'due': False, 'reason': 'unreadable'})
        self.assertEqual(self.shrink('based'), {'based': False})
        self.assertEqual(self.shrink('run'), {'shrink': 'failed', 'relinked': 0, 'dedupe': 'failed'})
        self.assertFalse(self.record.exists())


if __name__ == '__main__':
    unittest.main()
