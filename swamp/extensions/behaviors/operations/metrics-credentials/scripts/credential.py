"""One Grafana HTTP action. Private credentials arrive only on stdin."""

import base64
import http.client
import ipaddress
import json
import sys


def main():
    action, address = sys.argv[1:]
    address = str(ipaddress.IPv4Address(address))
    credentials = json.load(sys.stdin)
    password = credentials['active_password' if action == 'apply' else 'pending_password']
    headers = {'Authorization': 'Basic ' + base64.b64encode(('admin:' + password).encode()).decode()}
    body = None
    if action == 'apply':
        body = json.dumps({'oldPassword': password, 'newPassword': credentials['pending_password'], 'confirmNewPassword': credentials['pending_password']})
        headers['Content-Type'] = 'application/json'
    elif action not in ('probe', 'confirm'):
        raise ValueError('metrics.credential_action_invalid')
    try:
        connection = http.client.HTTPConnection(address, 3000)
        connection.request('PUT' if action == 'apply' else 'GET', '/api/user/password' if action == 'apply' else '/api/user', body=body, headers=headers)
        response = connection.getresponse()
        response.read()
        status = response.status
        connection.close()
    except (OSError, http.client.HTTPException):
        raise SystemExit('metrics.credentials_unavailable') from None
    if action == 'probe' and status == 401:
        print('{"verified":false}')
        return
    if status != 200:
        raise SystemExit('metrics.credentials_refused' if status in (401, 403) else 'metrics.credentials_unavailable')
    print('{"applied":true}' if action == 'apply' else '{"verified":true}')


if __name__ == '__main__':
    main()
