"""F10 parity against the actual disposable Reverb reached by official SSH."""
import os
import shlex
import subprocess
import sys

PROBE = r'''
import hashlib
import hmac
import http.client
import json
import socket
import time
import urllib.parse

c = http.client.HTTPConnection('127.0.0.1', 18791, timeout=3)
try:
    c.request('GET', '/state')
    state = json.loads(c.getresponse().read())
finally:
    c.close()
assert state['revision'] == 2 and state['state'] == 'serving'

def handshake(key):
    with socket.create_connection(('127.0.0.1', 18790), timeout=3) as c:
        c.sendall(('GET /app/' + key + '?protocol=7&client=js&version=8.4.0 HTTP/1.1\r\nHost: localhost\r\nConnection: Upgrade\r\nUpgrade: websocket\r\nSec-WebSocket-Version: 13\r\nSec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==\r\n\r\n').encode())
        return b'101 Switching Protocols' in c.recv(4096)

assert handshake('created-key')
assert handshake('unrelated-key')
assert not handshake('retired-key')

def publish(secret):
    body = json.dumps({'name': 'proof', 'channel': 'proof', 'data': '{}'}, separators=(',', ':'))
    query = urllib.parse.urlencode({'auth_key': 'rotating-key', 'auth_timestamp': str(int(time.time())), 'auth_version': '1.0', 'body_md5': hashlib.md5(body.encode()).hexdigest()})
    signature = hmac.new(secret.encode(), ('POST\n/apps/rotating/events\n' + query).encode(), hashlib.sha256).hexdigest()
    c = http.client.HTTPConnection('127.0.0.1', 18790, timeout=3)
    try:
        c.request('POST', '/apps/rotating/events?' + query + '&auth_signature=' + signature, body, {'Content-Type': 'application/json'})
        response = c.getresponse()
        response.read()
        return response.status
    finally:
        c.close()

assert publish('rotating-secret-1') == 401
assert publish('rotating-secret-2') == 200
print('current-revision created-app removed-app rotated-secret unrelated-app: PASS')
'''


def main():
    prefix = shlex.split(os.environ['PROOF_TARGET_SSH'])
    subprocess.run([*prefix, 'python3 -'], input=PROBE, text=True, check=True, timeout=15)
    # The provider's ported legacy cases also run on this isolated target.
    test_command = ('cd /var/lib/orbit-swamp-proof/websocket/reverb && '
                    'vendor/bin/pest --compact tests/Feature/RollingWireTest.php tests/Feature/RegistryApplyTest.php')
    command = 'sudo -n -- sh -c ' + shlex.quote(test_command)
    subprocess.run([*prefix, command], check=True, timeout=120)
    print('Legacy parity passed for ' + sys.argv[1])


if __name__ == '__main__':
    main()
