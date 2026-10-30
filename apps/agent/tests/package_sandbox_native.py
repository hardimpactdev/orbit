"""Required real systemd/package boundary tests (also run by CI). Run as a normal sudo-capable user.

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
import socket
import subprocess
import tempfile
import time
import unittest
import uuid

AGENT = os.environ.get('ORBIT_PACKAGE_AGENT', str(Path(__file__).resolve().parents[1] / 'target/debug/orbit-agent'))
USER = 'orbit-swamp-package'
RUNTIME = Path('/opt/orbit/swamp-package-runtime')
STATE = Path('/var/lib/orbit-swamp-package')


def root(*args, **kwargs):
    return subprocess.run(['sudo', '-n', *map(str, args)], check=True, capture_output=True, text=True, timeout=90, **kwargs)


class PackageBoundary(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        if os.geteuid() == 0:
            raise AssertionError('Run the test as a normal user, not root')
        cls.binary = Path(AGENT).resolve()
        cls.digest = hashlib.sha256(cls.binary.read_bytes()).hexdigest()
        cls.node = Path(os.environ.get('ORBIT_PACKAGE_NODE') or shutil.which('node')).resolve()
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
        if not cls.created_runtime or not cls.created_account:
            raise AssertionError('Native tests require a disposable host without the package runtime/account')
        if cls.created_runtime:
            root('/usr/bin/python3', '-I', '-c', '''import json,os,pathlib,shutil,sys
# Exercise runner defaults without letting umask define the trusted fixture.
os.umask(0o002)
p=pathlib.Path(sys.argv[1]);p.mkdir(parents=True,mode=0o755);p.chmod(0o755)
shutil.copyfile(sys.argv[2],p/'node');os.chmod(p/'node',0o755)
# Test a real setuid sudo binary even on systems whose /usr/bin/sudo
# symlink targets /etc/alternatives outside the package root.
shutil.copyfile(pathlib.Path('/usr/bin/sudo').resolve(),p/'sudo');os.chmod(p/'sudo',0o4755)
(p/'native-test-owner').write_text(sys.argv[3])
(p/'network-deny.json').write_text(json.dumps({'public_addresses':['80.47.233.206','2001:db8::209']}))
(p/'network-deny.json').chmod(0o644)
''', RUNTIME, cls.node, str(cls.path))
        # Trusted fixture runner starts the real typed command under the same
        # native-host lifetime used by production, with a synthetic credential.
        runner = cls.path / 'host.py'
        runner.write_text('''import json,os,subprocess,sys
v=json.load(open(sys.argv[2]));v=json.loads(json.dumps(v).replace('HOST_PID',str(os.getpid())).replace('HOST_UNIT',sys.argv[3]))
session=v.pop('session',True)
# Positive baseline: every sensitive file exists with its intended owner and mode.
# Only open/close synthetic fixtures; no credential contents are read.
if session and v['input'].get('action')=='run':
 paths=v.pop('baseline',[])
 probe="import os,stat,sys;[(lambda fd:os.close(fd))(os.open(p,os.O_RDONLY)) for p in sys.argv[1:]]"
 subprocess.run(['/usr/bin/sudo','-n','/usr/bin/python3','-I','-c',probe,*paths],check=True)
body=json.dumps(v['input'].pop('files',{})).encode() if session else b''
if session: v['stream_bytes']=len(body)
r=subprocess.run(['/usr/bin/sudo','-n',sys.argv[1],'command','package.session' if session else 'package.prepare-account'],input=json.dumps(v).encode()+b'\\n'+body,capture_output=True)
sys.stdout.buffer.write(r.stdout);sys.stderr.buffer.write(r.stderr);sys.exit(r.returncode)
''')
        cls.runner = runner
        cls.source = Path('/run') / ('orbit-package-proof-' + uuid.uuid4().hex)
        root('/usr/bin/python3', '-I', '-c', "import pathlib,sys;p=pathlib.Path(sys.argv[1]);p.mkdir(mode=0o700);(p/'key').write_text('SYNTHETIC_NOT_A_TOKEN');(p/'key').chmod(0o600)", cls.source)
        # Fixed, non-secret preflight makes a runner mismatch an actionable failure.
        metadata = root('/usr/bin/python3', '-I', '-c', """import ipaddress,json,os,pathlib,stat,subprocess,sys
p=pathlib.Path(sys.argv[1]);m=(p/'network-deny.json').stat()
r=subprocess.run(['/usr/sbin/ip','-j','address','show'],capture_output=True,text=True,check=True)
interfaces=json.loads(r.stdout)
assert all(isinstance(v.get('addr_info'),list) for v in interfaces),'ip_addr_info_missing'
for interface in interfaces:
 for info in interface['addr_info']:ipaddress.ip_address(info['local'])
assert (m.st_uid,m.st_gid,stat.S_IMODE(m.st_mode),m.st_nlink)==(0,0,0o644,1),'unsafe_fixture_network_policy'
print(json.dumps({'runtime_mode':oct(stat.S_IMODE(p.stat().st_mode)),'policy_mode':oct(stat.S_IMODE(m.st_mode)),'ip_interfaces':len(interfaces),'systemd_version':subprocess.check_output(['/usr/bin/systemctl','--version'],text=True).splitlines()[0]}))
""", RUNTIME)
        print('Native package fixture preflight: '+metadata.stdout.strip(), flush=True)
        cls.call({'session': False, 'worker_user': pwd.getpwuid(os.getuid()).pw_name})
        cls.package_uid = pwd.getpwnam(USER).pw_uid
        # Real synthetic files in PID1's host filesystem: the package unit is
        # started by systemd independently of the caller's mount namespace.
        # Each name is unique; only fixture-created paths are removed.
        owner=pwd.getpwnam('orbit').pw_uid if any(p.pw_name=='orbit' for p in pwd.getpwall()) else os.getuid()
        name='orbit-package-native-'+uuid.uuid4().hex
        cls.baseline=[]
        cls.fixture_directories=[]
        files={
            '/home/orbit/.config/swamp/'+name+'-token': (owner,0o600),
            '/home/orbit/.config/swamp/vault-keys/'+name+'.key': (owner,0o600),
            '/home/orbit/apps/orbit-swamp/default/.swamp/'+name+'/receipt': (owner,0o600),
            '/var/lib/orbit-node-worker/credentials/'+name: (0,0o600),
            '/var/lib/orbit-node-worker/cache/'+name: (owner,0o600),
            '/etc/orbit/swamp-issuer/'+name+'.key': (0,0o600),
            '/etc/orbit/swamp-issuer/'+name+'-operator': (0,0o600),
            '/etc/orbit/swamp-issuer/'+name+'-api-key': (0,0o600),
        }
        for filename,(uid,mode) in files.items():
            result=root('/usr/bin/python3','-I','-c',"""import json,os,pathlib,sys
p=pathlib.Path(sys.argv[1]);created=[]
def parents(d):
 if d.exists():
  assert d.is_dir() and not d.is_symlink();return
 parents(d.parent);d.mkdir(mode=0o700);created.append(str(d))
parents(p.parent)
# All source files are synthetic, unique, and positively verified from the
# actual host namespace. No existing file is read or overwritten.
fd=os.open(p,os.O_CREAT|os.O_EXCL|os.O_WRONLY|os.O_NOFOLLOW,int(sys.argv[3]));os.write(fd,b'SYNTHETIC_NOT_A_TOKEN');os.fchown(fd,int(sys.argv[2]),int(sys.argv[2]));os.close(fd)
print(json.dumps(created))
""",filename,uid,mode)
            cls.fixture_directories.extend(json.loads(result.stdout))
            cls.baseline.append(filename)
        cls.fixture_files=list(cls.baseline)
        cls.baseline.extend([str(cls.source/'key'),'/run/credentials/HOST_UNIT/fixture.key','/proc/HOST_PID/root/run/credentials/HOST_UNIT/fixture.key'])


    @classmethod
    def call(cls, value, unit=None, wait=True, deadline_ms=180000, expect_error=False):
        envelope = {'agent_sha256': cls.digest, 'deadline_ms': deadline_ms, 'input': value} if 'session' not in value else {'session': False, 'agent_sha256': cls.digest, 'deadline_ms': deadline_ms, 'input': {k:v for k,v in value.items() if k!='session'}}
        if value.get('action')=='run': envelope['baseline']=cls.baseline
        path = cls.path / (uuid.uuid4().hex + '.json')
        path.write_text(json.dumps(envelope))
        unit = unit or ('orbit-swamp-host-' + uuid.uuid4().hex + '.service')
        cls.units.append(unit)
        args = ['sudo', '-n', '/usr/bin/systemd-run', '--quiet', '--wait', '--pipe', '--collect', '--service-type=exec', '--unit='+unit, '--uid='+str(os.getuid()), '--gid='+str(os.getgid()), '--property=KillMode=control-group', '--property=TimeoutStopSec=2', '--property=RuntimeMaxSec=40', '--property=LoadCredential=fixture.key:'+str(cls.source/'key'), '--', '/usr/bin/python3', '-I', str(cls.runner), str(cls.binary), str(path), unit]
        if hasattr(cls,'binds'):
            args[args.index('--'):args.index('--')] = ['--property=BindReadOnlyPaths='+entry for entry in cls.binds]
        if not wait:
            return subprocess.Popen(args, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
        result = subprocess.run(args, capture_output=True, text=True, timeout=45)
        if expect_error:
            reply=json.loads(result.stdout.strip().splitlines()[-1])
            if reply.get('ok'): raise AssertionError('expected typed refusal: '+str(reply))
            return reply
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

    def test_a_missing_account_refuses_before_staging(self):
        root('/usr/sbin/userdel',USER)
        job=uuid.uuid4().hex
        try:
            reply=self.call({'action':'open','job':job,'files':{'package.json':'{}'}},expect_error=True)
            self.assertEqual(reply['error']['code'],'package.isolation_unavailable')
            self.assertFalse((STATE/'jobs'/job).exists())
        finally:
            self.call({'session':False,'worker_user':pwd.getpwuid(os.getuid()).pw_name})
            type(self).package_uid=pwd.getpwnam(USER).pw_uid

    def test_b_missing_runtime_refuses_before_staging(self):
        hidden=RUNTIME.with_name(RUNTIME.name+'-native-test-hidden')
        root('/usr/bin/mv',RUNTIME,hidden)
        job=uuid.uuid4().hex
        try:
            reply=self.call({'action':'open','job':job,'files':{'package.json':'{}'}},expect_error=True)
            self.assertEqual(reply['error']['code'],'package.isolation_unavailable')
            self.assertFalse((STATE/'jobs'/job).exists())
        finally:root('/usr/bin/mv',hidden,RUNTIME)

    def test_c_writable_network_policy_refuses_before_package_code(self):
        job = self.job()
        policy = RUNTIME / 'network-deny.json'
        root('/usr/bin/chmod', '0664', policy)
        try:
            reply = self.call({'action':'run','job':job,'tool':'node','cwd':'.',
                               'args':['-e',"require('fs').writeFileSync('package.json','package-code-reached')"],
                               'files':{'package.json':'{}'}}, expect_error=True)
            self.assertEqual(reply['error']['code'], 'package.isolation_unavailable')
            content = root('/usr/bin/python3', '-I', '-c',
                           'import pathlib,sys;print(pathlib.Path(sys.argv[1]).read_text())',
                           STATE/'jobs'/job/'root/work/package.json').stdout.strip()
            self.assertEqual(content, '{}', 'package code ran with writable network policy')
        finally:
            root('/usr/bin/chmod', '0644', policy)

    def test_missing_native_host_unit_refuses(self):
        job=uuid.uuid4().hex
        envelope={'agent_sha256':self.digest,'deadline_ms':5000,'stream_bytes':2,'input':{'action':'open','job':job}}
        r=subprocess.run(['sudo','-n',str(self.binary),'command','package.session'],input=json.dumps(envelope)+'\n{}',capture_output=True,text=True,timeout=10)
        reply=json.loads(r.stdout.strip().splitlines()[-1])
        self.assertFalse(reply['ok'])
        self.assertEqual(reply['error']['code'],'package.isolation_unavailable')
        self.assertFalse((STATE/'jobs'/job).exists())

    def test_missing_private_pids_refuses_before_package_code(self):
        # Exercise the exact trusted guard without PrivatePIDs. A manager that
        # ignores that property must refuse before reaching package code.
        source=(Path(__file__).resolve().parents[1]/'src/commands/package_sandbox/runtime/network.rs').read_text()
        guard=source.split('const GUARD: &str = r#"',1)[1].split('"#;',1)[0]
        ready=self.path/'network-ready';ready.write_text('ready\n')
        def guarded(code):
            unit='orbit-package-guard-'+uuid.uuid4().hex+'.service';self.units.append(unit)
            properties=['PrivatePIDs=no','PrivateIPC=yes','PrivateMounts=yes','NoNewPrivileges=yes','CapabilityBoundingSet=','AmbientCapabilities=','RestrictAddressFamilies=AF_INET AF_INET6','SocketBindDeny=any','SystemCallArchitectures=native','SystemCallFilter=~socketpair io_uring_setup io_uring_enter io_uring_register','SystemCallErrorNumber=EPERM','BindReadOnlyPaths='+str(ready)+':/run/network-ready']
            return subprocess.run(['sudo','-n','systemd-run','--quiet','--wait','--pipe','--collect','--unit='+unit,'--uid='+str(os.getuid()),*['--property='+v for v in properties],'--','/usr/bin/python3','-I','-c',code,'/usr/bin/python3','-I','-c',"print('package-code-reached')"],capture_output=True,text=True,timeout=10)
        # Positive control proves every other guard check passes in this fixture.
        # Only this copied test guard omits the PID check; production is unchanged.
        control=guarded(guard.replace('os.getpid()!=1 or ',''))
        self.assertEqual(control.returncode,0,control.stderr)
        self.assertEqual(control.stdout.strip(),'package-code-reached')
        refused=guarded(guard)
        self.assertEqual(refused.returncode,78,refused.stderr)
        self.assertNotIn('package-code-reached',refused.stdout)

    def test_runtime_max_sec_kills_setsid_descendants(self):
        job=self.job()
        child="import os,signal,time;os.setsid();signal.signal(signal.SIGTERM,signal.SIG_IGN);open('/work/package.json','w').write('detached-started');time.sleep(100)"
        script="const cp=require('child_process');cp.spawn('/usr/bin/python3',['-I','-c',"+json.dumps(child)+"],{stdio:'ignore'});setTimeout(()=>{},100000)"
        unit='orbit-swamp-host-'+uuid.uuid4().hex+'.service'
        started=time.monotonic()
        r=self.call({'action':'run','job':job,'tool':'node','cwd':'.','args':['-e',script],'files':{'package.json':'{}'}},unit=unit,deadline_ms=12000,wait=False)
        # Prove the package unit started with an actual RuntimeMaxSec deadline.
        package='orbit-package-'+job+'.service'
        deadline=None
        for _ in range(80):
            probe=root('/usr/bin/systemctl','show',package,'-p','RuntimeMaxUSec','-p','MainPID').stdout
            if 'MainPID=0' not in probe and 'RuntimeMaxUSec=' in probe:
                deadline=probe;break
            if r.poll() is not None:break
            time.sleep(.1)
        self.assertIsNotNone(deadline, r.communicate(timeout=5) if r.poll() is not None else 'package never started')
        detached=False
        for _ in range(60):
            probe=root('/usr/bin/python3','-I','-c',"import pathlib,sys;print(pathlib.Path(sys.argv[1]).read_text())",STATE/'jobs'/job/'root/work/package.json').stdout
            if 'detached-started' in probe:detached=True;break
            if r.poll() is not None:break
            time.sleep(.1)
        self.assertTrue(detached,'detached package code never started')
        stdout,stderr=r.communicate(timeout=25)
        self.assertLess(time.monotonic()-started,23)
        self.assertNotIn('RuntimeMaxUSec=infinity',deadline)
        # No manual stop: systemd's package deadline must do the killing.
        journal=root('/usr/bin/journalctl','--unit='+package,'--no-pager','-n','12','-o','cat').stdout
        self.assertIn('runtime time limit',journal.lower(),journal)
        events=Path('/sys/fs/cgroup/system.slice')/package/'cgroup.events'
        for _ in range(70):
            if not events.exists() or 'populated 0' in events.read_text():break
            time.sleep(.1)
        if events.exists():self.assertIn('populated 0',events.read_text())
        reused=self.run_node(job,"console.log('reused-after-timeout')")
        self.assertEqual(reused['code'],0,reused['stderr'])
        self.assertEqual(reused['stdout'].strip(),'reused-after-timeout')

    def test_native_uid_credentials_proc_sudo_environment_and_registry(self):
        job = self.job()
        paths = self.baseline

        script = '''const fs=require('fs'),cp=require('child_process'),https=require('https');
const paths=PATHS;const denied=paths.map(p=>{try{let fd=fs.openSync(p,'r');fs.closeSync(fd);return false}catch(e){return ['EACCES','EPERM','ENOENT'].includes(e.code)}});
const out=fs.openSync('/work/sudo.out','w'),err=fs.openSync('/work/sudo.err','w');
const sudo=cp.spawnSync('/opt/package-runtime/sudo',['-n','/usr/bin/python3','-I','-c','import os;os.close(os.open("ROOT_SOURCE",os.O_RDONLY))'],{stdio:['ignore',out,err]});fs.closeSync(out);fs.closeSync(err);
const sudoError=fs.readFileSync('/work/sudo.err','utf8');
const report={uid:process.getuid(),gid:process.getgid(),groups:process.getgroups(),denied,sudoDenied:!sudo.error&&Number.isInteger(sudo.status)&&sudo.status>0&&/no new privileges|not allowed|not in the sudoers|effective uid is not 0/i.test(sudoError),sudoStatus:sudo.status,sudoSpawnError:sudo.error?.code,sudoError,secretEnv:Object.keys(process.env).filter(k=>/SWAMP|TOKEN|GITHUB|GH_/.test(k))};
https.get('https://registry.npmjs.org/-/ping',r=>{report.registry=r.statusCode;r.resume();r.on('end',()=>console.log(JSON.stringify(report)))}).on('error',e=>{console.error(e.code);process.exitCode=1});
'''.replace('PATHS', json.dumps(paths)).replace('ROOT_SOURCE',str(self.source/'key'))
        result = self.run_node(job, script)
        self.assertEqual(result['code'], 0, result['stderr'])
        report = json.loads(result['stdout'])
        self.assertEqual(report['uid'], self.package_uid)
        self.assertNotEqual(report['uid'], os.getuid())
        self.assertTrue(all(report['denied']), report)
        self.assertTrue(report['sudoDenied'],report)
        self.assertEqual(report['secretEnv'], [])
        self.assertEqual(report['registry'], 200)
        self.assertTrue(all(g == report['gid'] for g in report['groups']))

    def test_host_wildcard_service_is_unreachable_but_public_registry_is_allowed(self):
        interfaces=json.loads(subprocess.check_output(['/usr/sbin/ip','-j','address','show'],text=True))
        addresses=[v['local'] for i in interfaces for v in i['addr_info'] if v['family']=='inet' and not v['local'].startswith('127.')]
        self.assertTrue(addresses,'native fixture requires a non-loopback host address')
        address=addresses[0]
        with socket.socket(socket.AF_INET,socket.SOCK_STREAM) as listener:
            listener.bind(('0.0.0.0',0));listener.listen(2)
            port=listener.getsockname()[1]
            with socket.create_connection((address,port),timeout=1):pass
            baseline,_=listener.accept();baseline.close()
            script="const s=require('net').connect({host:"+json.dumps(address)+",port:"+str(port)+"});s.setTimeout(1500);s.on('connect',()=>{console.log('connected');s.destroy()});s.on('timeout',()=>{console.log('denied:timeout');s.destroy()});s.on('error',e=>console.log('denied:'+e.code))"
            result=self.run_node(self.job(),script)
            self.assertEqual(result['code'],0,result['stderr'])
            self.assertRegex(result['stdout'].strip(),r'^denied:(timeout|EPERM|EACCES)$')
            listener.settimeout(.1)
            with self.assertRaises(socket.timeout):listener.accept()

    def test_php_cli_keeps_selected_extensions_without_pool_configuration(self):
        job=self.job()
        php="echo json_encode(['modules'=>get_loaded_extensions(),'phar'=>class_exists('Phar'),'config'=>php_ini_loaded_file(),'pools'=>glob('/etc/php/*/fpm')]);"
        expected=json.loads(subprocess.check_output(['/usr/bin/php','-r',php],text=True))
        self.assertTrue(expected['phar'],'fixture PHP must have Phar enabled')
        script="const r=require('child_process').spawnSync('/usr/bin/php',['-r',"+json.dumps(php)+"],{stdio:['ignore',1,2]});process.exitCode=r.status===null?99:r.status"
        result=self.run_node(job,script)
        self.assertEqual(result['code'],0,result['stderr'])
        report=json.loads(result['stdout'])
        self.assertEqual(sorted(report['modules']),sorted(expected['modules']))
        self.assertTrue(report['phar'])
        self.assertEqual(report['pools'],[])
        self.assertRegex(report['config'],r'^/etc/php/[^/]+/cli/php.ini$')

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
        root('/usr/bin/python3','-I','-c',"import json,pathlib,sys;[pathlib.Path(p).unlink() for p in json.loads(sys.argv[1])];[pathlib.Path(p).rmdir() for p in reversed(json.loads(sys.argv[2]))]",json.dumps(cls.fixture_files),json.dumps(cls.fixture_directories))
        cls.temp.cleanup()


if __name__ == '__main__': unittest.main()
