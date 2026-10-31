/** Fixed root program; only typed action data crosses stdin, never private argv. */
export const NETWORK_PROGRAM = String.raw`
import base64,fcntl,hashlib,ipaddress,json,os,re,stat,subprocess,sys,tempfile,time
WG='/etc/wireguard'
LOCK='/run/lock/orbit-wireguard-peer.lock'
ROOT_UID=0
SERVICE='wg-quick@orbit'
TOOLS={'wg':'/usr/bin/wg','wg-quick':'/usr/bin/wg-quick','systemctl':'/usr/bin/systemctl','resolvectl':'/usr/bin/resolvectl','networkctl':'/usr/bin/networkctl','ufw':'/usr/sbin/ufw','sysctl':'/usr/sbin/sysctl'}
class Refusal(Exception):pass
class Uncertain(Exception):pass
def refuse(code):raise Refusal(code)
def command(name,args=(),data=None,check=True):
    try:r=subprocess.run([TOOLS[name],*args],input=data,text=True,stdout=subprocess.PIPE,stderr=subprocess.DEVNULL,timeout=30)
    except Exception:raise Uncertain()
    if len(r.stdout)>1024*1024:raise Uncertain()
    if check and r.returncode:raise Uncertain()
    return r

def read(name,optional=False):
    p=WG+'/'+name
    try:s=os.lstat(p)
    except FileNotFoundError:
        if optional:return None
        raise Uncertain()
    if not stat.S_ISREG(s.st_mode) or s.st_uid!=ROOT_UID or s.st_mode&0o077:raise Uncertain()
    with open(p,'rb') as f:b=f.read(1024*1024+1)
    if len(b)>1024*1024:raise Uncertain()
    return b.decode()

def write(name,value):
    p=WG+'/'+name
    if os.path.lexists(p):
        s=os.lstat(p)
        if not stat.S_ISREG(s.st_mode) or s.st_uid!=ROOT_UID or s.st_mode&0o077:raise Uncertain()
    fd,temp=tempfile.mkstemp(prefix='.orbit-native-',dir=WG)
    try:
        os.fchmod(fd,0o600)
        with os.fdopen(fd,'w') as f:f.write(value);f.flush();os.fsync(f.fileno())
        os.replace(temp,p)
    finally:
        if os.path.exists(temp):os.unlink(temp)

def remove(name):
    if read(name,True) is not None:os.unlink(WG+'/'+name)

def state():
    return {'active':command('systemctl',['is-active','--quiet',SERVICE],check=False).returncode==0,
            'enabled':command('systemctl',['is-enabled',SERVICE],check=False).stdout.strip()}

def set_state(saved):
    for args in [['unmask',SERVICE],['unmask','--runtime',SERVICE],['disable',SERVICE],['disable','--runtime',SERVICE]]:command('systemctl',args)
    if saved['enabled'] in ['enabled','enabled-runtime']:command('systemctl',['enable']+(['--runtime'] if saved['enabled']=='enabled-runtime' else [])+[SERVICE])
    command('systemctl',['restart' if saved['active'] else 'stop',SERVICE])
    if saved['enabled'] in ['masked','masked-runtime']:command('systemctl',['mask']+(['--runtime'] if saved['enabled']=='masked-runtime' else [])+[SERVICE])

def capture_resolver(link):
    if not re.fullmatch(r'[A-Za-z0-9_.:+-]+',link):raise Uncertain()
    result={}
    for key in ['dns','domain','default-route','llmnr','mdns','dnssec','dnsovertls','nta']:
        value=command('resolvectl',[key,link]).stdout
        result[key]=value.split(':',1)[-1].strip()
    return result

def apply_resolver(link,settings):
    for key,value in settings.items():command('resolvectl',[key,link,*value.split()] if value else [key,link,''])

def dns_state():
    text=read('orbit.dns-link',True)
    if text is None:return None
    fields=text.splitlines()
    if len(fields)<3 or not re.fullmatch(r'[A-Za-z0-9_.:+-]+',fields[0]):raise Uncertain()
    return {'link':fields[0],'server':fields[1],'domains':fields[2:]}

def snapshot(owner):
    resolver=dns_state()
    return {'owner':owner,'config':read('orbit.conf',True),'dns_file':read('orbit.dns-link',True),
            'state':state(),'resolver':resolver,'resolver_settings':capture_resolver(resolver['link']) if resolver else None,
            'orbit_settings':capture_resolver('orbit') if state()['active'] else None}

def restore(saved):
    command('systemctl',['stop',SERVICE])
    for key,name in [('config','orbit.conf'),('dns_file','orbit.dns-link')]:
        if saved[key] is None:remove(name)
        else:write(name,saved[key])
    set_state(saved['state'])
    if saved.get('resolver'):apply_resolver(saved['resolver']['link'],saved['resolver_settings'])
    if saved.get('orbit_settings'):apply_resolver('orbit',saved['orbit_settings'])
    remove('.orbit.native-network-transaction')

def transaction(owner):
    existing=read('.orbit.native-network-transaction',True)
    if existing is not None:
        saved=json.loads(existing)
        if saved.get('owner')!=owner:refuse('wireguard.peer_recovery_pending')
        return saved,True
    saved=snapshot(owner)
    write('.orbit.native-network-transaction',json.dumps(saved,separators=(',',':')))
    return saved,False

def key():
    private=read('orbit.key')
    public=command('wg',['pubkey'],private).stdout.strip()
    if not re.fullmatch(r'[A-Za-z0-9+/]{43}=',public):raise Uncertain()
    return private,public

def uplink(private,previous):
    text=command('resolvectl',['dns']).stdout
    for line in text.splitlines():
        if not re.match(r'^(Global|Link [0-9]+ \([^)]+\)):',line) or re.match(r'^Link [0-9]+ \(orbit\):',line):continue
        for value in line.split(':',1)[-1].split():
            value=re.split('[%#]',value)[0]
            try:ip=ipaddress.ip_address(value)
            except ValueError:continue
            if not ip.is_loopback and not ip.is_unspecified and value not in [private,previous]:return True
    return False

def hook(server,domains):
    return 'PostUp = resolvectl dns %i '+server+'; resolvectl domain %i '+' '.join('\\~'+domain for domain in domains)

def apply_dns(server,domains,old):
    command('resolvectl',['dns','orbit',server]);command('resolvectl',['domain','orbit',*['~'+domain for domain in domains]])
    command('resolvectl',['default-route','orbit','no'])
    if old and old['link']!='orbit':
        for name in ['dns','domain']:command('resolvectl',[name,old['link'],''])
        command('resolvectl',['default-route',old['link'],'yes'])
        command('networkctl',['reconfigure',old['link']])
        deadline=time.monotonic()+15
        while not uplink(server,old['server']):
            if time.monotonic()>=deadline:raise Uncertain()
            time.sleep(.2)
    write('orbit.dns-link','orbit\n'+server+'\n'+'\n'.join(domains)+'\n')

def hub(data):
    config=data['config']
    if not config or len(config)>1024*1024 or any(re.match(r'(?i)^\s*(PreUp|PostUp|PreDown|PostDown)\s*=',line) for line in config.splitlines()):refuse('wireguard.hub_config_invalid')
    write('orbit-candidate.conf',config)
    if command('wg-quick',['strip',WG+'/orbit-candidate.conf'],check=False).returncode:
        remove('orbit-candidate.conf');refuse('wireguard.hub_config_invalid')
    prior=read('orbit.conf',True);saved=state();runtime=None
    try:
        command('sysctl',['-w','net.ipv4.ip_forward=1']);write('orbit.conf',config);command('systemctl',['enable',SERVICE])
        if saved['active']:
            runtime='orbit-native-runtime.conf';write(runtime,command('wg-quick',['strip',WG+'/orbit.conf']).stdout)
            command('wg',['syncconf','orbit',WG+'/'+runtime])
        else:command('systemctl',['start',SERVICE])
        command('systemctl',['is-active','--quiet',SERVICE]);command('systemctl',['is-enabled','--quiet',SERVICE])
    except Exception:
        try:
            if prior is None:remove('orbit.conf')
            else:write('orbit.conf',prior)
            set_state(saved)
        except Exception:raise Uncertain()
        refuse('wireguard.hub_start_failed')
    finally:
        remove('orbit-candidate.conf')
        if runtime:remove(runtime)
    return {}

def prepare(data):
    existing=read('orbit.key',True)
    if existing is None:
        if data['existing']:refuse('wireguard.peer_key_missing')
        generated=command('wg',['genkey']).stdout.strip()
        if not re.fullmatch(r'[A-Za-z0-9+/]{43}=',generated):raise Uncertain()
        write('orbit.key',generated+'\n')
    return {'public_key':key()[1]}

def render_peer(private,data):
    return '[Interface]\nAddress = '+data['address']+'\nPrivateKey = '+private.strip()+'\n'+hook(data['dns_server'],data['domains'])+'\n\n[Peer]\nPublicKey = '+data['server_public_key']+'\nEndpoint = '+data['endpoint']+'\nAllowedIPs = '+data['subnet']+'\nPersistentKeepalive = 25\n'

def recover_peer(data):
    raw=read('.orbit.native-network-transaction',True)
    private=read('orbit.key',True)
    if private is None:
        if raw is not None:raise Uncertain()
        return {'restore_public_key':True}
    _,public=key()
    peer=dict(data['peer'],peer_public_key=public)
    if raw is not None:
        saved=json.loads(raw)
        owner='peer-'+hashlib.sha256(json.dumps(peer,sort_keys=True,separators=(',',':')).encode()).hexdigest()
        if saved.get('owner')!=owner:refuse('wireguard.peer_recovery_pending')
        restore(saved)
        return {'restore_public_key':True}
    # A commit may have finished before its Gateway callback was lost. Keep its
    # key when the exact desired local configuration is active; never infer root success.
    committed=(read('orbit.conf',True)==render_peer(private,peer) and state()['active']
        and command('wg',['show','orbit','public-key']).stdout.strip()==public
        and dns_state()=={'link':'orbit','server':peer['dns_server'],'domains':peer['domains']})
    return {'restore_public_key':not committed}

def publish(data):
    private,public=key()
    if public!=data['peer_public_key']:refuse('vpn.peer_key_mismatch')
    owner='peer-'+hashlib.sha256(json.dumps(data,sort_keys=True,separators=(',',':')).encode()).hexdigest()
    saved,resumed=transaction(owner)
    if resumed:
        # A pending transaction may precede any write or contain only a partial
        # publication. Restore its exact baseline, then apply every desired field.
        restore(saved);saved,_=transaction(owner)
    if data['operator_dns'] is None and not uplink(data['dns_server'],saved['resolver']['server'] if saved['resolver'] else '') and (not saved['resolver'] or saved['resolver']['link']=='orbit'):
        remove('.orbit.native-network-transaction');refuse('wireguard.peer_dns_uplink_missing')
    config=render_peer(private,data)
    try:
        write('orbit-candidate.conf',config)
        if command('wg-quick',['strip',WG+'/orbit-candidate.conf'],check=False).returncode:raise Uncertain()
        command('systemctl',['stop',SERVICE]);write('orbit.conf',config)
        command('systemctl',['unmask',SERVICE]);command('systemctl',['enable',SERVICE]);command('systemctl',['start',SERVICE])
        apply_dns(data['dns_server'],data['domains'],saved['resolver'])
        command('systemctl',['is-active','--quiet',SERVICE])
        if command('wg',['show','orbit','public-key']).stdout.strip()!=public:raise Uncertain()
        return {'public_key':public}
    except Exception:
        try:restore(saved)
        except Exception:raise Uncertain()
        refuse('wireguard.peer_config_failed')
    finally:remove('orbit-candidate.conf')

def commit(data):
    if key()[1]!=data['public_key'] or command('wg',['show','orbit','public-key']).stdout.strip()!=data['public_key']:raise Uncertain()
    remove('.orbit.native-network-transaction');return {}

def rollback(data):
    raw=read('.orbit.native-network-transaction',True)
    if raw is not None:restore(json.loads(raw))
    return {}

def repair_dns(data):
    if not state()['active'] or command('wg',['show','orbit','public-key']).stdout.strip()!=data['public_key']:refuse('wireguard.peer_dns_state_unsupported')
    old=dns_state()
    if old is None:refuse('wireguard.peer_dns_state_unsupported')
    if old['link']=='orbit' and not uplink(data['dns_server'],old['server']):refuse('wireguard.peer_dns_uplink_missing')
    owner='dns-'+hashlib.sha256(json.dumps(data,sort_keys=True,separators=(',',':')).encode()).hexdigest()
    saved,resumed=transaction(owner)
    if resumed:restore(saved);saved,_=transaction(owner)
    config=read('orbit.conf')
    lines=config.splitlines();hooks=[i for i,line in enumerate(lines) if re.match(r'^PostUp\s*=\s*resolvectl dns %i ',line)]
    if len(hooks)>1:refuse('wireguard.peer_dns_state_unsupported')
    if hooks:lines[hooks[0]]=hook(data['dns_server'],data['domains'])
    elif lines.count('[Interface]')==1:lines.insert(lines.index('[Interface]')+1,hook(data['dns_server'],data['domains']))
    else:refuse('wireguard.peer_dns_state_unsupported')
    try:
        candidate='\n'.join(lines)+'\n';write('orbit-candidate.conf',candidate)
        if command('wg-quick',['strip',WG+'/orbit-candidate.conf'],check=False).returncode:raise Uncertain()
        write('orbit.conf',candidate);apply_dns(data['dns_server'],data['domains'],old)
        remove('.orbit.native-network-transaction');return {}
    except Exception:
        try:restore(saved)
        except Exception:raise Uncertain()
        refuse('wireguard.peer_dns_apply_failed')
    finally:remove('orbit-candidate.conf')

def main():
    if os.geteuid()!=ROOT_UID:raise Uncertain()
    if not os.path.exists(WG):os.mkdir(WG,0o700)
    s=os.lstat(WG)
    if not stat.S_ISDIR(s.st_mode) or s.st_uid!=ROOT_UID or s.st_mode&0o077:raise Uncertain()
    raw=sys.stdin.read(1024*1024+1)
    if len(raw)>1024*1024:raise Uncertain()
    message=json.loads(raw)
    if any(os.path.lexists(WG+'/'+name) for name in ['.orbit.peer-transaction','.orbit.conf.rollback','.orbit.dns-link.rollback']):refuse('wireguard.peer_recovery_pending')
    parent=os.lstat(os.path.dirname(LOCK))
    if not stat.S_ISDIR(parent.st_mode) or parent.st_uid!=ROOT_UID:raise Uncertain()
    if parent.st_mode&0o022 and not (os.path.dirname(LOCK)=='/run/lock' and parent.st_mode&stat.S_ISVTX):raise Uncertain()
    fd=os.open(LOCK,os.O_CREAT|os.O_RDWR|os.O_NOFOLLOW|os.O_CLOEXEC,0o600)
    try:
        locked=os.fstat(fd)
        if not stat.S_ISREG(locked.st_mode) or locked.st_uid!=ROOT_UID or stat.S_IMODE(locked.st_mode)!=0o600:raise Uncertain()
        deadline=time.monotonic()+30
        while True:
            try:fcntl.flock(fd,fcntl.LOCK_EX|fcntl.LOCK_NB);break
            except BlockingIOError:
                if time.monotonic()>deadline:refuse('wireguard.peer_busy')
                time.sleep(.05)
        actions={'wireguard.prepare-peer-key':prepare,'wireguard.publish-peer':publish,'wireguard.commit-peer':commit,'wireguard.rollback-peer':rollback,'wireguard.recover-peer':recover_peer,'wireguard.project-hub':hub,'wireguard.repair-peer-dns':repair_dns}
        if message['name'] not in actions:raise Uncertain()
        return actions[message['name']](message['input'])
    finally:os.close(fd)
try:print(json.dumps(main(),separators=(',',':')))
except Refusal as error:print(json.dumps({'error_code':str(error)},separators=(',',':')))
except Exception:sys.stdout.write('{}');sys.exit(1)
`;
