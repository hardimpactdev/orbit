"""Synthetic CLIProxyAPI and continuous held-key client for the native cutover rehearsal."""
import json
import os
import sys
import threading
import time
import urllib.request
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer


class Handler(BaseHTTPRequestHandler):
    def log_message(self, *_args):
        pass

    def do_GET(self):  # noqa: N802
        if self.path.startswith('/v1/'):
            if self.headers.get('Authorization') != 'Bearer held-task-key':
                self.send_error(401)
                return
            payload = {'data': [{'id': 'test-model'}]}
        elif self.headers.get('Authorization') != 'Bearer synthetic-management':
            self.send_error(401)
            return
        elif self.path.endswith('/auth-files'):
            payload = {'files': [{'name': 'codex.json', 'auth_index': 'codex-1', 'provider': 'codex', 'email': 'test@example.test'}]}
        else:
            payload = {'models': [{'id': 'test-model', 'owned_by': 'openai'}]}
        self.reply(payload)

    def do_POST(self):  # noqa: N802
        self.rfile.read(int(self.headers.get('Content-Length', 0)))
        if self.path == '/v1/chat/completions':
            if self.headers.get('Authorization') != 'Bearer held-task-key':
                self.send_error(401)
                return
            self.reply({'choices': [{'message': {'content': 'ok'}}]})
            return
        if self.headers.get('Authorization') != 'Bearer synthetic-management':
            self.send_error(401)
            return
        self.reply({'status_code': 200, 'body': json.dumps({'rate_limit': {'primary_window': {'used_percent': 25, 'limit_window_seconds': 18000}}})})

    def reply(self, value):
        body = json.dumps(value).encode()
        self.send_response(200)
        self.send_header('Content-Length', str(len(body)))
        self.end_headers()
        self.wfile.write(body)


def probe(path):
    result = {'ok': 0, 'failed': 0, 'errors': []}
    while True:
        try:
            request = urllib.request.Request('http://127.0.0.1:8317/v1/chat/completions', data=b'{"model":"test-model","messages":[{"role":"user","content":"ping"}]}', headers={'Authorization': 'Bearer held-task-key', 'Content-Type': 'application/json'})
            with urllib.request.urlopen(request, timeout=1) as response:
                assert response.status == 200
                assert json.load(response)['choices'][0]['message']['content'] == 'ok'
            result['ok'] += 1
        except Exception as error:
            result['failed'] += 1
            result['errors'].append({'time': time.time(), 'error': repr(error)})
        with open(path + '.next', 'w', encoding='utf-8') as output:
            json.dump(result, output)
        os.replace(path + '.next', path)
        time.sleep(0.02)


server = ThreadingHTTPServer(('127.0.0.1', 8317), Handler)
threading.Thread(target=probe, args=(sys.argv[1],), daemon=True).start()
server.serve_forever()
