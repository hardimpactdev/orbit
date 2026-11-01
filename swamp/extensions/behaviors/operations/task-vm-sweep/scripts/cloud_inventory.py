"""Public labelled UpCloud inventory using the canonical provider API helper."""
import json
import sys
import urllib.error

from upcloud import api


def inventory():
    servers = []
    for label in ('orbit-task-vm', 'orbit-task-image'):
        for row in api('GET', 'server?label=' + label)['servers']['server']:
            if 'labels' not in row:
                row = api('GET', 'server/' + row['uuid']).get('server')
                if row is None:
                    continue
            servers.append({key: row[key] for key in ('uuid', 'title', 'hostname', 'labels') if key in row})
    templates = api('GET', 'storage/template')['storages']['storage']
    return {'servers': servers, 'templates': [{key: row[key] for key in ('uuid', 'title', 'access')} for row in templates]}


if __name__ == '__main__':
    try:
        print(json.dumps(inventory()))
    except (OSError, ValueError, KeyError, TypeError, urllib.error.URLError):
        print('{"error_code":"task_vm.host_command_failed"}')
        print('task_vm.host_command_failed', file=sys.stderr)
        raise SystemExit(1) from None
