"""Disposable HTTPS GitHub contract; no requests can reach a real repository."""
from datetime import datetime, timedelta, timezone
import importlib.util
import json
import os
from pathlib import Path
import secrets
import signal
import ssl
import subprocess
import sys
import threading
import time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import urlsplit

SOURCE = Path(__file__).resolve().parents[3]
STATE = Path('/home/orbit/.orbit/swamp-proof/dependency-merge')
DATA = STATE / 'state.json'
spec = importlib.util.spec_from_file_location('evidence', Path(__file__).with_name('evidence.py'))
evidence = importlib.util.module_from_spec(spec)
spec.loader.exec_module(evidence)


def command(argv, **kwargs):
    return subprocess.run(argv, capture_output=True, text=True, timeout=90, check=True, **kwargs).stdout.strip()


def serve():
    lock = threading.Lock()

    class Handler(BaseHTTPRequestHandler):
        def log_message(self, *_args):pass
        def do_GET(self):self.handle_request()
        def do_POST(self):self.handle_request()
        def do_PUT(self):self.handle_request()
        def do_DELETE(self):self.handle_request()

        def handle_request(self):
            with lock:
                data = json.loads(DATA.read_text())
                path = urlsplit(self.path)
                raw = self.rfile.read(int(self.headers.get('Content-Length', 0)))
                data['calls'].append([self.command, self.path])
                DATA.write_text(json.dumps(data))
                assert self.headers.get('Host') == 'api.github.com'
                if path.path == '/repos/hardimpactdev/orbit/installation':
                    self.respond({'id':123})
                    return
                if path.path == '/app/installations/123/access_tokens':
                    body = json.loads(raw)
                    assert body['repositories'] == ['orbit']
                    permissions = body['permissions']
                    scope = 'write' if permissions.get('contents') == 'write' else 'read'
                    expected = {'contents':'write','pull_requests':'write'} if scope == 'write' else {'contents':'read','pull_requests':'read','checks':'read','actions':'read'}
                    assert permissions == expected
                    data['minted'].append(scope)
                    DATA.write_text(json.dumps(data))
                    expires = (datetime.now(timezone.utc)+timedelta(minutes=59)).strftime('%Y-%m-%dT%H:%M:%SZ')
                    self.respond({'token':data[scope], 'permissions':permissions, 'expires_at':expires}, 201)
                    return
                scheme, _, credential = self.headers.get('Authorization', '').partition(' ')
                assert scheme.lower() in ('bearer','token') and credential in [data['read'],data['write']]
                if path.path == '/installation/repositories':
                    self.respond({'total_count':1,'repositories':[{'full_name':'hardimpactdev/orbit'}]})
                elif path.path == '/installation/token':
                    data['revoked'].append('read' if credential == data['read'] else 'write')
                    DATA.write_text(json.dumps(data))
                    self.respond(None, 204)
                elif self.command == 'PUT':
                    assert credential == data['write']
                    assert json.loads(raw) == {'sha':evidence.HEAD,'merge_method':'squash'}
                    data['puts'] += 1
                    DATA.write_text(json.dumps(data))
                    self.respond({'merged':True,'sha':evidence.MERGE})
                else:
                    assert credential == data['read']
                    rows = evidence.evidence(group=data['group'],case=data['case'])
                    reply = rows[self.path]
                    if '/files?' in self.path or '/check-runs?' in self.path:reply=reply[0]
                    self.respond(reply)

        def respond(self,value,status=200):
            body = json.dumps(value).encode() if value is not None else b''
            self.send_response(status)
            self.send_header('Content-Type','application/json')
            self.send_header('Content-Length',str(len(body)))
            self.end_headers()
            self.wfile.write(body)

    server = ThreadingHTTPServer(('127.0.0.2',443),Handler)
    context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
    context.load_cert_chain(STATE/'tls/cert.pem',STATE/'tls/key.pem')
    server.socket=context.wrap_socket(server.socket,server_side=True)
    (STATE/'ready').touch()
    server.serve_forever()


def setup(case,node,operator,run):
    STATE.mkdir(mode=0o700,exist_ok=True)
    if (STATE/'pid').exists():
        try:os.kill(int((STATE/'pid').read_text()),signal.SIGTERM)
        except ProcessLookupError:pass
        time.sleep(.2)
    if not Path('/usr/bin/gh').exists():
        command(['apt-get','update'])
        command(['apt-get','install','-y','gh'])
    tls=STATE/'tls';tls.mkdir(exist_ok=True)
    ca=Path('/home/orbit/.orbit/ca')
    command(['openssl','req','-new','-newkey','rsa:2048','-nodes','-subj','/CN=api.github.com','-keyout',str(tls/'key.pem'),'-out',str(tls/'request.pem')])
    (tls/'extensions').write_text('subjectAltName=DNS:api.github.com\nkeyUsage=digitalSignature,keyEncipherment\nextendedKeyUsage=serverAuth\n')
    command(['openssl','x509','-req','-in',str(tls/'request.pem'),'-CA',str(ca/'root.pem'),'-CAkey',str(ca/'root.key'),'-CAcreateserial','-days','1','-extfile',str(tls/'extensions'),'-out',str(tls/'cert.pem')])
    hosts=Path('/etc/hosts');hosts.write_text('\n'.join(line for line in hosts.read_text().splitlines() if not line.startswith('127.0.0.2 '))+'\n127.0.0.2 api.github.com\n')
    bindings=json.loads(command(['runuser','-u','orbit','--','php',str(Path(__file__).with_name('prepare.php')),node,operator,run],cwd=SOURCE/'apps/gateway').splitlines()[-1])
    DATA.write_text(json.dumps({'case':case,'group':bindings['secrets']['group'],'read':secrets.token_urlsafe(32),'write':secrets.token_urlsafe(32),'calls':[],'minted':[],'revoked':[],'puts':0}))
    DATA.chmod(0o600)
    (STATE/'ready').unlink(missing_ok=True)
    with (STATE/'server.log').open('w') as log:
        process=subprocess.Popen(['python3','-I',str(Path(__file__).resolve()),'serve'],stdin=subprocess.DEVNULL,stdout=log,stderr=log,start_new_session=True)
    (STATE/'pid').write_text(str(process.pid))
    for _ in range(100):
        if (STATE/'ready').exists():break
        time.sleep(.05)
    assert (STATE/'ready').exists(), 'GitHub fixture did not bind'
    print(json.dumps(bindings))


def parity():
    data=json.loads(DATA.read_text())
    operation=json.loads(Path(os.environ['PROOF_OPERATION_FILE']).read_text())
    public=json.dumps(operation)+Path(os.environ['PROOF_OUTPUTS']).read_text()
    assert data['read'] not in public and data['write'] not in public
    assert sorted(data['minted']) == sorted(data['revoked']) == ['read','write']
    assert not list(Path('/tmp').glob('orbit-dependency-merge-*'))
    if data['case']=='major':
        assert operation['status']=='failed' and operation['error_code']=='dependency_merge.major_bump', operation
        assert data['puts']==0
    else:
        assert operation['status']=='succeeded', operation
        assert operation['result']=={'merged':True,'head_sha':evidence.HEAD,'merge_sha':evidence.MERGE}
        assert data['puts']==(0 if data['case']=='merged' else 1)
    print('Native exact-head merge policy, two admission scopes, both tokens revoked, no secret outputs or scratch; HTTPS GitHub fixture only.')


if __name__=='__main__':
    if sys.argv[1]=='serve':serve()
    elif sys.argv[1]=='setup':setup(*sys.argv[2:])
    else:parity()
