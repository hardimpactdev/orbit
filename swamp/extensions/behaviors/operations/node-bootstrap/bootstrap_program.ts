/** Retained observer delivery program used by the role parent. */
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
