"""Real systemd/SO_PEERCRED proof. Run only as root inside a fresh scratch Node.

Install the candidate agent at /usr/local/bin/orbit-agent and keep units/ beside
this test's parent. This test refuses existing Orbit accounts or socket units.
"""
import hashlib
import json
import os
from pathlib import Path
import pwd
import socket
import stat
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
SOCKET = '/run/orbit-agent/local.sock'
WORKER = 'orbit-swamp-worker'
PACKAGE = 'orbit-swamp-package'
OWNER = 'orbit-local-proof'
OTHER = 'orbit-local-other'
AGENT = '/usr/local/bin/orbit-agent'


def run(*argv, **kwargs):
    return subprocess.run(list(map(str, argv)), check=True, capture_output=True,
                          text=True, timeout=45, **kwargs).stdout.strip()


CLIENT = '''import json,socket,sys
s=socket.socket(socket.AF_UNIX);s.settimeout(10)
try:
 s.connect('/run/orbit-agent/local.sock');s.sendall(sys.stdin.buffer.read());s.shutdown(socket.SHUT_WR)
 value=b''
 while not value.endswith(b'\\n'):
  chunk=s.recv(16385-len(value))
  if not chunk:break
  value+=chunk
  assert len(value)<=16384
 print(value.decode(),end='')
except (PermissionError,ConnectionResetError):print('DENIED')
finally:s.close()
'''


class LocalBoundary(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        assert os.geteuid() == 0, 'scratch_root_required'
        for user in [WORKER, PACKAGE, OWNER, OTHER]:
            try:
                pwd.getpwnam(user)
            except KeyError:
                continue
            raise AssertionError('scratch_account_already_exists')
        assert not Path(SOCKET).exists(), 'scratch_socket_already_exists'
        cls.units = ['orbit-agent-local.socket', 'orbit-agent-local@.service']
        for name in cls.units:
            assert not (Path('/etc/systemd/system') / name).exists(), 'scratch_unit_already_exists'
        for user in [WORKER, PACKAGE, OWNER, OTHER]:
            run('useradd', '--system', '--user-group', '--create-home',
                *(['--home-dir', '/var/lib/orbit-node-worker/home'] if user == WORKER else []), user)
        # A user with the socket group must still fail SO_PEERCRED.
        run('usermod', '-a', '-G', WORKER, OTHER)
        for name in cls.units:
            run('install', '-o', 'root', '-g', 'root', '-m', '0644',
                ROOT / 'units' / name, '/etc/systemd/system/' + name)
        run('systemctl', 'daemon-reload')
        run('systemctl', 'start', 'orbit-agent-local.socket')
        # Instrument the trusted ACL child, then execute the real binary unchanged.
        # A socket on any inherited descriptor would expose the root reply channel.
        acl = Path('/usr/bin/setfacl')
        acl.rename('/usr/bin/setfacl.proof-real')
        acl.write_text("""#!/usr/bin/python3
import json,os,sys
from pathlib import Path
fds={}
for p in Path('/proc/self/fd').iterdir():
 try:fds[p.name]=os.readlink(p)
 except FileNotFoundError:pass
Path('/run/orbit-local-child-fds.json').write_text(json.dumps(fds))
print('SYNTHETIC_RAW_CHILD_STDOUT_SENTINEL',flush=True)
os.execv('/usr/bin/setfacl.proof-real',['setfacl',*sys.argv[1:]])
""")
        acl.chmod(0o755)
        cls.digest = hashlib.sha256(Path(AGENT).read_bytes()).hexdigest()
        cls.home = Path('/home') / OWNER
        release = cls.home / 'releases/proof-1'
        (release / 'web/public').mkdir(parents=True)
        (release / 'web/public/index.html').write_text('PUBLIC_FIXTURE')
        (cls.home / '.env').write_text('SYNTHETIC_PRIVATE_INPUT_SENTINEL')
        run('chown', '-R', f'{OWNER}:{OWNER}', cls.home)
        run('chmod', '0700', cls.home)
        run('runuser', '-u', OWNER, '--', 'git', '-C', release, 'init', '-q')
        run('runuser', '-u', OWNER, '--', 'git', '-C', release,
            '-c', 'user.name=Proof', '-c', 'user.email=proof@example.test',
            'commit', '--allow-empty', '-qm', 'Proof')
        cls.commit = run('runuser', '-u', OWNER, '--', 'git', '-C', release, 'rev-parse', 'HEAD')
        (cls.home / 'current').symlink_to(release)
        state = Path('/var/lib/orbit/app-instance-sources/99001')
        state.mkdir(parents=True, mode=0o700)
        marker = state / 'release-layout'
        marker.write_bytes(f'https://example.test/proof.git\0{OWNER}\0{cls.home}\0initial\0'.encode())
        marker.chmod(0o600)
        try:
            pwd.getpwnam('caddy')
        except KeyError:
            run('useradd', '--system', '--user-group', 'caddy')
        cls.input = dict(repository='https://example.test/proof.git', user=OWNER,
                         home=str(cls.home), instance=99001, relative_root='web/public',
                         application_suffix='/web', served=[dict(web_root='web/public', directory='web')])

    @classmethod
    def tearDownClass(cls):
        run('systemctl', 'stop', 'orbit-agent-local.socket')
        # The container is the cleanup boundary; do not remove host accounts by name.

    def request(self, command='instance.prepare-served-web-roots', **changes):
        value = dict(command=command, agent_sha256=self.digest, input=self.input)
        value.update(changes)
        return json.dumps(value) + '\n'

    def call(self, body, user=WORKER, sandbox=False):
        argv = ['systemd-run', '--quiet', '--wait', '--pipe', '--collect',
                '--uid=' + user, '--property=NoNewPrivileges=yes',
                '--property=ProtectHome=yes', '--property=ProtectSystem=strict',
                '--property=ReadWritePaths=/var/lib/orbit-node-worker']
        if sandbox:
            argv += ['--property=InaccessiblePaths=-/run/orbit-agent']
        argv += ['/usr/bin/python3', '-I', '-c', CLIENT]
        return run(*argv, input=body)

    def test_worker_calls_catalogue_action_under_no_new_privileges(self):
        meta = Path(SOCKET).stat()
        self.assertEqual((meta.st_uid, meta.st_gid, stat.S_IMODE(meta.st_mode)),
                         (0, pwd.getpwnam(WORKER).pw_gid, 0o660))
        result = json.loads(self.call(self.request()))
        self.assertEqual(result, dict(ok=True, data=dict(name='proof-1', commit=self.commit)))
        self.assertEqual(os.readlink(self.home / 'releases/proof-1/web/.env'), '../../../env/web/.env')
        # Successful output and denied inputs never enter the service journal.
        journal = run('journalctl', '--no-pager', '-u', 'orbit-agent-local@*')
        self.assertNotIn('SYNTHETIC_PRIVATE_INPUT_SENTINEL', journal)
        self.assertNotIn(self.commit, journal)
        self.assertNotIn('SYNTHETIC_RAW_CHILD_STDOUT_SENTINEL', journal)
        child_fds = json.loads(Path('/run/orbit-local-child-fds.json').read_text())
        self.assertFalse(any(value.startswith('socket:') for value in child_fds.values()))
        self.assertEqual([child_fds[str(n)] for n in [0, 1, 2]], ['/dev/null'] * 3)

    def test_package_and_other_uid_refused(self):
        self.assertEqual(self.call(self.request(), PACKAGE), 'DENIED')
        self.assertIn(self.call(self.request(), OTHER), ['', 'DENIED'])
        self.assertEqual(self.call(self.request(), PACKAGE, sandbox=True), 'DENIED')

    def test_unknown_action_pin_mismatch_and_malformed_frames_refused(self):
        for body, code in [
            (self.request('agent.install'), 'agent.local_action_forbidden'),
            (self.request(agent_sha256='0' * 64), 'agent.version_mismatch'),
            (self.request(executable='/bin/sh'), 'agent.envelope_invalid'),
            (self.request(input={'private': 'SYNTHETIC_PRIVATE_INPUT_SENTINEL'}), 'agent.invalid_input'),
            ('not-json\n', 'agent.envelope_invalid'),
            ('{}', 'agent.envelope_invalid'),
            ('x' * (64 * 1024) + '\n', 'agent.envelope_invalid'),
        ]:
            result = json.loads(self.call(body))
            self.assertFalse(result['ok'])
            self.assertEqual(result['error']['code'], code)
            self.assertEqual(result['error']['details'], {})
            self.assertNotIn('SYNTHETIC_PRIVATE_INPUT_SENTINEL', json.dumps(result))

    def test_typed_primitive_refuses_a_foreign_home(self):
        value = dict(self.input, home='/etc')
        result = json.loads(self.call(self.request(input=value)))
        self.assertFalse(result['ok'])
        self.assertEqual(result['error']['code'], 'instance.release_invalid')
        self.assertNotIn('/etc', json.dumps(result))


if __name__ == '__main__':
    unittest.main()
