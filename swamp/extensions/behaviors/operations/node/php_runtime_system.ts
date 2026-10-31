import { localSystemCommand, type SystemTransport } from "./system_command.ts";

export type PhpRuntimeInput = {
  version: string;
  runtime_ini: string;
  release: { id: string; codenames: string[] };
};
export type SharedPoolsInput = { configurations: Record<string, string> };
export type MissingPhpPool = {
  pool: string;
  version: string;
  directory: string;
  installed: boolean;
};
export type PhpRuntimeOutput = {
  changed: boolean;
  reloaded?: boolean;
  deferred?: string[];
  missing_pools?: MissingPhpPool[];
  error_code?: string;
  restored?: boolean;
};

/** Node-owned runtime configuration only. Recipe installation belongs to Fleet. */
export function phpRuntimeSystem(invoke: SystemTransport = localSystemCommand) {
  return async (
    name: "php.configure-runtime" | "php.converge-shared-pools",
    input: PhpRuntimeInput | SharedPoolsInput,
    signal?: AbortSignal,
  ): Promise<PhpRuntimeOutput> => {
    signal?.throwIfAborted();
    const result = await invoke({
      command: "/usr/bin/sudo",
      args: ["-n", "/usr/bin/python3", "-c", PhpRuntimeProgram, name],
      stdin: JSON.stringify(input),
      timeoutMs: 900000,
    }, signal);
    if (!result.success) {
      throw new Error("PHP runtime command has no confirmed outcome");
    }
    try {
      const output = JSON.parse(result.stdout);
      if (typeof output.changed !== "boolean") throw new Error();
      if (
        output.error_code !== undefined && ![
          "php.runtime_input_invalid",
          "php.runtime_platform_unsupported",
          "php.runtime_configuration_failed",
          "php.shared_pool_invalid",
          "php.shared_pool_unsafe",
          "php.shared_pool_failed",
          "php.shared_pool_recovery_failed",
        ].includes(output.error_code)
      ) throw new Error();
      return output;
    } catch {
      throw new Error("PHP runtime command returned an invalid outcome");
    }
  };
}

export const PhpRuntimeProgram = String.raw`
import fcntl, json, os, re, shutil, stat, subprocess, sys, tempfile
from pathlib import Path
ROOT=Path('/')
UID,GID=0,0

def path(value): return ROOT / value.lstrip('/')
class Refusal(Exception):
    def __init__(self,code): self.code=code

def fail(code): raise Refusal(code)
def version(v): return isinstance(v,str) and re.fullmatch(r'[0-9]+\.[0-9]+',v) is not None

def metadata(p,directory=False,required=False):
    try: m=p.lstat()
    except FileNotFoundError:
        if required: fail('php.shared_pool_unsafe')
        return None
    if m.st_uid!=UID or not (stat.S_ISDIR(m.st_mode) if directory else stat.S_ISREG(m.st_mode)): fail('php.shared_pool_unsafe')
    return m

def parents(p):
    if p==ROOT: return
    parents(p.parent)
    if not p.exists():
        p.mkdir(mode=0o755);os.chown(p,UID,GID)
    metadata(p,True,True)

def publish(p,data,mode=0o644,uid=None,gid=None):
    parents(p.parent)
    fd,candidate=tempfile.mkstemp(prefix='.orbit-php-',dir=p.parent)
    try:
        with os.fdopen(fd,'wb') as f:
            f.write(data.encode() if isinstance(data,str) else data);f.flush();os.fsync(f.fileno())
            os.fchmod(f.fileno(),mode);os.fchown(f.fileno(),UID if uid is None else uid,GID if gid is None else gid)
        os.replace(candidate,p)
    finally:
        if os.path.exists(candidate): os.unlink(candidate)

def call(argv,required=True):
    p=subprocess.run(argv,stdin=subprocess.DEVNULL,stdout=subprocess.PIPE,stderr=subprocess.DEVNULL,check=False,timeout=60)
    if required and p.returncode!=0: fail('php.shared_pool_failed')
    return p.returncode,p.stdout.decode(errors='replace')

def size(v):
    m=re.fullmatch(r'([0-9]+)([KMG]?)',v)
    if not m: return None
    n=int(m[1])*{'':1,'K':1024,'M':1024**2,'G':1024**3}[m[2]]
    return n if 0<n<=1024**3 else None

def settings(p):
    if not isinstance(p,dict) or set(p)!={'version','runtime_ini','release'} or not version(p['version']) or not isinstance(p['runtime_ini'],str) or len(p['runtime_ini'].encode())>8192: fail('php.runtime_input_invalid')
    r=p['release']
    if not isinstance(r,dict) or set(r)!={'id','codenames'} or not isinstance(r['id'],str) or not re.fullmatch(r'[a-z0-9][a-z0-9+.-]*',r['id']) or not isinstance(r['codenames'],list) or not r['codenames'] or any(not isinstance(c,str) or not re.fullmatch(r'[a-z0-9][a-z0-9+.-]*',c) for c in r['codenames']): fail('php.runtime_input_invalid')
    result={}
    for line in p['runtime_ini'].splitlines():
        line=line.strip()
        if not line or line.startswith(';'): continue
        if '=' not in line: fail('php.runtime_input_invalid')
        k,v=map(str.strip,line.split('=',1))
        valid=(size(v) is not None if k in ('post_max_size','upload_max_filesize') else v=='On' if k=='opcache.enable' else v=='disable' if k=='opcache.jit' else v=='0' if k=='opcache.jit_buffer_size' else bool(re.fullmatch(r'[0-9]+',v)) and 0<int(v)<=4294967295 if k in ('opcache.memory_consumption','opcache.interned_strings_buffer','opcache.max_accelerated_files') else False)
        if not valid or k in result: fail('php.runtime_input_invalid')
        result[k]=v
    if len(result)!=8 or size(result['upload_max_filesize'])>size(result['post_max_size']): fail('php.runtime_input_invalid')
    return result

def fpm_configuration(text):
    has_global=any(l.strip()=='[global]' for l in text.splitlines())
    out='' if has_global else '[global]\nprocess_control_timeout = 30s\n'
    global_section=True;emitted=not has_global
    for line in text.splitlines(keepends=True):
        trimmed=line.strip()
        if trimmed.startswith('['): global_section=trimmed=='[global]'
        if global_section and trimmed=='[global]' and not emitted:
            out+=line+'process_control_timeout = 30s\n';emitted=True;continue
        if global_section and '=' in trimmed and trimmed.split('=',1)[0].strip()=='process_control_timeout': continue
        out+=line
    return out

def fpm_units(v):
    shared='php'+v+'-fpm.service';result=[]
    if call(['/usr/bin/systemctl','is-active','--quiet',shared],False)[0]==0: result.append((shared,path('/etc/php/'+v+'/fpm/php-fpm.conf')))
    listed=call(['/usr/bin/systemctl','list-units','--type=service','--state=active','--no-legend','--plain','orbit-*-php'+v+'-fpm.service'],False)[1]
    for line in listed.splitlines():
        unit=line.split()[0] if line.split() else ''
        if not re.fullmatch(r'orbit-[a-z0-9_-]+-php'+re.escape(v)+r'-fpm\.service',unit): continue
        start=call(['/usr/bin/systemctl','show','-p','ExecStart','--value',unit],False)[1]
        m=re.search(r'--fpm-config\s+(\S+)',start)
        result.append((unit,path(m[1]) if m else path('/__missing_fpm_configuration__')))
    return result

def configure(p):
    expected=settings(p)
    release={}
    try:
        for line in path('/etc/os-release').read_text().splitlines():
            if '=' in line:
                k,v=line.split('=',1);release[k]=v.strip('"\'')
    except OSError: pass
    if release.get('ID')!=p['release']['id'] or release.get('VERSION_CODENAME') not in p['release']['codenames']: fail('php.runtime_platform_unsupported')
    v=p['version'];binary=str(path('/usr/sbin/php-fpm'+v))
    if not Path(binary).exists(): return {'changed':False,'reloaded':False}
    available=path('/etc/php/'+v+'/mods-available/orbit-runtime.ini');enabled=path('/etc/php/'+v+'/fpm/conf.d/99-orbit-runtime.ini');shared=path('/etc/php/'+v+'/fpm/php-fpm.conf');unit='php'+v+'-fpm.service'
    before_file=available.read_bytes() if available.exists() else None
    before_link=None
    if enabled.is_symlink(): before_link=os.readlink(enabled)
    elif enabled.exists(): fail('php.runtime_configuration_failed')
    metadata(shared,False,True)
    before_shared=shared.read_text();configuration=fpm_configuration(before_shared)
    correct=enabled.exists() and enabled.resolve()==available.resolve()
    changed=before_file!=p['runtime_ini'].encode() or not correct or configuration!=before_shared
    touched=False;masters={};reloaded=[];deferred=[]
    try:
        if changed:
            touched=True;publish(available,p['runtime_ini']);publish(shared,configuration)
            if not correct:
                enabled.unlink(missing_ok=True);enabled.symlink_to('../../mods-available/orbit-runtime.ini')
        info=call([binary,'-i'])[1]
        if str(enabled) not in info or any(k+' => '+val+' => '+val not in [line.strip() for line in info.splitlines()] for k,val in expected.items()): fail('php.runtime_configuration_failed')
        if changed:
            for name,configuration_path in fpm_units(v):
                if name!=unit:
                    metadata(configuration_path,False,True)
                    previous=configuration_path.read_text();n=fpm_configuration(previous)
                    if n!=previous: masters[configuration_path]=previous;publish(configuration_path,n)
                args=[binary,'-t']+([] if name==unit else ['--fpm-config',str(configuration_path)])
                if call(args,False)[0]!=0: deferred.append(name);continue
                reloaded.append((name,args));call(['/usr/bin/systemctl','reload',name])
        call(['/usr/bin/systemctl','enable',unit]);call([str(path('/usr/bin/php'+v)),'-v']);call([binary,'-v'])
        if 'pcov' in [line.strip().lower() for line in call([binary,'-m'])[1].splitlines()]: fail('php.runtime_configuration_failed')
        call(['/usr/bin/systemctl','is-enabled','--quiet',unit])
        return {'changed':changed,'reloaded':bool(reloaded),'deferred':deferred}
    except Exception:
        restored=True
        if touched:
            for file,data in [(shared,before_shared),*masters.items(),(available,before_file)]:
                try:
                    if data is None: file.unlink(missing_ok=True)
                    else: publish(file,data)
                except Exception: restored=False
            try:
                enabled.unlink(missing_ok=True)
                if before_link is not None: enabled.symlink_to(before_link)
            except Exception: restored=False
            for name,args in reloaded:
                try:
                    if call(args,False)[0]!=0 or call(['/usr/bin/systemctl','reload',name],False)[0]!=0: restored=False
                except Exception: restored=False
        return {'changed':False,'error_code':'php.runtime_configuration_failed','restored':restored}

def parse(text):
    if not isinstance(text,str) or len(text.encode())>1024*1024 or '\0' in text: fail('php.shared_pool_invalid')
    prefix='';pools=[];names=set()
    for line in text.splitlines(keepends=True):
        t=line.strip()
        if t.startswith('['):
            m=re.fullmatch(r'\[(orbit-(?:instance|workspace|app-instance)-[1-9][0-9]*(?:-[0-9a-f]{8})?)\]',t)
            if not m or m[1] in names: fail('php.shared_pool_invalid')
            names.add(m[1]);pools.append({'name':m[1],'directory':None,'text':''})
        if pools:
            pool=pools[-1]
            if '=' in t:
                k,val=map(str.strip,t.split('=',1))
                if k=='chdir':
                    if not val.startswith('/') or any(ord(c)<32 or ord(c)==127 for c in val) or pool['directory'] is not None: fail('php.shared_pool_invalid')
                    pool['directory']=val
            pool['text']+=line
        elif not t or t.startswith((';','#')): prefix+=line
        else: fail('php.shared_pool_invalid')
    return prefix,pools

def render(c,keep=lambda p:True):
    kept=[p for p in c[1] if keep(p)]
    return '' if not kept and c[1] else c[0]+''.join(p['text'] for p in kept)
def present(c): return render(c,lambda p:p['directory'] is None or path(p['directory']).is_dir())
def pool_path(v): return path('/etc/php/'+v+'/fpm/pool.d/orbit-scopes.conf')
def check_parents(v):
    for p in ['/etc','/etc/php','/etc/php/'+v,'/etc/php/'+v+'/fpm','/etc/php/'+v+'/fpm/pool.d']: metadata(path(p),True,True)

def validate(v,text):
    live=pool_path(v);metadata(live.parent.parent/'php-fpm.conf',False,True)
    with tempfile.TemporaryDirectory(prefix='orbit-php-pools-') as tmp:
        work=Path(tmp);pools=work/'pool.d';pools.mkdir()
        for p in live.parent.iterdir():
            if p.name=='orbit-scopes.conf' or p.suffix!='.conf': continue
            metadata(p,False,True);shutil.copyfile(p,pools/p.name)
        (pools/'orbit-scopes.conf').write_text(text)
        expected='/etc/php/'+v+'/fpm/pool.d/*.conf';count=0;rewritten=''
        for line in (live.parent.parent/'php-fpm.conf').read_text().splitlines(keepends=True):
            t=line.strip()
            if '=' in t and tuple(map(str.strip,t.split('=',1)))==('include',expected):
                count+=1;rewritten+='include = '+str(pools)+'/*.conf\n'
            else: rewritten+=line
        if count!=1: fail('php.shared_pool_invalid')
        candidate=work/'php-fpm.conf';candidate.write_text(rewritten)
        call([str(path('/usr/sbin/php-fpm'+v)),'--test','--fpm-config',str(candidate)])

def state(action,v):
    code=call(['/usr/bin/systemctl',action,'--quiet','php'+v+'-fpm'],False)[0]
    if code==0: return True
    if code==(1 if action=='is-enabled' else 3): return False
    fail('php.shared_pool_failed')
def control(action,v): call(['/usr/bin/systemctl',action,'php'+v+'-fpm'])
def put_pool(v,text,prior=None):
    p=pool_path(v)
    if not text and prior is None: p.unlink(missing_ok=True)
    else: publish(p,text,0o644 if prior is None else prior.st_mode&0o7777,None if prior is None else prior.st_uid,None if prior is None else prior.st_gid)

def shared_pools(p):
    if not isinstance(p,dict) or set(p)!={'configurations'} or not isinstance(p['configurations'],dict): fail('php.shared_pool_invalid')
    requested={};names=set();missing=[]
    for v,text in sorted(p['configurations'].items()):
        if not version(v): fail('php.shared_pool_invalid')
        c=parse(text)
        for pool in c[1]:
            if pool['directory'] is None or pool['name'] in names: fail('php.shared_pool_invalid')
            names.add(pool['name'])
            if not path(pool['directory']).is_dir(): missing.append({'pool':pool['name'],'version':v,'directory':pool['directory'],'installed':False})
        requested[v]=parse(present(c))
    snapshots={};metadata(path('/etc'),True,True)
    if metadata(path('/etc/php'),True) is not None:
        for entry in sorted(path('/etc/php').iterdir()):
            v=entry.name
            if not version(v): continue
            metadata(entry,True,True)
            if metadata(entry/'fpm',True) is None or metadata(entry/'fpm/pool.d',True) is None: continue
            check_parents(v);m=metadata(pool_path(v))
            if m is not None:
                text=pool_path(v).read_text();snapshots[v]={'configuration':parse(text),'bytes':text,'metadata':m}
    for v,c in requested.items():
        if c[1]:
            check_parents(v)
            snapshots.setdefault(v,{'configuration':('',[]),'bytes':None,'metadata':None})
    desired_versions={pool['name']:v for v,c in requested.items() for pool in c[1]}
    moving=set();retiring=set();activating=set()
    for v,s in snapshots.items():
        for pool in s['configuration'][1]:
            to=desired_versions.get(pool['name'])
            if to!=v:
                moving.add(pool['name']);retiring.add(v)
                if to is not None: activating.add(to)
        if v not in requested: retiring.add(v)
    publications=[(v,render(requested.get(v,('',[])),lambda p:p['name'] not in moving)) for v in sorted(retiring)]
    publications += [(v,render(requested.get(v,('',[])))) for v in [*sorted(activating),*[v for v in sorted(snapshots) if v not in retiring and v not in activating]]]
    for v,text in publications: validate(v,text)
    for v,s in snapshots.items(): s.update(active=state('is-active',v),enabled=state('is-enabled',v))
    touched=[];changed=False
    try:
        for v,text in publications:
            pp=pool_path(v);m=metadata(pp);current=pp.read_text() if m is not None else None
            same=m is None if not text else current==text and m is not None and m.st_gid==GID and m.st_mode&0o7777==0o644
            active=state('is-active',v);enabled=state('is-enabled',v)
            if same and active and enabled: continue
            touched.append(v)
            if not same: put_pool(v,text)
            if not enabled: control('enable',v)
            if not same or not active: control('reload-or-restart',v)
            changed=True
    except Exception:
        recovered=True;seen=set()
        for v in reversed(touched):
            if v in seen: continue
            seen.add(v);s=snapshots[v];text=present(s['configuration']) if s['bytes'] is not None else ''
            try:
                prior=s['metadata'] if text or s['bytes']=='' else None
                put_pool(v,text,prior);control('enable' if s['enabled'] else 'disable',v);control('reload-or-restart' if s['active'] else 'stop',v)
            except Exception: recovered=False
        return {'changed':False,'error_code':'php.shared_pool_failed' if recovered else 'php.shared_pool_recovery_failed'}
    return {'changed':changed,'missing_pools':missing}

if __name__=='__main__':
    name=sys.argv[1]
    try:
        with open('/run/lock/orbit-php-runtime.lock','a') as lock:
            fcntl.flock(lock,fcntl.LOCK_EX)
            result=(configure if name=='php.configure-runtime' else shared_pools)(json.load(sys.stdin))
    except Refusal as e: result={'changed':False,'error_code':e.code}
    except Exception: result={'changed':False,'error_code':'php.runtime_configuration_failed' if name=='php.configure-runtime' else 'php.shared_pool_failed'}
    print(json.dumps(result,separators=(',',':')))
`;
