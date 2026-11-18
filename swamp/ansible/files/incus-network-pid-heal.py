#!/usr/bin/python3 -I
"""Before Incus starts on a fleet Node, drop each managed network's dnsmasq pid file that no live dnsmasq of that network owns.

See docs/reference/development-containers.md#no-stale-network-pid-file. Its `ExecStartPre` drop-in runs it, whatever boots the Node.

    incus-network-pid-heal [<networks folder> [<proc folder> [<boot id file>]]]

Incus reads `<networks>/<network>/dnsmasq.pid` at the network's start to stop the old dnsmasq. A file that is not YAML, such as an
empty one from a copy of a running Node, fails the network with `Could not read pid file`. A file stays only when it is a regular file
that reads as the record Incus writes (one `key: value` line per top-level key, its list values continued on indented lines) with
`name: dnsmasq`, a `pid`, a `start_time` and a `boot_id`, and when that `boot_id` is this boot's, the process lives, its `comm` is
`dnsmasq` and its command line holds `--interface=<network>` as one whole argument: a daemon restart keeps that dnsmasq and its file.
Every other file goes. No link is followed: a network folder that is a link is left alone, and a pid file that is a link is unlinked
itself. It prints `removed <network>` for each removal and never fails the start.
"""
import errno
import os
import re
import stat
import sys

KEY = re.compile(r'([a-z_]+):(?: (.*))?')
BOOT_ID = re.compile(r'[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}')
FOLDER = os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW


def open_path(path):
    """The folder `path`, opened one part at a time from `/` without following a link; None when a part is missing or no folder."""
    folder = os.open('/', FOLDER)
    for part in [part for part in path.split('/') if part]:
        try:
            below = os.open(part, FOLDER, dir_fd=folder)
        except OSError as error:
            if error.errno in (errno.ENOENT, errno.ENOTDIR, errno.ELOOP):
                below = None
            else:
                raise
        finally:
            os.close(folder)
        if below is None:
            return None
        folder = below
    return folder


def record(text):
    """The top-level keys of the record, or None when it is not the shape Incus writes."""
    if not text.endswith('\n'):
        return None
    keys = {}
    for line in text[:-1].split('\n'):
        if line.startswith((' ', '- ')) and keys:
            continue
        match = KEY.fullmatch(line)
        if match is None or match.group(1) in keys:
            return None
        keys[match.group(1)] = match.group(2) or ''
    return keys


def owned(text, network, proc, boot_id):
    keys = record(text)
    if keys is None or keys.get('name') != 'dnsmasq' or 'start_time' not in keys:
        return False
    pid = keys.get('pid', '')
    if not re.fullmatch(r'[1-9][0-9]*', pid) or not BOOT_ID.fullmatch(keys.get('boot_id', '')) or keys['boot_id'] != boot_id:
        return False
    try:
        with open(f'{proc}/{pid}/comm', encoding='utf-8') as comm, open(f'{proc}/{pid}/cmdline', 'rb') as cmdline:
            return comm.read().strip() == 'dnsmasq' and f'--interface={network}'.encode() in cmdline.read().split(b'\0')
    except OSError:
        return False


def heal(networks, proc, boot_id):
    folder = open_path(networks)
    if folder is None:
        return []
    removed = []
    try:
        for network in sorted(os.listdir(folder)):
            try:
                network_folder = os.open(network, FOLDER, dir_fd=folder)
            except OSError:
                continue
            try:
                try:
                    mode = os.stat('dnsmasq.pid', dir_fd=network_folder, follow_symlinks=False).st_mode
                except FileNotFoundError:
                    continue
                if stat.S_ISDIR(mode):
                    continue
                if stat.S_ISREG(mode):
                    descriptor = os.open('dnsmasq.pid', os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK, dir_fd=network_folder)
                    with os.fdopen(descriptor, 'rb') as file:
                        text = file.read(65536).decode('utf-8', 'replace')
                    if owned(text, network, proc, boot_id):
                        continue
                os.unlink('dnsmasq.pid', dir_fd=network_folder)
                removed.append(network)
            finally:
                os.close(network_folder)
    finally:
        os.close(folder)
    return removed


def main(arguments):
    networks = arguments[0] if len(arguments) > 0 else '/var/lib/incus/networks'
    proc = arguments[1] if len(arguments) > 1 else '/proc'
    boot_file = arguments[2] if len(arguments) > 2 else '/proc/sys/kernel/random/boot_id'
    try:
        with open(boot_file, encoding='utf-8') as file:
            boot_id = file.read().strip()
        for network in heal(networks, proc, boot_id):
            print(f'removed {network}')
    except OSError as error:
        print(f'heal failed: {error}', file=sys.stderr)
    return 0


if __name__ == '__main__':
    sys.exit(main(sys.argv[1:]))
