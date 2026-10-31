/** First-use manager setup owned by this native workflow, with pinned recipe artifacts. */
export const materializeProgram = String.raw`
def prerequisites(host,packages):
 host.run(['/usr/bin/apt-get','-o','DPkg::Lock::Timeout=300','update'],root=True,env={'DEBIAN_FRONTEND':'noninteractive'})
 host.run(['/usr/bin/apt-get','-o','DPkg::Lock::Timeout=300','install','--yes','--no-install-recommends','--no-remove','--',*packages],root=True,env={'DEBIAN_FRONTEND':'noninteractive'})

def composer_materialize(host,data):
 uid,gid=host.account.pw_uid,host.account.pw_gid
 dirs=[('/opt/orbit',0,0),('/opt/orbit/composer',uid,gid),('/opt/orbit/composer/vendor',uid,gid),('/opt/orbit/composer/vendor/bin',uid,gid)]
 for path,u,g in dirs:owned(path,u,g,missing=True)
 manifest=Path('/opt/orbit/composer/composer.json');exists=owned(manifest,uid,gid,directory=False,missing=True)
 prerequisites(host,['composer','git','unzip'])
 for path,u,g in dirs:directory(path,u,g)
 if not exists:
  fd=os.open(manifest,os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,0o644)
  with os.fdopen(fd,'wb') as f:f.write(b'{"require":{}}\n');os.fchown(f.fileno(),uid,gid)
 composer(host,'version',{});return {}

def brew_materialize(host,data):
 account=host.account;parent=Path('/home/linuxbrew');prefix=parent/'.linuxbrew'
 owned(parent,0,0,missing=True);exists=metadata(prefix) is not None
 if exists:brew_scope(host)
 prerequisites(host,['build-essential','procps','curl','file','git','ca-certificates'])
 directory(parent,0,0)
 if not exists:
  work=Path(tempfile.mkdtemp(prefix='.orbit-brew-',dir=parent));os.chown(work,account.pw_uid,account.pw_gid);os.chmod(work,0o755)
  try:
   repo=work/'Homebrew';host.run(['/usr/bin/git','clone','--filter=blob:none','--no-checkout',BREW_ORIGIN,str(repo)])
   host.run(['/usr/bin/git','-C',str(repo),'-c','advice.detachedHead=false','checkout','--detach',BREW_REVISION])
   for path in ['bin','Cellar','Caskroom','Frameworks','etc','include','lib','opt','sbin','share','var','var/homebrew','var/homebrew/locks']:directory(work/path,account.pw_uid,account.pw_gid,0o775)
   (work/'bin/brew').symlink_to('../Homebrew/bin/brew');os.lchown(work/'bin/brew',account.pw_uid,account.pw_gid)
   if metadata(prefix):refuse('tool.homebrew_scope_conflict')
   os.rename(work,prefix)
  finally:
   if work.exists():shutil.rmtree(work)
 prefix=brew_scope(host);repo=prefix/'Homebrew'
 def git(args,check=True):return host.run(['/usr/bin/git','-C',str(repo),*args],check=check)
 if git(['rev-parse','HEAD'])[1].rstrip('\r\n')!=BREW_REVISION:
  git(['fetch','--filter=blob:none','origin',BREW_REVISION]);git(['-c','advice.detachedHead=false','checkout','--detach',BREW_REVISION])
 if git(['show-ref','--verify','--quiet','refs/tags/7.0.0'],False)[0]:
  git(['fetch','--filter=blob:none','origin','tag','7.0.0'])
  cache=repo/'.git/describe-cache'
  if metadata(cache):owned(cache,account.pw_uid,account.pw_gid);shutil.rmtree(cache)
 if git(['rev-parse','--verify','7.0.0^{commit}'])[1].rstrip('\r\n')!=BREW_REVISION:refuse('tool.homebrew_materialize_failed')
 brew(host,'version',{});host.run([str(prefix/'bin/brew'),'config'],env=BREW_ENV);return {}

def https(url):
 if not isinstance(url,str):refuse('tool.vp_materialize_invalid')
 parsed=urllib.parse.urlsplit(url)
 if parsed.scheme!='https' or not parsed.hostname or parsed.username or parsed.password or parsed.fragment:refuse('tool.vp_materialize_invalid')
 return url
class SecureRedirect(urllib.request.HTTPRedirectHandler):
 def redirect_request(self,request,fp,code,msg,headers,newurl):return super().redirect_request(request,fp,code,msg,headers,https(newurl))

def artifact(pin,work):
 value=object_(object_(pin.get('artifacts')).get('amd64'));url=https(value.get('url'));digest=value.get('sha256');algorithm='sha256'
 if not isinstance(digest,str) or not re.fullmatch(r'[a-f0-9]{64}',digest):digest=value.get('sha512');algorithm='sha512'
 if not isinstance(digest,str) or not re.fullmatch(r'[a-f0-9]{'+('64' if algorithm=='sha256' else '128')+r'}',digest):refuse('tool.vp_materialize_invalid')
 archive=work/(algorithm+'-'+digest);checksum=hashlib.new(algorithm);size=0
 with urllib.request.build_opener(SecureRedirect()).open(url,timeout=120) as response,archive.open('wb') as target:
  while True:
   block=response.read(65536)
   if not block:break
   size+=len(block)
   if size>512*1024*1024:refuse('tool.vp_materialize_invalid')
   checksum.update(block);target.write(block)
 if checksum.hexdigest()!=digest:refuse('tool.vp_materialize_invalid')
 return archive,hashlib.sha256(archive.read_bytes()).hexdigest()

def extract(archive,target,account):
 target.mkdir();total=0
 with tarfile.open(archive,'r:*') as tar:
  for member in tar:
   total+=member.size
   if total>2*1024*1024*1024 or member.isdev() or member.isfifo():refuse('tool.vp_materialize_invalid')
   tar.extract(member,target,filter='data')
 for root,dirs,files in os.walk(target,followlinks=False):
  for path in [Path(root),*[Path(root)/n for n in dirs+files]]:
   os.lchown(path,account.pw_uid,account.pw_gid)
   if not path.is_symlink():os.chmod(path,path.lstat().st_mode & 0o755)

def vp_materialize(host,data):
 account=host.account;uid,gid=account.pw_uid,account.pw_gid;tools=object_(data.get('tools'))
 if data.get('architecture')!='amd64' or set(tools)!={'node','vp-cli','vp','pnpm'} or data.get('user')!=account.pw_name:refuse('tool.vp_materialize_invalid')
 for pin in tools.values():
  if not isinstance(pin,dict) or not isinstance(pin.get('version'),str) or not re.fullmatch(r'[0-9][0-9A-Za-z.+:~_-]*',pin['version']):refuse('tool.vp_materialize_invalid')
 scope=vp_scope(host,True);binary=scope/'bin/vp';missing=not binary.exists()
 kept={};names=('vp','node','pnpm','npm','npx')
 def launcher(name):return ('#!/bin/sh\nexport VP_HOME="'+str(scope)+'"\nexec "'+str(scope)+'/bin/'+name+'" "$@"\n').encode()
 for name in names:
  path=Path('/usr/local/bin')/name;s=metadata(path)
  if s:
   if not stat.S_ISREG(s.st_mode) or s.st_uid!=0 or s.st_gid!=0 or stat.S_IMODE(s.st_mode)!=0o755:refuse('tool.vp_materialize_conflict')
   old=read(path,0,0);export='export VP_HOME='+str(scope)+'\n' if str(scope)=='/opt/orbit/vite-plus' else ''
   former=('#!/bin/sh\n'+export+'exec "'+str(scope)+'/bin/'+name+'" "$@"\n').encode()
   if old not in (launcher(name),former):refuse('tool.vp_materialize_conflict')
   kept[path]=(old,s.st_atime_ns,s.st_mtime_ns)
  else:kept[path]=None
 try:
  if missing:
   for path in (Path(host.home)/'.local',Path(host.home)/'.local/share'):directory(path,uid,gid)
   directory('/opt/orbit',0,0)
   for path in (scope,scope/'bin',scope/'recipe',scope/'js_runtime',scope/'js_runtime/node'):directory(path,uid,gid)
   with tempfile.TemporaryDirectory(prefix='orbit-vp-') as temporary:
    work=Path(temporary)
    for name in ('node','vp-cli','pnpm'):
     archive,digest=artifact(tools[name],work);unpacked=work/('unpack-'+name);extract(archive,unpacked,account)
     if name=='node':
      children=list(unpacked.iterdir())
      if len(children)!=1 or not children[0].is_dir() or not (children[0]/'bin/node').is_file():refuse('tool.vp_materialize_invalid')
      target=scope/'js_runtime/node'/tools[name]['version']
      if not target.exists():os.rename(children[0],target)
      owned(target,uid,gid)
      publish(scope/'.orbit-recipe-node.json',json.dumps({'version':tools[name]['version']}).encode(),uid,gid,0o644)
      if first(host.run([str(target/'bin/node'),'--version'])[1]).removeprefix('v')!=tools[name]['version']:refuse('tool.vp_materialize_failed')
     else:
      target=scope/'recipe'/(name+'-'+digest)
      if not target.exists():os.rename(unpacked,target)
      owned(target,uid,gid)
      if name=='vp-cli':
       publish(scope/'bin/vp',read(target/'vp',uid,gid,32*1024*1024),uid,gid);publish(scope/'bin/.vp-setup-complete',b'',uid,gid,0o644)
      else:
       pkg=object_(json_(read(target/'package/package.json',uid,gid)))
       if pkg.get('version')!=tools[name]['version']:refuse('tool.vp_materialize_invalid')
       native=target/'package/pnpm'
       content=read(native,uid,gid,256*1024*1024) if native.is_file() else b''
       if not content.startswith(b'\x7fELF'):
        executable=target/'package/bin/pnpm.cjs'
        if not executable.is_file():refuse('tool.vp_materialize_invalid')
        content=('#!/bin/sh\nexec "'+str(scope)+'/bin/node" "'+str(executable)+'" "$@"\n').encode()
       publish(scope/'bin/pnpm',content,uid,gid);link(scope/'recipe/pnpm-current',target/'package',account)
   node=scope/'js_runtime/node'/tools['node']['version'];runtime=scope/'recipe';pin=tools['vp'];content=data.get('vp_lock');digest=pin.get('lock_sha256')
   if not isinstance(content,str) or hashlib.sha256(content.encode()).hexdigest()!=digest:refuse('tool.vp_materialize_invalid')
   lock=object_(json_(content));dependencies={'vite-plus':pin['version']}
   if object_(object_(lock.get('packages')).get('')).get('dependencies')!=dependencies or not isinstance(lock.get('name'),str) or not isinstance(lock.get('version'),str):refuse('tool.vp_materialize_invalid')
   target=runtime/('vp-runtime-'+digest)
   if not target.exists():
    staging=Path(tempfile.mkdtemp(prefix='.vp-runtime-',dir=runtime));os.chown(staging,uid,gid);os.chmod(staging,0o755)
    try:
     publish(staging/'package.json',json.dumps({'name':lock['name'],'version':lock['version'],'private':True,'dependencies':dependencies}).encode(),uid,gid,0o644)
     publish(staging/'package-lock.json',content.encode(),uid,gid,0o644)
     directory(runtime/'npm-cache',uid,gid)
     host.run([str(node/'bin/node'),str(node/'lib/node_modules/npm/bin/npm-cli.js'),'ci','--ignore-scripts','--no-audit','--no-fund','--prefix',str(staging)],env={'VP_HOME':str(scope),'PATH':str(node)+'/bin:/usr/bin:/bin','npm_config_cache':str(runtime/'npm-cache')});os.rename(staging,target)
    finally:
     if staging.exists():shutil.rmtree(staging)
   if hashlib.sha256(read(target/'package-lock.json',uid,gid)).hexdigest()!=digest or object_(json_(read(target/'node_modules/vite-plus/package.json',uid,gid))).get('version')!=pin['version']:refuse('tool.vp_materialize_invalid')
   directory(scope/'node_modules',uid,gid);link(scope/'node_modules/vite-plus',target/'node_modules/vite-plus',account);link(runtime/'vp-current',target/'node_modules/vite-plus',account)
   for name in ('node','npm','npx'):
    path=scope/'bin'/name;s=metadata(path)
    if s:
     if s.st_uid!=uid or stat.S_ISDIR(s.st_mode):refuse('tool.vp_materialize_conflict')
     path.unlink()
    path.symlink_to('vp');os.lchown(path,uid,gid)
   host.run([str(binary),'env','default',tools['node']['version']],env={'VP_HOME':str(scope)})
  for name in names:
   if not (scope/'bin'/name).is_file() or not os.access(scope/'bin'/name,os.X_OK):refuse('tool.vp_materialize_failed')
  paths=[scope,scope/'package_manager']
  for manager in ('npm','pnpm'):
   path=scope/'package_manager'/manager;paths.append(path)
   if path.is_dir() and not path.is_symlink():paths += [p for p in path.iterdir() if re.fullmatch(r'[0-9][0-9A-Za-z.+:~_-]*',p.name)]
  for path in paths:
   if not metadata(path):continue
   owned(path,uid,gid);os.chmod(path,stat.S_IMODE(path.lstat().st_mode)&~0o022)
  for name in names:
   publish(Path('/usr/local/bin')/name,launcher(name),0,0)
   host.run(['/usr/local/bin/'+name,'--version'],env={'VP_HOME':str(scope)})
  return {'binary':str(binary),'changed':missing or any(old is None or old[0]!=launcher(p.name) for p,old in kept.items())}
 except Exception:
  for path,old in kept.items():
   if old is None:
    if metadata(path):path.unlink()
   else:publish(path,old[0],0,0);os.utime(path,ns=old[1:])
  raise
`;
