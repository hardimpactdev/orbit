"""Clear the inner Incus's network pid files from stopped nested containers before the refresh takes `base`.

See docs/reference/development-containers.md#no-stale-network-pid-file.

The refresh runs it as root in the Instance container: `python3 -I -c <this file> <pools> <name>...`, where `pools` is the inner
Incus's storage folder and the names are the nested containers whose `base` it retakes, all stopped by then.

An Incus Node keeps the pid file of each managed bridge's dnsmasq at `/var/lib/incus/networks/<network>/dnsmasq.pid`. A stop keeps it,
so a `base` would hold it; at the next start Incus reads it, and a file that is not YAML (an empty one) stops the network. In a stopped
container no dnsmasq runs, so every such file is stale and goes. The Node's own heal (files/incus-network-pid-heal.sh) covers a boot
of a disk that still holds one; this keeps each new `base` clean.

It reads each container from the inner Incus: a container whose status is not `Stopped` (a frozen one keeps its dnsmasq) is skipped
and named, and only the root disk in the pool of its `root` device is cleared. The root disk belongs to the nested container's root,
so no path below it is followed through a symlink: each folder is opened with `O_NOFOLLOW` from the one above it, and the pid file is
unlinked, never what it may link to. Its one line on standard output is a JSON object:
{"removed": ["<name>/<network>", ...], "skipped": ["<name>", ...]}.
"""
import errno
import json
import os
import stat
import subprocess
import sys

FOLDERS = ['var', 'lib', 'incus', 'networks']
PID_FILE = 'dnsmasq.pid'


def open_folder(parent, name):
    """The folder `name` in the open folder `parent`, never through a symlink; None when it is missing or no folder."""
    try:
        return os.open(name, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW, dir_fd=parent)
    except (FileNotFoundError, NotADirectoryError):
        return None
    except OSError as error:
        if error.errno == errno.ELOOP:  # a symlink
            return None
        raise


def walk(folder, parts):
    """The folder below the open `folder` along `parts`, each opened without following a symlink; None when one is missing. It
    closes `folder` and each folder on the way."""
    for part in parts:
        below = open_folder(folder, part)
        os.close(folder)
        if below is None:
            return None
        folder = below
    return folder


def instance(name):
    """The inner Incus's record of the nested container `name`: its status and its root disk's pool."""
    found = json.loads(subprocess.run(['incus', 'query', f'/1.0/instances/{name}'], capture_output=True, text=True, check=True).stdout)
    return found['status'], found['expanded_devices']['root']['pool']


def clear(pools, names):
    removed, skipped = [], []
    for name in names:
        status, pool = instance(name)
        # Only a stopped container: a frozen one keeps its processes, its dnsmasq among them.
        if status != 'Stopped':
            skipped.append(name)
            continue
        top = os.open(pools, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
        folder = walk(top, [pool, 'containers', name, 'rootfs', *FOLDERS])
        if folder is None:
            continue
        try:
            for network in sorted(os.listdir(folder)):
                network_folder = open_folder(folder, network)
                if network_folder is None:
                    continue
                try:
                    mode = os.stat(PID_FILE, dir_fd=network_folder, follow_symlinks=False).st_mode
                    if not stat.S_ISDIR(mode):
                        os.unlink(PID_FILE, dir_fd=network_folder)
                        removed.append(f'{name}/{network}')
                except FileNotFoundError:
                    pass
                finally:
                    os.close(network_folder)
        finally:
            os.close(folder)
    return {'removed': removed, 'skipped': skipped}


if __name__ == '__main__':
    print(json.dumps(clear(sys.argv[1], sys.argv[2:])))
