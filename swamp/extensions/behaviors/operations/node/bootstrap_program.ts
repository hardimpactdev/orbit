/** Code-owned bootstrap program. Input is data; stdout is one bounded result. */
export const BootstrapHostProgram = String.raw`
import fcntl,ipaddress,json,os,pathlib,pwd,re,signal,stat,subprocess,sys,tempfile,time,urllib.parse
class Refusal(Exception):
 def __init__(self,code):self.code=code
P=pathlib.Path
ENV={**os.environ,'LC_ALL':'C','DEBIAN_FRONTEND':'noninteractive'}
def run(argv,timeout=30,required=True):
 try:r=subprocess.run(argv,stdin=subprocess.DEVNULL,stdout=subprocess.PIPE,stderr=subprocess.DEVNULL,env=ENV,timeout=timeout)
 except Exception:raise Refusal('node.bootstrap_failed')
 if len(r.stdout)>65536 or required and r.returncode:raise Refusal('node.bootstrap_failed')
 return r.returncode,r.stdout.decode('utf8','strict')
def real(path,directory,required=True):
 try:s=os.lstat(path)
 except FileNotFoundError:
  if not required:return
  raise Refusal('node.bootstrap_unsafe')
 if not (stat.S_ISDIR(s.st_mode) if directory else stat.S_ISREG(s.st_mode)):raise Refusal('node.bootstrap_unsafe')
def account(user):
 try:a=pwd.getpwnam(user)
 except KeyError:return None
 if a.pw_uid==0 or not a.pw_dir.startswith('/') or a.pw_dir=='/':raise Refusal('node.bootstrap_invalid')
 real(a.pw_dir,True)
 for child in ['.ssh','.orbit']:real(P(a.pw_dir)/child,True,False)
 real(P(a.pw_dir)/'.ssh/authorized_keys',False,False)
 return a
def publish(path,data,mode,uid=0,gid=0):
 real(path,False,False);fd,name=tempfile.mkstemp(prefix='.orbit-bootstrap-',dir=P(path).parent)
 try:
  os.fchmod(fd,mode);os.fchown(fd,uid,gid)
  with os.fdopen(fd,'wb') as f:f.write(data);f.flush();os.fsync(f.fileno())
  os.replace(name,path)
 finally:
  if os.path.exists(name):os.unlink(name)
def resolves(hosts):
 return all(run(['/usr/bin/getent','ahosts',h],5,False)[0]==0 for h in hosts)
def sourcehosts(text):
 result=[]
 for line in text.splitlines():
  if not line.startswith("'"):continue
  uri=line[1:].split("'",1)[0];url=urllib.parse.urlsplit(uri)
  if url.scheme in ['file','copy','cdrom']:continue
  if url.scheme not in ['http','https','ftp'] or not url.hostname:raise Refusal('node.bootstrap_dns_refused')
  name=url.hostname
  if not re.fullmatch('[A-Za-z0-9][A-Za-z0-9.:-]*',name):raise Refusal('node.bootstrap_dns_refused')
  if name not in result:result.append(name)
 return result
def owned_dns():
 marker=P('/etc/wireguard/orbit.dns-link');real(marker.parent,True);real(marker,False)
 s=os.lstat(marker)
 if s.st_uid!=0 or stat.S_IMODE(s.st_mode)!=0o600:raise Refusal('node.bootstrap_dns_refused')
 lines=marker.read_text().splitlines()
 if len(lines)!=3 or lines[0]!='orbit' or lines[2]!='.':raise Refusal('node.bootstrap_dns_refused')
 try:ipaddress.ip_address(lines[1])
 except ValueError:raise Refusal('node.bootstrap_dns_refused')
 for prop,wanted in [('dns',lines[1]),('domain','~.')]:
  out=run(['/usr/bin/resolvectl',prop,'orbit'],10)[1].rstrip('\n')
  if not re.fullmatch(r'Link [0-9]+ \(orbit\): '+re.escape(wanted),out):raise Refusal('node.bootstrap_dns_refused')
 return lines[1]
def packages(i):
 options=['-o','Acquire::Retries=0','-o','Acquire::http::Timeout=15','-o','Acquire::https::Timeout=15']
 def update(seconds):return run(['/usr/bin/apt-get','-o','APT::Update::Error-Mode=any',*options,'update'],seconds,False)[0]==0
 suspended=None
 real('/run/lock',True)
 fd=os.open('/run/lock/orbit-wireguard-peer.lock',os.O_RDWR|os.O_CREAT|os.O_NOFOLLOW,0o600)
 try:
  until=time.monotonic()+30
  while True:
   try:fcntl.flock(fd,fcntl.LOCK_EX|fcntl.LOCK_NB);break
   except BlockingIOError:
    if time.monotonic()>=until:raise Refusal('node.bootstrap_failed')
    time.sleep(.1)
  try:
   if not update(30):
    hosts=sourcehosts(run(['/usr/bin/apt-get','--print-uris','update'],20)[1])
    if not resolves(hosts):
     if i['dns_policy']=='preserve':raise Refusal('node.bootstrap_dns_refused')
     suspended=owned_dns()
     for prop in ['dns','domain']:run(['/usr/bin/resolvectl',prop,'orbit',''],10)
     if not resolves(hosts):raise Refusal('node.bootstrap_dns_refused')
    if not update(180):raise Refusal('node.bootstrap_failed')
   run(['/usr/bin/apt-get',*options,'install','--yes','--no-install-recommends','--',*i['packages']],300)
  finally:
   if suspended is not None:
    for signum in [signal.SIGTERM,signal.SIGHUP,signal.SIGINT]:signal.signal(signum,signal.SIG_IGN)
    restored=True
    for prop,value in [('dns',suspended),('domain','~.')]:
     try:restored=run(['/usr/bin/resolvectl',prop,'orbit',value],10,False)[0]==0 and restored
     except Refusal:restored=False
    if not restored:raise Refusal('node.bootstrap_dns_recovery_failed')
 finally:os.close(fd)
def prepare(i):
 if set(i)!=set(['user','public_key','releases','packages','dns_policy','ssh_port','firewall']):raise Refusal('node.bootstrap_invalid')
 if not re.fullmatch('[a-z_][a-z0-9_-]{0,31}',i['user']) or i['user']=='root':raise Refusal('node.bootstrap_invalid')
 if not isinstance(i['public_key'],str) or not i['public_key'] or len(i['public_key'])>16384 or any(ord(c)<32 for c in i['public_key']):raise Refusal('node.bootstrap_invalid')
 if not i['packages'] or any(not re.fullmatch('[a-z0-9][a-z0-9+.-]*',p) for p in i['packages']):raise Refusal('node.bootstrap_invalid')
 if i['dns_policy'] not in ['managed','preserve']:raise Refusal('node.bootstrap_invalid')
 release={}
 for line in P('/etc/os-release').read_text().splitlines():
  if '=' in line:
   key,value=line.split('=',1);release[key]=value.strip('"')
 if release.get('ID')!='ubuntu' or release.get('VERSION_CODENAME') not in i['releases']:raise Refusal('node.bootstrap_os_unsupported')
 a=account(i['user']);packages(i)
 if a is None:run(['/usr/sbin/useradd','--create-home','--shell','/bin/bash','--',i['user']],30);a=account(i['user'])
 if a is None:raise Refusal('node.bootstrap_failed')
 real('/etc/sudoers.d',True);rule=P('/etc/sudoers.d')/i['user'];real(rule,False,False)
 sudo=run(['/usr/bin/sudo','-n','-u',i['user'],'--','/usr/bin/sudo','-n','true'],10,False)[0]==0
 candidate=None
 try:
  if not sudo:
   fd,candidate=tempfile.mkstemp(prefix='.orbit-bootstrap-',dir='/etc/sudoers.d');os.fchmod(fd,0o440)
   with os.fdopen(fd,'w') as out:out.write(i['user']+' ALL=(ALL) NOPASSWD:ALL\n');out.flush();os.fsync(out.fileno())
   run(['/usr/sbin/visudo','-cf',candidate],10)
  for child in ['.ssh','.orbit']:
   path=P(a.pw_dir)/child
   if not path.exists():os.mkdir(path,0o700);os.chown(path,a.pw_uid,a.pw_gid)
  keys=P(a.pw_dir)/'.ssh/authorized_keys';fd=os.open(keys,os.O_RDWR|os.O_APPEND|os.O_CREAT|os.O_NOFOLLOW,0o600)
  with os.fdopen(fd,'a+b') as out:
   os.fchmod(out.fileno(),0o600);os.fchown(out.fileno(),a.pw_uid,a.pw_gid);out.seek(0);text=out.read()
   if i['public_key'].encode() not in text.splitlines():out.write((b'\n' if text and not text.endswith(b'\n') else b'')+i['public_key'].encode()+b'\n');out.flush();os.fsync(out.fileno())
  if not sudo:publish(rule,(i['user']+' ALL=(ALL) NOPASSWD:ALL\n').encode(),0o440)
 finally:
  if candidate and os.path.exists(candidate):os.unlink(candidate)
 if i['firewall']:
  if not isinstance(i['ssh_port'],int) or not 0<i['ssh_port']<65536:raise Refusal('node.bootstrap_invalid')
  status=run(['/usr/sbin/ufw','status','numbered'],10)[1]
  owned=[line for line in status.splitlines() if '# orbit:public-ssh-recovery' in line]
  if any(not re.search(r'\b'+str(i['ssh_port'])+r'/tcp\b',line) for line in owned):raise Refusal('firewall.rule_collision')
  if not owned:run(['/usr/sbin/ufw','allow','proto','tcp','from','any','to','any','port',str(i['ssh_port']),'comment','orbit:public-ssh-recovery'],15)
  run(['/usr/sbin/ufw','--force','enable'],15)
 return {'prepared':True}
if __name__=='__main__':
 def interrupted(signum,frame):raise Refusal('node.bootstrap_failed')
 for signum in [signal.SIGTERM,signal.SIGHUP,signal.SIGINT]:signal.signal(signum,interrupted)
 try:result=prepare(json.load(sys.stdin))
 except Refusal as e:result={'error_code':e.code}
 except Exception:result={'error_code':'node.bootstrap_failed'}
 print(json.dumps(result,separators=(',',':')))
`;

/** Atomic observer delivery only; no orbit-agent action is invoked. */
export const BootstrapInstallProgram = String.raw`
import fcntl,hashlib,json,os,pathlib,stat,sys,tempfile,time
P=pathlib.Path
def real(path,directory):
 s=os.lstat(path)
 if not (stat.S_ISDIR(s.st_mode) if directory else stat.S_ISREG(s.st_mode)) or s.st_uid!=0:raise ValueError()
 if s.st_mode&0o022 and not (directory and str(path)=='/run/lock' and s.st_mode&stat.S_ISVTX):raise ValueError()
i=json.load(sys.stdin)
try:
 if set(i)!=set(['candidate','sha256']) or len(i['sha256'])!=64 or any(c not in '0123456789abcdef' for c in i['sha256']):raise ValueError()
 for path in ['/usr','/usr/local','/usr/local/bin','/run/lock']:real(path,True)
 source=P(i['candidate']);s=os.lstat(source)
 if not stat.S_ISREG(s.st_mode) or stat.S_ISLNK(s.st_mode):raise ValueError()
 with source.open('rb') as f:
  if hashlib.file_digest(f,'sha256').hexdigest()!=i['sha256']:raise ValueError()
 lock=os.open('/run/lock/orbit-self-update.lock',os.O_RDWR|os.O_CREAT|os.O_NOFOLLOW,0o600)
 try:
  s=os.fstat(lock)
  if not stat.S_ISREG(s.st_mode) or s.st_uid!=0 or s.st_mode&0o022:raise ValueError()
  until=time.monotonic()+120
  while True:
   try:fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB);break
   except BlockingIOError:
    if time.monotonic()>until:raise ValueError()
    time.sleep(.1)
  target=P('/usr/local/bin/orbit-agent');changed=True
  try:
   real(target,False)
   with target.open('rb') as f:changed=hashlib.file_digest(f,'sha256').hexdigest()!=i['sha256']
  except FileNotFoundError:pass
  if changed:
   fd,tmp=tempfile.mkstemp(prefix='.orbit-observer-',dir=target.parent)
   try:
    os.fchmod(fd,0o755);os.fchown(fd,0,0)
    with os.fdopen(fd,'wb') as out,source.open('rb') as inp:
     while chunk:=inp.read(65536):out.write(chunk)
     out.flush();os.fsync(out.fileno())
    with open(tmp,'rb') as f:
     if hashlib.file_digest(f,'sha256').hexdigest()!=i['sha256']:raise ValueError()
    os.replace(tmp,target)
   finally:
    if os.path.exists(tmp):os.unlink(tmp)
 finally:os.close(lock)
 result={'replaced':changed,'sha256':i['sha256']}
except Exception:result={'error_code':'node.bootstrap_install_failed'}
print(json.dumps(result,separators=(',',':')))
`;
