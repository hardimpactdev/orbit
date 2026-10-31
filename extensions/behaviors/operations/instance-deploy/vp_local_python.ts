/** Fixed Node-local VP primitives. Private Project data travels only over child stdin/stdout. */
export const VP_LOCAL = String.raw`
import os,sys,json,pwd,stat,re,time,fcntl,tempfile,shutil,hashlib,base64,tarfile,io,posixpath,urllib.request,urllib.parse,urllib.error,subprocess
class Refused(Exception): pass
def need(value,code='tool.store_invalid'):
 if not value: raise Refused(code)
def meta(path):
 try: return os.lstat(path)
 except FileNotFoundError: return None
def safe_absolute(path): return isinstance(path,str) and path.startswith('/') and '\x00' not in path and all(p not in ('','.','..') for p in path[1:].split('/'))
def owned(path,directory=False):
 m=meta(path);need(m is not None and not stat.S_ISLNK(m.st_mode) and m.st_uid==uid and not m.st_mode&0o022 and (not directory or stat.S_ISDIR(m.st_mode)))
 return m
def store_home(path):
 need(safe_absolute(path));owned(path,True)
def checked(root,path):
 need(path==root or path.startswith(root+'/'))
 current=path
 while current!=root:
  if meta(current) is not None: owned(current)
  current=os.path.dirname(current)
def paths(env):
 need(set(env)<=set(('store','node_version','package_manager','package_manager_version','package_manager_hash')))
 root=env['store'];store_home(root)
 for field in ('node_version','package_manager_version'): need(re.fullmatch(r'[0-9]+\.[0-9]+\.[0-9]+(?:-[0-9A-Za-z.-]+)?',env[field]))
 need(env['package_manager'] in ('npm','pnpm'))
 pin=env.get('package_manager_hash')
 if pin:
  match=re.fullmatch(r'(sha224|sha256|sha384|sha512)\.([a-fA-F0-9]+)',pin,re.I);need(match and len(match[2])==hashlib.new(match[1].lower()).digest_size*2)
 node=root+'/js_runtime/node/'+env['node_version'];manager=root+'/package_manager/'+env['package_manager']+'/'+env['package_manager_version']
 checked(root,node);checked(root,manager);return node,manager
def executable(path):
 try:
  m=os.stat(path);return stat.S_ISREG(m.st_mode) and bool(m.st_mode&0o111)
 except FileNotFoundError: return False
def installed(env):
 node,manager=paths(env)
 if not executable(node+'/bin/node'): return False
 package=manager+'/'+env['package_manager']
 try:
  manifest=json.loads(open(package+'/package.json').read())
 except FileNotFoundError: return False
 except ValueError: return False
 return manifest.get('name')==env['package_manager'] and manifest.get('version')==env['package_manager_version'] and executable(package+'/bin/'+env['package_manager'])
def atomic(root,path,value):
 checked(root,path);fd,temp=tempfile.mkstemp(prefix='.orbit-stage-',dir=os.path.dirname(path))
 try:
  with os.fdopen(fd,'w') as file: json.dump(value,file,separators=(',',':'));file.flush();os.fsync(file.fileno());os.fchmod(file.fileno(),0o644)
  os.replace(temp,path)
 finally:
  if os.path.exists(temp): os.unlink(temp)
def ledger(root):
 path=root+'/.orbit-runtimes.json';checked(root,path)
 try: data=json.load(open(path))
 except FileNotFoundError: return {}
 need(isinstance(data,dict) and all(isinstance(k,str) and isinstance(v,str) for k,v in data.items()))
 return data
def directories(root,path):
 checked(root,path);relative=os.path.relpath(path,root);current=root
 for part in relative.split('/'):
  if part=='.': continue
  current+='/'+part
  if meta(current) is None: os.mkdir(current,0o755)
  owned(current,True)
class Lock:
 def __init__(self,root):
  self.path=root+'/.orbit-store.lock';self.fd=os.open(self.path,os.O_RDWR|os.O_CREAT|os.O_NOFOLLOW|os.O_CLOEXEC,0o644)
 def __enter__(self):
  m=os.fstat(self.fd);need(stat.S_ISREG(m.st_mode) and m.st_uid==uid);os.fchmod(self.fd,0o644)
  until=min(deadline,time.time()+120)
  while True:
   try: fcntl.flock(self.fd,fcntl.LOCK_EX|fcntl.LOCK_NB);return self
   except BlockingIOError:
    need(time.time()<until,'tool.store_lock_timeout');time.sleep(min(.1,max(0,until-time.time())))
 def __exit__(self,*args): os.close(self.fd)
def https(value):
 url=urllib.parse.urlsplit(value);need(url.scheme=='https' and url.hostname and url.username is None and url.password is None);return url
def origin(url): return (url.scheme,url.hostname,url.port or 443)
def configuration(text):
 need(isinstance(text,str) and len(text.encode())<=1048576)
 result={}
 for line in text.splitlines():
  line=line.strip()
  if line.startswith(('#',';')) or '=' not in line: continue
  key,value=line.split('=',1);result[key.strip()]=re.sub(r'\$\{([^}]+)\}',lambda m:os.environ.get(m[1],''),value.strip())
 return result
def authorization(url,config):
 result=None
 for key,value in config.items():
  for suffix,kind in ((':_authToken','Bearer'),(':_auth','Basic')):
   if key.startswith('//') and key.endswith(suffix):
    scope=https('https:'+key[:-len(suffix)]);prefix=scope.path.rstrip('/')+'/'
    if origin(scope)==origin(url) and url.path.startswith(prefix): result=kind+' '+value
 return result
class NoRedirect(urllib.request.HTTPRedirectHandler):
 def redirect_request(self,*args,**kwargs): return None
def download(value,config):
 initial=https(value);current=value
 for _ in range(10):
  parsed=https(current);need(origin(parsed)==origin(initial),'tool.store_failed')
  if current in fixtures:
   fixture=fixtures[current]
   if isinstance(fixture,dict):
    if 'authorization' in fixture: need(authorization(parsed,config)==fixture['authorization'],'tool.store_failed')
    if 'redirect' in fixture: current=urllib.parse.urljoin(current,fixture['redirect']);continue
    raw=base64.b64decode(fixture['bytes'])
   else: raw=base64.b64decode(fixture)
   need(len(raw)<=268435456,'tool.store_failed');return raw
  need(not fixture_only,'tool.store_failed')
  headers={};auth=authorization(parsed,config)
  if auth: headers['Authorization']=auth
  remaining=min(60,deadline-time.time());need(remaining>0,'tool.store_failed')
  try: response=urllib.request.build_opener(NoRedirect).open(urllib.request.Request(current,headers=headers),timeout=remaining)
  except urllib.error.HTTPError as error:
   if 300<=error.code<400:
    current=urllib.parse.urljoin(current,error.headers.get('Location',''));need(current!=error.url,'tool.store_failed');continue
   raise Refused('tool.store_failed')
  with response:
   need(response.status>=200 and response.status<300 and int(response.headers.get('Content-Length','0'))<=268435456,'tool.store_failed')
   data=bytearray()
   while True:
    need(time.time()<deadline,'tool.store_failed');chunk=response.read(65536)
    if not chunk: return bytes(data)
    data.extend(chunk);need(len(data)<=268435456,'tool.store_failed')
 raise Refused('tool.store_failed')
def integrity(data,value):
 for token in value.split():
  if '-' not in token: continue
  kind,digest=token.split('-',1)
  if kind in ('sha256','sha384','sha512') and base64.b64encode(hashlib.new(kind,data).digest()).decode()==digest: return True
 return False
def registry(name,version,config):
 scope=name.split('/')[0] if name.startswith('@') else None
 root=config.get(scope+':registry') if scope else None
 root=(root or config.get('registry','https://registry.npmjs.org/')).rstrip('/')
 manifest=json.loads(download(root+'/'+name+'/'+version,config));need(manifest.get('name')==name and manifest.get('version')==version,'tool.store_failed')
 checksum=manifest['dist']['integrity'];data=download(manifest['dist']['tarball'],config);need(integrity(data,checksum),'tool.store_failed');return data,checksum
def extract(data,destination):
 os.mkdir(destination,0o700)
 with tarfile.open(fileobj=io.BytesIO(data),mode='r:*') as archive:
  entries=archive.getmembers();size=0;names=set();links=set()
  for entry in entries:
   name=entry.name.rstrip('/');parts=name.split('/')
   need(name and not name.startswith('/') and all(p not in ('','..') for p in parts) and (entry.isfile() or entry.isdir() or entry.issym() or entry.islnk()))
   need(name not in names);names.add(name);size+=entry.size;need(size<=2147483648)
   if entry.issym() or entry.islnk():
    target=posixpath.normpath(posixpath.join(posixpath.dirname(name),entry.linkname) if entry.issym() else entry.linkname)
    need(not target.startswith('/') and target!='..' and not target.startswith('../'));links.add(name)
  for name in names:
   need(not any('/'.join(name.split('/')[:i]) in links for i in range(1,len(name.split('/')))))
  for entry in entries:
   target=destination+'/'+entry.name.rstrip('/');os.makedirs(os.path.dirname(target),mode=0o755,exist_ok=True)
   if entry.isdir(): os.makedirs(target,mode=0o755,exist_ok=True)
   elif entry.isfile():
    with open(target,'xb') as output,archive.extractfile(entry) as source: shutil.copyfileobj(source,output,65536)
    os.chmod(target,entry.mode&0o755)
   elif entry.issym(): os.symlink(entry.linkname,target)
  for entry in entries:
   if entry.islnk():
    source=destination+'/'+posixpath.normpath(entry.linkname);need(os.path.isfile(source) and not os.path.islink(source));os.link(source,destination+'/'+entry.name)
  for base,dirs,files in os.walk(destination,followlinks=False):
   for name in dirs+files:
    path=base+'/'+name
    if not os.path.islink(path): os.chmod(path,os.stat(path).st_mode&0o755)
def launchers(package,env,native):
 need(not os.path.islink(package) and os.path.isdir(package));manifest=json.load(open(package+'/package.json'))
 need(manifest.get('name')==env['package_manager'] and manifest.get('version')==env['package_manager_version'],'tool.store_failed')
 need(isinstance(manifest.get('bin'),dict),'tool.store_failed');binpath=package+'/bin';need(not os.path.islink(binpath));os.makedirs(binpath,mode=0o755,exist_ok=True)
 for name,script in manifest['bin'].items():
  if name not in ('npm','npx','pnpm','pnpx'): continue
  need(isinstance(script,str));target=os.path.realpath(package+('/pnpm' if native else '/'+script));need(target.startswith(os.path.realpath(package)+'/') and os.path.isfile(target))
  if not native: need(open(target,'rb').read(20)==b'#!/usr/bin/env node\n','tool.store_failed')
  os.chmod(target,0o755);launcher=binpath+'/'+name
  if launcher==target: continue
  if os.path.lexists(launcher): need(not os.path.isdir(launcher));os.unlink(launcher)
  os.symlink(os.path.relpath(target,binpath),launcher)
 need(executable(binpath+'/'+env['package_manager']),'tool.store_failed')
def install(env,npmrc):
 node,manager=paths(env)
 if installed(env): return {'verified':{}}
 root=env['store']
 with Lock(root):
  if installed(env): return {'verified':{}}
  receipts=ledger(root)
  if not os.path.exists(node+'/bin/node'):
   need(meta(node) is None);version=env['node_version'];source='node-v'+version+'-'+system+'-'+architecture;filename=source+'.tar.gz';base='https://nodejs.org/dist/v'+version+'/'
   sums=download(base+'SHASUMS256.txt',{}).decode();expected=next((line.split()[0] for line in sums.splitlines() if len(line.split())==2 and line.split()[1]==filename and re.fullmatch('[a-fA-F0-9]{64}',line.split()[0])),None)
   need(expected is not None,'tool.store_failed');data=download(base+filename,{});need(hashlib.sha256(data).hexdigest()==expected,'tool.store_failed')
   directories(root,os.path.dirname(node));stage=tempfile.mkdtemp(prefix='.orbit-stage-',dir=os.path.dirname(node))
   try:
    content=stage+'/content';extract(data,content);candidate=content+'/'+source;need(not os.path.islink(candidate) and os.path.isdir(candidate) and executable(candidate+'/bin/node'),'tool.store_failed');os.rename(candidate,node)
   finally: shutil.rmtree(stage)
   receipts['js_runtime/node/'+version]='sha256-'+expected;atomic(root,root+'/.orbit-runtimes.json',receipts)
  if not os.path.exists(manager+'/'+env['package_manager']+'/package.json'):
   need(meta(manager) is None);config=configuration(npmrc);data,checksum=registry(env['package_manager'],env['package_manager_version'],config)
   pin=env.get('package_manager_hash')
   if pin:
    kind,expected=pin.split('.',1);need(hashlib.new(kind.lower(),data).hexdigest()==expected.lower(),'tool.store_failed')
   directories(root,os.path.dirname(manager));stage=tempfile.mkdtemp(prefix='.orbit-stage-',dir=os.path.dirname(manager))
   try:
    content=stage+'/content';extract(data,content);package=content+'/package';need(not os.path.islink(package) and os.path.isdir(package));manifest=json.load(open(package+'/package.json'));native=False
    if env['package_manager']=='pnpm':
     name='@pnpm/exe.'+system+'-'+architecture;version=manifest.get('optionalDependencies',{}).get(name)
     if version is not None:
      need(version==env['package_manager_version']);binarydata,binarysum=registry(name,version,config);unpacked=stage+'/native';extract(binarydata,unpacked);nativepackage=unpacked+'/package';need(not os.path.islink(nativepackage) and os.path.isdir(nativepackage));nativeinfo=json.load(open(nativepackage+'/package.json'));need(nativeinfo.get('name')==name and nativeinfo.get('version')==version,'tool.store_failed');binary=nativepackage+'/pnpm';need(not os.path.islink(binary) and os.path.isfile(binary));binarydata=open(binary,'rb').read();need(binarydata.startswith((b'\x7fELF',b'\xcf\xfa\xed\xfe',b'\xfe\xed\xfa\xcf',b'\xca\xfe\xba\xbe',b'\xbe\xba\xfe\xca')),'tool.store_failed');need(not os.path.islink(package+'/pnpm'));open(package+'/pnpm','wb').write(binarydata);os.chmod(package+'/pnpm',0o755);native=True;checksum+=' '+name+'@'+version+'='+binarysum
    launchers(package,env,native);os.rename(package,content+'/'+env['package_manager']);os.chmod(content,0o755);os.rename(content,manager)
   finally: shutil.rmtree(stage)
   receipts['package_manager/'+env['package_manager']+'/'+env['package_manager_version']]=checksum;atomic(root,root+'/.orbit-runtimes.json',receipts)
  need(installed(env),'tool.store_failed');return {'verified':receipts}
def prune(root,references):
 store_home(root);keep=set()
 for env in references:
  need(env['store']==root);keep.update(os.path.relpath(path,root) for path in paths(env))
 with Lock(root):
  receipts=ledger(root);reference=root+'/.orbit-recipe-node.json';checked(root,reference)
  if meta(reference) is not None:
   version=json.load(open(reference))['version'];need(re.fullmatch(r'[0-9]+\.[0-9]+\.[0-9]+(?:-[0-9A-Za-z.-]+)?',version));keep.add('js_runtime/node/'+version)
  removed=sorted(name for name in receipts if name not in keep and re.fullmatch(r'(?:js_runtime/node|package_manager/(?:npm|pnpm))/[0-9]+\.[0-9]+\.[0-9]+(?:-[0-9A-Za-z.-]+)?',name))
  for name in removed:
   checked(root,root+'/'+name);m=meta(root+'/'+name);need(m is None or stat.S_ISDIR(m.st_mode))
  for name in removed:
   if meta(root+'/'+name) is not None: shutil.rmtree(root+'/'+name)
   del receipts[name]
  atomic(root,root+'/.orbit-runtimes.json',receipts);return {'removed':removed}
def small(root,name,limit=1048576):
 try: fd=os.open(root+'/'+name,os.O_RDONLY|os.O_NOFOLLOW|os.O_NONBLOCK)
 except FileNotFoundError: return None
 with os.fdopen(fd,'rb') as file:
  m=os.fstat(file.fileno());need(stat.S_ISREG(m.st_mode) and m.st_size<=limit,'tool.project_invalid');data=file.read(limit+1);need(len(data)<=limit,'tool.project_invalid');return data.decode()
def project(root):
 need(safe_absolute(root),'tool.project_invalid');package=json.loads(small(root,'package.json') or '{}');need(isinstance(package,dict),'tool.project_invalid')
 hint=next((manager for manager,file in [('pnpm','pnpm-workspace.yaml'),('pnpm','pnpm-lock.yaml'),('yarn','yarn.lock'),('yarn','.yarnrc.yml'),('npm','package-lock.json'),('bun','bun.lock'),('bun','bun.lockb')] if os.path.isfile(root+'/'+file)),None)
 result={'package':package,'.node-version':None,'.nvmrc':None,'package_manager_hint':hint,'locks':{},'workspaces':{},'npmrc':''}
 uses=any(name=='vite-plus' or isinstance(value,str) and value.startswith('npm:vite-plus@') for key in ('dependencies','devDependencies') for name,value in (package.get(key) or {}).items())
 if not uses: return result
 for name in ('pnpm-lock.yaml','package-lock.json','yarn.lock'):
  text=small(root,name,16777216)
  if text is not None: result['locks'][name]=text
 for text in result['locks'].values():
  for link in re.findall(r'version: ["\x27]?link:([^\s"\x27]+)',text):
   directory=os.path.realpath(root+'/'+link);need(directory.startswith(os.path.realpath(root)+'/') or directory==os.path.realpath(root),'tool.project_invalid');workspace=json.loads(small(directory,'package.json') or '{}')
   if workspace.get('name')=='vite-plus': result['workspaces']['link:'+link]=workspace.get('version')
 result['.node-version']=small(root,'.node-version');result['.nvmrc']=small(root,'.nvmrc');result['npmrc']=(small(home,'.npmrc') or '')+'\n'+(small(root,'.npmrc') or '');return result
def scope():
 need(safe_absolute(home) and os.path.isdir(home),'tool.vp_scope_unreadable');candidates=[]
 if system=='linux':
  m=meta(opt)
  if m: need(stat.S_ISDIR(m.st_mode) and m.st_uid==root_uid and m.st_gid==root_gid,'tool.vp_scope_conflict')
  candidates.append(opt+'/vite-plus')
 candidates.extend((home+'/.vite-plus',home+'/.local/share/vite-plus'));found=[]
 for candidate in candidates:
  m=meta(candidate)
  if m is None: continue
  need(stat.S_ISDIR(m.st_mode) and m.st_uid==uid and (system!='linux' or m.st_gid==gid),'tool.vp_scope_conflict');binary=candidate+'/bin/vp';m=meta(binary)
  if m is None: continue
  need(m.st_uid==uid and (system!='linux' or m.st_gid==gid) and executable(binary),'tool.vp_scope_conflict');found.append(binary)
  if system=='linux': break
 need(len(found)<=1,'tool.vp_scope_conflict');need(len(found)==1,'tool.vp_scope_absent');return {'binary':found[0]}
def main():
 if mode=='project': return project(data['path'])
 if mode=='scope': return scope()
 if mode=='version':
  binary=scope()['binary'];result=subprocess.run([binary,'--version'],cwd=home,env={'PATH':'/usr/local/bin:/usr/bin:/bin','HOME':home,'VP_HOME':os.path.dirname(os.path.dirname(binary))},capture_output=True,timeout=max(.001,deadline-time.time()));need(result.returncode==0,'tool.store_failed');need(len(result.stdout)<=1048576,'tool.store_failed');return {'version':result.stdout.decode().strip()}
 if mode=='inspect': return {'installed':[installed(env) for env in data['environments']]}
 if mode=='install': return install(data['environment'],data.get('npmrc',''))
 if mode=='prune': return prune(data['store'],data['references'])
 raise Refused('tool.store_invalid')
try:
 request=json.load(sys.stdin);mode=request['mode'];data=request['input'];options=request['options'];account=pwd.getpwnam(options['user']);uid=os.geteuid();gid=os.getegid();need(uid!=0 and uid==account.pw_uid and gid==account.pw_gid);home=options.get('home',account.pw_dir);opt=options.get('opt','/opt/orbit');root_uid=options.get('root_uid',0);root_gid=options.get('root_gid',0);system='darwin' if options['platform']=='macos' else 'linux';architecture={'x86_64':'x64','arm64':'arm64'}[options['architecture']];deadline=options['deadline']/1000;fixtures=options.get('fixtures',{});fixture_only=options.get('fixture_only',False);need(time.time()<deadline,'deployment.deadline_exceeded');print(json.dumps(main(),separators=(',',':')))
except Refused as error: print(json.dumps({'error_code':str(error)}))
except Exception: print(json.dumps({'error_code':'tool.project_invalid' if globals().get('mode')=='project' else 'tool.store_failed'}))
`;
