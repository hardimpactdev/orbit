/** Workflow-owned filesystem program. Its only input is the admitted environment action. */
export const environmentProgram = String.raw`
import base64,json,os,pwd,re,secrets,stat,subprocess,sys
class Refused(Exception): pass
class Tracking(Exception): pass
def check(ok):
 if not ok: raise Refused()
def identity(s): return (s.st_dev,s.st_ino)
def openbase(base):
 check(isinstance(base,str) and base.startswith('/') and base!='/' and all(p and p not in ('.','..') for p in base[1:].split('/')))
 fd=os.open('/',os.O_RDONLY|os.O_DIRECTORY|os.O_CLOEXEC)
 try:
  for segment in base[1:].split('/'):
   nxt=os.open(segment,os.O_RDONLY|os.O_DIRECTORY|os.O_NOFOLLOW|os.O_CLOEXEC,dir_fd=fd);os.close(fd);fd=nxt
  return fd
 except: os.close(fd);raise Refused()
class Access:
 def __init__(self,data):
  self.data=data;self.base=data['base'];self.maximum=data['maximum'];self.file=data.get('file','.env')
  check(type(self.maximum)==int and 0<=self.maximum<=1048576 and self.file in ('.env','.env.testing'))
  check(re.fullmatch('[a-z_][a-z0-9_-]{0,31}',data['user']) is not None and data['user']!='root')
  self.account=pwd.getpwnam(data['user']);check(self.account.pw_uid!=0)
  check(not data.get('require_home',False) or self.account.pw_dir==self.base)
  self.directory=openbase(self.base);self.dirinfo=os.fstat(self.directory);self.previous=self.destination();self.installed=False
 def close(self): os.close(self.directory)
 def destination(self):
  try: info=os.stat(self.file,dir_fd=self.directory,follow_symlinks=False)
  except FileNotFoundError:return None
  check(stat.S_ISREG(info.st_mode) and info.st_uid==self.account.pw_uid)
  return info
 def read(self):
  if self.previous is None:return None
  check(self.previous.st_size<=self.maximum)
  fd=os.open(self.file,os.O_RDONLY|os.O_NONBLOCK|os.O_NOFOLLOW|os.O_CLOEXEC,dir_fd=self.directory)
  try:
   check(identity(os.fstat(fd))==identity(self.previous))
   with os.fdopen(fd,'rb',closefd=False) as file: value=file.read(self.maximum+1)
   check(len(value)<=self.maximum);return value
  finally:os.close(fd)
 def unchanged(self):
  fresh=openbase(self.base)
  try:
   check(identity(os.fstat(fresh))==identity(self.dirinfo))
   try: current=os.stat(self.file,dir_fd=fresh,follow_symlinks=False)
   except FileNotFoundError:current=None
   check((identity(current) if current else None)==(identity(self.previous) if self.previous else None))
  finally:os.close(fresh)
 def writable(self):
  check(self.dirinfo.st_uid==self.account.pw_uid and self.dirinfo.st_mode&0o300==0o300)
  disk=os.fstatvfs(self.directory)
  check(not disk.f_flag&os.ST_RDONLY and disk.f_bavail*disk.f_frsize>=self.data.get('required_capacity',0))
 def tracked(self):
  def drop():
   if os.geteuid()==0:
    os.initgroups(self.account.pw_name,self.account.pw_gid);os.setgid(self.account.pw_gid);os.setuid(self.account.pw_uid)
   else:check(os.geteuid()==self.account.pw_uid)
  def git(args):return subprocess.run(['/usr/bin/git','-c','core.hooksPath=/dev/null','-c','core.fsmonitor=false','-C',self.base]+args,stdin=subprocess.DEVNULL,stdout=subprocess.PIPE,stderr=subprocess.PIPE,env={'PATH':'/usr/bin:/bin','HOME':self.account.pw_dir,'LC_ALL':'C','GIT_OPTIONAL_LOCKS':'0'},preexec_fn=drop,timeout=30)
  try:
   inside=git(['rev-parse','--is-inside-work-tree'])
   if inside.returncode==128 and inside.stderr.split(b'\n')[0].lower().startswith(b'fatal: not a git repository (or any '):return False
   if inside.returncode!=0 or inside.stdout!=b'true\n':raise Tracking()
   listed=git(['ls-files','--error-unmatch','--',self.file])
   if listed.returncode not in (0,1):raise Tracking()
   return listed.returncode==0
  except Tracking:raise
  except:raise Tracking()
 def publish(self,payload):
  check(len(payload)<=self.maximum)
  existing=self.read()
  if self.previous is not None and self.previous.st_mode&0o7777==0o600 and existing==payload:
   self.unchanged();return {'state':'unchanged','installed':False}
  name='.env.orbit-'+secrets.token_hex(16)
  fd=os.open(name,os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW|os.O_CLOEXEC,0o600,dir_fd=self.directory)
  temporary=identity(os.fstat(fd))
  try:
   os.fchown(fd,self.account.pw_uid,self.account.pw_gid);os.fchmod(fd,0o600)
   with os.fdopen(fd,'wb',closefd=False) as file:file.write(payload);file.flush();os.fsync(fd)
   self.unchanged();os.rename(name,self.file,src_dir_fd=self.directory,dst_dir_fd=self.directory);self.installed=True;os.fsync(self.directory)
   return {'state':'changed','installed':True}
  finally:
   os.close(fd)
   try:
    if identity(os.stat(name,dir_fd=self.directory,follow_symlinks=False))==temporary:os.unlink(name,dir_fd=self.directory)
   except FileNotFoundError:pass
 def perform(self):
  mode=self.data.get('mode','inspect');check(mode in ('inspect','read','write','merge'))
  if mode=='read':
   value=self.read()
   return {'state':'missing','installed':False} if value is None else {'state':'ok','content':base64.b64encode(value).decode('ascii'),'installed':False}
  self.writable()
  if mode=='inspect':
   self.unchanged();return {'preflight':{'state':'ok'},'existing':self.perform_read()}
  supplied=self.data['content'].encode('utf8');check(len(supplied)<=self.maximum)
  if mode=='merge':
   keys=self.data['managed_keys'];check(self.file=='.env.testing' and keys and all(re.fullmatch('[A-Z][A-Z0-9_]*',key) for key in keys))
   if self.tracked():self.unchanged();return {'state':'tracked','installed':False}
   updates={}
   for line in supplied.splitlines(keepends=True):
    check(line.endswith(b'\n'));key=line.split(b'=')[0].decode('ascii',errors='replace')
    if key in keys:check(key not in updates);updates[key]=line
   check(updates)
   existing=self.read()
   if existing is not None:
    payload=[];written=set()
    for line in existing.splitlines(keepends=True):
     match=re.match(rb'[ \t]*(?:export[ \t]+)?([A-Za-z_][A-Za-z0-9_.]*)[ \t]*=',line)
     key=match[1].decode('ascii') if match else None
     if key in keys:
      if key in updates and key not in written:payload.append(updates[key]);written.add(key)
     else:payload.append(line)
    missing=[line for key,line in updates.items() if key not in written]
    if missing and payload and not payload[-1].endswith(b'\n'):payload[-1]+=b'\n'
    supplied=b''.join(payload+missing)
  return self.publish(supplied)
 def perform_read(self):
  value=self.read()
  return {'state':'missing','installed':False} if value is None else {'state':'ok','content':base64.b64encode(value).decode('ascii'),'installed':False}
def main():
 access=None
 try:
  data=json.load(sys.stdin);access=Access(data);result=access.perform()
 except Tracking:result={'state':'tracking_unknown','installed':False}
 except Refused:result={'state':'refused' if access is None or not access.installed else 'failed','installed':bool(access and access.installed)}
 except:result={'state':'failed','installed':bool(access and access.installed)}
 finally:
  if access is not None:access.close()
 print(json.dumps(result,separators=(',',':')))
main()
`;
