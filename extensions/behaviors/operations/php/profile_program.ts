import { commonPhpProgram } from "./common_program.ts";

// Fixed Swamp-owned local program. Inputs enter only through stdin.
export const phpProfileProgram = commonPhpProgram + "\n" +
  String.raw`def size_bytes(value):
    m=re.fullmatch(r'([0-9]+)([KMG]?)',value)
    if not m:return None
    n=int(m[1])*{'':1,'K':1024,'M':1024**2,'G':1024**3}[m[2]]
    return n if 0<n<=1024**3 else None
def settings(i):
    out={}
    for raw in i['runtime_ini'].splitlines():
        line=raw.strip()
        if not line or line.startswith(';'):continue
        key,sep,value=line.partition('=');key=key.strip();value=value.strip()
        valid=False
        if key in ('post_max_size','upload_max_filesize'):valid=size_bytes(value) is not None
        elif key=='opcache.enable':valid=value=='On'
        elif key=='opcache.jit':valid=value=='disable'
        elif key=='opcache.jit_buffer_size':valid=value=='0'
        elif key in ('opcache.memory_consumption','opcache.interned_strings_buffer','opcache.max_accelerated_files'):valid=bool(re.fullmatch(r'\+?[0-9]+',value)) and 0<int(value)<=4294967295
        require(sep and valid and key not in out,'php.runtime_input_invalid');out[key]=value
    require(len(out)==8 and size_bytes(out['upload_max_filesize'])<=size_bytes(out['post_max_size']),'php.runtime_input_invalid')
    return out
def configure_runtime(i,h):
    expected=settings(i);release_check(h,i['release'],'php.runtime_platform_unsupported');v=i['version']
    binary=h.path('/usr/sbin/php-fpm'+v)
    if not binary.exists():return dict(changed=False,reloaded=False,deferred=[])
    available=h.path('/etc/php/'+v+'/mods-available/orbit-runtime.ini');enabled=h.path('/etc/php/'+v+'/fpm/conf.d/99-orbit-runtime.ini')
    shared=h.path('/etc/php/'+v+'/fpm/php-fpm.conf');unit='php'+v+'-fpm.service'
    require(not lexists(enabled) or enabled.is_symlink(),'php.runtime_configuration_failed',restored=True)
    before_file=available.read_bytes() if available.exists() else None;before_link=os.readlink(enabled) if enabled.is_symlink() else None;before_shared=shared.read_bytes().decode('utf-8')
    correct=enabled.exists() and available.exists() and enabled.resolve()==available.resolve()
    configuration=fpm_configuration(before_shared);changed=before_file!=i['runtime_ini'].encode() or not correct or before_shared!=configuration
    touched=False;reloaded=[];deferred=[];masters={}
    try:
        if changed:
            touched=True;h.publish(available,i['runtime_ini']);h.publish(shared,configuration)
            if not correct:remove(enabled);enabled.symlink_to('../../mods-available/orbit-runtime.ini')
        info=h.run([binary,'-i'])[1]
        require(str(enabled) in info and all(any(line.strip()==key+' => '+value+' => '+value for line in info.splitlines()) for key,value in expected.items()),'php.runtime_configuration_failed')
        if changed:
            units,skipped=fpm_units(h,v,'all');deferred+=skipped
            for name,config in units:
                if name!=unit:
                    previous=config.read_bytes().decode('utf-8');next_text=fpm_configuration(previous)
                    if previous!=next_text:masters[config]=previous;h.publish(config,next_text)
                args=[binary,'-t']+(['--fpm-config',config] if name!=unit else [])
                if h.run(args,False)[0]!=0:deferred.append(name);continue
                reloaded.append((name,args));h.run(['systemctl','reload',name])
        h.run(['systemctl','enable',unit]);h.run([h.path('/usr/bin/php'+v),'-v']);h.run([binary,'-v'])
        require('pcov' not in modules(h,binary),'php.runtime_configuration_failed')
        h.run(['systemctl','is-enabled','--quiet',unit])
        return dict(changed=changed,reloaded=bool(reloaded),deferred=deferred)
    except BaseException:
        signal.signal(signal.SIGTERM,signal.SIG_IGN);signal.signal(signal.SIGINT,signal.SIG_IGN);h.deadline=max(h.deadline,time.monotonic()+120)
        restored=True
        def attempt(f):
            try:f();return True
            except Exception:return False
        if touched:
            restored &= attempt(lambda:h.publish(shared,before_shared))
            for p,prior in masters.items():restored &= attempt(lambda p=p,prior=prior:h.publish(p,prior))
            restored &= attempt(lambda:remove(available) if before_file is None else h.publish(available,before_file))
            restored &= attempt(lambda:remove(enabled))
            if before_link is not None:restored &= attempt(lambda:enabled.symlink_to(before_link))
            for name,args in reloaded:
                restored &= attempt(lambda args=args,name=name:(h.run(args),h.run(['systemctl','reload',name])))
        raise Refused('php.runtime_configuration_failed',restored=restored)
if __name__=='__main__':execute(configure_runtime, 'php.runtime_configuration_failed')
`;
