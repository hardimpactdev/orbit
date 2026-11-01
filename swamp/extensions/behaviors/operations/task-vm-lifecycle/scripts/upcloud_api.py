"""One UpCloud HTTPS request per placed native step; no waits or retries."""
import json
import os
import sys
import urllib.error
import urllib.request

ORIGIN = 'https://api.upcloud.com/1.3'


def _api(method, path, body=None):
    class NoRedirect(urllib.request.HTTPRedirectHandler):
        def redirect_request(self, req, fp, code, msg, headers, newurl):
            return None
    req = urllib.request.Request(
        ORIGIN + '/' + path, method=method,
        data=None if body is None else json.dumps(body).encode(),
        headers={'Authorization': 'Bearer ' + os.environ['ORBIT_UPCLOUD_TOKEN'], 'Content-Type': 'application/json', 'Accept': 'application/json'},
    )
    with urllib.request.build_opener(NoRedirect).open(req, timeout=10) as response:
        raw = response.read(1048577)
        if len(raw) > 1048576:
            raise ValueError('oversize')
        return json.loads(raw) if raw.strip() else {}


def api(method, path, body=None):
    try:
        return _api(method, path, body)
    except urllib.error.HTTPError as error:
        if error.code == 404:
            return {}
        raise


def poll_read(path):
    """A GET observation must fail on every HTTP error, including missing VMs."""
    return _api('GET', path)


def public_server(server):
    return {key: server[key] for key in ('uuid', 'title', 'hostname', 'state', 'labels', 'storage_devices', 'ip_addresses') if key in server}


def request(intent, stage):
    if stage == 'discover':
        rows = api('GET', 'server?label=orbit-task-vm')['servers']['server']
        return {'servers': {'server': [public_server(row) for row in rows]}}
    uuid = intent['provider_id']
    if uuid is None:
        if stage == 'observe' or intent['action'] == 'delete':
            return {}
        raise ValueError('missing VM')
    method, path, body = 'GET', f'server/{uuid}', None
    if stage == 'action':
        action = intent['action']
        if action == 'delete':
            method, path = 'DELETE', path + '?storages=1&backups=delete'
        else:
            method, path = 'POST', path + f'/{action}'
            body = {'server': {}} if action == 'start' else {'stop_server': {'stop_type': 'soft', 'timeout': '120'}}
    value = api(method, path, body)
    return {'server': public_server(value['server'])} if stage == 'observe' and value.get('server') else {}


def main():
    try:
        print(json.dumps(request(json.loads(os.environ['ORBIT_PROVIDER_INTENT']), sys.argv[1])))
    except (OSError, ValueError, KeyError, IndexError, TypeError, urllib.error.URLError):
        print('task_vm.upcloud_failed', file=sys.stderr)
        return 1
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
