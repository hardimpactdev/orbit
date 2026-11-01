"""Delete one exact selected UpCloud orphan, with no custom transition polling."""
import json
import os
import sys
import urllib.error

from upcloud_api import api


def owned(current, data):
    if current['uuid'] != data['uuid'] or current['title'] != data['name']:
        raise ValueError()
    if data['kind'] == 'server':
        matches = [row['value'] for row in current['labels']['label'] if row['key'] == data['label']]
        if current['hostname'] != data['name'] or matches != [data['value']]:
            raise ValueError()
    elif current['access'] != 'private':
        raise ValueError()


def remove(data):
    name, uuid = data['name'], data['uuid']
    if data['kind'] not in ('server', 'template'):
        raise ValueError()
    path = ('server/' if data['kind'] == 'server' else 'storage/') + uuid
    field = 'server' if data['kind'] == 'server' else 'storage'
    current = api('GET', path).get(field)
    if current is None:
        return {'name': name, 'deleted': True}
    owned(current, data)
    if field == 'server':
        if current['state'] == 'started':
            api('POST', path + '/stop', {'stop_server': {'stop_type': 'hard'}})
            current = api('GET', path)['server']
            owned(current, data)
        if current['state'] != 'stopped':
            raise ValueError()
        api('DELETE', path + '?storages=1&backups=delete')
    else:
        if current['access'] != 'private':
            raise ValueError()
        api('DELETE', path)
    if api('GET', path).get(field) is not None:
        raise ValueError()
    return {'name': name, 'deleted': True}


if __name__ == '__main__':
    try:
        print(json.dumps(remove(json.loads(os.environ['ORBIT_SWEEP_ITEM']))))
    except (OSError, ValueError, KeyError, TypeError, urllib.error.URLError):
        print('{"error_code":"task_vm.host_command_failed"}')
        print('task_vm.host_command_failed', file=sys.stderr)
        raise SystemExit(1) from None
