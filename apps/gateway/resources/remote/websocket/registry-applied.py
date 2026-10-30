# Program: websocket.registry-applied
# Uses: lib/program.py
# Outcomes: succeeded pending superseded failed
# Timeout: 330
# Input: {"registry_operation_id": exact recorded registry change UUID}.
# Result: A fresh Gateway read proves every current required boot applied the revision.
import json
import re
import ssl
import time
import urllib.error
import urllib.request


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


def main(payload):
    identity = payload.get('registry_operation_id')
    if not isinstance(identity, str) or not re.fullmatch(r'[a-f0-9-]{36}', identity):
        return 'failed', {'error_code': 'websocket.registry_invalid'}
    try:
        context = ssl.create_default_context(cafile='/usr/local/share/ca-certificates/orbit-managed-root-ca.crt')
    except (OSError, ssl.SSLError):
        return 'pending', {'backend': 'pending'}
    opener = urllib.request.build_opener(urllib.request.HTTPSHandler(context=context), NoRedirect())
    deadline = time.monotonic() + 300
    while time.monotonic() < deadline:
        try:
            request = urllib.request.Request('https://gateway.orbit/api/v1/websocket-operations/' + identity,
                headers={'Accept': 'application/json'})
            with opener.open(request, timeout=10) as response:
                state = json.load(response)['operation']['state']
            if state == 'applied':
                return 'succeeded', {'backend': 'applied'}
            if state == 'superseded':
                return 'superseded', {'error_code': 'websocket.registry_superseded'}
        except (OSError, ValueError, KeyError, urllib.error.URLError):
            # A transport failure proves nothing about the running processes.
            pass
        time.sleep(2)
    return 'pending', {'backend': 'pending'}


run_program(main)
