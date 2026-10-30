"""Opt-in real systemd/package boundary proof. Run as a normal sudo-capable user.

ORBIT_PACKAGE_AGENT=target/debug/orbit-agent ORBIT_PACKAGE_NODE=/path/to/node \
python3 -B -m unittest discover -s tests -p package_sandbox_native.py -v

Uses synthetic credentials only. Creates missing test prerequisites and removes
only those it created; never replaces an existing account or toolchain.
"""
import hashlib
import json
import os
from pathlib import Path
import pwd
import shutil
import subprocess
import tempfile
import time
import unittest
import uuid

AGENT = os.environ.get('ORBIT_PACKAGE_AGENT')
USER = 'orbit-swamp-package'
RUNTIME = Path('/opt/orbit/swamp-package-runtime')
STATE = Path('/var/lib/orbit-swamp-package')


def root(*args, **kwargs):
    return subprocess.run(['sudo', '-n', *map(str, args)], check=True, capture_output=True, text=True, timeout=90, **kwargs)


@unittest.skipUnless(AGENT and os.environ.get('ORBIT_PACKAGE_NODE'), 'explicit native agent and node binary required')
class PackageBoundary(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        if os.geteuid() == 0:
            raise AssertionError('Run the test as a normal user, not root')
        cls.binary = Path(AGENT).resolve()
        cls.digest = hashlib.sha256(cls.binary.read_bytes()).hexdigest()
        cls.temp = tempfile.TemporaryDirectory(prefix='package-native-')
        cls.path = Path(cls.temp.name)
        cls.jobs = []
        cls.units = []
        cls.created_runtime = not RUNTIME.exists()
        cls.created_state = not STATE.exists()
        try:
            pwd.getpwnam(USER)
            cls.created_account = False
        except KeyError:
            cls.created_account = True
        if cls.created_runtime:
            root('/usr/bin/python3', '-I', '-c', '''import os,pathlib,shutil,sys
p=pathlib.Path(sys.argv[1]);p.mkdir(parents=True,mode=0o755)
shutil.copyfile(sys.argv[2],p/'node');os.chmod(p/'node',0o755)
(p/'native-test-owner').write_text(sys.argv[3])
''', RUNTIME, Path(os.environ['ORBIT_PACKAGE_NODE']).resolve(), str(cls.path))
        # Trusted fixture runner starts the real typed command under the same
        # native-host lifetime used by production, with a synthetic credential.
        runner = cls.path / 'host.py'
        runner.write_text('''import json,os,subprocess,sys
v=json.load(open(sys.argv[2]));v=json.loads(json.dumps(v).replace('HOST_PID',str(os.getpid())).replace('HOST_UNIT',sys.argv[3]))
session=v.pop('session',True)
body=json.dumps(v['input'].pop('files',{})).encode() if session else b''
if session: v['stream_bytes']=len(body)
r=subprocess.run(['/usr/bin/sudo','-n',sys.argv[1],'command','package.session' if session else 'package.prepare-account'],input=json.dumps(v).encode()+b'\\n'+body,capture_output=True)
sys.stdout.buffer.write(r.stdout);sys.stderr.buffer.write(r.stderr);sys.exit(r.returncode)
''')
        cls.runner = runner
        cls.source = Path('/run') / ('orbit-package-proof-' + uuid.uuid4().hex)
        root('/usr/bin/python3', '-I', '-c', "import pathlib,sys;p=pathlib.Path(sys.argv[1]);p.mkdir(mode=0o700);(p/'key').write_text('SYNTHETIC_NOT_A_TOKEN');(p/'key').chmod(0o600)", cls.source)
        cls.call({'session': False, 'worker_user': pwd.getpwuid(os.getuid()).pw_name})
        cls.package_uid = pwd.getpwnam(USER).pw_uid

    @classmethod
    def call(cls, value, unit=None, wait=True):
        envelope = {'agent_sha256': cls.digest, 'deadline_ms': 180000, 'input': value} if 'session' not in value else {'session': False, 'agent_sha256': cls.digest, 'deadline_ms': 180000, 'input': {k:v for k,v in value.items() if k!='session'}}
        path = cls.path / (uuid.uuid4().hex + '.json')
        path.write_text(json.dumps(envelope))
        unit = unit or ('orbit-swamp-host-' + uuid.uuid4().hex + '.service')
        cls.units.append(unit)
        args = ['sudo', '-n', '/usr/bin/systemd-run', '--quiet', '--wait', '--pipe', '--collect', '--service-type=exec', '--unit='+unit, '--uid='+str(os.getuid()), '--gid='+str(os.getgid()), '--property=KillMode=control-group', '--property=TimeoutStopSec=2', '--property=RuntimeMaxSec=40', '--property=LoadCredential=fixture.key:'+str(cls.source/'key'), '--', '/usr/bin/python3', '-I', str(cls.runner), str(cls.binary), str(path), unit]
        if not wait:
            return subprocess.Popen(args, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
        result = subprocess.run(args, capture_output=True, text=True, timeout=45)
        if result.returncode:
            raise AssertionError('native command refused: ' + result.stdout[-1500:] + result.stderr[-1000:])
        reply = json.loads(result.stdout.strip().splitlines()[-1])
        if not reply.get('ok'):
            raise AssertionError(str(reply) + result.stderr[-2000:])
        return reply['data']

    def job(self):
        job = uuid.uuid4().hex
        self.jobs.append(job)
        self.call({'action':'open','job':job,'files':{'package.json':'{"name":"fixture","version":"1.0.0"}'}})
        return job

    def run_node(self, job, script):
        return self.call({'action':'run','job':job,'tool':'node','cwd':'.','args':['-e',script],'files':{'package.json':'{"name":"fixture","version":"1.0.0"}'}})

    def test_native_uid_credentials_proc_sudo_environment_and_registry(self):
        job = self.job()
        paths = [str(self.source/'key'), '/run/credentials/HOST_UNIT/fixture.key', '/proc/HOST_PID/root/run/credentials/HOST_UNIT/fixture.key', str(Path.home()), '/home/orbit', '/home/orbit/.swamp', '/home/orbit/.config/swamp/vault-keys', '/home/orbit/apps/orbit-swamp/default/.swamp', '/var/lib/orbit-node-worker', '/etc/orbit/swamp-issuer/enrollment-vault.key']
        script = '''const fs=require('fs'),cp=require('child_process'),https=require('https');
const paths=PATHS;const denied=paths.map(p=>{try{let fd=fs.openSync(p,'r');fs.closeSync(fd);return false}catch{return true}});
const sudo=cp.spawnSync('/usr/bin/sudo',['-n','/usr/bin/python3','-I','-c','import os;os.close(os.open("ROOT_SOURCE",os.O_RDONLY))'],{encoding:'utf8'});
const report={uid:process.getuid(),gid:process.getgid(),groups:process.getgroups(),denied,sudoDenied:sudo.status!==0,secretEnv:Object.keys(process.env).filter(k=>/SWAMP|TOKEN|GITHUB|GH_/.test(k))};
https.get('https://registry.npmjs.org/-/ping',r=>{report.registry=r.statusCode;r.resume();r.on('end',()=>console.log(JSON.stringify(report)))}).on('error',e=>{console.error(e.code);process.exitCode=1});
'''.replace('PATHS', json.dumps(paths)).replace('ROOT_SOURCE',str(self.source/'key'))
        result = self.run_node(job, script)
        self.assertEqual(result['code'], 0, result['stderr'])
        report = json.loads(result['stdout'])
        self.assertEqual(report['uid'], self.package_uid)
        self.assertNotEqual(report['uid'], os.getuid())
        self.assertTrue(all(report['denied']), report)
        self.assertTrue(report['sudoDenied'])
        self.assertEqual(report['secretEnv'], [])
        self.assertEqual(report['registry'], 200)
        self.assertTrue(all(g == report['gid'] for g in report['groups']))

    def test_native_socketpair_and_io_uring_cannot_bypass_address_family_filter(self):
        job = self.job()
        python = """import ctypes,json,socket
r={}
try:
 a,b=socket.socketpair(socket.AF_UNIX,socket.SOCK_DGRAM);a.close();b.close();r['socketpair']=False
except OSError:r['socketpair']=True
libc=ctypes.CDLL(None,use_errno=True)
# x86_64 io_uring_setup, with deliberately invalid parameters: EPERM must
# precede EINVAL, proving the syscall itself is blocked.
result=libc.syscall(425,0,0);r['io_uring']=result==-1 and ctypes.get_errno()==1
print(json.dumps(r))
"""
        script = "const r=require('child_process').spawnSync('/usr/bin/python3',['-I','-c',"+json.dumps(python)+"],{stdio:['ignore',1,2]});process.exitCode=r.status===null?99:r.status"
        result = self.run_node(job,script)
        self.assertEqual(result['code'],0,result['stderr'])
        self.assertEqual(json.loads(result['stdout']),{'socketpair':True,'io_uring':True})

    def test_native_cancel_stops_setsid_descendant_before_reusing_job(self):
        job = self.job()
        unit = 'orbit-swamp-host-' + uuid.uuid4().hex + '.service'
        script = "const cp=require('child_process'),fs=require('fs');cp.spawn('/usr/bin/python3',['-I','-c','import os,signal,time;os.setsid();signal.signal(signal.SIGTERM,signal.SIG_IGN);time.sleep(100)'],{stdio:'ignore'});fs.writeFileSync('/work/package.json','{\\\"started\\\":true}');setTimeout(()=>{},100000)"
        process = self.call({'action':'run','job':job,'tool':'node','cwd':'.','args':['-e',script],'files':{'package.json':'{}'}}, unit=unit, wait=False)
        try:
            started = False
            for _ in range(100):
                r=root('/usr/bin/python3','-I','-c',"import pathlib,sys;print(pathlib.Path(sys.argv[1]).read_text())",STATE/'jobs'/job/'root/work/package.json')
                if 'started' in r.stdout:
                    started=True;break
                if process.poll() is not None: break
                time.sleep(.1)
            if not started:
                self.fail('package never started: ' + str(process.communicate(timeout=5)))
            root('/usr/bin/systemctl','stop',unit)
            process.communicate(timeout=10)
            events=Path('/sys/fs/cgroup/system.slice')/('orbit-package-'+job+'.service')/'cgroup.events'
            if events.exists(): self.assertIn('populated 0',events.read_text())
            result=self.run_node(job,"console.log('reused')")
            self.assertEqual(result['code'],0,result['stderr'])
            self.assertEqual(result['stdout'].strip(),'reused')
        finally:
            subprocess.run(['sudo','-n','systemctl','stop',unit],capture_output=True,timeout=15)
            if process.poll() is None: process.kill();process.communicate(timeout=5)

    @classmethod
    def tearDownClass(cls):
        for unit in cls.units: subprocess.run(['sudo','-n','systemctl','stop',unit],capture_output=True,timeout=15)
        for job in cls.jobs:
            try: cls.call({'action':'cancel','job':job});cls.call({'action':'close','job':job})
            except AssertionError:
                root('/usr/bin/systemctl','stop','orbit-package-'+job+'.service')
                root('/usr/bin/python3','-I','-c',"import shutil,sys;shutil.rmtree(sys.argv[1])",STATE/'jobs'/job)
        root('/usr/bin/python3','-I','-c',"import shutil,sys;shutil.rmtree(sys.argv[1])",cls.source)
        if cls.created_runtime:
            root('/usr/bin/python3','-I','-c',"import pathlib,shutil,sys;p=pathlib.Path(sys.argv[1]);assert (p/'native-test-owner').read_text()==sys.argv[2];shutil.rmtree(p)",RUNTIME,cls.path)
        if cls.created_state:
            root('/usr/bin/python3','-I','-c',"import pathlib,sys;p=pathlib.Path(sys.argv[1]);(p/'jobs').rmdir();p.rmdir()",STATE)
        if cls.created_account: root('/usr/sbin/userdel',USER)
        cls.temp.cleanup()


if __name__ == '__main__': unittest.main()
