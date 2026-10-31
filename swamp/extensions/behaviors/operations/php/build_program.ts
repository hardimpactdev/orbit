import { commonPhpProgram } from "./common_program.ts";

// Fixed Swamp-owned local program. Inputs enter only through stdin.
export const phpBuildProgram = commonPhpProgram + "\n" +
  String.raw`CACHE='/var/cache/orbit/php-packages'
BACKUP='/var/cache/orbit/php-unowned-extensions'
def installed(h):
    text=h.run(['dpkg-query','-W','-f=$'+'{Package}\t$'+'{Status}\t$'+'{Version}\n'])[1];out={}
    for line in text.splitlines():
        fields=line.split('\t');require(len(fields)==3,'php.build_execution_failed')
        if fields[1]=='install ok installed': out[fields[0]]=fields[2]
    return out
def unowned(h,v,extensions):
    loading={};all_paths=[]
    for ini in paths(h.path('/etc/php/'+v+'/mods-available')):
        if ini.suffix!='.ini': continue
        libs=[]
        for match in re.finditer(r'(?m)^\s*(?:zend_)?extension\s*=\s*"?([^"\s;]+)',ini.read_bytes().decode('utf-8')):
            library=P(match[1]);stem=library.stem.lower();module='zend opcache' if stem=='opcache' else stem
            candidates=[h.path(str(library))] if library.is_absolute() else sorted(h.path('/usr/lib/php').glob('*/'+stem+'.so'))
            present=[p for p in candidates if p.is_file()];all_paths+=present;libs.append((module,present))
        if libs: all_paths.append(ini);loading[ini]=libs
    known=set()
    if all_paths:
        code,text=h.run(['dpkg','-S','--',*all_paths],False);require(code in (0,1),'php.build_execution_failed')
        known={P(l.split(': ',1)[1]) for l in text.splitlines() if ': ' in l}&set(all_paths)
    out=[]
    for ini,libs in sorted(loading.items()):
        if ini in known: continue
        names=set();members=[ini]
        for module,present in libs:
            unknown=[p for p in present if p not in known]
            if module not in extensions or unknown: names.add(module)
            members+=unknown
        if names: out.append(('+'.join(sorted(names)),members))
    return out
def prepare(h,i):
    release_check(h,i.get('release'),'php.build_platform_unsupported')
    architecture=h.run(['dpkg','--print-architecture'])[1].strip()
    require(architecture=='amd64','php.build_platform_unsupported')
    pins={}
    for v,b in sorted(i['versions'].items()):
        for pin in b['packages']:
            require(pin['architecture']==architecture,'php.build_download_failed')
            if pin['name'] in pins:
                prior=pins[pin['name']];require(all(prior[k]==pin[k] for k in ('version','sha256','url')),'php.build_input_invalid')
            pins[pin['name']]=pin
    sources=[];retired=h.path('/etc/apt/sources.list.d/orbit-php.sources')
    for p in [h.path('/etc/apt/sources.list'),*paths(h.path('/etc/apt/sources.list.d'))]:
        if p==retired or not p.is_file() or (p.suffix and p.suffix not in ('.list','.sources')): continue
        if re.search(r'ppa\.launchpadcontent\.net/ondrej/php|packages\.sury\.org/php|ondrej/php',p.read_bytes().decode('utf-8'),re.I): sources.append(p)
    require(not sources or i['extras']=='purge','php.build_conflicting_source')
    old=installed(h)
    extras=sorted({n for v,b in i['versions'].items() for n in old if n.startswith('php'+v+'-') and n not in {p['name'] for p in b['packages']} and n!='php'+v+'-opentelemetry'})
    require(not extras or i['extras']!='refuse','php.build_extra_packages')
    unknown={v:unowned(h,v,set(b['extensions'])) for v,b in sorted(i['versions'].items())}
    names=sorted({'php'+v+':'+name for v,entries in unknown.items() for name,_ in entries})
    require(not names or i['extras']!='refuse','php.build_unowned_extensions')
    pending=sorted(n for n,p in pins.items() if old.get(n)!=p['version']);repair=[]
    for name in sorted(set(pins)&set(old)-set(pending)):
        code,text=h.run(['dpkg','--verify','--',name],False)
        require(code in (0,1),'php.build_execution_failed')
        for line in text.splitlines():
            f=line.split()
            if len(f)<2 or (len(f)==3 and f[1]=='c'): continue
            if f[0]=='missing' or len(f[0])>2 and f[0][2]=='5': repair.append(name);break
    return dict(old=old,pins=dict(sorted(pins.items())),sources=sources,extras=extras,unowned=unknown,names=names,pending=pending,repair=repair)
def metadata_matches(h,p,name,version,arch):
    fields={}
    for l in h.run(['dpkg-deb','--field',p,'Package','Version','Architecture'])[1].splitlines():
        if ': ' in l:
            k,v=l.split(': ',1);fields[k]=v
    return fields.get('Package')==name and fields.get('Version')==version and fields.get('Architecture') in (arch,'all')
def keep(h,source,name):
    digest=sha(source);directory=h.path(CACHE);h.directory(directory);target=directory/(name+'_'+digest+'.deb')
    if lexists(target):
        m=target.lstat();require(stat.S_ISREG(m.st_mode) and m.st_uid==h.uid,'php.build_execution_failed')
        if sha(target)==digest: return target
    # Copy and verify without retaining package contents in memory.
    fd,candidate=tempfile.mkstemp(prefix='.orbit-php-',dir=directory)
    try:
        with os.fdopen(fd,'wb') as out,source.open('rb') as src:
            for chunk in iter(lambda:src.read(1048576),b''): out.write(chunk)
            out.flush();os.fchmod(out.fileno(),0o644);os.fchown(out.fileno(),h.uid,h.gid);os.fsync(out.fileno())
        require(sha(P(candidate))==digest,'php.build_download_failed');os.replace(candidate,target)
    finally:
        if lexists(candidate):os.unlink(candidate)
    return target
def download(h,pins,mirror,work):
    found={};remaining={};by_name={p['name']:p for p in pins}
    for pin in pins:
        p=h.path(CACHE)/(pin['name']+'_'+pin['sha256']+'.deb')
        if p.is_file() and not p.is_symlink() and sha(p)==pin['sha256']: found[pin['name']]=p
        else:
            urls=([mirror+'/'+pin['name']+'_'+pin['sha256']+'.deb'] if mirror else [])
            if pin['url'] not in urls:urls.append(pin['url'])
            remaining[pin['name']]=urls
    while remaining:
        argv=['curl','--parallel','--parallel-immediate','--parallel-max','4'];candidates={}
        limit=str(300/((len(remaining)+3)//4))
        for name,urls in sorted(remaining.items()):
            require(bool(urls),'php.build_download_failed');url=urls.pop(0);p=work/(name+'.deb');remove(p)
            if candidates:argv.append('--next')
            argv+=['--fail','--silent','--show-error','--location','--proto','=https','--proto-redir','=https','--tlsv1.2','--connect-timeout','15','--max-time',limit,'--max-filesize','536870912','--write-out','%{json}\n','--output',str(p),'--url',url];candidates[name]=p
        _,text=h.run(argv,False,timeout=300)
        try:reports=[json.loads(l) for l in text.splitlines()];results={P(r['filename_effective']):r['exitcode'] for r in reports}
        except (ValueError,KeyError,TypeError):raise Refused('php.build_download_failed')
        require(len(reports)==len(candidates) and set(results)==set(candidates.values()),'php.build_download_failed')
        for name,p in candidates.items():
            if results[p]!=0:continue
            pin=by_name[name]
            require(p.is_file() and not p.is_symlink() and sha(p)==pin['sha256'],'php.build_download_failed')
            require(metadata_matches(h,p,name,pin['version'],'amd64'),'php.build_download_failed')
            found[name]=keep(h,p,name);del remaining[name]
    return [found[p['name']] for p in pins]
def matches_installed(h,archive,name,work):
    info=h.path('/var/lib/dpkg/info');possible=[info/(name+'.md5sums'),*sorted(info.glob(name+':*.md5sums'))]
    sums=next((p for p in possible if p.is_file()),None)
    if sums is None:return False
    directory=work/('extract-'+uuid.uuid4().hex);directory.mkdir()
    if h.run(['dpkg-deb','--extract','--',archive,directory],False)[0]!=0:return False
    for line in sums.read_bytes().decode('utf-8').splitlines():
        if '  ' not in line:return False
        expected,relative=line.split('  ',1);relative=P(relative.strip())
        if relative.is_absolute() or '..' in relative.parts:return False
        member=directory/relative
        if not member.is_file():return False
        if h.run(['md5sum','--',member])[1].split()[0]!=expected.strip():return False
    return True
def previous_packages(h,names,old,pin,work):
    found={}
    for name in names:
        version=old[name]
        for p in sorted(h.path(CACHE).glob(name+'_*.deb')):
            if p.is_file() and not p.is_symlink() and p.stem==name+'_'+sha(p) and metadata_matches(h,p,name,version,'amd64'):
                found[name]=p;break
        if name in found:continue
        for p in sorted(h.path('/var/cache/apt/archives').glob(name+'_*.deb')):
            if metadata_matches(h,p,name,version,'amd64') and matches_installed(h,p,name,work):found[name]=keep(h,p,name);break
        if name in found:continue
        if 'dpkg-repack' not in old:
            code,_=h.run(['apt-get','-o','DPkg::Lock::Timeout=300','install','--yes','--no-install-recommends','--no-remove',pin['package']+'='+pin['version']],False,env={'DEBIAN_FRONTEND':'noninteractive'})
            require(code==0,'php.build_rollback_unavailable');old['dpkg-repack']=pin['version']
        directory=work/('repack-'+uuid.uuid4().hex);directory.mkdir()
        h.run(['env','-C',directory,'dpkg-repack','--generate','--',name],False)
        trees=[p for p in paths(directory) if p.is_dir() and p.name.startswith('dpkg-repack.'+name+'.')]
        require(len(trees)==1,'php.build_rollback_unavailable');tree=trees[0];conffiles=tree/'DEBIAN/conffiles'
        listed=conffiles.read_bytes().decode('utf-8') if conffiles.exists() else ''
        kept=[l for l in listed.splitlines() if l.strip() and (tree/l.strip().lstrip('/')).exists()]
        if len(kept)!=len([l for l in listed.splitlines() if l.strip()]):
            if kept:h.publish(conffiles,'\n'.join(kept)+'\n')
            else:remove(conffiles)
        output=directory/(name+'_repacked.deb');h.run(['dpkg-deb','--build','--',tree,output],False)
        require(output.is_file() and metadata_matches(h,output,name,version,'amd64'),'php.build_rollback_unavailable')
        found[name]=keep(h,output,name)
    return found
@contextlib.contextmanager
def policy(h):
    path=h.path('/usr/sbin/policy-rc.d');created=False
    if not lexists(path):
        h.directory(path.parent)
        fd=os.open(path,os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,0o755)
        with os.fdopen(fd,'wb') as f:f.write(b'#!/bin/sh\nexit 101\n');f.flush();os.fchmod(f.fileno(),0o755);os.fsync(f.fileno())
        created=True
    try:yield
    finally:
        if created:remove(path)
def install_packages(h,packages,reinstall):
    with policy(h):
        args=['apt-get','-o','DPkg::Lock::Timeout=300','-o','Dpkg::Options::=--force-confold','install','--yes','--no-install-recommends','--no-remove','--allow-downgrades']
        if reinstall:args.append('--reinstall')
        return h.run([*args,'--',*packages],False,env={'DEBIAN_FRONTEND':'noninteractive','NEEDRESTART_SUSPEND':'1'})[0]==0
def sockets(h,configuration):
    pending=[configuration];seen=set();out=set()
    while pending:
        p=pending.pop()
        if p in seen or not p.is_file():continue
        seen.add(p)
        for line in p.read_bytes().decode('utf-8').splitlines():
            key,sep,value=line.partition('=')
            if not sep:continue
            value=value.split(';')[0].strip();key=key.strip()
            if key=='include':pending.extend(map(P,glob.glob(str(h.path(value) if value.startswith('/') else p.parent/value))))
            elif key=='listen' and value.startswith('/'):out.add(h.path(value))
    return sorted(out)
def refresh(h,v,unit,configuration,otel,action,touched):
    binary=h.path('/usr/sbin/php-fpm'+v);args=[binary,'-t']
    if not unit.startswith('php'):args+=['--fpm-config',configuration]
    if h.run(args,False)[0]!=0:return 'fpm_invalid'
    touched.append(unit)
    if h.run(['systemctl',action,unit],False)[0]!=0 or h.run(['systemctl','is-active','--quiet',unit],False)[0]!=0:return 'service'
    loaded=modules(h,binary)
    required={'bcmath','curl','gd','imagick','intl','mbstring','mysqli','pdo_mysql','pdo_pgsql','pdo_sqlite','redis','simplexml','xml','zip'}
    if 'pcov' in loaded or not required<=loaded or ('opentelemetry' in loaded)!=otel:return 'modules'
    for p in sockets(h,configuration):
        try:
            if not h.ping(p):return 'socket'
        except (OSError,Refused):return 'socket'
    return None
def disable_fpm(h,versions):
    changed=False
    for v in versions:
        unit='php'+v+'-fpm.service';enabled=h.run(['systemctl','is-enabled',unit],False)[1].strip();active=h.run(['systemctl','is-active','--quiet',unit],False)[0]==0
        stopped=enabled!='masked' or not active or h.run(['systemctl','stop',unit],False)[0]==0
        disabled=enabled=='masked' or h.run(['systemctl','disable','--now',unit],False)[0]==0
        require(stopped and disabled and h.run(['systemctl','is-enabled',unit],False)[1].strip() in ('disabled','masked') and h.run(['systemctl','is-active','--quiet',unit],False)[0]!=0,'php.build_fpm_invalid')
        changed|=active or enabled not in ('disabled','masked')
    return changed
def install_build(i,h):
    prepared=prepare(h,i);versions=sorted(i['versions']);old=prepared['old'];pins=prepared['pins']
    for p in [h.path('/etc/apt/sources.list.d/orbit-php.sources'),h.path('/usr/share/keyrings/orbit-sury-php.gpg'),*prepared['sources']]:remove(p)
    h.directory(h.path(CACHE));before={v:module_links(h,v) for v in versions}
    pools=[h.path('/etc/php/'+v+'/fpm/pool.d/www.conf') for v in versions if 'php'+v+'-fpm' in old and not lexists(h.path('/etc/php/'+v+'/fpm/pool.d/www.conf'))]
    purge=prepared['extras'] if i['extras']=='purge' else [];selected=prepared['pending']+prepared['repair']
    replaced=[n for n in selected if n in old]+purge;added=[n for n in prepared['pending'] if n not in old]
    with tempfile.TemporaryDirectory(prefix='orbit-php-',dir=h.tmp) as work:
        downloads=download(h,[pins[n] for n in selected],i.get('mirror',''),P(work))
        previous=previous_packages(h,replaced,old,i['repack'],P(work))
    moved=[];ini={};restarted=[];reloaded=[];reinstall=bool(prepared['repair'])
    def rollback():
        ok=True
        def attempt(f):
            try:return f() is not False
            except Exception:return False
        for iteration in range(2):
            try:
                current=installed(h);present=[n for n in added if n in current]
                if present:ok &= attempt(lambda:h.run(['dpkg','--remove','--',*present],False)[0]==0)
            except Exception:ok=False
            if iteration==0 and previous:
                ok &= attempt(lambda:install_packages(h,list(previous.values()),reinstall))
                for p in pools:ok &= attempt(lambda p=p:remove(p))
        try:
            current=installed(h);ok &= all(current.get(n)==old[n] for n in replaced) and all(n not in current for n in added)
        except Exception:ok=False
        for original,kept in reversed(moved):ok &= attempt(lambda:os.replace(kept,original))
        for v in versions:ok &= attempt(lambda v=v:restore_links(h,v,before[v]))
        for p,value in ini.items():ok &= attempt(lambda p=p,value=value:remove(p) if value is None else h.publish(p,value))
        for action,units in (('restart',restarted),('reload',reloaded)):
            for unit in units:
                ok &= attempt(lambda:h.run(['systemctl',action,unit],False)[0]==0)
                ok &= attempt(lambda:h.run(['systemctl','is-active','--quiet',unit],False)[0]==0)
        return ok
    try:
        if i['extras']=='purge' and prepared['names']:
            backup=h.path(BACKUP)/uuid.uuid4().hex
            for v,entries in prepared['unowned'].items():
                for _,members in entries:
                    for sapi,links in module_links(h,v).items():
                        for name,target in links.items():
                            if P(target).name==members[0].name:remove(h.path('/etc/php/'+v+'/'+sapi+'/conf.d')/name)
                    for p in members:
                        if any(original==p for original,_ in moved):continue
                        kept=backup/v/p.relative_to(h.root);h.directory(kept.parent);os.replace(p,kept);moved.append((p,kept))
        if purge:require(h.run(['dpkg','--purge','--',*purge],False)[0]==0,'php.build_install_failed')
        success=not downloads or install_packages(h,downloads,reinstall)
        for p in pools:remove(p)
        require(success,'php.build_install_failed');current=installed(h)
        require(all(current.get(n)==p['version'] for n,p in pins.items()),'php.build_install_failed')
        for v,b in sorted(i['versions'].items()):
            for tool,sapi,module in (('phpenmod' if i['pcov'] else 'phpdismod','cli','pcov'),('phpdismod','fpm','pcov'),('phpdismod','cli','opentelemetry'),('phpenmod' if i['opentelemetry'] else 'phpdismod','fpm','opentelemetry')):h.run([tool,'-v',v,'-s',sapi,module])
            for sapi in ('cli','fpm'):
                p=h.path('/etc/php/'+v+'/'+sapi+'/conf.d/99-cve-2026-109618-upload-progress.ini');wanted=b'session.upload_progress.enabled = 0\n'
                prior=p.read_bytes() if p.exists() else None
                if prior!=wanted:ini[p]=prior;h.publish(p,wanted)
            expected=set(b['extensions'])-({'pcov'} if not i['pcov'] else set());loaded=modules(h,h.path('/usr/bin/php'+v))
            require(expected<=loaded and (i['extras']=='keep' or loaded<=expected),'php.build_modules_mismatch')
        changed=bool(selected or purge or moved) or any(module_links(h,v)!=before[v] for v in versions)
        action='restart' if changed else 'reload';changed|=bool(ini)
        disabled=disable_fpm(h,versions) if i['fpm']=='disabled' else False
        scope='all' if action=='reload' else i['fpm'];deferred=[];stale=[]
        if changed and i['fpm']!='disabled' and scope!='none':
            for v in versions:
                units,skipped=fpm_units(h,v,scope);stale+=skipped
                for unit,config in units:
                    reason=refresh(h,v,unit,config,i['opentelemetry'],action,restarted if action=='restart' else reloaded)
                    if reason is None:continue
                    if reason=='fpm_invalid' and (i['fpm']=='shared' or action=='reload' and i['fpm']=='none'):deferred.append(unit);continue
                    raise Refused('php.build_fpm_invalid' if reason=='fpm_invalid' else 'php.build_rolled_back')
        return dict(outcome='applied' if changed or disabled else 'current',data=dict(changed=prepared['pending'],repaired=prepared['repair'],purged=purge,disabled=prepared['names'] if moved else [],restarted=restarted,reloaded=reloaded,deferred=deferred,stale_masters=sorted(set(stale)),fpm_disabled=disabled))
    except BaseException as error:
        # Cancellation cannot interrupt restoration. Cleanup has its own bounded reserve.
        signal.signal(signal.SIGTERM,signal.SIG_IGN);signal.signal(signal.SIGINT,signal.SIG_IGN);h.deadline=max(h.deadline,time.monotonic()+120)
        restored=rollback()
        raise Refused((error.code if isinstance(error,Refused) else 'php.build_execution_failed') if restored else 'php.build_rollback_failed',restored=restored)
if __name__=='__main__':execute(install_build)
`;
