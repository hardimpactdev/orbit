/** Fixed Documents startup gate. No PHP, agent action, provider access or cleanup authorization. */
export const cleanupProgram = String.raw`
import fcntl,json,os,pathlib,pwd,re,secrets,stat,sys,tempfile
ROOT=pathlib.Path('/run/orbit')
ROOT_UID=0
ROOT_GID=0
class Refused(Exception):pass
def refuse():raise Refused()
def meta(path):
 try:return path.lstat()
 except FileNotFoundError:return None
def checked(path,directory,uid,gid,mode):
 s=meta(path)
 if s is None or not (stat.S_ISDIR(s.st_mode) if directory else stat.S_ISREG(s.st_mode)) or s.st_uid!=uid or s.st_gid!=gid or stat.S_IMODE(s.st_mode)!=mode or (not directory and s.st_nlink!=1):refuse()
 return s
def state(path,uid,gid):
 s=checked(path,False,uid,gid,0o600)
 if s.st_size>8192:refuse()
 v=json.loads(path.read_bytes())
 if not isinstance(v,dict) or set(v)!={'schema_version','generation','report_id','report_sha256','lock_device','lock_inode'} or type(v['schema_version']) is not int or v['schema_version']!=1:refuse()
 if type(v['lock_device']) is not int or v['lock_device']<0 or type(v['lock_inode']) is not int or v['lock_inode']<=0:refuse()
 for k in ['generation','report_id','report_sha256']:
  if v[k] is None and k!='generation':continue
  if not isinstance(v[k],str) or not re.fullmatch('[a-f0-9]{64}',v[k]):refuse()
 if (v['report_id'] is None)!=(v['report_sha256'] is None):refuse()
 return v
def prepare():
 account=pwd.getpwnam('orbit');uid=account.pw_uid;gid=account.pw_gid
 if uid==0:refuse()
 for parent in [ROOT,*ROOT.parents]:
  s=meta(parent)
  if s is None:
   if parent!=ROOT:refuse()
   continue
  if not stat.S_ISDIR(s.st_mode):refuse()
  if parent==ROOT:
   if s.st_uid!=ROOT_UID or s.st_gid!=ROOT_GID or s.st_mode&0o022:refuse()
  elif s.st_uid not in (0,ROOT_UID) or (s.st_mode&0o022 and not s.st_mode&stat.S_ISVTX):refuse()
 if meta(ROOT) is None:
  ROOT.mkdir(mode=0o755);os.chown(ROOT,ROOT_UID,ROOT_GID);os.chmod(ROOT,0o755)
 directory=ROOT/'project-documents';created=False
 if meta(directory) is None:
  directory.mkdir(mode=0o700);os.chown(directory,uid,gid);created=True
 checked(directory,True,uid,gid,0o700)
 lock=directory/'execution.lock'
 if meta(lock) is None:
  if not created:refuse()
  fd=os.open(lock,os.O_CREAT|os.O_EXCL|os.O_WRONLY|os.O_NOFOLLOW,0o600)
  try:os.fchown(fd,uid,gid)
  finally:os.close(fd)
 before=checked(lock,False,uid,gid,0o600)
 fd=os.open(lock,os.O_RDWR|os.O_NOFOLLOW)
 with os.fdopen(fd,'r+b') as handle:
  fcntl.flock(handle,fcntl.LOCK_EX)
  after=checked(lock,False,uid,gid,0o600);opened=os.fstat(handle.fileno())
  if (before.st_dev,before.st_ino)!=(after.st_dev,after.st_ino) or (opened.st_dev,opened.st_ino)!=(after.st_dev,after.st_ino):refuse()
  checked(directory,True,uid,gid,0o700)
  generation=directory/'generation.json';permit=directory/'permit.json'
  if meta(generation) is not None:
   old=state(generation,uid,gid)
   if (old['lock_device'],old['lock_inode'])!=(after.st_dev,after.st_ino):refuse()
  if meta(permit) is not None:checked(permit,False,uid,gid,0o600);permit.unlink()
  if meta(generation) is not None:generation.unlink()
  value={'schema_version':1,'generation':secrets.token_hex(32),'report_id':None,'report_sha256':None,'lock_device':after.st_dev,'lock_inode':after.st_ino}
  fd,candidate=tempfile.mkstemp(prefix='.generation-',dir=directory)
  try:
   with os.fdopen(fd,'wb') as out:
    os.fchown(out.fileno(),uid,gid);os.fchmod(out.fileno(),0o600);out.write(json.dumps(value,separators=(',',':')).encode());out.flush();os.fsync(out.fileno())
   os.replace(candidate,generation)
  finally:pathlib.Path(candidate).unlink(missing_ok=True)
  return {'generation':value['generation']}
if __name__=='__main__':
 try:print(json.dumps(prepare()))
 except Exception:print(json.dumps({'error_code':'project_documents.cleanup_state_unavailable'}))
`;
