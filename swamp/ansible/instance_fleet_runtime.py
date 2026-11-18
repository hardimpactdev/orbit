"""Clear the inner Incus's network pid files from stopped nested containers: before the refresh takes `base`, and in a reset
between the restore of `base` and the start.

See docs/reference/development-containers.md#no-stale-network-pid-file.

The fleet runs it as root in the Instance container: `python3 -I -c <this file> <pools> <name>...`, where `pools` is the inner
Incus's storage folder and the names are stopped nested containers: those whose `base` the refresh retakes, and those a reset restored.
A `base` taken before the Node's heal existed holds neither the heal nor a clean disk, so the reset clears it before it boots.

An Incus Node keeps the pid file of each managed bridge's dnsmasq at `/var/lib/incus/networks/<network>/dnsmasq.pid`. A stop keeps it,
so a `base` would hold it; at the next start Incus reads it, and a file that is not YAML (an empty one) stops the network. In a stopped
container no dnsmasq runs, so every such file is stale and goes. The Node's own heal (files/incus-network-pid-heal.py) covers every
other boot of a disk that still holds one.

It reads each container from the inner Incus: a container whose status is not `Stopped` (a frozen one keeps its dnsmasq) is skipped
and named, and only the root disk in the pool of its `root` device is cleared. The root disk belongs to the nested container's root,
so no path below it is followed through a symlink: each folder is opened with `O_NOFOLLOW` from the one above it, and the pid file is
unlinked, never what it may link to. Its one line on standard output is a JSON object:
{"removed": ["<name>/<network>", ...], "skipped": ["<name>", ...], "unsafe": ["<name>", ...]}. A container whose root disk is
missing, or holds a link where the walk needs a folder (on the way to `networks`, or as a network folder), is `unsafe`: the caller
fails, so no `base` is taken from it.
"""
import errno
import json
import os
import stat
import subprocess
import sys

FOLDERS = ['var', 'lib', 'incus', 'networks']
PID_FILE = 'dnsmasq.pid'


class Unsafe(Exception):
    """A path below the root disk that is a link or no folder: it cannot be checked without following it."""


def open_folder(parent, name, missing_ok=True):
    """The folder `name` in the open folder `parent`, never through a link. None when it is missing and `missing_ok`; Unsafe when it
    is a link or no folder."""
    try:
        return os.open(name, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW, dir_fd=parent)
    except FileNotFoundError:
        if missing_ok:
            return None
        raise Unsafe(name) from None
    except OSError as error:
        if error.errno in (errno.ELOOP, errno.ENOTDIR):
            raise Unsafe(name) from None
        raise


def walk(folder, parts, missing_ok):
    """The folder below the open `folder` along `parts`, each opened without following a link; None when one is missing and
    `missing_ok`. It closes `folder` and each folder on the way."""
    for part in parts:
        try:
            below = open_folder(folder, part, missing_ok)
        finally:
            os.close(folder)
        if below is None:
            return None
        folder = below
    return folder


def instance(name):
    """The inner Incus's record of the nested container `name`: its status and its root disk's pool."""
    found = json.loads(subprocess.run(['incus', 'query', f'/1.0/instances/{name}'], capture_output=True, text=True, check=True).stdout)
    return found['status'], found['expanded_devices']['root']['pool']


def clear_disk(pools, pool, name, removed):
    """Add each network whose pid file went from the root disk of `name` in `pool` to `removed`, also when a later part is Unsafe."""
    top = os.open(pools, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
    disk = walk(top, [pool, 'containers', name, 'rootfs'], missing_ok=False)
    folder = walk(disk, FOLDERS, missing_ok=True)
    if folder is None:
        return
    try:
        for network in sorted(os.listdir(folder)):
            # A network folder that is a link may hold a pid file that this cannot reach safely: the disk is unsafe.
            mode = os.stat(network, dir_fd=folder, follow_symlinks=False).st_mode
            if stat.S_ISLNK(mode):
                raise Unsafe(network)
            if not stat.S_ISDIR(mode):
                continue
            network_folder = open_folder(folder, network)
            # Gone between its read and its open: nothing of it is left to clear.
            if network_folder is None:
                continue
            try:
                mode = os.stat(PID_FILE, dir_fd=network_folder, follow_symlinks=False).st_mode
                if not stat.S_ISDIR(mode):
                    os.unlink(PID_FILE, dir_fd=network_folder)
                    removed.append(network)
            except FileNotFoundError:
                pass
            finally:
                os.close(network_folder)
    finally:
        os.close(folder)


def clear(pools, names):
    removed, skipped, unsafe = [], [], []
    for name in names:
        status, pool = instance(name)
        # Only a stopped container: a frozen one keeps its processes, its dnsmasq among them.
        if status != 'Stopped':
            skipped.append(name)
            continue
        cleared = []
        try:
            clear_disk(pools, pool, name, cleared)
        except Unsafe:
            unsafe.append(name)
        removed += [f'{name}/{network}' for network in cleared]
    return {'removed': removed, 'skipped': skipped, 'unsafe': unsafe}


if __name__ == '__main__':
    print(json.dumps(clear(sys.argv[1], sys.argv[2:])))
