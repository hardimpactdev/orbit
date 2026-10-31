// Fixed Swamp-owned local program. Inputs enter only through stdin.
export const commonPhpProgram = String
  .raw`import contextlib, glob, hashlib, json, os, pathlib, re, signal, socket, stat, struct, subprocess, sys, tempfile, time, urllib.parse, uuid
P = pathlib.Path
class Refused(Exception):
    def __init__(self, code, **details):
        self.code, self.details = code, details
        super().__init__(code)
def require(ok, code, **details):
    if not ok: raise Refused(code, **details)
def lexists(p): return os.path.lexists(p)
def remove(p):
    try: p.unlink()
    except FileNotFoundError: pass
def paths(p): return sorted(p.iterdir()) if p.exists() else []
def sha(p):
    h=hashlib.sha256()
    with p.open('rb') as f:
        for chunk in iter(lambda:f.read(1048576),b''): h.update(chunk)
    return h.hexdigest()
class Host:
    def __init__(self):
        self.root=P('/'); self.uid=os.getuid(); self.gid=os.getgid(); self.tmp=None
        self.deadline=time.monotonic()+880
    def path(self, p):
        p=P(p)
        require(p.is_absolute() and '..' not in p.parts, 'php.build_execution_failed')
        return self.root / str(p).lstrip('/')
    def run(self, args, required=True, env=None, timeout=300):
        remaining=self.deadline-time.monotonic()
        require(remaining>0, 'php.build_execution_failed')
        # Capture privately on disk and read a bound. Neither stderr nor command text leaves the worker.
        with tempfile.TemporaryFile() as out:
            result=subprocess.run(list(map(str,args)),stdin=subprocess.DEVNULL,stdout=out,stderr=subprocess.DEVNULL,
                env=dict(os.environ,LC_ALL='C',**(env or {})),timeout=min(timeout,remaining))
            require(out.tell()<=8388608,'php.build_execution_failed')
            out.seek(0); text=out.read().decode('utf-8',errors='replace')
        require(not required or result.returncode==0,'php.build_execution_failed')
        return result.returncode,text
    def ping(self, path):
        end=min(self.deadline,time.monotonic()+5)
        def record(kind,body): return struct.pack('!BBHHBB',1,kind,1,len(body),0,0)+body
        params=b''
        for key,value in ((b'SCRIPT_FILENAME',b'/orbit-health-check.php'),(b'REQUEST_METHOD',b'GET')):
            params+=bytes((len(key),len(value)))+key+value
        def remaining():
            value=end-time.monotonic();require(value>0,'php.build_fpm_invalid');return value
        with socket.socket(socket.AF_UNIX) as s:
            s.settimeout(remaining());s.connect(str(path))
            s.settimeout(remaining())
            s.sendall(record(1,bytes((0,1,0,0,0,0,0,0)))+record(4,params)+record(4,b'')+record(5,b''))
            def read(n):
                data=b''
                while len(data)<n:
                    s.settimeout(remaining()); part=s.recv(n-len(data))
                    require(bool(part),'php.build_fpm_invalid');data+=part
                return data
            total=0
            while True:
                header=read(8); ver,kind,request,length,padding,_=struct.unpack('!BBHHBB',header)
                total+=8+length+padding
                require(ver==1 and request==1 and total<=1048576,'php.build_fpm_invalid')
                read(length+padding)
                if kind==3:
                    require(length==8,'php.build_fpm_invalid'); return True
    def directory(self,p):
        require(p.is_relative_to(self.root),'php.build_execution_failed')
        if not lexists(p):
            self.directory(p.parent);p.mkdir(mode=0o755)
        m=p.lstat()
        require(stat.S_ISDIR(m.st_mode) and m.st_uid==self.uid and m.st_gid==self.gid,'php.build_execution_failed')
    def publish(self,p,data,mode=0o644,uid=None,gid=None):
        self.directory(p.parent)
        if lexists(p):
            m=p.lstat();require(stat.S_ISREG(m.st_mode) and m.st_uid==(self.uid if uid is None else uid),'php.build_execution_failed')
        fd,tmp=tempfile.mkstemp(prefix='.orbit-php-',dir=p.parent)
        try:
            with os.fdopen(fd,'wb') as f:
                f.write(data.encode() if isinstance(data,str) else data);f.flush()
                os.fchmod(f.fileno(),mode);os.fchown(f.fileno(),self.uid if uid is None else uid,self.gid if gid is None else gid)
                os.fsync(f.fileno())
            os.replace(tmp,p)
        finally:
            if lexists(tmp): os.unlink(tmp)
def release_check(h, release, code):
    if release is None: return
    found={}
    try:
        for line in h.path('/etc/os-release').read_bytes().decode('utf-8').splitlines():
            k,sep,v=line.partition('=')
            if sep: found[k]=v.strip('"\'')
    except OSError: pass
    require(found.get('ID')==release['id'] and found.get('VERSION_CODENAME') in release['codenames'],code)
def module_links(h,v):
    return {s:{p.name:os.readlink(p) for p in paths(h.path('/etc/php/'+v+'/'+s+'/conf.d')) if p.suffix=='.ini' and p.is_symlink()} for s in ('cli','fpm')}
def restore_links(h,v,wanted):
    current=module_links(h,v)
    for sapi,links in wanted.items():
        directory=h.path('/etc/php/'+v+'/'+sapi+'/conf.d')
        for name,target in current[sapi].items():
            if links.get(name)!=target: remove(directory/name)
        for name,target in links.items():
            if current[sapi].get(name)==target: continue
            require(not lexists(directory/name),'php.build_execution_failed');(directory/name).symlink_to(target)
def modules(h,binary):
    return {l.strip().lower() for l in h.run([binary,'-m'])[1].splitlines() if l.strip() and not l.strip().startswith('[')}
def fpm_units(h,v,scope):
    shared='php'+v+'-fpm.service';units=[];skipped=[]
    if h.run(['systemctl','is-active','--quiet',shared],False)[0]==0:
        units.append((shared,h.path('/etc/php/'+v+'/fpm/php-fpm.conf')))
    listed=h.run(['systemctl','list-units','--type=service','--state=active','--no-legend','--plain','orbit-*-php'+v+'-fpm.service'],False)[1]
    for line in listed.splitlines():
        unit=line.split()[0] if line.split() else ''
        if not re.fullmatch(r'orbit-[a-z0-9_-]+-php'+re.escape(v)+r'-fpm\.service',unit): continue
        start=h.run(['systemctl','show','-p','ExecStart','--value',unit],False)[1]
        found=re.search(r'--fpm-config\s+(\S+)',start)
        require(found is not None,'php.build_execution_failed')
        configuration=h.path(found[1])
        if scope=='shared': skipped.append(unit)
        else: units.append((unit,configuration))
    return units,skipped
def fpm_configuration(text):
    has_global=any(l.strip()=='[global]' for l in text.splitlines());emitted=not has_global;global_section=True
    out='' if has_global else '[global]\nprocess_control_timeout = 30s\n'
    for line in text.splitlines(keepends=True):
        trimmed=line.strip()
        if trimmed.startswith('['): global_section=trimmed=='[global]'
        if global_section and trimmed=='[global]' and not emitted:
            out+=line+'process_control_timeout = 30s\n';emitted=True;continue
        if global_section and trimmed.partition('=')[0].strip()=='process_control_timeout': continue
        out+=line
    return out
def execute(action, failure='php.build_execution_failed'):
    def interrupted(signum,frame): raise Refused('php.build_execution_failed')
    signal.signal(signal.SIGTERM,interrupted);signal.signal(signal.SIGINT,interrupted)
    try:
        payload=json.load(sys.stdin);result=action(payload['input'],Host());print(json.dumps(result,separators=(',',':')))
    except Refused as e: print(json.dumps(dict(error_code=e.code,**e.details),separators=(',',':')))
    except Exception: print(json.dumps(dict(error_code=failure)))
`;
