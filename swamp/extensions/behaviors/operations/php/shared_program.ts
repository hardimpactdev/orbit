import { commonPhpProgram } from "./common_program.ts";

// Fixed Swamp-owned local program. Inputs enter only through stdin.
export const phpSharedProgram = commonPhpProgram + "\n" +
  String.raw`SHARED_INVALID='php.shared_pool_invalid'
SHARED_UNSAFE='php.shared_pool_unsafe'
SHARED_FAILED='php.shared_pool_failed'
class Configuration:
    def __init__(self,text):
        require(len(text.encode())<=1048576 and '\0' not in text,SHARED_INVALID)
        self.prefix='';self.pools=[];names=set()
        for line in text.splitlines(keepends=True):
            trimmed=line.strip()
            if trimmed.startswith('['):
                m=re.fullmatch(r'\[(orbit-(?:instance|workspace|app-instance)-[1-9][0-9]*(?:-[0-9a-f]{8})?)\]',trimmed)
                require(m is not None and m[1] not in names,SHARED_INVALID);names.add(m[1]);self.pools.append(dict(name=m[1],directory=None,text=''))
            if self.pools:
                p=self.pools[-1];key,sep,value=trimmed.partition('=')
                if sep and key.strip()=='chdir':
                    value=value.strip();require(value.startswith('/') and not any(ord(c)<32 or ord(c)==127 for c in value) and p['directory'] is None,SHARED_INVALID)
                    p['directory']=value
                p['text']+=line
            else:
                require(not trimmed or trimmed.startswith((';','#')),SHARED_INVALID);self.prefix+=line
    def render(self,keep=lambda p:True):
        pools=[p for p in self.pools if keep(p)]
        return '' if not pools and self.pools else self.prefix+''.join(p['text'] for p in pools)
    def present(self,h):return self.render(lambda p:p['directory'] is None or h.path(p['directory']).is_dir())
    def missing(self,h,v,installed):
        return [dict(pool=p['name'],version=v,directory=p['directory'],installed=installed) for p in self.pools if p['directory'] is not None and not h.path(p['directory']).is_dir()]
def shared_desired(i):
    result={};names=set()
    for v,text in sorted(i['configurations'].items()):
        c=Configuration(text)
        for p in c.pools:
            require(p['directory'] is not None and p['name'] not in names,SHARED_INVALID);names.add(p['name'])
        result[v]=c
    return result
def shared_metadata(h,p,directory):
    if not lexists(p):return None
    m=p.lstat();require(m.st_uid==h.uid and (stat.S_ISDIR(m.st_mode) if directory else stat.S_ISREG(m.st_mode)),SHARED_UNSAFE);return m
def shared_path(h,v):return h.path('/etc/php/'+v+'/fpm/pool.d/orbit-scopes.conf')
def shared_parents(h,v):
    for p in ('/etc','/etc/php','/etc/php/'+v,'/etc/php/'+v+'/fpm','/etc/php/'+v+'/fpm/pool.d'):
        require(shared_metadata(h,h.path(p),True) is not None,SHARED_FAILED)
def shared_installed(h):
    require(shared_metadata(h,h.path('/etc'),True) is not None,SHARED_UNSAFE)
    if shared_metadata(h,h.path('/etc/php'),True) is None:return {}
    result={}
    for entry in paths(h.path('/etc/php')):
        v=entry.name
        if not re.fullmatch(r'[0-9]+\.[0-9]+',v):continue
        shared_metadata(h,entry,True)
        if shared_metadata(h,entry/'fpm',True) is None or shared_metadata(h,entry/'fpm/pool.d',True) is None:continue
        shared_parents(h,v);p=shared_path(h,v);m=shared_metadata(h,p,False)
        if m is not None:
            text=p.read_bytes().decode('utf-8');result[v]=dict(configuration=Configuration(text),text=text,metadata=m,active=False,enabled=False)
    return result
def shared_call(h,args,required=True):
    try:return h.run(args,required)
    except Exception:raise Refused(SHARED_FAILED)
def shared_state(h,action,v):
    code,_=shared_call(h,['/usr/bin/systemctl',action,'--quiet','php'+v+'-fpm'],False)
    require(code in (0,1 if action=='is-enabled' else 3),SHARED_FAILED)
    return code==0
def shared_control(h,action,v):shared_call(h,['/usr/bin/systemctl',action,'php'+v+'-fpm'])
def shared_validate(h,v,text):
    with tempfile.TemporaryDirectory(prefix='orbit-php-pools-',dir=h.tmp) as tmp:
        work=P(tmp);pools=work/'pool.d';pools.mkdir();live=shared_path(h,v)
        for entry in paths(live.parent):
            if entry.name==live.name or entry.suffix!='.conf':continue
            require(shared_metadata(h,entry,False) is not None,SHARED_UNSAFE);(pools/entry.name).write_bytes(entry.read_bytes())
        (pools/live.name).write_text(text);main=live.parent.parent/'php-fpm.conf'
        require(shared_metadata(h,main,False) is not None,SHARED_FAILED)
        expected='/etc/php/'+v+'/fpm/pool.d/*.conf';count=0;rewritten=''
        for line in main.read_bytes().decode('utf-8').splitlines(keepends=True):
            key,sep,value=line.strip().partition('=')
            if sep and key.strip()=='include' and value.strip()==expected:
                count+=1;line='include = '+str(pools)+'/*.conf\n'
            rewritten+=line
        require(count==1,SHARED_INVALID);candidate=work/'php-fpm.conf';candidate.write_text(rewritten)
        shared_call(h,[h.path('/usr/sbin/php-fpm'+v),'--test','--fpm-config',candidate])
def shared_publish(h,v,text,prior=None):
    p=shared_path(h,v)
    if not text and prior is None:
        if shared_metadata(h,p,False) is not None:remove(p)
    else:h.publish(p,text,stat.S_IMODE(prior.st_mode) if prior else 0o644,prior.st_uid if prior else h.uid,prior.st_gid if prior else h.gid)
def converge_shared(i,h):
    requested=shared_desired(i);missing=[p for v,c in requested.items() for p in c.missing(h,v,False)]
    desired={v:Configuration(c.present(h)) for v,c in requested.items()};snapshots=shared_installed(h)
    for v,c in desired.items():
        if not c.pools:continue
        shared_parents(h,v)
        if v not in snapshots:snapshots[v]=dict(configuration=Configuration(''),text=None,metadata=None,active=False,enabled=False)
    desired_versions={p['name']:v for v,c in desired.items() for p in c.pools};moving=set();retiring=set();activating=set()
    for v,snapshot in snapshots.items():
        for p in snapshot['configuration'].pools:
            if desired_versions.get(p['name'])!=v:
                moving.add(p['name']);retiring.add(v)
                if p['name'] in desired_versions:activating.add(desired_versions[p['name']])
        if v not in desired:retiring.add(v)
    publications=[(v,desired[v].render(lambda p:p['name'] not in moving) if v in desired else '') for v in sorted(retiring)]
    publications += [(v,desired[v].render() if v in desired else '') for v in [*sorted(activating),*sorted(set(snapshots)-retiring-activating)]]
    for v,text in publications:shared_validate(h,v,text)
    for v,snapshot in sorted(snapshots.items()):
        snapshot['active']=shared_state(h,'is-active',v);snapshot['enabled']=shared_state(h,'is-enabled',v)
    touched=[];changed=False
    try:
        for v,text in publications:
            p=shared_path(h,v);m=shared_metadata(h,p,False);current=p.read_bytes().decode('utf-8') if m else None
            same=m is None if not text else current==text and m is not None and m.st_gid==h.gid and stat.S_IMODE(m.st_mode)==0o644
            active=shared_state(h,'is-active',v);enabled=shared_state(h,'is-enabled',v)
            if same and active and enabled:continue
            touched.append(v)
            if not same:shared_publish(h,v,text)
            if not enabled:shared_control(h,'enable',v)
            if not same or not active:shared_control(h,'reload-or-restart',v)
            changed=True
        return dict(changed=changed,missing_pools=missing)
    except BaseException as error:
        signal.signal(signal.SIGTERM,signal.SIG_IGN);signal.signal(signal.SIGINT,signal.SIG_IGN);h.deadline=max(h.deadline,time.monotonic()+120)
        restored=set();recovered=True
        for v in reversed(touched):
            if v in restored:continue
            restored.add(v);s=snapshots[v]
            try:
                text=s['configuration'].present(h) if s['text'] is not None else ''
                prior=s['metadata'] if text or s['text']=='' else None
                shared_publish(h,v,text,prior)
                shared_control(h,'enable' if s['enabled'] else 'disable',v)
                shared_control(h,'reload-or-restart' if s['active'] else 'stop',v)
            except Exception:recovered=False
        raise Refused(error.code if recovered and isinstance(error,Refused) else SHARED_FAILED if recovered else 'php.shared_pool_recovery_failed')
if __name__=='__main__':execute(converge_shared, SHARED_FAILED)
`;
