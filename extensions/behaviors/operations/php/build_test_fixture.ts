export const buildFixture = String
  .raw`MODULES={'bcmath','curl','gd','imagick','intl','mbstring','mysqli','pdo_mysql','pdo_pgsql','pdo_sqlite','redis','simplexml','xml','zip'}
def artifact(name,version,arch='amd64'):return ('Package: '+name+'\nVersion: '+version+'\nArchitecture: '+arch+'\n').encode()
def pin(name,version='2.0'):
    data=artifact(name,version)
    return dict(name=name,version=version,architecture='amd64',url='https://origin.example/'+name+'.deb',sha256=hashlib.sha256(data).hexdigest())
def input_build():return dict(versions={'8.5':dict(packages=[pin('php8.5-cli'),pin('php8.5-fpm')],extensions=sorted(MODULES))},mirror='https://mirror.example',pcov=False,opentelemetry=False,extras='refuse',fpm='all',repack=dict(package='dpkg-repack',version='1.0'),release=dict(id='ubuntu',codenames=['resolute']))
class Fake(Host):
    def __init__(self,root):
        super().__init__();self.root=root;self.tmp=str(root);self.packages={};self.calls=[];self.fail_install=False;self.fail_modules=False;self.bad_fpm=False;self.bad_ping=False;self.mirror_missing=False;self.bad_digest=False;self.bad_metadata=False;self.active=True;self.enabled='enabled';self.dedicated=True;self.required=set(MODULES);self.arch='amd64';self.owned=set();self.damaged=set();self.repack=[];self.info_override=False
        self.write('/etc/os-release','ID=ubuntu\nVERSION_CODENAME=resolute\n')
        for v in ('8.5',):
            self.write('/etc/php/'+v+'/fpm/php-fpm.conf','[global]\ninclude = /etc/php/'+v+'/fpm/pool.d/*.conf\n')
            self.write('/etc/php/'+v+'/mods-available/pcov.ini','extension=pcov.so\n');self.owned.add(self.path('/etc/php/'+v+'/mods-available/pcov.ini'))
            self.write('/etc/php/'+v+'/fpm/pool.d/www.conf','[www]\nlisten = /run/php/shared.sock\n')
            self.write('/etc/php/'+v+'/cli/conf.d/00-stock.ini','; stock\n')
            self.write('/etc/php/'+v+'/fpm/conf.d/00-stock.ini','; stock\n')
            self.write('/usr/sbin/php-fpm'+v,'binary');self.write('/usr/bin/php'+v,'binary')
        self.custom=self.write('/etc/orbit/php-fpm/example/generated/php-fpm.conf','[global]\ninclude = /etc/orbit/php-fpm/example/generated/pool.conf\n')
        self.write('/etc/orbit/php-fpm/example/generated/pool.conf','[orbit-example]\nlisten = /run/php/example.sock\n')
    def write(self,path,value):
        p=self.path(path);p.parent.mkdir(parents=True,exist_ok=True);p.write_bytes(value if isinstance(value,bytes) else value.encode());return p
    def cache(self,name,version):
        data=artifact(name,version);p=self.path(CACHE)/(name+'_'+hashlib.sha256(data).hexdigest()+'.deb');p.parent.mkdir(parents=True,exist_ok=True);p.write_bytes(data);return p
    def ping(self,path):return not self.bad_ping
    def run(self,args,required=True,env=None,timeout=300):
        args=list(map(str,args));self.calls.append(args);tool=P(args[0]).name;code=0;text=''
        if tool=='dpkg-query':text=''.join(n+'\tinstall ok installed\t'+v+'\n' for n,v in sorted(self.packages.items()))
        elif tool=='dpkg' and args[1]=='--print-architecture':text=self.arch+'\n'
        elif tool=='dpkg' and args[1]=='-S':
            text=''.join('owned: '+str(p)+'\n' for p in self.owned if str(p) in args);code=1
        elif tool=='dpkg' and args[1]=='--verify':
            if args[-1] in self.damaged:text='??5?????? /usr/bin/php\n';code=1
        elif tool=='dpkg' and args[1] in ('--remove','--purge'):
            for name in args[3:]:self.packages.pop(name,None)
        elif tool=='curl':
            results=[]
            for idx,arg in enumerate(args):
                if arg!='--output':continue
                p=P(args[idx+1]);url=args[idx+3];name=p.stem
                success=not(self.mirror_missing and url.startswith('https://mirror.'))
                if success:p.write_bytes(b'corrupt' if self.bad_digest else artifact(name,'2.0','arm64' if self.bad_metadata else 'amd64'))
                results.append(dict(filename_effective=str(p),exitcode=0 if success else 22))
            text=''.join(json.dumps(r)+'\n' for r in results)
        elif tool=='dpkg-deb' and args[1]=='--field':text=P(args[2]).read_text()
        elif tool=='dpkg-deb' and args[1]=='--extract':
            (P(args[-1])/'usr/bin').mkdir(parents=True);(P(args[-1])/'usr/bin/php8.5').write_text('installed')
        elif tool=='md5sum':text=hashlib.md5(P(args[-1]).read_bytes()).hexdigest()+'  '+args[-1]+'\n'
        elif tool=='env' and 'dpkg-repack' in args:
            name=args[-1];tree=P(args[2])/('dpkg-repack.'+name+'.fixture');(tree/'DEBIAN').mkdir(parents=True)
            (tree/'DEBIAN/control').write_bytes(artifact(name,self.packages[name]));(tree/'DEBIAN/conffiles').write_text('/etc/missing.conf\n')
            self.repack.append(tree)
        elif tool=='dpkg-deb' and args[1]=='--build':
            tree=P(args[-2]);assert not (tree/'DEBIAN/conffiles').exists();P(args[-1]).write_bytes((tree/'DEBIAN/control').read_bytes())
        elif tool=='apt-get':
            if '--' in args:
                assert self.path('/usr/sbin/policy-rc.d').exists()
                assert env=={'DEBIAN_FRONTEND':'noninteractive','NEEDRESTART_SUSPEND':'1'}
                for p in args[args.index('--')+1:]:
                    fields=dict(l.split(': ',1) for l in P(p).read_text().splitlines());self.packages[fields['Package']]=fields['Version']
                if self.fail_install:self.fail_install=False;code=1
            else:
                assert args[-1]=='dpkg-repack=1.0';self.packages['dpkg-repack']='1.0'
        elif tool in ('phpenmod','phpdismod'):
            sapi=args[-2];module=args[-1];p=self.path('/etc/php/8.5/'+sapi+'/conf.d/20-'+module+'.ini')
            if tool=='phpenmod' and not lexists(p):p.symlink_to('../../mods-available/'+module+'.ini')
            elif tool=='phpdismod':remove(p)
        elif tool=='systemctl':
            if args[1]=='is-active':code=0 if self.active else 3
            elif args[1]=='list-units':text='orbit-example-php8.5-fpm.service loaded active running\n' if self.dedicated else ''
            elif args[1]=='show':text='--fpm-config /etc/orbit/php-fpm/example/generated/php-fpm.conf\n'
            elif args[1] in ('restart','reload'):pass
            elif args[1]=='enable':self.enabled='enabled'
            elif args[1]=='is-enabled':text=self.enabled+'\n';code=0 if self.enabled=='enabled' else 1
            elif args[1]=='stop':self.active=False
            elif args[1]=='disable':self.enabled='disabled';self.active=False
            else:raise AssertionError(args)
        elif tool in ('php8.5','php-fpm8.5'):
            if args[1]=='-m':
                text='\n'.join(sorted(self.required))
                if self.fail_modules:text+='\nforeign'
            elif args[1]=='-t':code=1 if self.bad_fpm else 0
            elif args[1]=='-v':text='PHP 8.5'
            elif args[1]=='-i':
                enabled=self.path('/etc/php/8.5/fpm/conf.d/99-orbit-runtime.ini')
                text=str(enabled)+'\n'+'\n'.join(k+' => '+('999M' if self.info_override and k=='post_max_size' else v)+' => '+v for k,v in settings(dict(runtime_ini=self.path('/etc/php/8.5/mods-available/orbit-runtime.ini').read_text())).items())
            else:raise AssertionError(args)
        else:raise AssertionError(args)
        require(not required or code==0,'php.build_execution_failed');return code,text
`;
