import type { Row } from "./doctor_contract.ts";

const NAMES = new Set([
  "doctor.inspect-project",
  "doctor.inspect-development-source",
  "doctor.inspect-production-instance",
  "doctor.inspect-application-urls",
  "doctor.inspect-private-route",
  "doctor.inspect-public-route",
  "doctor.inspect-custom-proxy",
  "instance.inspect-laravel-routes",
]);

// The program returns bounded comparisons only. It neither repairs files nor returns application/environment bytes.
const PROGRAM = String
  .raw`import hashlib,ipaddress,json,os,pathlib,pwd,grp,re,selectors,signal,socket,ssl,stat,subprocess,sys,time,unicodedata
HOST = {"homes":"/home","etc":"/etc/orbit","units":"/etc/systemd/system","sockets":"/run/php","proc":"/proc","sbin":"/usr/sbin","php_conf":"/etc/php","systemctl":"/usr/bin/systemctl","caddy":"/etc/caddy/Caddyfile","roots":"/usr/share/ca-certificates/mozilla","ufw":"/usr/sbin/ufw","getent":"/usr/bin/getent","root_uid":0,"root_gid":0,"caddy_gid":None,"tls_address":["127.0.0.1",443]}
class Invalid(Exception): pass
class Unavailable(Exception): pass
def interrupted(*_): raise Unavailable()
signal.signal(signal.SIGTERM,interrupted)
signal.signal(signal.SIGINT,interrupted)
name=sys.argv[1]
i=json.loads(sys.argv[2])
def refuse(ok):
 if not ok: raise Invalid()
def shape(value,keys):
 refuse(isinstance(value,dict) and set(value)==set(keys))
def text(value): return isinstance(value,str) and '\x00' not in value and '\n' not in value and '\r' not in value
def absolute(path): return isinstance(path,str) and path.startswith('/') and '..' not in path.split('/') and '\x00' not in path
def inside(path,root):
 try: return pathlib.Path(path).is_relative_to(root)
 except Exception: return False
def real(path):
 try: return str(pathlib.Path(path).resolve(strict=True))
 except OSError: return None
def regular(path):
 try: return stat.S_ISREG(os.lstat(path).st_mode)
 except OSError: return False
def directory(path):
 try: return stat.S_ISDIR(os.lstat(path).st_mode)
 except OSError: return False
def owned(path,uid,gid,mode=None):
 try:
  m=os.lstat(path)
  return m.st_uid==uid and m.st_gid==gid and (mode is None or stat.S_IMODE(m.st_mode)==mode)
 except OSError: return False
def data(path):
 with open(path,'rb') as f: value=f.read(8388609)
 if len(value)>8388608: raise Unavailable()
 return value
def content(path): return data(path).decode('utf-8')
def safe_content(path,default=''):
 try: return content(path)
 except (OSError,UnicodeError): return default
def exact(path,value,uid,gid,mode):
 try: return regular(path) and owned(path,uid,gid,mode) and data(path)==value
 except OSError: return False
def account(user):
 try: return pwd.getpwnam(user)
 except KeyError: return None
def capture(argv,limit=5,user=None,cwd=None,env=None):
 def drop():
  if user is not None:
   a=account(user)
   if a is None: raise Unavailable()
   if os.geteuid()==0:
    os.initgroups(user,a.pw_gid); os.setgid(a.pw_gid); os.setuid(a.pw_uid)
   elif os.geteuid()!=a.pw_uid: raise Unavailable()
 try:
  p=subprocess.Popen(argv,stdin=subprocess.DEVNULL,stdout=subprocess.PIPE,stderr=subprocess.DEVNULL,cwd=cwd,env=env,preexec_fn=drop if user else None,start_new_session=True)
 except (OSError,subprocess.SubprocessError): raise Unavailable()
 out=bytearray(); end=time.monotonic()+limit; finished=False
 try:
  with selectors.DefaultSelector() as watch:
   watch.register(p.stdout,selectors.EVENT_READ)
   while watch.get_map():
    left=end-time.monotonic()
    if left<=0: raise Unavailable()
    for key,_ in watch.select(left):
     block=os.read(key.fd,65536)
     if not block: watch.unregister(key.fileobj)
     else:
      out.extend(block)
      if len(out)>8388608: raise Unavailable()
  status=p.wait(timeout=max(.01,end-time.monotonic()))
  finished=True
  return status,bytes(out)
 except (OSError,subprocess.TimeoutExpired): raise Unavailable()
 finally:
  if not finished:
   try: os.killpg(p.pid,signal.SIGKILL)
   except ProcessLookupError: pass
   p.wait()
  p.stdout.close()
def git(path,user,args):
 a=account(user)
 if a is None: raise Unavailable()
 code,out=capture(['/usr/bin/git','-c','core.hooksPath=/dev/null','-c','core.fsmonitor=false','-C',path]+args,user=user,env={'PATH':'/usr/bin:/bin','HOME':a.pw_dir,'USER':user,'LOGNAME':user,'GIT_OPTIONAL_LOCKS':'0'})
 if code!=0: return None
 try: return out.decode('utf-8').rstrip('\n')
 except UnicodeError: raise Unavailable()
def identity(origin):
 if not isinstance(origin,str) or not origin or any(unicodedata.category(c)[0] in 'ZC' for c in origin): return None
 if origin.startswith('git@'):
  rest=origin[4:]
  if ':' not in rest: return None
  host,path=rest.split(':',1)
  if not host or not path or any(c in host+path for c in '?#'): return None
 else:
  if '://' not in origin: return None
  scheme,rest=origin.split('://',1)
  if any(c in rest for c in '?#') or '/' not in rest: return None
  authority,path=rest.split('/',1)
  userinfo=None
  if '@' in authority: userinfo,authority=authority.rsplit('@',1)
  if not ((scheme=='https' and userinfo is None) or (scheme=='ssh' and (userinfo is None or ':' not in userinfo))): return None
  host=authority
  colon=authority.rfind(':')
  if colon>authority.rfind(']'):
   port=authority[colon+1:]
   if port:
    match=re.match(r'^([+-]?)([0-9]+)',port)
    if len(port)>5 or not match or int(match[2])>65535 or (match[1]=='-' and int(match[2])!=0): return None
   host=authority[:colon]
  if not host or not path: return None
 path=path.strip('/')
 if path.endswith('.git'): path=path[:-4]
 return host.translate(str.maketrans('ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz'))+'/'+path.rstrip('/')
def project():
 shape(i,['repository','checkouts']); repository=identity(i['repository']); refuse(repository is not None)
 refuse(isinstance(i['checkouts'],list) and len(i['checkouts'])<=4096)
 seen=set(); observed=[]
 for c in i['checkouts']:
  shape(c,['instance_id','path','root','user','production'])
  refuse(type(c['instance_id']) is int and c['instance_id']>0 and c['instance_id'] not in seen and text(c['user']) and bool(c['user']) and type(c['production']) is bool)
  seen.add(c['instance_id']); matches=None
  try:
   if not absolute(c['path']) or not absolute(c['root']) or not inside(c['path'],c['root']): raise Unavailable()
   if not directory(c['path']): matches=False
   else:
    if c['production']:
     a=account(c['user'])
     if a is None or os.path.islink(c['root']): raise Unavailable()
     if real(c['path'])!=c['path'] or os.stat(c['path']).st_uid!=a.pw_uid or git(c['path'],c['user'],['rev-parse','--show-toplevel'])!=c['path']:
      observed.append({'instance_id':c['instance_id'],'matches':False}); continue
    matches=identity(git(c['path'],c['user'],['config','--get','remote.origin.url']))==repository
  except (OSError,Unavailable): matches=None
  observed.append({'instance_id':c['instance_id'],'matches':matches})
 return {'checkouts':observed}
def development():
 shape(i,['repository','checkout','root','user','group','layout','starting_commit'])
 refuse(identity(i['repository']) is not None and absolute(i['checkout']) and absolute(i['root']) and i['layout'] in ['checkout','worktree'] and (i['starting_commit']=='' or re.fullmatch('[a-fA-F0-9]{40}',i['starting_commit']) is not None))
 a=account(i['user']); refuse(a is not None and a.pw_uid!=0)
 try: group=grp.getgrnam(i['group']).gr_gid
 except KeyError: raise Invalid()
 path=i['checkout']; root=i['root']
 checkout=path!=root and inside(path,root) and directory(path) and real(path)==path and owned(path,a.pw_uid,group)
 layout=False
 if checkout and git(path,i['user'],['rev-parse','--show-toplevel'])==path:
  dot=path+'/.git'; own=git(path,i['user'],['rev-parse','--absolute-git-dir']); common=git(path,i['user'],['rev-parse','--path-format=absolute','--git-common-dir'])
  layout=(directory(dot) and own==dot and common==dot) if i['layout']=='checkout' else (regular(dot) and own is not None and common is not None and own!=common)
 origin=git(path,i['user'],['config','--get','remote.origin.url'])==i['repository']
 commit=i['starting_commit']
 history=bool(commit) and git(path,i['user'],['rev-parse','--verify',commit+'^{commit}'])==commit and git(path,i['user'],['merge-base',commit,'HEAD']) is not None
 return {'checkout':checkout,'layout':layout,'origin':origin,'history':history}
def domain(value): return isinstance(value,str) and len(value)<=253 and all(re.fullmatch(r'[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?',p) for p in value.split('.'))
def address(value):
 try:
  if value.startswith('['): host,port=value[1:].split(']:',1)
  else: host,port=value.rsplit(':',1)
  ip=ipaddress.ip_address(host); port=int(port)
  if not 0<port<=65535: raise ValueError()
  return str(ip),port
 except Exception: raise Invalid()
def connected(value):
 host,port=address(value)
 try:
  with socket.create_connection((host,port),1): return True
 except OSError: return False
def dns(value):
 try:
  code,out=capture([HOST['getent'],'ahostsv4',value])
  return str(ipaddress.IPv4Address(out.decode().split()[0])) if code==0 else None
 except (Unavailable,ValueError,IndexError,UnicodeError): return None
def firewall():
 try:
  code,out=capture([HOST['ufw'],'status'])
  return code==0 and any(line.startswith('Status: active') for line in out.decode().splitlines())
 except (Unavailable,UnicodeError): return False
def url_matches(path,url):
 if not regular(path): return False
 try: values=re.findall(rb'^APP_URL=([^\r\n]*)',data(path),re.M)
 except OSError: return False
 if len(values)!=1: return False
 value=values[0].strip(b' \t\n\r\x0b\x0c')
 if len(value)>=2 and value[:1]==value[-1:] and value[:1] in [b'"',b"'"]: value=value[1:-1]
 return value==url.encode()
def application_urls():
 shape(i,['checkout','applications']); refuse(absolute(i['checkout']) and isinstance(i['applications'],list))
 for app in i['applications']:
  shape(app,['directory','url']); directory_=app['directory']
  refuse(isinstance(directory_,str) and (not directory_ or all(p and p not in ['.','..'] and '\x00' not in p and '\\' not in p for p in directory_.split('/'))) and text(app['url']) and bool(app['url']))
 return {'matches':[not regular(os.path.join(i['checkout'],a['directory'],'artisan')) or url_matches(os.path.join(i['checkout'],a['directory'],'.env'),a['url']) for a in i['applications']]}
def private_route():
 shape(i,['domain','certificates','projection']); refuse(domain(i['domain']) and absolute(i['certificates']))
 p=i['projection']; refuse(isinstance(p,dict))
 if p.get('kind')=='workload':
  shape(p,['kind','dns_address','laravel'])
  if p['dns_address'] is not None:
   try: ipaddress.IPv4Address(p['dns_address'])
   except ValueError: raise Invalid()
  if p['laravel'] is not None:
   l=p['laravel']; refuse(isinstance(l,dict))
   if l.get('kind')=='skip': shape(l,['kind'])
   else: shape(l,['kind','environment','url']); refuse(l['kind']=='check' and absolute(l['environment']) and text(l['url']) and bool(l['url']))
 elif p.get('kind')=='router':
  shape(p,['kind','upstreams']); refuse(isinstance(p['upstreams'],list) and len(p['upstreams'])<=1024 and all(text(v) and 0<len(v)<=512 for v in p['upstreams']))
 else: raise Invalid()
 live=safe_content(HOST['caddy']); found=i['domain'] in live
 result={'caddy':found,'tls':os.path.isfile(i['certificates']+'/cert.pem') and os.path.isfile(i['certificates']+'/key.pem'),'firewall':firewall(),'dns':None,'laravel':None,'pool':None}
 if p['kind']=='router': result['caddy']=result['pool']=found and all(v in live for v in p['upstreams'])
 else:
  result['dns']=None if p['dns_address'] is None else dns(i['domain'])==p['dns_address']
  l=p['laravel']; result['laravel']=None if l is None else (True if l['kind']=='skip' else url_matches(l['environment'],l['url']))
 return result
def custom_proxy():
 shape(i,['domain','certificates','upstream','dns_address']); refuse(domain(i['domain']) and absolute(i['certificates']))
 host,_=address(i['upstream']); refuse(ipaddress.ip_address(host).is_loopback)
 if i['dns_address'] is not None:
  try: ipaddress.IPv4Address(i['dns_address'])
  except ValueError: raise Invalid()
 live=safe_content(HOST['caddy'])
 return {'caddy':i['domain'] in live and i['upstream'] in live,'tls':os.path.isfile(i['certificates']+'/cert.pem') and os.path.isfile(i['certificates']+'/key.pem'),'dns':None if i['dns_address'] is None else dns(i['domain'])==i['dns_address'],'upstream':connected(i['upstream'])}
def without_tls(value): return '\n'.join(line for line in value.splitlines() if not line.lstrip().startswith('tls '))
def public_certificate(value):
 try:
  context=ssl.SSLContext(ssl.PROTOCOL_TLS_CLIENT); loaded=False
  for entry in os.scandir(HOST['roots']):
   if entry.name.endswith('.crt') and entry.is_file(follow_symlinks=False): context.load_verify_locations(cafile=entry.path); loaded=True
  if not loaded: return False
  with socket.create_connection(tuple(HOST['tls_address']),10) as stream:
   with context.wrap_socket(stream,server_hostname=value) as tls:
    certificate=tls.getpeercert(); start=ssl.cert_time_to_seconds(certificate['notBefore']); end=ssl.cert_time_to_seconds(certificate['notAfter']); now=int(time.time())
    return end>start and now>=start and end-now>=(end-start)//6
 except (OSError,ValueError,KeyError,ssl.SSLError): return False
def public_route():
 shape(i,['domain','expected_site','certificates','forwarding']); refuse(domain(i['domain']) and absolute(i['certificates']) and isinstance(i['expected_site'],str) and 0<len(i['expected_site'].encode())<=1048576 and isinstance(i['forwarding'],list) and len(i['forwarding'])<=1024)
 for v in i['forwarding']: address(v)
 try: live=content(HOST['caddy'])
 except FileNotFoundError: live=''
 except (OSError,UnicodeError): raise Unavailable()
 expected=without_tls('\n'.join(i['expected_site'].splitlines()[1:])); refuse(bool(expected))
 site=[]; found=False
 for line in live.splitlines():
  if line==i['domain']+' {': found=True
  if found:
   site.append(line)
   if line=='}': break
 disabled=any(line.strip() in ['auto_https disable_certs','auto_https off'] for line in live.splitlines())
 automated=bool(site) and ('tls '+i['certificates']+'/cert.pem') not in live and (any(line.strip()=='tls force_automate' for line in site) or not disabled)
 return {'ingress':expected in without_tls(live),'forwarding':all(connected(v) for v in i['forwarding']),'tls':automated and public_certificate(i['domain'])}
def valid_name(value): return isinstance(value,str) and re.fullmatch('[a-zA-Z0-9][a-zA-Z0-9._-]{0,127}',value) is not None
def relative(value): return isinstance(value,str) and bool(value) and not value.startswith('/') and '..' not in value.split('/') and '\x00' not in value
def show(service,property_):
 code,out=capture([HOST['systemctl'],'show','--property='+property_,'--value',service])
 if code!=0: raise Unavailable()
 try: return out.decode().rstrip('\n')
 except UnicodeError: raise Unavailable()
def process_start(pid):
 try: return int(content(HOST['proc']+'/'+str(pid)+'/stat').rsplit(') ',1)[1].split()[19])
 except (OSError,ValueError,IndexError): raise Unavailable()
def process_ids(value,key):
 try:
  line=next(v[len(key):] for v in value.splitlines() if v.startswith(key)); values=[int(v) for v in line.split()]
  if len(values)!=4: raise ValueError()
  return values
 except (StopIteration,ValueError): raise Unavailable()
def stable_process(pid,inspect):
 before=process_start(pid); ok=inspect()
 if not ok: return False
 if process_start(pid)!=before: raise Unavailable()
 return True
def worker_exited(pid):
 path=HOST['proc']+'/'+str(pid)
 if not os.path.isdir(path): return True
 value=safe_content(path+'/status')
 for line in value.splitlines():
  if line.startswith('State:') and line[6:].split()[:1] in [['Z'],['X']]: return True
 try: return bool(int(content(path+'/stat').rsplit(') ',1)[1].split()[6])&4) or not os.path.isdir(path)
 except (OSError,ValueError,IndexError): return not os.path.isdir(path)
def pool_bytes(runtime,home):
 current=home+'/current'; app=runtime['application']; initial=runtime['initial_application']; config=runtime['configuration']
 if app!=initial:
  if os.path.lexists(current):
   refuse(os.path.islink(current)); selected=real(current)
   refuse(selected is not None and inside(selected,home+'/releases') and selected!=home+'/releases' and valid_name(os.path.relpath(selected,home+'/releases')) and inside(app,current))
   below=os.path.relpath(app,current); expected=selected if below=='.' else selected+'/'+below
   resolved=real(app); refuse(resolved==expected); pool=config['pool']; directory_=app
  else:
   resolved=real(initial); refuse(resolved==initial); pool=config['initial_pool']; directory_=initial
 else:
  resolved=real(app); refuse(resolved==app); pool=config['pool']; directory_=app
 refuse(os.path.isdir(directory_))
 return (pool+'\n; Orbit application release: '+resolved+'\n').encode()
def tuning(value,pool):
 forbidden=['pid','user','group','listen','listen.owner','listen.group','listen.mode','chdir','chroot','env[home]','env[user]','include']
 for raw in value.splitlines():
  line=raw.lstrip()
  if not line or line.startswith((';','#')): continue
  if line.startswith('['):
   if raw.strip()!='['+pool+']': return False
   continue
  if any(re.match(re.escape(key)+r'[ \t]*=',line.lower()) for key in forbidden): return False
 return True
def php_matches(runtime,home,user,caddy_gid):
 version=runtime['version']
 if not isinstance(version,str) or re.fullmatch(r'[0-9]+\.[0-9]+',version) is None: return False
 a=account(user)
 if a is None: return False
 directory_=HOST['etc']+'/php-fpm/'+user; generated=directory_+'/generated'; service='orbit-'+user+'-php'+version+'-fpm.service'; unit=HOST['units']+'/'+service; socket_=HOST['sockets']+'/'+user+'.sock'; executable=HOST['sbin']+'/php-fpm'+version
 try: pool=pool_bytes(runtime,home)
 except Invalid: return False
 config=runtime['configuration']
 rootuid=HOST['root_uid']; rootgid=HOST['root_gid']
 if not all(directory(v) and owned(v,rootuid,rootgid,0o755) for v in [directory_,generated]): return False
 for path,value in [(generated+'/php-fpm.conf',config['main'].encode()),(generated+'/pool.conf',pool),(generated+'/master.ini',config['master_ini'].encode()),(unit,config['unit'].encode()),(directory_+'/orbit.identity',runtime['marker'].encode())]:
  if not exact(path,value,rootuid,rootgid,0o644): return False
 local=directory_+'/local.conf'
 if not regular(local) or not owned(local,rootuid,rootgid,0o644) or not tuning(content(local),'orbit-'+user): return False
 active=show(service,'ActiveState')
 if active!='active':
  if active in ['inactive','failed','activating','deactivating','reloading']: return False
  raise Unavailable()
 if show(service,'User')!='': return False
 try: pid=int(show(service,'MainPID'))
 except ValueError: raise Unavailable()
 if pid<=1: raise Unavailable()
 expected='{ path='+executable+' ; argv[]='+executable+' --nodaemonize --fpm-config '+generated+'/php-fpm.conf ; '
 exec_=show(service,'ExecStart')
 if not exec_.startswith(expected):
  if exec_.startswith('{ path=') and ' ; argv[]=' in exec_: return False
  raise Unavailable()
 if show(service,'Environment')!='PHP_INI_SCAN_DIR='+HOST['php_conf']+'/'+version+'/fpm/conf.d:'+generated: return False
 def master():
  process=HOST['proc']+'/'+str(pid); target=real(process+'/exe')
  if target is None: raise Unavailable()
  return target==executable and process_ids(content(process+'/status'),'Uid:')==[0]*4
 if not stable_process(pid,master): return False
 children=content(HOST['proc']+f'/{pid}/task/{pid}/children').split()
 for child in children:
  try:
   child=int(child)
   def worker():
    process=HOST['proc']+'/'+str(child); value=content(process+'/status')
    try: parent=int(next(v[5:] for v in value.splitlines() if v.startswith('PPid:')))
    except (StopIteration,ValueError): raise Unavailable()
    if parent!=pid: raise Unavailable()
    if process_ids(value,'Uid:')!=[a.pw_uid]*4 or process_ids(value,'Gid:')!=[a.pw_gid]*4: return False
    root=real(process+'/root')
    if root is None: raise Unavailable()
    return root=='/'
   ok=stable_process(child,worker)
   if not ok and not worker_exited(child): return False
  except (Unavailable,OSError,ValueError):
   if not worker_exited(child): raise Unavailable()
 try: socketmeta=os.lstat(socket_)
 except OSError: return False
 if not stat.S_ISSOCK(socketmeta.st_mode) or not owned(socket_,a.pw_uid,caddy_gid,0o660): return False
 matches=[]
 for line in content(HOST['proc']+'/net/unix').splitlines():
  fields=line.split()
  if len(fields)>=8 and fields[3]=='00010000' and fields[5]=='01' and fields[7]==socket_: matches.append(fields[6])
 if not matches: return False
 if len(matches)!=1 or not matches[0].isdigit(): raise Unavailable()
 matched=False
 for entry in os.scandir(HOST['proc']+'/'+str(pid)+'/fd'):
  try: matched=matched or os.readlink(entry.path)=='socket:['+matches[0]+']'
  except OSError: pass
 if not matched:
  if not os.path.isdir(HOST['proc']+'/'+str(pid)): raise Unavailable()
  return False
 if show(service,'MainPID')!=str(pid): raise Unavailable()
 receipt=directory_+'/.runtime-generation.applied'
 if os.path.lexists(directory_+'/.runtime-generation.pending') or not exact_receipt(receipt,rootuid,rootgid): return False
 lines=content(receipt).rstrip('\n').split('\n')
 selected=b''.join(line+b'\n' for line in pool.split(b'\n') if line.startswith((b'chdir = ',b'; Orbit application release: ')))
 if len(lines)!=4 or lines[0]!=hashlib.sha256(selected).hexdigest(): return False
 try: recorded=int(lines[3]); boot=content(HOST['proc']+'/sys/kernel/random/boot_id').rstrip('\n'); current=process_start(pid)
 except ValueError: return False
 if re.fullmatch('[a-fA-F0-9-]{36}',boot) is None or show(service,'MainPID')!=str(pid): return False
 if lines[1]==boot and lines[2]==str(pid) and lines[3]==str(current): return True
 return re.fullmatch('[a-fA-F0-9-]{36}',lines[1]) is not None and (lines[1]!=boot or current>recorded)
def exact_receipt(path,uid,gid): return regular(path) and owned(path,uid,gid,0o600)
def production():
 shape(i,['user','home','root','application_suffix','environment','caddy_sites','association','runtime'])
 user=i['user']; home=i['home']; suffix=i['application_suffix']
 refuse(isinstance(user,str) and re.fullmatch('[a-z_][a-z0-9_-]{0,31}',user) is not None and absolute(home) and (suffix=='' or (isinstance(suffix,str) and suffix.startswith('/') and relative(suffix[1:]))))
 refuse(isinstance(i['environment'],str) and isinstance(i['caddy_sites'],list) and all(isinstance(v,str) for v in i['caddy_sites']) and type(i['association']) is bool)
 caddy_gid=HOST['caddy_gid']
 if caddy_gid is None:
  try: caddy_gid=grp.getgrnam('caddy').gr_gid
  except KeyError: raise Unavailable()
 a=account(user); current=home+'/current'; has=os.path.lexists(current); selected=None
 if a is not None and os.path.islink(current):
  target=os.readlink(current)
  if target.startswith('releases/') and valid_name(target[len('releases/'):]) and owned(current,a.pw_uid,a.pw_gid):
   resolved=real(current)
   if resolved is not None and inside(resolved,home+'/releases') and directory(resolved) and owned(resolved,a.pw_uid,a.pw_gid): selected=resolved
 home_matches=a is not None and home==HOST['homes']+'/'+user and real(home)==home and directory(home) and owned(home,a.pw_uid,a.pw_gid)
 root=not has
 if has and selected is not None and relative(i['root']):
  resolved=real(selected+'/'+i['root']); root=resolved is not None and inside(resolved,selected) and os.path.isdir(resolved)
 environment=a is not None and exact(home+'/.env',i['environment'].encode(),a.pw_uid,a.pw_gid,0o600) and (not has or (selected is not None and os.path.islink(selected+suffix+'/.env') and real(selected+suffix+'/.env')==home+'/.env'))
 caddy=real(HOST['caddy'])
 if caddy is None or not os.path.isfile(caddy): caddy_matches=False
 else:
  try: caddy_matches=all(site.rstrip('\n') in content(caddy) for site in i['caddy_sites'])
  except (OSError,UnicodeError): caddy_matches=None
 php=False
 if i['association']:
  if i['runtime'] is None: php=True
  else:
   runtime=i['runtime']; shape(runtime,['version','marker','configuration','application','initial_application']); shape(runtime['configuration'],['main','pool','initial_pool','local_defaults','master_ini','unit'])
   try: php=php_matches(runtime,home,user,caddy_gid)
   except (Unavailable,OSError,UnicodeError): php=None
 return {'home':home_matches,'selection':not has or selected is not None,'root':root,'environment':environment,'php':php,'caddy':caddy_matches}
def first_segment(segment):
 pattern=''; pos=0
 for match in re.finditer(r'\{[^{}]+\}',segment):
  literal=segment[pos:match.start()]
  if literal and literal[-1] in ',;.:-_~+*=@|' and match[0].endswith('?}'):
   pattern+=re.escape(literal[:-1])+'(?:'+re.escape(literal[-1])+r'[^/]*)?'
  else: pattern+=re.escape(literal)+r'[^/]*'
  pos=match.end()
 return pattern+re.escape(segment[pos:])
def laravel_routes():
 shape(i,['user','home','application','php_version'])
 home=i['home']; user=i['user']; current=home+'/current'; a=account(user)
 refuse(absolute(home) and a is not None and real(home)==home and os.path.islink(current) and os.lstat(home).st_uid==a.pw_uid)
 release=real(current); app=real(i['application']); refuse(release is not None and app is not None and str(pathlib.Path(release).parent)==home+'/releases' and inside(app,release))
 refuse(isinstance(i['php_version'],str) and re.fullmatch(r'[0-9]+\.[0-9]+',i['php_version']) is not None)
 code,out=capture(['php'+i['php_version'],'artisan','route:list','--json','--no-interaction'],limit=45,user=user,cwd=app)
 if code!=0: raise Unavailable()
 try:
  routes=json.loads(out); refuse(isinstance(routes,list) and all(isinstance(row,dict) and isinstance(row.get('uri'),str) for row in routes))
 except (ValueError,UnicodeError): raise Unavailable()
 collision=False
 for row in routes:
  uri=row['uri'].strip('/')
  if uri=='{fallbackPlaceholder}': continue
  pattern=first_segment(uri.split('/')[0].lower())
  if re.fullmatch(pattern,'app') or re.fullmatch(pattern,'apps'): collision=True
 return {'collision':collision}
readers={'doctor.inspect-project':project,'doctor.inspect-development-source':development,'doctor.inspect-production-instance':production,'doctor.inspect-application-urls':application_urls,'doctor.inspect-private-route':private_route,'doctor.inspect-public-route':public_route,'doctor.inspect-custom-proxy':custom_proxy,'instance.inspect-laravel-routes':laravel_routes}
try:
 refuse(isinstance(i,dict)); result=readers[name]()
 print(json.dumps(result,separators=(',',':')))
except Invalid: print('{"__error":"doctor.native_input_invalid"}')
except Exception: print('{"__error":"doctor.native_inspection_failed"}')
`;

export async function readApplications(
  name: string,
  input: Row,
  local: (argv: string[]) => Promise<string>,
): Promise<Row | undefined> {
  if (!NAMES.has(name)) return undefined;
  const text = await local([
    "sudo",
    "-n",
    "/usr/bin/python3",
    "-I",
    "-c",
    PROGRAM,
    name,
    JSON.stringify(input),
  ]);
  let result: unknown;
  try {
    result = JSON.parse(text);
  } catch {
    throw new Error("doctor.native_inspection_failed");
  }
  if (typeof result !== "object" || result === null || Array.isArray(result)) {
    throw new Error("doctor.native_inspection_failed");
  }
  const row = result as Row;
  if (row.__error) {
    throw new Error(
      row.__error === "doctor.native_input_invalid"
        ? "doctor.native_input_invalid"
        : "doctor.native_inspection_failed",
    );
  }
  return row;
}
