"""Keep the nested fleet's root disks small (docs/reference/development-containers.md#the-inner-machines-stay-small).

The fleet refresh runs it as root in the Instance container: `python3 -I -c <this file> <mode> <record> <pools> <name>...`, where
`pools` is the inner Incus's storage folder and the names are the three nested containers. `check` only reads. A due `run` never holds
up the refresh: the refresh takes `base` of the running fleet, then starts `run` as the unit orbit-fleet-shrink in the Instance
container, which runs on after the Operation while the three run. It records `base: false`; the next refresh finds the check `shrunk`,
retakes `base` of the running fleet so it holds the small form, and runs `based`, which records that. Each refresh stops a shrink that
still runs before it changes anything, so no shrink runs while a refresh holds `base`.

Three parts, in this order:
- Relink. Ubuntu's rust-coreutils ships one binary as 116 hardlinks: `/usr/bin/coreutils` and its 115 links in
  `/usr/lib/cargo/bin/coreutils/`. In the first nested fleet they were 116 separate copies, 1.26 GiB per machine. RELINK names each
  package and the one file it ships with links. Only the files of that package that dpkg records with that file's digest are
  relinked, and only those that are byte for byte the same regular file with the same owner and mode: the files the package ships as
  links of that file. Other files of one digest, such as the package's empty locale files, are separate files in the package and stay.
  Each is replaced atomically by a link to the file (a new link beside it, swapped with the path by renameat2 RENAME_EXCHANGE), so no
  reader ever finds the path missing or partial. A swapped-out file that a package replaced after the compare is swapped back. The paths come from the nested container's own dpkg files, so each must stay inside its root disk (`inside`):
  an absolute path, a `..` part or a symlink anywhere below the disk is skipped. Each machine's count of skipped paths goes to
  standard error; the result counts only the paths it relinked.
- Caches. Each nested container's apt archives are emptied by its own `apt-get clean` (`incus exec`): apt keeps no new package files
  (the fleet file 91orbit-nested-package-files), and the egress proxy serves a package again when apt needs it. The containers run,
  and an apt run needs the package files it fetched until dpkg unpacked them. apt holds the archives' lock over that time, and
  `apt-get clean` takes the same lock without waiting: while apt holds it, the clean fails, that container keeps its files for a later
  shrink, and its failure goes to standard error. The clean runs as the nested root, so no path of the outer side is involved.
- Dedupe. `duperemove -dr` over the three root disks makes identical blocks of different files share one extent (the kernel
  compares the bytes before it shares them, so a file never changes). It needs a btrfs pool and the `duperemove` package, which
  instance_shape_tools.py keeps in the container; without it the first two parts run. Its arguments are the three root disks, named
  by the outer side; a root disk that is a symlink fails the run, and duperemove's own walk follows no symlink.

The nested container's root owns the files of a root disk, and the containers run. So every file `run` links is reached
through folders it holds open, each opened from the one above with O_NOFOLLOW (`walk`): a folder swapped for a symlink fails the open,
and the link and the swap happen inside the held folder. A file is linked only over one of the same bytes, owner and mode, so a
reader finds the old file or the link, never a missing or partial one. The files it only reads (dpkg's status and md5sums) are found
through `inside`: a nested root that swaps one for a symlink can at most make the step hash or list another file (nothing leaves the
digest; each listed path is walked as above) or time out as `unreadable`. duperemove shares only blocks the kernel found equal, so a
file never changes, and a seed taken while it runs holds whole files.

It costs nothing when nothing changed: the record holds the sha256 of each machine's dpkg status, and `check` is due only when the
record is missing or a status differs (a package was installed, removed or upgraded), or as `shrunk` when `base` predates the shrink. `run` writes the record only when the dedupe ran
too (or the pool is no btrfs pool), so a run without duperemove is due again at the next refresh. A check that cannot read (`unreadable`) is not due.

It never fails: its last line on standard output is one JSON object. `check`: {"due": true|false, "reason": "record"|"packages"|
"shrunk"|"current"|"unreadable"}. `based`: {"based": true|false}. `run`: {"shrink": "ran"|"relinked"|"failed", "relinked": <files>, "dedupe": "ran"|"missing"|"not_btrfs"|"failed"}.
Everything its programs print goes to standard error.
"""
import ctypes
import hashlib
import json
import os
import shutil
import stat
import subprocess
import sys
from pathlib import Path, PurePosixPath

# Each package and the file it ships with links: every file of the package with that file's digest is one of them (see the docstring).
RELINK = {'rust-coreutils': 'usr/bin/coreutils'}
# A new rule changes this, so every record is stale once.
RULE = '2'
# The cache clean of a nested container, run inside it: apt takes its archives' lock without waiting and fails while an apt run holds it.
CLEAN = ('apt-get', 'clean')


def inside(disk, relative, kind):
    """`disk / relative` when the path stays inside `disk`: no absolute path, no `..` part, every part below `disk` a real folder (no
    symlink, by lstat) and the last part of `kind` (stat.S_ISREG or stat.S_ISDIR). None otherwise, and when a part is missing."""
    # `is_absolute` also holds for `//x`, whose first part is `//`, not `/`.
    parts = PurePosixPath(relative).parts
    if not parts or PurePosixPath(relative).is_absolute() or '..' in parts:
        return None
    path = disk
    for index, part in enumerate(parts):
        path = path / part
        try:
            mode = path.lstat().st_mode
        except (OSError, ValueError):
            return None
        if not (kind if index == len(parts) - 1 else stat.S_ISDIR)(mode):
            return None
    return path


def rootfs(pools, name):
    """The container's root disk in the inner Incus's pool, and whether that pool is btrfs."""
    instance = json.loads(subprocess.run(['incus', 'query', f'/1.0/instances/{name}'], capture_output=True, text=True, check=True).stdout)
    pool = instance['expanded_devices']['root']['pool']
    driver = json.loads(subprocess.run(['incus', 'query', f'/1.0/storage-pools/{pool}'], capture_output=True, text=True, check=True).stdout)['driver']
    disk = inside(Path(pools), f'{pool}/containers/{name}/rootfs', stat.S_ISDIR)
    if disk is None:
        raise ValueError(f'the root disk of {name} is no folder inside {pools}')
    return disk, driver == 'btrfs'


def state(disks):
    """The sha256 of each machine's dpkg status, with the rule's version."""
    def status(disk):
        path = inside(disk, 'var/lib/dpkg/status', stat.S_ISREG)
        if path is None:
            raise ValueError(f'{disk}: the dpkg status is no file inside the root disk')
        return hashlib.sha256(path.read_bytes()).hexdigest()
    return {'rule': RULE, **{name: status(disk) for name, (disk, _) in disks.items()}}


def read_record(record):
    try:
        recorded = json.loads(Path(record).read_text())
    except (OSError, ValueError):
        return None
    return recorded if isinstance(recorded, dict) else None


def md5sums(disk, package):
    """dpkg's digests of the package's files, with each path as dpkg records it (relative to `disk`)."""
    for name in (f'{package}.md5sums', f'{package}:amd64.md5sums'):
        path = inside(disk, f'var/lib/dpkg/info/{name}', stat.S_ISREG)
        if path is not None:
            for line in path.read_text(errors='surrogateescape').splitlines():
                digest, _, relative = line.partition('  ')
                if digest and relative:
                    yield digest, relative
            return


def walk(disk, relative):
    """The open folder that holds `relative` inside `disk` and the last part's name, or None: every folder is opened from the one
    above it with O_NOFOLLOW, so a running container that swaps a folder for a symlink can never lead a link or a removal out of
    `disk`. The caller closes the folder."""
    parts = PurePosixPath(relative).parts
    if not parts or PurePosixPath(relative).is_absolute() or '..' in parts:
        return None
    flags = os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW
    try:
        folder = os.open(disk, flags)
    except OSError:
        return None
    for part in parts[:-1]:
        try:
            below = os.open(part, flags, dir_fd=folder)
        except (OSError, ValueError):
            os.close(folder)
            return None
        os.close(folder)
        folder = below
    return folder, parts[-1]


def regular(folder, name):
    try:
        return os.stat(name, dir_fd=folder, follow_symlinks=False)
    except (OSError, ValueError):
        return None


LIBC = ctypes.CDLL(None, use_errno=True)
RENAME_EXCHANGE = 2


def exchange(folder, one, two):
    """Swap two names in one folder atomically (renameat2 RENAME_EXCHANGE)."""
    if LIBC.renameat2(folder, os.fsencode(one), folder, os.fsencode(two), RENAME_EXCHANGE) != 0:
        number = ctypes.get_errno()
        raise OSError(number, os.strerror(number))


def same_file(first, second):
    """Both (folder, name) are the same regular file by bytes, owner and mode, each read through a descriptor that followed no link."""
    opened = []
    try:
        for folder, name in (first, second):
            opened.append(os.open(name, os.O_RDONLY | os.O_NOFOLLOW, dir_fd=folder))
        a, b = (os.fstat(each) for each in opened)
        if not (stat.S_ISREG(a.st_mode) and stat.S_ISREG(b.st_mode) and a.st_dev == b.st_dev and a.st_size == b.st_size
                and (a.st_mode, a.st_uid, a.st_gid) == (b.st_mode, b.st_uid, b.st_gid)):
            return False
        # Bounded by the size both had; the read at that size also compares what either gained since.
        return all(os.pread(opened[0], 1 << 20, at) == os.pread(opened[1], 1 << 20, at) for at in range(0, a.st_size + 1, 1 << 20))
    except OSError:
        return False
    finally:
        for each in opened:
            os.close(each)


def relink(disk):
    """Link the files that a package ships as links of one file back to that file; the number of paths it replaced, and the number it
    skipped because they are no regular file inside `disk`. It runs while the containers run: each folder is held open (`walk`)."""
    replaced = skipped = 0
    for package, shipped in RELINK.items():
        digests = list(md5sums(disk, package))
        digest = next((digest for digest, relative in digests if relative == shipped), None)
        first = walk(disk, shipped)
        if digest is None or first is None:
            continue
        try:
            first_stat = regular(*first)
            if first_stat is None or not stat.S_ISREG(first_stat.st_mode):
                continue
            for relative in [relative for each, relative in digests if each == digest and relative != shipped]:
                path = walk(disk, relative)
                if path is None:
                    skipped += 1
                    continue
                folder, name = path
                try:
                    found = regular(folder, name)
                    if found is None or not stat.S_ISREG(found.st_mode):
                        skipped += 1
                        continue
                    if found.st_ino == first_stat.st_ino or not same_file(first, path):
                        continue
                    # A new link beside it, swapped with the path in one step, both inside the held folders. The containers run, so a
                    # package may replace either file meanwhile: a link that is not the compared shipped file goes, and a swapped-out
                    # file that is not the compared one is swapped back, so no newer file is ever lost. A leftover of an earlier run
                    # goes. A path that cannot be linked (a folder in the temporary's place, a name too long for the prefix) is skipped.
                    temporary = '.orbit-relink-' + name
                    try:
                        if regular(folder, temporary) is not None:
                            os.unlink(temporary, dir_fd=folder)
                        os.link(first[1], temporary, src_dir_fd=first[0], dst_dir_fd=folder, follow_symlinks=False)
                        linked = regular(folder, temporary)
                        if linked is None or linked.st_ino != first_stat.st_ino:
                            os.unlink(temporary, dir_fd=folder)
                            skipped += 1
                            continue
                        exchange(folder, temporary, name)
                        old = regular(folder, temporary)
                        if old is None or (old.st_dev, old.st_ino) != (found.st_dev, found.st_ino):
                            exchange(folder, temporary, name)
                            os.unlink(temporary, dir_fd=folder)
                            skipped += 1
                            continue
                        os.unlink(temporary, dir_fd=folder)
                    except OSError:
                        skipped += 1
                        continue
                    replaced += 1
                finally:
                    os.close(folder)
        finally:
            os.close(first[0])
    return replaced, skipped


def clean(name):
    """Empty the apt archives of the running nested container `name` with its own `apt-get clean` (CLEAN); a failed clean is named."""
    done = subprocess.run(['timeout', '60', 'incus', 'exec', name, '--', *CLEAN], stdout=sys.stderr, check=False)
    if done.returncode != 0:
        print(f'{name}: apt-get clean exited {done.returncode}, so its archives keep their package files', file=sys.stderr)


def write_record(record, recorded):
    path = Path(record)
    path.parent.mkdir(parents=True, exist_ok=True)
    partial = path.with_name(path.name + '.tmp')
    partial.write_text(json.dumps(recorded, sort_keys=True))
    os.rename(partial, path)


def check(record, pools, names):
    disks = {name: rootfs(pools, name) for name in names}
    recorded = read_record(record)
    # A record of an older rule is no record of this one. `base: false`: the shrink ran after the last `base`, which still holds the
    # old blocks; a record without the key is one that a `base` of the stopped fleet took.
    if recorded is None or recorded.get('rule') != RULE:
        reason = 'record'
    elif {key: value for key, value in recorded.items() if key != 'base'} != state(disks):
        reason = 'packages'
    else:
        reason = 'shrunk' if recorded.get('base') is False else 'current'
    return {'due': reason != 'current', 'reason': reason}


def based(record, pools, names):
    """`base` of all three was retaken after a shrink: the record says so, when the disks are still as the shrink left them."""
    disks = {name: rootfs(pools, name) for name in names}
    recorded = read_record(record)
    current = state(disks)
    if recorded is None or {key: value for key, value in recorded.items() if key != 'base'} != current:
        return {'based': False}
    write_record(record, current)
    return {'based': True}


def run(record, pools, names):
    disks = {name: rootfs(pools, name) for name in names}
    # The containers run: a package that changes during the run may change a file after its compare, so such a run records nothing
    # and the next refresh starts it again.
    before = state(disks)
    replaced = 0
    for name, (disk, _) in disks.items():
        relinked, skipped = relink(disk)
        replaced += relinked
        if skipped:
            print(f'{name}: skipped {skipped} paths that are no regular file inside the root disk', file=sys.stderr)
    for name in disks:
        clean(name)
    if not all(btrfs for _, btrfs in disks.values()):
        dedupe = 'not_btrfs'
    elif shutil.which('duperemove') is None:
        dedupe = 'missing'
    else:
        # Its own bound inside the container, under the step's 600 s: a timeout of the step ends only the `incus exec` client.
        done = subprocess.run(['timeout', '570', 'duperemove', '-d', '-r', '-q', *[str(disk) for disk, _ in disks.values()]], stdout=sys.stderr,
                              check=False)
        dedupe = 'ran' if done.returncode == 0 else 'failed'
    if dedupe in ('ran', 'not_btrfs') and state(disks) == before:
        write_record(record, {**before, 'base': False})
    return {'shrink': 'ran' if dedupe in ('ran', 'not_btrfs') else 'relinked' if dedupe == 'missing' else 'failed',
            'relinked': replaced, 'dedupe': dedupe}


def main():
    mode, record, pools, *names = sys.argv[1:]
    try:
        result = {'check': check, 'run': run, 'based': based}[mode](record, pools, names)
    except (OSError, ValueError, KeyError, subprocess.CalledProcessError) as error:
        print(f'{type(error).__name__}: {error}', file=sys.stderr)
        # A check that cannot read is not due: the refresh keeps `base`, and a failed read never costs a dedupe at each refresh.
        result = ({'due': False, 'reason': 'unreadable'} if mode == 'check' else {'based': False} if mode == 'based'
                  else {'shrink': 'failed', 'relinked': 0, 'dedupe': 'failed'})
    print(json.dumps(result))


if __name__ == '__main__':
    main()
