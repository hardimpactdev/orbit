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

def package(manager,value):
 patterns={'apt':r'(?=.{2,128}\Z)(?=.*[a-z])[a-z0-9][a-z0-9+.-]*','composer':r'(?=.{1,255}\Z)[a-z0-9](?:[a-z0-9_.-]*[a-z0-9])?/[a-z0-9](?:[a-z0-9_.-]*[a-z0-9])?','vp':r'(?=.{1,214}\Z)(?:[a-z0-9][a-z0-9._~-]*|@[a-z0-9][a-z0-9._~-]*/[a-z0-9][a-z0-9._~-]*)','brew':r'(?=.{1,255}\Z)[a-z0-9](?:[a-z0-9@+._-]*[a-z0-9])?\+*'}
 if not isinstance(value,str) or not re.fullmatch(patterns['brew' if manager=='brew-cask' else manager],value):refuse('tool.package_invalid')
 return value

def first(text):
 value=text.splitlines()[0] if text else ''
 if not safe(value):refuse('tool.output_invalid')
 return value

def apt(host,verb,data):
 if verb=='version':return {'version':first(host.run(['/usr/bin/apt-get','--version'])[1])}
 if verb=='inventory':
  text=host.run(['/usr/bin/dpkg-query','--show','--showformat=$'+'{Package}\\t$'+'{Status}\\t$'+'{Version}\\n'])[1];packages=[]
  absent={'deinstall ok config-files','deinstall ok not-installed','purge ok config-files','purge ok not-installed','unknown ok not-installed'}
  for line in text.split('\n'):
   if not line:continue
   fields=line.split('\t')
   if len(fields)!=3:refuse('tool.output_invalid')
   name,status,value=fields
   try:package('apt',name)
   except Refusal:continue
   if status not in absent:packages.append({'name':name,'version':value if status=='install ok installed' and safe(value) else None})
  return {'packages':packages}
 name=package('apt',data.get('package'))
 if verb=='candidate':
  rows=[line.lstrip()[10:] for line in host.run(['/usr/bin/apt-cache','policy','--',name])[1].splitlines() if line.lstrip().startswith('Candidate:')]
  if len(rows)!=1 or not rows[0].startswith((' ','\t')) or not version(rows[0].strip()):refuse('tool.output_invalid')
  return {'version':None if rows[0].strip()=='(none)' else rows[0].strip()}
 host.run(['/usr/bin/apt-get','-o','DPkg::Lock::Timeout=300','install','--yes','--no-install-recommends','--',name],root=True,env={'DEBIAN_FRONTEND':'noninteractive'});return {}

COMPOSER_FLAGS=['--no-interaction','--no-ansi','--no-progress','--no-audit','--with-all-dependencies']
def composer(host,verb,data):
 def call(args,check=True):return host.run(['/usr/bin/composer',*args],env={'COMPOSER_HOME':'/opt/orbit/composer'},check=check)
 if verb=='version':return {'version':first(call(['--version','--no-ansi'])[1])}
 if verb=='inventory':
  value=json_(call(['global','show','--format=json','--no-ansi'])[1])
  if value==[]:return {'packages':[]}
  entries=object_(value).get('installed')
  if not isinstance(entries,list):refuse('tool.output_invalid')
  packages=[]
  for entry in entries:
   entry=object_(entry)
   try:name=package('composer',entry.get('name'))
   except Refusal:continue
   packages.append({'name':name,'version':entry.get('version') if version(entry.get('version')) else None})
  return {'packages':packages}
 name=package('composer',data.get('package'));op={'install':'require','update':'update','candidate':'require' if data.get('operation')=='install' else 'update'}[verb]
 target=name+':*' if op=='require' else name;args=['global',op,target]
 if verb=='candidate':args+=['--dry-run']
 code,out,err=call(args+COMPOSER_FLAGS,check=False)
 if len(out.encode())>65536 or len(err.encode())>65536:refuse('tool.output_invalid')
 text=out+'\n'+err
 if code:
  missing='Could not find a matching version of package '+name+'. Check the package spelling, your version constraint and that the package is available in a stability which matches your minimum-stability'
  if verb=='candidate' and len(text)<=32768 and missing in ' '.join(text.split()):return {'version':None}
  refuse('tool.command_failed')
 if verb!='candidate':return {}
 if not text.strip() or len(text)>32768:refuse('tool.output_invalid')
 found=[];operations=0;noop=0
 for line in text.splitlines():
  if line=='Nothing to install, update or remove':noop+=1;continue
  match=re.fullmatch(r'\s*-\s+(Locking|Installing)\s+(\S+)\s+\(([^\s()]+)\)\s*',line) or re.fullmatch(r'\s*-\s+(Upgrading|Downgrading)\s+(\S+)\s+\([^\s()]+\s+=>\s+([^\s()]+)\)\s*',line)
  if match:
   operations+=1
   if match[2]==name:found.append((match[1],match[3]))
  elif re.match(r'\s*-\s+(Locking|Installing|Upgrading|Downgrading|Removing)(?:\s|$)',line):refuse('tool.output_invalid')
 if not noop and (len(found)==1 or len(found)==2 and found[0][0]=='Locking' and found[1][0]=='Installing' and found[0][1]==found[1][1]) and version(found[-1][1]):return {'version':found[-1][1]}
 if noop==1 and operations==0:
  entries=[p for p in composer(host,'inventory',{})['packages'] if p['name']==name]
  if len(entries)==1 and version(entries[0]['version']):return {'version':entries[0]['version']}
 refuse('tool.output_invalid')

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
 def call(args):return host.run([str(scope/'bin/vp'),*args],env={'VP_HOME':str(scope)})[1]
 if verb=='scope':return {'binary':str(scope/'bin/vp')}
 if verb=='version':return {'version':first(call(['--version']))}
 if verb=='inventory':
  entries=json_(call(['list','-g','--json']))
  if not isinstance(entries,list):refuse('tool.output_invalid')
  packages=[];seen=set();complete=True
  for entry in entries:
   entry=object_(entry);name=entry.get('name');value=entry.get('version')
   if not isinstance(name,str):refuse('tool.output_invalid')
   value=value if safe(value) else None;complete=complete and safe(name) and value is not None and name not in seen;seen.add(name);packages.append({'name':name,'version':value})
  return {'packages':packages,'scan_complete':complete}
 name=package('vp',data.get('package'))
 if verb=='candidate':
  value=json_(call(['info',name,'version','--json']))
  if not safe(value):refuse('tool.output_invalid')
  return {'version':value}
 if name=='pnpm':refuse('tool.vp_package_protected')
 argv={'install':['install','-g',name,'--node','lts'],'update':['update','-g',name,'--reinstall-node-mismatch']}[verb]
 call(argv);return {}
`;
