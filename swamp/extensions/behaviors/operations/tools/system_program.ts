/** Fixed native Tools implementation. Only typed, admitted data enters stdin. */
export const toolSystemProgram = String.raw`
import hashlib,json,os,pwd,re,selectors,signal,stat,subprocess,sys,tarfile,tempfile,time,urllib.request,urllib.parse,shutil
from pathlib import Path
LIMIT=4*1024*1024
def interrupted(*_):raise Refusal('tool.action_unknown')
signal.signal(signal.SIGTERM,interrupted)
class Refusal(Exception):
 def __init__(self,code): self.code=code

def refuse(code): raise Refusal(code)
def safe(value): return isinstance(value,str) and 0<len(value.encode())<=255 and not re.search(r'[\x00-\x1f\x7f]',value)
def version(value): return safe(value) and not re.search(r'\s',value)
def object_(value):
 if not isinstance(value,dict): refuse('tool.output_invalid')
 return value

def json_(text):
 try:return json.loads(text)
 except (ValueError,RecursionError):refuse('tool.output_invalid')

def metadata(path):
 try:return Path(path).lstat()
 except (FileNotFoundError,NotADirectoryError):return None

def owned(path,uid,gid=None,directory=True,missing=False):
 s=metadata(path)
 if s is None:
  if missing:return False
  refuse('tool.scope_absent')
 if (not stat.S_ISDIR(s.st_mode) if directory else not stat.S_ISREG(s.st_mode)) or s.st_uid!=uid or (gid is not None and s.st_gid!=gid):refuse('tool.scope_conflict')
 return True

def read(path,uid,gid=None,limit=1048576):
 fd=os.open(path,os.O_RDONLY|os.O_NOFOLLOW|os.O_NONBLOCK)
 with os.fdopen(fd,'rb') as f:
  s=os.fstat(f.fileno())
  if not stat.S_ISREG(s.st_mode) or s.st_uid!=uid or (gid is not None and s.st_gid!=gid):refuse('tool.scope_conflict')
  data=f.read(limit+1)
  if len(data)>limit:refuse('tool.output_invalid')
  return data

def directory(path,uid,gid,mode=0o755):
 path=Path(path)
 if not owned(path,uid,gid,missing=True):path.mkdir(mode=mode);os.chown(path,uid,gid)
 os.chmod(path,mode)

def publish(path,data,uid,gid,mode=0o755):
 path=Path(path);previous=metadata(path)
 if previous and (not stat.S_ISREG(previous.st_mode) or previous.st_uid!=uid or previous.st_gid!=gid):refuse('tool.scope_conflict')
 fd,temporary=tempfile.mkstemp(prefix='.orbit-tool-',dir=path.parent)
 try:
  with os.fdopen(fd,'wb') as out:out.write(data);os.fchmod(out.fileno(),mode);os.fchown(out.fileno(),uid,gid)
  os.replace(temporary,path)
 finally:
  if os.path.exists(temporary):os.unlink(temporary)

def link(path,target,account):
 path=Path(path);s=metadata(path)
 if s and (not stat.S_ISLNK(s.st_mode) or s.st_uid!=account.pw_uid):refuse('tool.scope_conflict')
 temporary=path.parent/('.orbit-tool-'+os.urandom(8).hex())
 try:
  temporary.symlink_to(target);os.lchown(temporary,account.pw_uid,account.pw_gid);os.replace(temporary,path)
 finally:
  if temporary.is_symlink():temporary.unlink()

class Host:
 def __init__(self,node):
  self.node=node;self.account=pwd.getpwnam(node['user']);self.end=time.monotonic()+900
  if self.account.pw_uid==0 or self.account.pw_gid==0 or os.geteuid() not in (0,self.account.pw_uid):refuse('tool.account_invalid')
  self.home=self.account.pw_dir
  if not re.fullmatch(r'/[A-Za-z0-9/._-]+',self.home) or '..' in self.home or self.home=='/':refuse('tool.account_invalid')
  self.env={'HOME':self.home,'USER':self.account.pw_name,'LOGNAME':self.account.pw_name,'PATH':'/usr/local/bin:/usr/bin:/bin','LC_ALL':'C'}
 def run(self,argv,root=False,env=None,check=True):
  if root and os.geteuid()!=0:refuse('tool.privilege_invalid')
  options={}
  if os.geteuid()==0 and not root:options={'user':self.account.pw_uid,'group':self.account.pw_gid,'extra_groups':os.getgrouplist(self.account.pw_name,self.account.pw_gid)}
  child=subprocess.Popen(argv,stdin=subprocess.DEVNULL,stdout=subprocess.PIPE,stderr=subprocess.PIPE,cwd=self.home,env={**self.env,**(env or {})},start_new_session=True,**options)
  output=[bytearray(),bytearray()];watch=selectors.DefaultSelector()
  for index,pipe in enumerate((child.stdout,child.stderr)):watch.register(pipe,selectors.EVENT_READ,index)
  try:
   while watch.get_map():
    remaining=self.end-time.monotonic()
    if remaining<=0:refuse('tool.action_unknown')
    for key,_ in watch.select(min(remaining,1)):
     data=os.read(key.fd,65536)
     if not data:watch.unregister(key.fileobj);key.fileobj.close();continue
     output[key.data].extend(data)
     if len(output[key.data])>LIMIT:refuse('tool.output_invalid')
   code=child.wait(timeout=max(0.01,self.end-time.monotonic()))
  finally:
   watch.close()
   if child.poll() is None:
    os.killpg(child.pid,signal.SIGKILL);child.wait()
  try:out,err=(bytes(value).decode('utf-8') for value in output)
  except UnicodeError:refuse('tool.output_invalid')
  if check and code:refuse('tool.command_failed')
  return code,out,err

def first(text):
 value=text.splitlines()[0] if text else ''
 if not safe(value):refuse('tool.output_invalid')
 return value

def apt(host,verb,data):
 if verb!='version':refuse('tool.input_invalid')
 return {'version':first(host.run(['/usr/bin/apt-get','--version'])[1])}

def composer(host,verb,data):
 if verb!='version':refuse('tool.input_invalid')
 return {'version':first(host.run(['/usr/bin/composer','--version','--no-ansi'],env={'COMPOSER_HOME':'/opt/orbit/composer'})[1])}

def vp_scope(host,missing=False):
 candidates=([Path('/opt/orbit/vite-plus')] if host.node['platform']=='linux' else [])+[Path(host.home)/'.vite-plus',Path(host.home)/'.local/share/vite-plus'];found=[]
 if host.node['platform']=='linux' and metadata('/opt/orbit'):owned('/opt/orbit',0,0)
 for path in candidates:
  if not metadata(path):continue
  owned(path,host.account.pw_uid,None if host.node['platform']=='macos' else host.account.pw_gid)
  binary=path/'bin/vp'
  if not metadata(binary):continue
  s=binary.lstat()
  if s.st_uid!=host.account.pw_uid or (host.node['platform']=='linux' and s.st_gid!=host.account.pw_gid) or not os.access(binary,os.X_OK):refuse('tool.vp_scope_conflict')
  found.append(path)
  if host.node['platform']=='linux':break
 if len(found)>1:refuse('tool.vp_scope_conflict')
 if not found:
  if missing:return candidates[-1]
  refuse('tool.vp_scope_absent')
 return found[0]

def vp(host,verb,data):
 scope=vp_scope(host)
 if verb=='scope':return {'binary':str(scope/'bin/vp')}
 if verb!='version':refuse('tool.input_invalid')
 return {'version':first(host.run([str(scope/'bin/vp'),'--version'],env={'VP_HOME':str(scope)})[1])}
`;
