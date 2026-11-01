"""One bounded UpCloud sweep phase using the canonical provider API library."""
import datetime
import json
import os
import re
import sys
import time
import urllib.error
import uuid

from upcloud import api

ERRORS = (OSError, ValueError, KeyError, TypeError, urllib.error.URLError)


def active(intent):
    if time.time() >= intent['deadline_at']:
        raise ValueError('expired sweep')


def rows(raw):
    if not isinstance(raw, list) or len(raw) > 4096 or any(not isinstance(row, dict) for row in raw):
        raise ValueError('unreadable inventory')
    return raw


def inventory(call=api):
    servers = {}
    for label in ('orbit-task-vm', 'orbit-task-image'):
        for row in rows(call('GET', 'server?label=' + label)['servers']['server']):
            if 'labels' not in row:
                row = call('GET', 'server/' + row['uuid']).get('server')
                if row is None:
                    continue
            uuid.UUID(row['uuid'])
            rows(row['labels']['label'])
            servers[row['uuid']] = row
    templates = rows(call('GET', 'storage/template')['storages']['storage'])
    return list(servers.values()), templates


def plan(intent, servers, templates):
    def stale(stamp):
        if not re.fullmatch(r'[0-9]{14}', stamp) or stamp in intent['running_stamps']:
            return False
        try:
            date = datetime.datetime.strptime(stamp, '%Y%m%d%H%M%S').replace(tzinfo=datetime.UTC)
            return date.strftime('%Y%m%d%H%M%S') == stamp and date.timestamp() < intent['image_cutoff']
        except ValueError:
            return False
    result = []
    for row in rows(servers):
        uuid.UUID(row['uuid'])
        for label in ('orbit-task-vm', 'orbit-task-image'):
            values = [v['value'] for v in rows(row['labels']['label']) if v['key'] == label]
            if len(values) > 1:
                raise ValueError('ambiguous owner')
            if not values:
                continue
            value = values[0]
            if label == 'orbit-task-vm':
                if not re.fullmatch(r'[1-9][0-9]*', value) or row['title'] != 'tvm-' + value:
                    continue
                if int(value) > intent['scope']['max_task_vm_id'] or row['title'] in intent['scope']['live_names']:
                    continue
            elif row['title'] not in ('tvm-image-' + value, 'tvm-image-' + value + '-smoke') or not stale(value):
                continue
            result.append({'uuid': row['uuid'], 'name': row['title'], 'kind': 'server', 'label': label, 'value': value})
    for row in rows(templates):
        match = re.fullmatch(r'orbit-task-base-([0-9]{14})-unverified', row['title'])
        if row['access'] == 'private' and match and stale(match[1]):
            uuid.UUID(row['uuid'])
            result.append({'uuid': row['uuid'], 'name': row['title'], 'kind': 'template'})
    return result


def owned(current, item):
    if current['uuid'] != item['uuid'] or current['title'] != item['name']:
        raise ValueError('foreign resource')
    if item['kind'] == 'server':
        values = [v['value'] for v in rows(current['labels']['label']) if v['key'] == item['label']]
        if current['hostname'] != item['name'] or values != [item['value']]:
            raise ValueError('foreign server')
    elif current['access'] != 'private':
        raise ValueError('foreign template')


def remove(intent, item, call=api):
    path = ('server/' if item['kind'] == 'server' else 'storage/') + item['uuid']
    field = 'server' if item['kind'] == 'server' else 'storage'
    active(intent)
    current = call('GET', path).get(field)
    if current is None:
        return
    owned(current, item)
    active(intent)
    if field == 'server':
        if current['state'] == 'started':
            call('POST', path + '/stop', {'stop_server': {'stop_type': 'hard'}})
            current = call('GET', path)['server']
            owned(current, item)
        active(intent)
        if current['state'] != 'stopped':
            raise ValueError('stop incomplete')
        call('DELETE', path + '?storages=1&backups=delete')
    else:
        call('DELETE', path)
    if call('GET', path).get(field) is not None:
        raise ValueError('delete incomplete')


def sweep(intent, call=api):
    active(intent)
    servers, templates = inventory(call)
    candidates = plan(intent, servers, templates)
    deleted = []
    for item in candidates:
        remove(intent, item, call)
        deleted.append(item['name'])
    servers, _ = inventory(call)
    observed = []
    for row in servers:
        values = [v['value'] for v in rows(row['labels']['label']) if v['key'] == 'orbit-task-vm']
        if len(values) == 1 and re.fullmatch(r'[1-9][0-9]*', values[0]) and row['title'] == 'tvm-' + values[0]:
            observed.append(row['title'])
    return {'ready': True, 'observed_names': list(dict.fromkeys(observed)), 'deleted_names': deleted}


if __name__ == '__main__':
    try:
        result = sweep(json.loads(os.environ['ORBIT_SWEEP_INTENT']))
    except ERRORS:
        result = {'ready': False, 'error_code': 'task_vm.host_command_failed'}
        print('task_vm.host_command_failed', file=sys.stderr)
    print(json.dumps(result))
    raise SystemExit(0 if result['ready'] else 1)
