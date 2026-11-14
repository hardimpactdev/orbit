"""The test-free harness of task:sync of a copy: the base and base-cleanup workspace phases, with real Ansible, real Git, a strict
local Incus shim and the Project's repository behind a local HTTPS server.

The default branch reaches the copy only as a bundle of what it lacks, into refs/remotes/origin/<default>. The read credential is a
Git header that reaches only the Node's one fetch: never a file, so neither a failed nor a cancelled run leaves it anywhere."""
import functools
import hashlib
import http.server
import json
import os
import runpy
import shutil
import signal
import ssl
import subprocess
import tempfile
import threading
import time
import unittest
import uuid
from pathlib import Path

SUPPORT = runpy.run_path(str(Path(__file__).parent / 'task_instance_workspace.py'))
ROOT, TASKS, COMMAND = SUPPORT['ROOT'], SUPPORT['TASKS'], SUPPORT['COMMAND']
CHILD = ROOT / 'workflows/workflow-task-copy-base.yaml'
GIT_ENV = {'GIT_CONFIG_GLOBAL': '/dev/null', 'GIT_CONFIG_NOSYSTEM': '1', 'GIT_AUTHOR_NAME': 'base fixture',
           'GIT_AUTHOR_EMAIL': 'base@example.test', 'GIT_COMMITTER_NAME': 'base fixture', 'GIT_COMMITTER_EMAIL': 'base@example.test'}
# The Git header that the sync's read token is, as TaskGitHubTokens::writeClone writes it.
SECRET = 'base-test-credential-value'
CREDENTIAL = 'AUTHORIZATION: basic ' + SECRET

# The production Incus boundary: exec as agent in the fixed checkout, root's two folder commands in the copy, and one push of the
# bundle, owned by root, into that folder. T2_HOLD_AT names a guest phase at which the shim marks its arrival and waits, so the test
# can cancel the run there.
SHIM = '''#!/usr/bin/env python3
import json, os, shutil, subprocess, sys, time
args = sys.argv[1:]
with open(os.environ['T2_CALLS'], 'a') as output:
    output.write(json.dumps(args) + '\\n')
assert args[:3] == ['--project', 'alternate-proof', 'exec'] or args[:4] == ['--project', 'alternate-proof', 'file', 'push'], args
folder = '/tmp/orbit-task-base-' + os.environ['T2_INSTANCE']
if args[2] == 'exec' and args[3:6] == ['--disable-stdin', 'proof-instance', '--']:
    assert args[6:] in (['/bin/rm', '-rf', '--', folder], ['/bin/mkdir', '-m', '0755', '--', folder]), args
    sys.exit(subprocess.run(args[6:], check=False).returncode)
if args[2] == 'exec':
    assert args[3:12] == ['--disable-stdin', '--cwd', '/srv/workspace', 'proof-instance', '--', '/usr/sbin/runuser', '-u', 'agent', '--'], args
    assert args[12:19] == ['/usr/bin/env', '-i', 'HOME=/home/agent', 'PATH=/usr/local/bin:/usr/bin:/bin', '/usr/bin/python3', '-I', '-c'], args
    assert sys.stdin.buffer.read() == b'', 'Incus stdin must be closed'
    if args[20] == os.environ.get('T2_HOLD_AT'):
        open(os.environ['T2_MARK'], 'w').close()
        time.sleep(120)
    sys.exit(subprocess.run([sys.executable, *args[17:]], cwd=os.environ['T2_GUEST'], env={**os.environ}, check=False).returncode)
assert args[4:10] == ['--uid', '0', '--gid', str(os.getgid()), '--mode', '0640'], args
assert len(args) == 12 and args[11] == 'proof-instance' + folder + '/base.bundle', args
target = folder + '/base.bundle'
shutil.copyfile(args[10], target)
os.chmod(target, 0o640)
'''


@functools.cache
def certificate():
    """One self-signed certificate for 127.0.0.1, which Git trusts through GIT_SSL_CAINFO."""
    folder = Path(tempfile.mkdtemp(prefix='t2-base-tls-'))
    subprocess.run(['openssl', 'req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-keyout', str(folder / 'key.pem'),
                    '-out', str(folder / 'cert.pem'), '-days', '1', '-subj', '/CN=127.0.0.1', '-addext', 'subjectAltName=IP:127.0.0.1'],
                   check=True, capture_output=True)
    return folder / 'cert.pem', folder / 'key.pem'


class Origin:
    """The Project's repository over HTTPS: Git's dumb HTTP protocol from a TLS listener, which records each request's header."""

    def __init__(self, folder):
        self.headers = []
        origin = self

        class Handler(http.server.SimpleHTTPRequestHandler):
            def __init__(self, *args, **kwargs):
                super().__init__(*args, directory=str(folder), **kwargs)

            def do_GET(self):
                origin.headers.append(self.headers.get('Authorization'))
                super().do_GET()

            def log_message(self, *_args):
                pass

        self.server = http.server.ThreadingHTTPServer(('127.0.0.1', 0), Handler)
        cert, key = certificate()
        context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
        context.load_cert_chain(cert, key)
        self.server.socket = context.wrap_socket(self.server.socket, server_side=True)
        threading.Thread(target=self.server.serve_forever, daemon=True).start()
        self.url = f'https://127.0.0.1:{self.server.server_address[1]}/origin.git'

    def close(self):
        self.server.shutdown()
        self.server.server_close()


class BaseHarness(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='t2-base-')
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.bin = self.root / 'bin'
        self.bin.mkdir()
        (self.bin / 'incus').write_text(SHIM)
        (self.bin / 'incus').chmod(0o755)
        self.calls = self.root / 'calls.jsonl'
        self.snapshot_id = str(uuid.uuid4())
        # A copy's Instance id names its scratch; a random one keeps parallel runs of these tests apart.
        self.instance_id = 900000000 + uuid.uuid4().int % 99999999
        self.node = Path(f'/var/tmp/orbit-task-base-{self.instance_id}')
        self.guest = Path(f'/tmp/orbit-task-base-{self.instance_id}')
        self.addCleanup(shutil.rmtree, self.node, True)
        self.addCleanup(shutil.rmtree, self.guest, True)
        # origin: the seed A, then B and C on main. The default's mirror holds the seed.
        served = self.root / 'served'
        served.mkdir()
        self.origin = served / 'origin.git'
        self.git('init', '--quiet', '--bare', '--initial-branch=main', str(self.origin), cwd=self.root)
        work = self.root / 'work'
        self.git('clone', '--quiet', str(self.origin), str(work), cwd=self.root)
        self.seed = self.commit(work, 'seed.txt', 'seed\n')
        self.git('push', '--quiet', 'origin', 'HEAD:main', cwd=work)
        self.mirrors = self.root / 'instance-repositories'
        self.mirrors.mkdir()
        self.source_mirror = self.mirrors / '11.git'
        self.git('clone', '--quiet', '--bare', str(self.origin), str(self.source_mirror), cwd=self.root)
        self.commit(work, 'main-one.txt', 'one\n')
        self.head = self.commit(work, 'main-two.txt', 'two\n')
        self.git('push', '--quiet', 'origin', 'HEAD:main', cwd=work)
        self.git('update-server-info', cwd=self.origin)
        self.server = Origin(served)
        self.addCleanup(self.server.close)
        # The copy: its branch at the seed plus the agent's own commit and uncommitted work; origin/main is the seed.
        self.checkout = self.root / 'checkout'
        self.git('clone', '--quiet', '--no-local', str(self.source_mirror), str(self.checkout), cwd=self.root)
        self.git('checkout', '--quiet', '-b', 'task-42')
        self.work = self.commit(self.checkout, 'work.txt', 'agent work\n')
        self.git('remote', 'remove', 'origin')
        self.git('update-ref', 'refs/remotes/origin/main', self.seed)
        (self.checkout / 'work.txt').write_text('uncommitted\n')
        (self.checkout / 'untracked.txt').write_text('untracked\n')
        self.git('add', 'untracked.txt')
        self.intent = {'runtime': {'project': 'alternate-proof', 'name': 'proof-instance'}, 'branch': 'task-42',
                       'snapshot_id': self.snapshot_id, 'instance_id': self.instance_id, 'default_branch': 'main', 'repository': self.server.url,
                       'mirror': str(self.mirrors / '12.git'), 'source_mirror': str(self.source_mirror), 'start': self.seed}
        self.play = self.root / 'play.yml'
        self.play.write_text(json.dumps([{'name': 'Exercise the production phase', 'hosts': 'localhost', 'connection': 'local',
            'gather_facts': False, 'tasks': [{'name': 'Run workspace phase', 'ansible.builtin.import_tasks': str(TASKS)}]}]))
        self.mark = self.root / 'held'
        self.result = self.root / 'result.json'
        # Ansible's own temporary folders of this run: no credential may rest there either.
        self.ansible_home = self.root / 'ansible-home'
        self.ansible_home.mkdir()

    def git(self, *args, cwd=None):
        return subprocess.run(['git', *args], cwd=cwd or self.checkout, check=True, capture_output=True,
                              env={**os.environ, **GIT_ENV}).stdout.decode().strip()

    def commit(self, cwd, name, content):
        (cwd / name).write_text(content)
        self.git('add', name, cwd=cwd)
        self.git('commit', '--quiet', '-m', name, cwd=cwd)
        return self.git('rev-parse', 'HEAD', cwd=cwd)

    def start(self, phase, intent=None, hold_at=''):
        variables = self.root / 'vars.json'
        self.result.unlink(missing_ok=True)
        variables.write_text(json.dumps({'intent': intent or self.intent, 'workspace_phase': phase,
                                         'workspace_result_path': str(self.result)}))
        cert, _key = certificate()
        return subprocess.Popen([COMMAND, '-i', 'localhost,', '-e', '@' + str(variables), str(self.play)],
            stdout=subprocess.PIPE, stderr=subprocess.STDOUT, text=True, start_new_session=True,
            env={**os.environ, 'PATH': str(self.bin) + ':' + os.environ['PATH'], 'T2_GUEST': str(self.checkout),
                 'T2_CALLS': str(self.calls), 'T2_HOLD_AT': hold_at, 'T2_MARK': str(self.mark), 'T2_INSTANCE': str(self.instance_id),
                 'ORBIT_GIT_AUTHORIZATION': CREDENTIAL, 'GIT_SSL_CAINFO': str(cert), 'NO_PROXY': '127.0.0.1', 'no_proxy': '127.0.0.1',
                 'ANSIBLE_LOCAL_TEMP': str(self.ansible_home / 'local'), 'ANSIBLE_REMOTE_TEMP': str(self.ansible_home / 'remote'),
                 'ANSIBLE_CONFIG': str(ROOT / 'ansible/ansible.cfg'), 'ANSIBLE_FORCE_COLOR': '0'})

    def phase(self, phase, intent=None, ok=True):
        process = self.start(phase, intent)
        output, _ = process.communicate(timeout=180)
        self.assertNotIn(SECRET, output)
        if ok:
            self.assertEqual(process.returncode, 0, output)
            return json.loads(self.result.read_text())
        self.assertNotEqual(process.returncode, 0, output)
        return output

    def cancel(self, process, ready):
        """Cancel the run as Swamp does: the whole process group ends at once, so no `always` block runs."""
        deadline = time.monotonic() + 60
        while not ready():
            self.assertIsNone(process.poll(), 'the run ended before the cancel')
            self.assertLess(time.monotonic(), deadline, 'the run never reached the cancel point')
            time.sleep(0.05)
        os.killpg(process.pid, signal.SIGKILL)
        process.communicate(timeout=30)

    def state(self):
        """HEAD, the local branches, the index and the working tree: what a sync never touches."""
        index = (self.checkout / '.git/index').read_bytes()
        return {'head': self.git('rev-parse', 'HEAD'), 'symbolic': self.git('symbolic-ref', 'HEAD'),
                'branches': self.git('for-each-ref', '--format=%(refname) %(objectname)', 'refs/heads'),
                'index': hashlib.sha256(index).hexdigest(), 'status': self.git('status', '--porcelain=v2'),
                'work': (self.checkout / 'work.txt').read_text()}

    def pushes(self):
        if not self.calls.exists():
            return []
        return [call for call in map(json.loads, self.calls.read_text().splitlines()) if call[2:4] == ['file', 'push']]

    def assert_nothing_left(self):
        self.assertFalse(self.node.exists(), 'the Node scratch is left')
        self.assertFalse(self.guest.exists(), 'the bundle folder in the copy is left')

    def assert_no_credential_anywhere(self):
        """No file of the run holds the credential: the Node scratch, Ansible's temporary folders, the copy and every other file
        the test made, the mirrors and the results included."""
        for top in (self.root, self.node, self.guest, self.ansible_home):
            for path in top.rglob('*') if top.exists() else []:
                if path.is_file() and not path.is_symlink():
                    self.assertNotIn(SECRET.encode(), path.read_bytes(), path)

    def evidence(self, phase):
        """The production typed reader of the phase result."""
        folder = Path('/tmp/orbit-ansible-' + str(uuid.uuid4()))
        folder.mkdir(mode=0o700)
        self.addCleanup(shutil.rmtree, folder, True)
        shutil.copyfile(self.result, folder / 'result.json')
        script = self.root / 'collector.ts'
        source = ROOT / 'extensions/behaviors/operations/task-instance-workspace/assets.ts'
        script.write_text('import { behavior } from ' + json.dumps(source.as_uri()) + ';\n'
                          'const value = await behavior.methods.result.execute({folder: Deno.args[0], phase: Deno.args[1]}, '
                          '{writeResource: async (_spec, _name, value) => value}); console.log(JSON.stringify(value));')
        done = subprocess.run(['deno', 'run', '-A', str(script), str(folder), phase], capture_output=True, text=True,
                              check=False, timeout=60)
        self.assertEqual(done.returncode, 0, done.stdout + done.stderr)
        return json.loads(done.stdout)['evidence']
