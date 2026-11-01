/** Homebrew policy and fixed native commands, including official cask checks. */
export const homebrewProgram = String.raw`
BREW_ORIGIN='https://github.com/Homebrew/brew'
BREW_REVISION='d79ef822ab8136e393ed5f86e2b56afc68d04874'
BREW_VERSION='Homebrew 7.0.0'
BREW_ENV={key:'1' for key in ('HOMEBREW_NO_AUTO_UPDATE','HOMEBREW_NO_ANALYTICS','HOMEBREW_NO_ENV_HINTS','HOMEBREW_NO_INSTALLED_DEPENDENTS_CHECK','HOMEBREW_NO_INSTALL_CLEANUP')}
def brew_scope(host):
 account=host.account;found=[]
 if host.node['platform']=='linux':
  if metadata('/home/linuxbrew'):owned('/home/linuxbrew',0,0)
  candidates=[Path('/home/linuxbrew/.linuxbrew')]
 else:candidates=[Path('/opt/homebrew'),Path('/usr/local'),Path(host.home)/'homebrew',Path(host.home)/'.homebrew']
 for prefix in candidates:
  binary=prefix/'bin/brew';nested=prefix/'Homebrew'
  if not any(metadata(p) for p in (binary,prefix/'.git',nested)):continue
  if not metadata(prefix):continue
  owned(prefix,account.pw_uid,None if host.node['platform']=='macos' else account.pw_gid)
  direct=bool(metadata(prefix/'.git') and stat.S_ISDIR((prefix/'.git').lstat().st_mode));sub=bool(metadata(nested/'.git') and stat.S_ISDIR((nested/'.git').lstat().st_mode))
  if direct==sub:refuse('tool.homebrew_scope_conflict')
  if direct:
   if host.node['platform']=='linux':refuse('tool.homebrew_scope_conflict')
   owned(prefix/'.git',account.pw_uid);owned(binary,account.pw_uid,directory=False)
   if not os.access(binary,os.X_OK):refuse('tool.homebrew_scope_conflict')
   repo=prefix
  else:
   owned(nested,account.pw_uid,None if host.node['platform']=='macos' else account.pw_gid)
   s=metadata(binary)
   if s is None or not stat.S_ISLNK(s.st_mode) or s.st_uid!=account.pw_uid or os.readlink(binary)!='../Homebrew/bin/brew':refuse('tool.homebrew_scope_conflict')
   repo=nested
  if host.run(['/usr/bin/git','-C',str(repo),'config','--get','remote.origin.url'])[1].rstrip('\r\n')!=BREW_ORIGIN:refuse('tool.homebrew_scope_conflict')
  if host.node['platform']=='linux' and host.run(['/usr/bin/git','-C',str(repo),'status','--porcelain=v1','--untracked-files=all'])[1].rstrip('\r\n'):refuse('tool.homebrew_scope_conflict')
  found.append(prefix)
 if not found:refuse('tool.homebrew_scope_absent')
 if len(found)!=1:refuse('tool.homebrew_scope_conflict')
 return found[0]

def brew_tag(host):
 if host.node['platform']=='linux':
  if first(host.run(['/usr/bin/uname','-m'])[1])!='x86_64':refuse('tool.homebrew_bottle_unavailable')
  return 'x86_64_linux'
 value=first(host.run(['/usr/bin/sw_vers','-productVersion'])[1])
 if not re.fullmatch(r'\d+(?:\.\d+){0,2}',value):refuse('tool.output_invalid')
 symbol={27:'golden_gate',26:'tahoe',15:'sequoia',14:'sonoma',13:'ventura',12:'monterey',11:'big_sur'}.get(int(value.split('.')[0]))
 if not symbol:refuse('tool.homebrew_bottle_unavailable')
 if host.node['architecture'] not in ('x86_64','amd64','arm64','aarch64'):refuse('tool.package_invalid')
 return ('arm64_' if host.node['architecture'] in ('arm64','aarch64') else '')+symbol

def bottle(formula,tag):
 files=object_(object_(object_(formula.get('bottle',{})).get('stable',{})).get('files',{}));value=files.get(tag,files.get('all',{}));value=object_(value)
 sha=value.get('sha256');url=value.get('url')
 return object_(formula.get('versions',{})).get('bottle') is True and isinstance(sha,str) and re.fullmatch(r'[a-f0-9]{64}',sha) is not None and isinstance(url,str) and url.startswith('https://ghcr.io/v2/homebrew/core/') and url.endswith('sha256:'+sha)

def official(formula,name):return formula.get('name')==name and formula.get('full_name')==name and formula.get('tap')=='homebrew/core'
PREFIX_KINDS={'binary','manpage','bash_completion','fish_completion','zsh_completion','generate_completions_from_executable'}
HOME_KINDS={'font','colorpicker','dictionary','internet_plugin','prefpane','qlplugin','mdimporter','screen_saver','service','audio_unit_plugin','vst_plugin','vst3_plugin'}
APP_KINDS={'app','suite'}
PRIVILEGED_KINDS={'pkg','installer','keyboard_layout','input_method'}
UNINSTALL_KEYS={'quit','signal','login_item','trash','delete','rmdir','pkgutil','kext','launchctl'}
def path_class(path,prefix,home):
 if not isinstance(path,str) or not path or '..' in path or re.search(r'[\x00-\x1f\x7f]',path):return 'unsupported'
 bases=['~','$HOME','/$HOME','$HOMEBREW_PREFIX',str(prefix),home]
 if '/' not in path or any(path==b or path.startswith(b+'/') for b in bases):return 'user'
 return 'authorization' if path.startswith(('/','$APPDIR')) else 'unsupported'

def artifacts_check(artifacts,prefix,home,removal=False):
 if not isinstance(artifacts,list):refuse('tool.output_invalid')
 installs=0;authorization=False
 for artifact in artifacts:
  artifact=object_(artifact)
  for kind,value in artifact.items():
   if kind in ('target','zap'):continue
   if kind=='uninstall':
    if not isinstance(value,list) or any(not isinstance(v,dict) or set(v)-UNINSTALL_KEYS for v in value):refuse('tool.homebrew_cask_artifact_unsupported')
    for directive in value:
     if {'pkgutil','kext','launchctl'} & set(directive):authorization=True
     for key in ('trash','delete','rmdir'):
      if key not in directive:continue
      paths=directive[key];paths=[paths] if isinstance(paths,str) else paths
      if not isinstance(paths,list) or any(path_class(p,prefix,home)!='user' for p in paths):authorization=True
    continue
   known=kind in PREFIX_KINDS|HOME_KINDS|APP_KINDS|PRIVILEGED_KINDS|{'artifact'}
   if not known:
    if removal:continue
    refuse('tool.homebrew_cask_artifact_unsupported')
   installs+=1
   paths=[]
   if isinstance(artifact.get('target'),str):paths.append(artifact['target'])
   if isinstance(value,list):paths += [v['target'] for v in value if isinstance(v,dict) and isinstance(v.get('target'),str)]
   if not paths:paths=['/Applications' if kind in APP_KINDS else '/$HOME' if kind in HOME_KINDS else '$HOMEBREW_PREFIX' if kind in PREFIX_KINDS else 'relative/missing-target']
   if kind in PRIVILEGED_KINDS:authorization=True
   else:
    classes=[path_class(p,prefix,home) for p in paths]
    if not removal and 'unsupported' in classes:refuse('tool.homebrew_cask_artifact_unsupported')
    if 'authorization' in classes:authorization=True
 if not removal and installs==0:refuse('tool.homebrew_cask_artifact_unsupported')
 if authorization:refuse('tool.homebrew_cask_authorization_required')

def assess_cask(value,name,prefix,home):
 if not isinstance(value.get('disabled'),bool):refuse('tool.output_invalid')
 if value.get('token')!=name or value.get('full_token')!=name or value.get('tap')!='homebrew/cask':refuse('tool.homebrew_cask_source_unsupported')
 url=value.get('url')
 if not isinstance(url,str) or not url.startswith('https://') or len(url)<=8 or re.search(r'\s',url):refuse('tool.homebrew_cask_source_unsupported')
 if value['disabled']:refuse('tool.homebrew_cask_artifact_unsupported')
 artifacts_check(value.get('artifacts'),prefix,home)
 if not isinstance(value.get('sha256'),str) or not re.fullmatch(r'[a-f0-9]{64}',value['sha256']):refuse('tool.homebrew_cask_checksum_unavailable')
 if not version(value.get('version')) or value['version']=='latest':refuse('tool.homebrew_cask_version_unavailable')
 return value['version']

def brew(host,verb,data):
 cask=host.node['manager']=='brew-cask';prefix=brew_scope(host)
 def call(args,refresh=False,check=True):return host.run([str(prefix/'bin/brew'),*args],env={**BREW_ENV,'PATH':str(prefix)+'/bin:/usr/bin:/bin',**({'HOMEBREW_FORCE_API_AUTO_UPDATE':'1'} if refresh and host.node['platform']=='macos' else {})},check=check)
 if verb=='version':
  value=first(call(['--version'])[1])
  if host.node['platform']=='linux' and value!=BREW_VERSION:refuse('tool.homebrew_version_mismatch')
  if host.node['platform']=='macos' and not re.fullmatch(r'Homebrew \d+\.\d+\.\d+',value):refuse('tool.output_invalid')
  return {'version':value}
 def inventory():
  packages={}
  if cask:
   entries=object_(json_(call(['info','--json=v2','--cask','--installed'])[1])).get('casks')
   if not isinstance(entries,list):refuse('tool.output_invalid')
   rows=[]
   for v in entries:
    v=object_(v)
    if v.get('tap')=='homebrew/cask':rows.append((v.get('token'),[v.get('installed')]))
  else:rows=[(line.split(' ')[0],line.split(' ')[1:]) for line in call(['list','--versions','--formula'])[1].rstrip('\n').split('\n') if line]
  for name,versions in rows:
   try:package('brew',name)
   except Refusal:continue
   entry=packages.setdefault(name,{'name':name,'versions':[],'unverifiable':False})
   if not versions or any(not version(v) or cask and v=='latest' for v in versions):entry['unverifiable']=True
   else:entry['versions']+=versions
  return {'packages':list(packages.values())}
 if verb=='inventory':return inventory()
 name=package(host.node['manager'],data.get('package'))
 if name in ('wireguard-tools','wireguard-go'):refuse('tool.package_protected')
 tag=brew_tag(host) if verb!='remove' or cask else None;coordinate=('homebrew/cask/' if cask else 'homebrew/core/')+name
 def metadata_(refresh=False):
  code,out,err=call(['info','--json=v2','--cask' if cask else '--formula',coordinate],refresh,False)
  if len(out)>131072 or len(err)>131072:refuse('tool.output_invalid')
  if code:
   text=out+'\n'+err
   missing=(not cask and 'No available formula with the name "'+coordinate+'"' in text) or (cask and ("Cask '"+coordinate+"' is unavailable." in text and 'This command requires the tap homebrew/cask.' in text or 'No Cask with this name exists' in text and any("Cask '"+n+"'" in text or 'Cask "'+n+'"' in text for n in (name,coordinate))))
   if code==1 and missing:return None
   refuse('tool.command_failed')
  value=object_(json_(out));rows=value.get('casks' if cask else 'formulae');other=value.get('formulae' if cask else 'casks')
  if not isinstance(rows,list) or len(rows)!=1 or other!=[]:refuse('tool.output_invalid')
  result=object_(rows[0]);variations=object_(result.get('variations',{}))
  return {**result,**object_(variations.get(tag,{}))} if cask else result
 def candidate(value):
  if value is None:return None
  if cask:return assess_cask(value,name,prefix,host.home)
  stable=object_(value.get('versions',{})).get('stable')
  if not official(value,name) or value.get('disabled') is not False or not version(stable) or not bottle(value,tag):refuse('tool.homebrew_candidate_unavailable')
  return stable
 if verb=='candidate':return {'version':candidate(metadata_(True))}
 if verb=='remove':
  if cask:
   value=metadata_()
   if value is None:
    values=object_(json_(call(['info','--json=v2','--cask','--installed'])[1])).get('casks')
    if not isinstance(values,list):refuse('tool.output_invalid')
    values=[v for v in values if isinstance(v,dict) and v.get('token')==name]
    if len(values)!=1 or values[0].get('tap')!='homebrew/cask':refuse('tool.homebrew_cask_source_unsupported')
    value={**values[0],**object_(object_(values[0].get('variations',{})).get(tag,{}))};coordinate=name
   artifacts_check(value.get('artifacts'),prefix,host.home,True)
  call(['uninstall','--cask' if cask else '--formula',coordinate]);return {}
 value=metadata_(True)
 if candidate(value) is None:refuse('tool.homebrew_candidate_unavailable')
 call(['install' if verb=='install' else 'upgrade','--cask',coordinate] if cask else ['install' if verb=='install' else 'upgrade','--formula','--force-bottle',coordinate],True);return {}
`;
