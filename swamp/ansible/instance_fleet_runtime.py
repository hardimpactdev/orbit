"""Clear the inner Incus's network runtime files from stopped nested containers.

See docs/reference/development-containers.md#no-stale-network-pid-file.

The fleet runs it as root in the Instance container: `python3 -I -c <this file> <pools> <name>...`, where `pools` is the inner Incus's
storage folder and the names are nested containers that are stopped: before the refresh takes `base`, and before a start boots them.

An Incus Node keeps the pid file of each managed bridge's dnsmasq on its disk, at `/var/lib/incus/networks/<network>/dnsmasq.pid`. At
its next start Incus reads that file to stop the old dnsmasq, and a file that is not YAML stops the network from starting: an empty file
fails with `Could not read pid file`, and every Instance on the bridge fails to start. A copy of a running fleet (a seed of the Instance
container) can hold such a file empty, its last write not yet on disk; a `base` taken from it held it too, and each reset brought it
back. In a stopped container no dnsmasq runs, so every such file is stale: it goes, and Incus starts the network clean.

The root disk belongs to the nested container's root, so no path below it is followed through a symlink: each folder is opened with
`O_NOFOLLOW` from the one above it, and the pid file is unlinked, never what it may link to. A folder that is missing (a nested
container that runs no managed network) is skipped. Its one line on standard output is a JSON object:
{"removed": ["<name>/<network>", ...]}.
"""
import errno
import json
import os
import stat
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


def clear(pools, names):
    removed = []
    for name in names:
        for pool in sorted(os.listdir(pools)):
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
    return removed


if __name__ == '__main__':
    print(json.dumps({'removed': clear(sys.argv[1], sys.argv[2:])}))
