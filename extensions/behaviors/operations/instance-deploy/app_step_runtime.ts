/** Trusted workflow code executed once as the app user, never as a service or broker. */
export const appStepRuntime = String.raw`
import ctypes,fcntl,hashlib,json,os,pwd,re,selectors,signal,subprocess,sys,time
from pathlib import Path
LIMIT=2048
cancelled=False
def cancel(*_):
 global cancelled
 cancelled=True
signal.signal(signal.SIGTERM,cancel)
signal.signal(signal.SIGINT,cancel)
CODE='instance.step_not_admitted'
def refuse(code=CODE):
 print(json.dumps({'error_code':code}),flush=True)
 sys.exit(0)
def directory(parent,name,uid,gid):
 fd=os.open(name,os.O_RDONLY|os.O_DIRECTORY|os.O_NOFOLLOW|os.O_CLOEXEC,dir_fd=parent)
 st=os.fstat(fd)
 if st.st_uid!=uid or st.st_gid!=gid or st.st_mode&0o022:os.close(fd);raise ValueError()
 return fd
try:
 raw=sys.stdin.buffer.read(2*1024*1024+1)
 if len(raw)>2*1024*1024:refuse()
 value=json.loads(raw)
 if set(value)-{'vp_environment'}!={'project_id','target','command_digest','timeout_seconds','script'}:refuse()
 project=value['project_id'];target=value['target'];script=value['script'];seconds=value['timeout_seconds']
 if type(project)!=int or project<1 or type(seconds)!=int or not 1<=seconds<=3600:refuse()
 if not isinstance(target,dict):refuse()
 if not isinstance(script,str) or not 1<=len(script.encode())<=16384 or '\0' in script:refuse()
 if hashlib.sha256(script.encode()).hexdigest()!=value['command_digest']:refuse()
 if script=='@orbit/ci-caches':script='# The task workspace\'s @orbit/ci-caches setup step: import the Pint and PHPStan caches of only the recorded code baseline\'s ref from the Gateway mirror.\n# Missing or invalid refs leave checks cold. Required tooling and cache writes remain setup failures.\nset -eu\nif [ ! -f bin/ci-cache-ref ]; then\n    echo \'CI cache import tooling is missing from this checkout.\' >&2\n    exit 1\nfi\nbranch=$(git symbolic-ref --quiet --short HEAD || true)\ndefault=$(git symbolic-ref --quiet --short refs/remotes/origin/HEAD || true)\nif [ "origin/$branch" = "$default" ]; then\n    commit=$(git rev-parse --verify HEAD)\nelse\n    commit=$(git rev-parse --verify --quiet refs/worktree/orbit/ci-baseline || true)\n    # Another branch at a main commit, such as a fresh agent branch, uses that commit\'s own caches.\n    if [ -z "$commit" ] && [ -n "$default" ] && git merge-base --is-ancestor HEAD "$default" 2>/dev/null; then\n        commit=$(git rev-parse --verify HEAD)\n    fi\nfi\nif [ -z "$commit" ]; then\n    echo \'CI caches cold: no recorded code baseline.\'\nelse\n    python3 bin/ci-cache-ref import --commit "$commit"\nfi\n'
 variant=target.get('variant')
 if variant=='release':
  if set(target)!={'variant','release'} or not isinstance(target['release'],str) or not re.fullmatch(r'[A-Za-z0-9][A-Za-z0-9._-]{0,127}',target['release']):refuse()
  username='orbit-app-'+str(project)
 elif variant=='checkout':
  if set(target)!={'variant','user','directory','seed_path','seed_commit'} or not re.fullmatch(r'[a-z_][a-z0-9_-]{0,63}',target['user']) or target['user']=='root':refuse()
  username=target['user']
 else:refuse()
 account=pwd.getpwnam(username);uid=account.pw_uid;gid=account.pw_gid
 if not uid or not gid or os.getresuid()!=(uid,uid,uid) or os.getresgid()!=(gid,gid,gid):refuse('instance.step_layout_invalid')
 home=account.pw_dir;opened=[]
 if variant=='release':
  if home!='/home/'+username:refuse('instance.step_layout_invalid')
  root=os.open('/home',os.O_RDONLY|os.O_DIRECTORY|os.O_NOFOLLOW|os.O_CLOEXEC);opened.append(root)
  st=os.fstat(root)
  if st.st_uid!=0 or st.st_mode&0o022:refuse('instance.step_layout_invalid')
  homefd=directory(root,username,uid,gid);opened.append(homefd)
  releases=directory(homefd,'releases',uid,gid);opened.append(releases)
  cwd=directory(releases,target['release'],uid,gid);opened.append(cwd);envdir=homefd
 else:
  path=target['directory']
  if not isinstance(path,str) or len(path)>4096 or not path.startswith('/') or any(x in ('','..','.') for x in path[1:].split('/')):refuse()
  fd=os.open('/',os.O_RDONLY|os.O_DIRECTORY|os.O_NOFOLLOW|os.O_CLOEXEC);opened.append(fd)
  for name in path[1:].split('/'):
   fd=os.open(name,os.O_RDONLY|os.O_DIRECTORY|os.O_NOFOLLOW|os.O_CLOEXEC,dir_fd=fd);opened.append(fd);st=os.fstat(fd)
   if st.st_uid not in (0,uid) or st.st_mode&0o002 or (st.st_mode&0o020 and st.st_gid!=gid):refuse('instance.step_layout_invalid')
  st=os.fstat(fd)
  if st.st_uid!=uid or st.st_gid!=gid:refuse('instance.step_layout_invalid')
  cwd=fd;envdir=cwd
 import stat
 try:envfd=os.open('.env',os.O_RDONLY|os.O_NOFOLLOW|os.O_CLOEXEC|os.O_NONBLOCK,dir_fd=envdir)
 except FileNotFoundError:
  if variant!='checkout':raise
  dotenv=''
 else:
  st=os.fstat(envfd)
  if not stat.S_ISREG(st.st_mode) or st.st_uid!=uid or st.st_gid!=gid or st.st_mode&0o022 or st.st_size>1048576:refuse('instance.step_layout_invalid')
  with os.fdopen(envfd,'r',encoding='utf-8') as f:dotenv=f.read(1048577)
 if len(dotenv.encode())>1048576:refuse()
 env={}
 def unescape(match):return {'n':chr(10),'r':chr(13),chr(92):chr(92),chr(34):chr(34),'$':'$'}[match.group(1)]
 for line in dotenv.splitlines():
  if not line.strip() or line.lstrip().startswith('#'):continue
  match=re.fullmatch(r'([A-Za-z_][A-Za-z0-9_]{0,254})=(.*)',line)
  if not match:refuse()
  key,text=match.groups()
  if key.startswith(('LD_','BASH_')) or '\0' in text:refuse()
  if text.startswith('"'):
   if not text.endswith('"') or len(text)<2:refuse()
   text=text[1:-1]
   if re.search(r'\\(?![nr\\"$])',text):refuse()
   text=re.sub(r'\\([nr\\"$])',unescape,text)
  elif '"' in text or "'" in text:refuse()
  if key in env or len(text.encode())>65536 or len(env)>=1024:refuse()
  env[key]=text
 secrets=set(line for text in env.values() for line in text.splitlines() if line)
 disabled=len(secrets)>4096
 secrets=sorted(secrets,key=len,reverse=True)[:4096]
 if len(sys.argv)==2:
  if not re.fullmatch(r'orbit-deploy-step:[1-9][0-9]*:[0-9a-fA-F-]{36}:[a-z_]+\.[a-z0-9-]+',sys.argv[1]):refuse()
  env['ORBIT_DEPLOY_STEP_RUNNER']=sys.argv[1]
 elif len(sys.argv)!=1:refuse()
 env.update(HOME=home,USER=account.pw_name,LOGNAME=account.pw_name,PATH='/usr/local/bin:/usr/bin:/bin')
 if variant=='checkout':
  env.update(ORBIT_SEED_PATH=target['seed_path'],ORBIT_SEED_COMMIT=target['seed_commit'])
  scopes=[]
  for candidate in [Path('/opt/orbit/vite-plus'),Path(home)/'.vite-plus',Path(home)/'.local/share/vite-plus']:
   binary=candidate/'bin/vp'
   if not binary.exists():continue
   for path in [candidate,binary]:
    st=path.lstat()
    if stat.S_ISLNK(st.st_mode) or st.st_uid!=uid or st.st_gid!=gid or st.st_mode&0o022:refuse('tool.vp_scope_conflict')
   scopes.append(str(candidate))
   break
  if scopes:env.update(VP_HOME=scopes[0],PATH=scopes[0]+'/bin:'+env['PATH'])
 vp=value.get('vp_environment')
 vp_args=None
 if vp is not None:
  if not isinstance(vp,dict) or set(vp)!={'store','owner','vp_version','node_version','package_manager','package_manager_version','package_manager_hash'}:refuse()
  owner=pwd.getpwnam(vp['owner'])
  if variant=='checkout' and owner.pw_name!=username:refuse('tool.vp_scope_conflict')
  if not owner.pw_uid or not owner.pw_gid:refuse('tool.vp_scope_conflict')
  store=vp['store']
  if not isinstance(store,str) or not store.startswith('/') or len(store)>4096 or any(x in ('','..','.') for x in store[1:].split('/')):refuse()
  if store not in ['/opt/orbit/vite-plus',owner.pw_dir+'/.vite-plus',owner.pw_dir+'/.local/share/vite-plus']:refuse('tool.vp_scope_conflict')
  for path in reversed([Path(store),*Path(store).parents]):
   st=path.lstat()
   if not stat.S_ISDIR(st.st_mode) or st.st_uid not in (0,owner.pw_uid) or st.st_mode&0o022:refuse('tool.vp_scope_conflict')
  for path in [Path(store),Path(store)/'bin',Path(store)/'bin/vp']:
   st=path.lstat()
   if st.st_uid!=owner.pw_uid or st.st_mode&0o022 or stat.S_ISLNK(st.st_mode):refuse('tool.vp_scope_conflict')
  for version in ['vp_version','node_version','package_manager_version']:
   if not isinstance(vp[version],str) or not re.fullmatch(r'\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?',vp[version]):refuse()
  manager=vp['package_manager'];pin=vp['package_manager_hash']
  if manager not in ('npm','pnpm') or (pin is not None and (not isinstance(pin,str) or not re.fullmatch(r'sha(?:224|256|384|512)\.[a-fA-F0-9]+',pin))):refuse()
  selector=manager+'@'+vp['package_manager_version']+('+'+pin if pin else '')
  vp_args=[store+'/bin/vp','env','exec','--node',vp['node_version'],'--package-manager',selector]
  env.update(VP_HOME=store)
 libc=ctypes.CDLL(None,use_errno=True)
 if libc.prctl(36,1,0,0,0)!=0 or libc.prctl(38,1,0,0,0)!=0:refuse('instance.step_start_failed')
 os.fchdir(cwd)
 lockfd=None
 if variant=='checkout':
  try:fcntl.flock(cwd,fcntl.LOCK_EX|fcntl.LOCK_NB)
  except BlockingIOError:refuse('instance.lifecycle_busy')
  lockfd=fcntl.fcntl(cwd,fcntl.F_DUPFD_CLOEXEC,4)
 for fd in opened:os.close(fd)
 fd=os.memfd_create('orbit-admitted-step',os.MFD_CLOEXEC|os.MFD_ALLOW_SEALING)
 os.write(fd,script.encode());os.lseek(fd,0,0)
 if fd!=3:os.dup2(fd,3,inheritable=True);os.close(fd);fd=3
 fcntl.fcntl(fd,fcntl.F_ADD_SEALS,fcntl.F_SEAL_WRITE|fcntl.F_SEAL_GROW|fcntl.F_SEAL_SHRINK|fcntl.F_SEAL_SEAL)
 deadline=time.monotonic()+seconds
 if cancelled:refuse('operation.cancelled')
 script_path='/proc/'+str(os.getpid())+'/fd/'+str(fd) if vp_args else '/proc/self/fd/'+str(fd)
 command=(vp_args+['/bin/bash'] if vp_args else ['/bin/bash'])+['--noprofile','--norc','-p','-eu',script_path]
 child=subprocess.Popen(command,stdin=subprocess.DEVNULL,stdout=subprocess.PIPE,stderr=subprocess.PIPE,env=env,pass_fds=(fd,),start_new_session=True)
 if vp_args is None:os.close(fd)
except Exception:refuse('instance.step_layout_invalid')
selector=selectors.DefaultSelector()
for pipe in [child.stdout,child.stderr]:os.set_blocking(pipe.fileno(),False);selector.register(pipe,selectors.EVENT_READ,bytearray())
output='';truncated=disabled;remaining=0 if disabled else 1048576;work=16*1048576;timed_out=False;pem={}

def line(text,stream):
 global work,remaining,truncated,output
 if time.monotonic()>=deadline or len(text)>4096:remaining=0;truncated=True;return
 if '-----BEGIN ' in text:pem[stream]=True
 if pem.get(stream):
  if '-----END ' in text:pem[stream]=False
  return
 text=re.sub(r'(?i)(authorization\s*:\s*(?:bearer|basic)\s+)\S+',r'\1[REDACTED]',text)
 text=re.sub(r'(?i)([a-z][a-z0-9+.-]*://)[^\s/@]+:[^\s/@]+@',r'\1[REDACTED]@',text)
 text=re.sub(r'(?i)((?:password|passwd|secret|token|api[_-]?key)\s*[=:]\s*)[^\s,;]+',r'\1[REDACTED]',text)
 for secret in secrets:
  work-=len(text)*2+len(secret)
  if work<0 or time.monotonic()>=deadline or len(text)>8192:remaining=0;truncated=True;return
  text=text.replace(secret,'[REDACTED]')
  if len(text)>8192:remaining=0;truncated=True;return
 output+=text+'\n'
 encoded=output.encode()
 if len(encoded)>LIMIT:output=encoded[-LIMIT:].decode('utf-8','ignore');truncated=True
try:
 drain_until=None
 while child.poll() is None or selector.get_map():
  if child.poll() is not None:
   if drain_until is None:drain_until=time.monotonic()+0.05
   if time.monotonic()>=drain_until:truncated=True;break
  if cancelled:break
  if time.monotonic()>=deadline:timed_out=True;break
  for key,_ in selector.select(min(0.05,max(0,deadline-time.monotonic()))):
   part=os.read(key.fd,4096)
   if not part:
    if key.data:truncated=True
    selector.unregister(key.fileobj);continue
   pending=key.data
   for byte in part:
    if not remaining or time.monotonic()>=deadline:remaining=0;truncated=True;pending.clear();break
    remaining-=1
    if byte==10:
     try:line(pending.decode('utf-8'),key.fd)
     except UnicodeError:remaining=0;truncated=True
     pending.clear()
    elif len(pending)<4096:pending.append(byte)
    else:remaining=0;truncated=True;pending.clear();break
finally:
 try:os.killpg(child.pid,signal.SIGKILL)
 except ProcessLookupError:pass
 child.wait()
 # As subreaper, adopt and kill descendants even after setsid/double-fork.
 cleanup=time.monotonic()+5
 while True:
  while True:
   try:
    if os.waitpid(-1,os.WNOHANG)[0]==0:break
   except ChildProcessError:break
  pids=Path('/proc/self/task/'+str(os.getpid())+'/children').read_text().split()
  if not pids:break
  if time.monotonic()>=cleanup:os._exit(74)
  for pid in pids:
   try:os.kill(int(pid),signal.SIGKILL)
   except ProcessLookupError:pass
  time.sleep(0.01)
 # Never flush incomplete tails or wait on a surviving pipe.
 for key in list(selector.get_map().values()):
  if key.data:truncated=True
  key.fileobj.close()
 selector.close()
if vp_args is not None:os.close(fd)
print(json.dumps({'exit_code':130 if cancelled else 124 if timed_out else child.returncode,'timed_out':timed_out,'output_tail':output,'truncated':truncated}),flush=True)
`;
