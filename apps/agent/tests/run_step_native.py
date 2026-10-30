"""Run as root only in a fresh disposable Incus container, never an enrolled Node.

Install the candidate at /usr/local/bin/orbit-agent; preserve ../units/ beside
this fixture. It uses synthetic TLS/material through the real fixed agent client.
"""
import hashlib
import http.server
import json
import os
from pathlib import Path
import pwd
import select
import shlex
import signal
import ssl
import subprocess
import threading
import time
import unittest

ROOT = Path(__file__).resolve().parents[1]
AGENT = Path('/usr/local/bin/orbit-agent')
WORKER = 'orbit-swamp-worker'
APP = 'orbit-app-99009'
HOME = Path('/home') / APP
RELEASE = HOME / 'releases/proof-1'
SECRET = 'a' * 64  # Synthetic fixture credential, never fleet material.
MATERIAL = {}
REQUESTS = []


def run(*args, **kwargs):
    return subprocess.run(list(map(str, args)), check=True, capture_output=True,
                          text=True, timeout=30, **kwargs).stdout.strip()


class Gateway(http.server.BaseHTTPRequestHandler):
    def do_GET(self):
        assert self.headers.get('Authorization') == 'Bearer ' + SECRET
        expected = '/api/v1/agent/operations/99044/step?step=setup.probe&digest=' + MATERIAL['digest']
        assert self.path == expected, self.path
        REQUESTS.append(self.path)
        body = json.dumps({'data': MATERIAL}).encode()
        self.send_response(200)
        self.send_header('Cache-Control', 'no-store, private')
        self.send_header('Content-Length', str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def log_message(self, *_args):
        pass


CLIENT = '''import json,socket,sys
s=socket.socket(socket.AF_UNIX);s.settimeout(20)
s.connect('/run/orbit-agent/local.sock');s.sendall(sys.stdin.buffer.read());s.shutdown(socket.SHUT_WR)
b=b''
while not b.endswith(b'\\n'):
 c=s.recv(16385-len(b))
 if not c:break
 b+=c
 assert len(b)<=16384
print(b.decode(),end='')
'''


class RunStepBoundary(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        assert os.geteuid() == 0, 'scratch_root_required'
        for user in [WORKER, APP]:
            try:
                pwd.getpwnam(user)
            except KeyError:
                continue
            raise AssertionError('scratch_account_already_exists')
        assert not Path('/etc/orbit/agent').exists(), 'scratch_agent_already_exists'
        for unit in ['orbit-agent-local.socket', 'orbit-agent-local@.service']:
            assert not (Path('/etc/systemd/system') / unit).exists(), 'scratch_unit_already_exists'
        for user in [WORKER, APP]:
            run('useradd', '--system', '--user-group', '--create-home', user)
        run('usermod', '-a', '-G', WORKER, APP)  # Supplementary groups must still be cleared.
        RELEASE.mkdir(parents=True)
        run('chown', '-R', APP + ':' + APP, HOME)
        for path in [HOME, HOME / 'releases', RELEASE]:
            path.chmod(0o700)
        state = Path('/var/lib/orbit/app-instance-sources/99012')
        state.mkdir(parents=True, mode=0o700)
        marker = state / 'release-layout'
        marker.write_bytes(f'https://example.test/proof.git\0{APP}\0{HOME}\0initial\0'.encode())
        marker.chmod(0o600)
        config = Path('/etc/orbit/agent')
        config.mkdir(parents=True, mode=0o700)
        run('openssl', 'req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-days', '1',
            '-subj', '/CN=Scratch CA', '-keyout', config / 'ca-key.pem', '-out', config / 'ca.pem')
        run('openssl', 'req', '-new', '-newkey', 'rsa:2048', '-nodes', '-subj', '/CN=gateway.orbit',
            '-keyout', config / 'key.pem', '-out', config / 'gateway.csr')
        (config / 'extensions').write_text('subjectAltName=DNS:gateway.orbit\nbasicConstraints=CA:FALSE\nkeyUsage=digitalSignature,keyEncipherment\nextendedKeyUsage=serverAuth\n')
        run('openssl', 'x509', '-req', '-in', config / 'gateway.csr', '-CA', config / 'ca.pem',
            '-CAkey', config / 'ca-key.pem', '-CAcreateserial', '-days', '1',
            '-extfile', config / 'extensions', '-out', config / 'gateway.pem')
        (config / 'secret').write_text(SECRET)
        (config / 'secret').chmod(0o600)
        (config / 'config.toml').write_text('gateway_url="https://gateway.orbit"\ngateway_address="127.0.0.1"\n')
        cls.server = http.server.HTTPServer(('127.0.0.1', 443), Gateway)
        tls = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
        tls.load_cert_chain(config / 'gateway.pem', config / 'key.pem')
        cls.server.socket = tls.wrap_socket(cls.server.socket, server_side=True)
        threading.Thread(target=cls.server.serve_forever, daemon=True).start()
        for unit in ['orbit-agent-local.socket', 'orbit-agent-local@.service']:
            run('install', '-m', '0644', ROOT / 'units' / unit, '/etc/systemd/system/' + unit)
        run('systemctl', 'daemon-reload')
        run('systemctl', 'start', 'orbit-agent-local.socket')
        cls.sha = hashlib.sha256(AGENT.read_bytes()).hexdigest()
        cls.evidence = {'agent_sha256': cls.sha, 'identity': None, 'escape': None}

    @classmethod
    def tearDownClass(cls):
        run('systemctl', 'stop', 'orbit-agent-local.socket')
        cls.server.shutdown()
        Path('/root/run-step-native-proof.json').write_text(json.dumps(cls.evidence, indent=2) + '\n')

    def call(self, script, seconds=2):
        MATERIAL.clear()
        MATERIAL.update(operation=99044, step='setup.probe', digest=hashlib.sha256(script.encode()).hexdigest(),
                        instance=99012, project=99009, repository='https://example.test/proof.git',
                        relative_root='public', release='proof-1', timeout_seconds=seconds, script=script,
                        environment={'APP_VALUE': 'synthetic-app-value'})
        frame = json.dumps(dict(command='instance.run-step', agent_sha256=self.sha,
                                input=dict(operation=99044, step='setup.probe', digest=MATERIAL['digest']))) + '\n'
        return json.loads(run('systemd-run', '--quiet', '--wait', '--pipe', '--collect',
                              '--uid=' + WORKER, '--property=NoNewPrivileges=yes',
                              '/usr/bin/python3', '-I', '-c', CLIENT, input=frame))

    def test_identity_environment_directory_and_descriptors(self):
        probe = '''import json,os
from pathlib import Path
status={}
for line in Path('/proc/self/status').read_text().splitlines():
 k,_,v=line.partition(':');status[k]=v.strip()
fds={}
for fd in Path('/proc/self/fd').iterdir():
 try:fds[fd.name]=os.readlink(fd)
 except FileNotFoundError:pass
Path('identity.json').write_text(json.dumps(dict(status=status,fds=fds,cwd=os.getcwd(),env=dict(item.split('=',1) for item in Path('/proc/self/environ').read_bytes().decode().strip('\\0').split('\\0')))))
print('probe-ok')
'''
        result = self.call("exec /usr/bin/python3 -I -c " + shlex.quote(probe))
        self.assertTrue(result['ok'], result)
        self.assertEqual(result['data']['exit_code'], 0, result)
        value = json.loads((RELEASE / 'identity.json').read_text())
        account = pwd.getpwnam(APP)
        status = value['status']
        self.assertEqual(list(map(int, status['Uid'].split())), [account.pw_uid] * 4)
        self.assertEqual(list(map(int, status['Gid'].split())), [account.pw_gid] * 4)
        self.assertEqual(status['Groups'], '')
        self.assertEqual(int(status['CapEff'], 16), 0)
        self.assertEqual(status['NoNewPrivs'], '1')
        self.assertEqual(value['cwd'], str(RELEASE))
        self.assertEqual(set(value['fds']), {'0', '1', '2', '3'})
        self.assertIn('memfd:orbit-admitted-step', value['fds']['3'])
        self.assertFalse(any(v.startswith('socket:') for v in value['fds'].values()))
        self.assertEqual(value['env'], {'APP_VALUE': 'synthetic-app-value', 'HOME': str(HOME),
                         'USER': APP, 'LOGNAME': APP, 'PATH': '/usr/local/bin:/usr/bin:/bin',
                         'PWD': str(RELEASE), 'SHLVL': '0'})
        self.evidence['identity'] = dict(uid=account.pw_uid, gid=account.pw_gid, groups=[], cap_eff=0,
                                        no_new_privs=1, cwd=value['cwd'], fds=value['fds'])

    def test_setsid_sigterm_ignoring_descendant_dies_after_systemd_grace(self):
        escaped = '''import os,signal,time
from pathlib import Path
os.setsid()
signal.signal(signal.SIGTERM,signal.SIG_IGN)
Path('escaped.pid').write_text(str(os.getpid()))
while True:time.sleep(1)
'''
        script = '/usr/bin/python3 -I -c ' + shlex.quote(escaped) + ' &\nwhile [ ! -f escaped.pid ]; do sleep 0.01; done\nsleep 30\n'
        start = time.monotonic()
        result = self.call(script, seconds=2)
        reply = time.monotonic()
        self.assertTrue(result['ok'], result)
        self.assertTrue(result['data']['timed_out'], result)
        pid = int((RELEASE / 'escaped.pid').read_text())
        self.assertEqual(os.getsid(pid), pid)
        status = Path(f'/proc/{pid}/status').read_text()
        ignored = int(next(x.split()[1] for x in status.splitlines() if x.startswith('SigIgn:')), 16)
        self.assertTrue(ignored & (1 << (signal.SIGTERM - 1)))
        cgroup = Path(f'/proc/{pid}/cgroup').read_text().strip()
        self.assertIn('orbit-agent-local@', cgroup)
        pidfd = os.pidfd_open(pid)
        try:
            poll = select.poll();poll.register(pidfd, select.POLLIN)
            self.assertFalse(poll.poll(3000), 'escaped descendant died before the 5s grace')
            self.assertTrue(poll.poll(5000), 'escaped descendant survived systemd cleanup')
        finally:
            os.close(pidfd)
        elapsed = time.monotonic() - reply
        self.assertGreaterEqual(elapsed, 4.5)
        self.assertLess(elapsed, 7.5)
        self.evidence['escape'] = dict(setsid=True, ignores_sigterm=True, cgroup=cgroup,
                                      reply_seconds=reply-start, cleanup_after_reply_seconds=elapsed,
                                      alive_after_3s=True, pidfd_exit=True)


if __name__ == '__main__':
    unittest.main()
