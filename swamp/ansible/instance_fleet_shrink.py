"""Keep the nested fleet's root disks small (docs/reference/development-containers.md#the-inner-machines-stay-small).

The fleet refresh runs it as root in the Instance container: `python3 -I -c <this file> <mode> <record> <pools> <name>...`, where
`pools` is the inner Incus's storage folder and the names are the three nested containers. `check` only reads; the refresh runs `run`
in its `base` window, with the three containers stopped, their old `base` deleted and before the new one is taken, so the new `base`
and the seed hold the small form.

Two parts:
- Relink. Ubuntu's rust-coreutils ships one binary as 116 hardlinks: `/usr/bin/coreutils` and its 115 links in
  `/usr/lib/cargo/bin/coreutils/`. In the first nested fleet they were 116 separate copies, 1.26 GiB per machine. RELINK names each
  package and the one file it ships with links. Only the files of that package that dpkg records with that file's digest are
  relinked, and only those that are byte for byte the same regular file with the same owner and mode: the files the package ships as
  links of that file. Other files of one digest, such as the package's empty locale files, are separate files in the package and stay.
  Each is replaced atomically by a link to the file (a new link beside it, then a rename over it), so no reader ever finds the path
  missing or partial.
- Dedupe. `duperemove -dr` over the three root disks makes identical blocks of different files share one extent (the kernel
  compares the bytes before it shares them, so a file never changes). It needs a btrfs pool and the `duperemove` package, which
  instance_shape_tools.py keeps in the container; without it only the relink runs.

It costs nothing when nothing changed: the record holds the sha256 of each machine's dpkg status, and `check` is due only when the
record is missing or a status differs (a package was installed, removed or upgraded). `run` writes the record only when both parts
ran, so a run without duperemove is due again at the next refresh. A check that cannot read (`unreadable`) is not due.

It never fails: its last line on standard output is one JSON object. `check`: {"due": true|false, "reason": "record"|"packages"|
"current"|"unreadable"}. `run`: {"shrink": "ran"|"relinked"|"failed", "relinked": <files>, "dedupe": "ran"|"missing"|"not_btrfs"|"failed"}.
Everything its programs print goes to standard error.
"""
import filecmp
import hashlib
import json
import os
import shutil
import stat
import subprocess
import sys
from pathlib import Path

# Each package and the file it ships with links: every file of the package with that file's digest is one of them (see the docstring).
RELINK = {'rust-coreutils': 'usr/bin/coreutils'}
# A new rule changes this, so every record is stale once.
RULE = '1'


def rootfs(pools, name):
    """The container's root disk in the inner Incus's pool, and whether that pool is btrfs."""
    instance = json.loads(subprocess.run(['incus', 'query', f'/1.0/instances/{name}'], capture_output=True, text=True, check=True).stdout)
    pool = instance['expanded_devices']['root']['pool']
    driver = json.loads(subprocess.run(['incus', 'query', f'/1.0/storage-pools/{pool}'], capture_output=True, text=True, check=True).stdout)['driver']
    return Path(pools) / pool / 'containers' / name / 'rootfs', driver == 'btrfs'


def state(disks):
    """The sha256 of each machine's dpkg status, with the rule's version."""
    return {'rule': RULE, **{name: hashlib.sha256((disk / 'var/lib/dpkg/status').read_bytes()).hexdigest() for name, (disk, _) in disks.items()}}


def read_record(record):
    try:
        return json.loads(Path(record).read_text())
    except (OSError, ValueError):
        return None


def md5sums(disk, package):
    """dpkg's digests of the package's files, as paths in `disk`."""
    for name in (f'{package}.md5sums', f'{package}:amd64.md5sums'):
        path = disk / 'var/lib/dpkg/info' / name
        if path.is_file():
            for line in path.read_text().splitlines():
                digest, _, relative = line.partition('  ')
                if digest and relative:
                    yield digest, disk / relative
            return


def same_file(first, second):
    a, b = first.lstat(), second.lstat()
    return (stat.S_ISREG(a.st_mode) and stat.S_ISREG(b.st_mode) and a.st_dev == b.st_dev and a.st_size == b.st_size
            and (a.st_mode, a.st_uid, a.st_gid) == (b.st_mode, b.st_uid, b.st_gid) and filecmp.cmp(first, second, shallow=False))


def relink(disk):
    """Link the files that a package ships as links of one file back to that file; the number of paths it replaced."""
    replaced = 0
    for package, shipped in RELINK.items():
        digests = list(md5sums(disk, package))
        first = disk / shipped
        digest = next((digest for digest, path in digests if path == first), None)
        if digest is None or not first.is_file() or first.is_symlink():
            continue
        for path in [path for each, path in digests if each == digest and path != first]:
            if path.is_symlink() or not path.is_file() or path.lstat().st_ino == first.lstat().st_ino or not same_file(first, path):
                continue
            temporary = path.with_name('.orbit-relink-' + path.name)
            if temporary.exists() or temporary.is_symlink():
                temporary.unlink()
            os.link(first, temporary)
            os.rename(temporary, path)
            replaced += 1
    return replaced


def check(record, pools, names):
    disks = {name: rootfs(pools, name) for name in names}
    recorded = read_record(record)
    reason = 'record' if recorded is None else 'packages' if recorded != state(disks) else 'current'
    return {'due': reason != 'current', 'reason': reason}


def run(record, pools, names):
    disks = {name: rootfs(pools, name) for name in names}
    replaced = sum(relink(disk) for disk, _ in disks.values())
    if not all(btrfs for _, btrfs in disks.values()):
        dedupe = 'not_btrfs'
    elif shutil.which('duperemove') is None:
        dedupe = 'missing'
    else:
        done = subprocess.run(['duperemove', '-d', '-r', '-q', *[str(disk) for disk, _ in disks.values()]], stdout=sys.stderr, check=False)
        dedupe = 'ran' if done.returncode == 0 else 'failed'
    if dedupe in ('ran', 'not_btrfs'):
        path = Path(record)
        path.parent.mkdir(parents=True, exist_ok=True)
        partial = path.with_name(path.name + '.tmp')
        partial.write_text(json.dumps(state(disks), sort_keys=True))
        os.rename(partial, path)
    return {'shrink': 'ran' if dedupe in ('ran', 'not_btrfs') else 'relinked' if dedupe == 'missing' else 'failed',
            'relinked': replaced, 'dedupe': dedupe}


def main():
    mode, record, pools, *names = sys.argv[1:]
    try:
        result = check(record, pools, names) if mode == 'check' else run(record, pools, names)
    except (OSError, ValueError, KeyError, subprocess.CalledProcessError) as error:
        print(f'{type(error).__name__}: {error}', file=sys.stderr)
        # A check that cannot read is not due: the refresh keeps `base`, and a failed read never costs a dedupe at each refresh.
        result = {'due': False, 'reason': 'unreadable'} if mode == 'check' else {'shrink': 'failed', 'relinked': 0, 'dedupe': 'failed'}
    print(json.dumps(result))


if __name__ == '__main__':
    main()
