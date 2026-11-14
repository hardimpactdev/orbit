"""The presented machine name of an Instance container (docs/reference/development-containers.md#the-machine-name).

The Incus name `instance-<id>` stays the machine key. The machine presents `<instance name>.<project slug>`: stock T3 reads
`PRETTY_HOSTNAME` in `/etc/machine-info` as its environment label, once at its start, and the kernel takes `/etc/hostname` at each
boot. The Gateway gives both values (InstanceMachineName); this file only places them. Every writer runs it on the Node and moves the
files through Incus itself: the clone script, the container and start phases and the Node converge.

- `/etc/hostname` holds the hostname alone.
- `/etc/machine-info` is Orbit's whole: `PRETTY_HOSTNAME=<name>`, as the clone's boot file writes it.
- `/etc/hosts` gets its `127.0.1.1` line, the one Incus's template writes for the container name: the hostname, its first label, the
  running kernel hostname when the writer knows it and it differs, and the Incus name, so the kernel hostname resolves before and after
  the next boot. Every other line stays, Orbit's private names block among them, so the block's writer and this one never undo each other.

Commands, each with JSON on stdin and JSON on stdout:
- `render`: `{"files": {path: text or null}, "hostname", "pretty", "container", "kernel"}` for the three paths in the order above,
  `kernel` the running kernel hostname or null; prints
  `{"files": {path: text}}` with only the files whose text changes. A missing file counts as empty.
- `hosts`: `{"hosts", "hostname", "container"}`; prints `{"content"}`, for the clone script's private names pass.
"""

import json
import sys

HOSTS_LINE = '127.0.1.1'


def machine_info(pretty):
    return f'PRETTY_HOSTNAME={pretty}\n'


def hosts(current, hostname, container, kernel=None):
    names = list(dict.fromkeys(name for name in [hostname, hostname.split('.')[0], kernel, container] if name))
    line = f'{HOSTS_LINE}\t' + ' '.join(names)
    lines = current.splitlines()
    for index, existing in enumerate(lines):
        if existing.split()[:1] == [HOSTS_LINE]:
            lines[index] = line
            return '\n'.join(lines) + '\n'
    return '\n'.join([line, *lines]) + '\n'


def render(files, hostname, pretty, container, kernel=None):
    """The changed files of the name; `files` maps the three paths, in order, to their current text, or None when it is missing."""
    hostname_path, info_path, hosts_path = files
    wanted = {hostname_path: hostname + '\n', info_path: machine_info(pretty), hosts_path: hosts(files[hosts_path] or '', hostname, container, kernel)}
    return {path: text for path, text in wanted.items() if text != files[path]}


if __name__ == '__main__':
    given = json.load(sys.stdin)
    if sys.argv[1:] == ['render']:
        print(json.dumps({'files': render(given['files'], given['hostname'], given['pretty'], given['container'], given['kernel'])}))
    elif sys.argv[1:] == ['hosts']:
        print(json.dumps({'content': hosts(given['hosts'], given['hostname'], given['container'])}))
    else:
        raise SystemExit('usage: instance_machine_name.py render | hosts')
