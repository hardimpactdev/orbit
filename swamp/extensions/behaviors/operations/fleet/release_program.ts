/** Fixed observer publication program; never invokes an agent action. */
export const ReleaseProgram = String
  .raw`import os,sys,json,re,stat,hashlib,tempfile,subprocess,urllib.request,urllib.parse,fcntl,shutil
BIN='/usr/local/bin'
LOCK='/run/lock/orbit-self-update.lock'
class Refusal(Exception):pass
def refuse(code):raise Refusal(code)
def digest(path):
 h=hashlib.sha256()
 with open(path,'rb') as f:
  for block in iter(lambda:f.read(1048576),b''):h.update(block)
 return h.hexdigest()
def owned(path,directory=False):
 s=os.lstat(path)
 if s.st_uid!=0 or s.st_mode&0o022 or not (stat.S_ISDIR(s.st_mode) if directory else stat.S_ISREG(s.st_mode)):refuse('agent.foreign_binary')
 return s
class HttpsOnly(urllib.request.HTTPRedirectHandler):
 def redirect_request(self,req,fp,code,msg,headers,newurl):
  if urllib.parse.urlsplit(newurl).scheme!='https':refuse('agent.download_failed')
  return super().redirect_request(req,fp,code,msg,headers,newurl)
def download(release,candidate):
 url=urllib.parse.urlsplit(release['url'])
 if url.scheme!='https' or url.username or url.password:refuse('agent.download_failed')
 if not re.fullmatch('[a-f0-9]{64}',release['sha256']):refuse('agent.checksum_mismatch')
 try:
  with urllib.request.build_opener(HttpsOnly()).open(release['url'],timeout=180) as response,open(candidate,'wb') as out:
   size=0
   while True:
    chunk=response.read(1048576)
    if not chunk:break
    size+=len(chunk)
    if size>134217728:refuse('agent.download_failed')
    out.write(chunk)
 except Refusal:raise
 except Exception:refuse('agent.download_failed')
 if digest(candidate)!=release['sha256']:refuse('agent.checksum_mismatch')
 with open(candidate,'rb') as f:
  if f.read(4)!=b'\x7fELF':refuse('agent.candidate_invalid')
 os.chmod(candidate,0o755)
 os.chown(candidate,0,0)
def candidate_for(directory):
 fd,path=tempfile.mkstemp(prefix='.orbit-release-',dir=directory);os.close(fd);return path
def service(*args):return subprocess.run(['/usr/bin/systemctl',*args],stdin=subprocess.DEVNULL,stdout=subprocess.PIPE,stderr=subprocess.DEVNULL,timeout=90)
def observer_digest():
 reply=service('show','--property=MainPID','--value','orbit-agent.service')
 pid=reply.stdout.decode().strip()
 if reply.returncode or not pid.isdigit() or int(pid)<=0:return None
 try:return digest('/proc/'+pid+'/exe')
 except OSError:return None
def install_agent(data):
 release=data['agent'];target=BIN+'/orbit-agent';old=None
 if os.path.lexists(target):owned(target);old=digest(target)
 changed=old!=release['sha256']
 if changed:
  candidate=candidate_for(BIN)
  try:
   download(release,candidate)
   if old:
    previous=BIN+'/orbit-agent.previous'
    if os.path.lexists(previous):owned(previous)
    shutil.copy2(target,previous)
   os.replace(candidate,target)
  finally:
   if os.path.lexists(candidate):os.unlink(candidate)
 restarted=observer_digest()!=release['sha256']
 if restarted:
  if service('restart','orbit-agent.service').returncode:refuse('agent.restart_failed')
 if observer_digest()!=release['sha256'] or service('is-active','--quiet','orbit-agent.service').returncode:refuse('agent.observer_unhealthy')
 return {'changed':changed or restarted,'version':release['version']}
def execute(data):
 for path in ['/usr','/usr/local',BIN]:owned(path,True)
 os.makedirs(os.path.dirname(LOCK),exist_ok=True)
 with open(LOCK,'a') as lock:
  try:fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
  except BlockingIOError:refuse('node.update_busy')
  return install_agent(data)
if __name__=='__main__':
 try:print(json.dumps(execute(json.load(sys.stdin))))
 except Refusal as error:print(json.dumps({'error_code':str(error)}))
 except Exception:print(json.dumps({'error_code':'fleet.release_apply_failed'}))
`;
