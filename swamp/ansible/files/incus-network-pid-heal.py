#!/usr/bin/python3 -I
"""Drop each managed network's dnsmasq pid file on a nested Instance host that no live dnsmasq of that network owns.

See docs/reference/development-containers.md#no-stale-network-pid-file.

    incus-network-pid-heal [<networks folder> [<proc folder> [<boot id file>]]]
    python3 -I -c <this file> ensure < <this file>

Incus reads `<networks>/<network>/dnsmasq.pid` at the network's start to stop the old dnsmasq. A file that is not YAML, such as an
empty one from a copy of a running Node, fails the network with `Could not read pid file`. A file stays only when all of these hold:
it is a regular file, no link, that reads in full; it parses as YAML (PyYAML, which the Node's Ansible needs) into the mapping Incus
writes, with `name: dnsmasq`, an integer `pid`, an integer `start_time` and a `boot_id`; the `boot_id` is this boot's; the process
lives, its `comm` is `dnsmasq`, its start time (field 22 of /proc/<pid>/stat, in clock ticks, as Incus records it) equals
`start_time`, and its command line holds `--interface=<network>` as one whole argument. Every other file goes. No link is followed: a
network folder that is a link is left alone, and a pid file that is a link is unlinked itself. Without PyYAML only an empty file goes.

The plain form runs as `ExecStartPre` of Incus. `ensure` is what the fleet start runs in each nested Instance host, running or just
started (tasks/instance-fleet-start.yml): it places this file (read from standard input) and the drop-in that runs it, heals, and
restarts Incus when it removed a file, so a guest that booted with an empty file and no drop-in brings its networks up. Its one line
on standard output is a JSON object: {"placed": true|false, "removed": [...], "restarted": true|false}. Neither form fails.
"""
import errno
import json
import os
import re
import stat
import subprocess
import sys

try:
    import yaml
except ImportError:
    yaml = None

BOOT_ID = re.compile(r'[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}')
FOLDER = os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW
PLACE = '/usr/local/sbin/incus-network-pid-heal'
DROP_IN = '/etc/systemd/system/incus.service.d/60-network-pid-heal.conf'
DROP_IN_TEXT = f'[Service]\nExecStartPre={PLACE}\n'


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


def read(network_folder):
    """The pid file's text, or None when it cannot be read in full: a file Incus could not read either is no record."""
    try:
        descriptor = os.open('dnsmasq.pid', os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK, dir_fd=network_folder)
        with os.fdopen(descriptor, 'rb') as file:
            return file.read(65536).decode('utf-8')
    except (OSError, UnicodeDecodeError):
        return None


def owned(text, network, proc, boot_id):
    if not text:
        return False
    if yaml is None:
        return True
    try:
        keys = yaml.safe_load(text)
    except yaml.YAMLError:
        return False
    if not isinstance(keys, dict) or keys.get('name') != 'dnsmasq':
        return False
    pid, start, boot = keys.get('pid'), keys.get('start_time'), keys.get('boot_id')
    if type(pid) is not int or pid <= 0 or type(start) is not int:
        return False
    if not isinstance(boot, str) or not BOOT_ID.fullmatch(boot) or boot != boot_id:
        return False
    try:
        with open(f'{proc}/{pid}/comm', encoding='utf-8') as comm, open(f'{proc}/{pid}/stat', encoding='utf-8') as stat_file, \
                open(f'{proc}/{pid}/cmdline', 'rb') as cmdline:
            # The fields after `(comm)` start at field 3, so field 22 (starttime) is the 20th of them.
            started = stat_file.read().rsplit(')', 1)[1].split()[19]
            return (comm.read().strip() == 'dnsmasq' and started == str(start)
                    and f'--interface={network}'.encode() in cmdline.read().split(b'\0'))
    except (OSError, IndexError):
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
                if stat.S_ISREG(mode) and owned(read(network_folder), network, proc, boot_id):
                    continue
                os.unlink('dnsmasq.pid', dir_fd=network_folder)
                removed.append(network)
            except OSError as error:
                print(f'heal of {network} failed: {error}', file=sys.stderr)
            finally:
                os.close(network_folder)
    finally:
        os.close(folder)
    return removed


def place(path, text, mode):
    """Write `text` to `path` with `mode` when it differs, through a file beside it; whether it wrote."""
    try:
        with open(path, encoding='utf-8') as file:
            if file.read() == text and stat.S_IMODE(os.stat(path).st_mode) == mode:
                return False
    except OSError:
        pass
    os.makedirs(os.path.dirname(path), mode=0o755, exist_ok=True)
    with open(path + '.tmp', 'w', encoding='utf-8') as file:
        file.write(text)
    os.chmod(path + '.tmp', mode)
    os.replace(path + '.tmp', path)
    return True


def current_boot(boot_file):
    with open(boot_file, encoding='utf-8') as file:
        return file.read().strip()


def ensure(source):
    placed = place(PLACE, source, 0o755)
    placed = place(DROP_IN, DROP_IN_TEXT, 0o644) or placed
    if placed:
        subprocess.run(['systemctl', 'daemon-reload'], check=False)
    removed = heal('/var/lib/incus/networks', '/proc', current_boot('/proc/sys/kernel/random/boot_id'))
    restarted = bool(removed) and subprocess.run(['systemctl', 'restart', 'incus.service'], check=False).returncode == 0
    return {'placed': placed, 'removed': removed, 'restarted': restarted}


def main(arguments):
    if arguments[:1] == ['ensure']:
        print(json.dumps(ensure(sys.stdin.read())))
        return 0
    networks = arguments[0] if len(arguments) > 0 else '/var/lib/incus/networks'
    proc = arguments[1] if len(arguments) > 1 else '/proc'
    boot_file = arguments[2] if len(arguments) > 2 else '/proc/sys/kernel/random/boot_id'
    try:
        for network in heal(networks, proc, current_boot(boot_file)):
            print(f'removed {network}')
    except OSError as error:
        print(f'heal failed: {error}', file=sys.stderr)
    return 0


if __name__ == '__main__':
    sys.exit(main(sys.argv[1:]))
