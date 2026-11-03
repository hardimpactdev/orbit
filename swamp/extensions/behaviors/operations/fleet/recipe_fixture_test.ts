/** Filesystem fixtures and command doubles for the shipped recipe module. */
export async function python(group: string, source: string) {
  const script = new URL("../fleet-recipe/scripts/recipe.py", import.meta.url).pathname;
  const result = await new Deno.Command("python3", {
    args: ["-I", "-c", `SCRIPT_PATH=${JSON.stringify(script)}\n` + setup + `\nf=${group}();f.setUp()\n` + source],
    stdout: "piped", stderr: "piped", signal: AbortSignal.timeout(5000),
  }).output();
  if (!result.success) throw new Error(new TextDecoder().decode(result.stderr));
  return JSON.parse(new TextDecoder().decode(result.stdout));
}
const setup = String.raw`import copy, hashlib, importlib.util, json, os, tempfile
from pathlib import Path
spec=importlib.util.spec_from_file_location('recipe',SCRIPT_PATH)
module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module)
ns=module.__dict__
def error(fn):
    try:return fn()
    except Exception as e:return {'class':type(e).__name__,'code':getattr(e,'code',None),'stage':getattr(e,'stage',None),'restored':getattr(e,'restored',None)}
class RecipeHistoricalOrigins:
    def setUp(self):pass

class RecipeIncusPolicy:
    def setUp(self):
        self.temporary=tempfile.TemporaryDirectory()
        root=Path(self.temporary.name)
        (root/'etc/apt/preferences.d').mkdir(parents=True)
        (root/'etc/apt/sources.list.d').mkdir()
        (root/'etc/os-release').write_text('ID=ubuntu\nVERSION_CODENAME=resolute\n')
        self.calls=[];self.installed={name:'7.0.1' for name in ('incus','incus-base','incus-client')}
        self.instances=[]
        owner=self
        class Host(ns['RecipeHost']):
            def run(self,argv,required=True,timeout=600,environment=None):
                owner.calls.append(argv)
                if argv==['dpkg','--print-architecture']:output='amd64\n'
                elif argv[0]=='dpkg-query':output=''.join(name+'\tamd64\t'+version+'\tinstall ok installed\n' for name,version in owner.installed.items())
                elif argv==['incus','list','--all-projects','--format=json']:output=json.dumps(owner.instances)
                else:raise RuntimeError('Unexpected system command: '+str(argv))
                return {'code':0,'stdout':output,'stderr':''}
        self.host=Host(root)
        artifact={'url':'https://example.test/incus.deb','sha256':'a'*64}
        self.input={'user':'orbit','snapshot':'20261031T000000Z','architecture':'amd64','git_packages':[],'artifact_mirror':'','tools':{name:{'source':'artifact','version':'7.0.1','artifacts':{'amd64':artifact}} for name in self.installed}}
        self.host.path('/etc/apt/preferences.d/orbit-incus').write_bytes(ns['preferences'](self.input['tools']))

    def preflight(self):return ns['incus_preflight'](self.host,self.input)

class RecipeSystemPrograms:
    def setUp(self):
        import os
        self.temporary=tempfile.TemporaryDirectory()
        self.root=Path(self.temporary.name);self.uid=os.getuid();self.gid=os.getgid()
        self.calls=[];self.responses={};owner=self
        class Host(ns['RecipeHost']):
            def run(self,argv,required=True,timeout=600,environment=None):
                owner.calls.append(list(map(str,argv)))
                if argv[0]=='sudo':
                    # Execute no fixture or privileged program. Observe the installed
                    # filesystem and model only the native CLI's documented responses.
                    strings=list(map(str,argv));binary=strings[-2] if strings[-1]=='--version' else None
                    if binary is not None:
                        if binary.endswith('/bin/vp'):output='vp 0.2.0\n'
                        elif binary.endswith('/bin/node'):output='v22.1.0\n'
                        elif binary.endswith('/bin/pnpm'):output='10.1.0\n'
                        else:raise RuntimeError(argv)
                    elif strings[-3:-1]==['env','default']:
                        scope=next(value.split('=',1)[1] for value in strings if value.startswith('VP_HOME='))
                        (Path(scope)/'config.json').write_text(json.dumps({'defaultNodeVersion':strings[-1]}));output=''
                    else:raise RuntimeError(argv)
                elif argv==['dpkg','--print-architecture']:output='amd64\n'
                elif argv[0]=='dpkg-query':output=''
                else:
                    if tuple(argv) not in owner.responses:raise RuntimeError(argv)
                    response=owner.responses[tuple(argv)]
                    if isinstance(response,Exception):raise response
                    return response
                return {'code':0,'stdout':output,'stderr':''}
        self.host=Host(self.root,self.uid,self.gid)
        for directory in ('home/test/.local/share','usr/local/bin','etc/apt/sources.list.d','etc/apt/preferences.d'):(self.root/directory).mkdir(parents=True)
        self.account={'name':'test','uid':self.uid,'gid':self.gid,'home':self.root/'home/test'}

    def archive(self,name,members):
        import io,tarfile
        target=self.root/name
        with tarfile.open(target,'w:gz') as archive:
            for path,content,kind in members:
                entry=tarfile.TarInfo(path)
                if kind=='link':entry.type=tarfile.SYMTYPE;entry.linkname=content;archive.addfile(entry)
                elif kind=='hardlink':entry.type=tarfile.LNKTYPE;entry.linkname=content;archive.addfile(entry)
                elif kind=='directory':entry.type=tarfile.DIRTYPE;archive.addfile(entry)
                else:
                    entry.mode=0o755;entry.size=len(content);archive.addfile(entry,io.BytesIO(content))
        return target

    def pin(self,version,archive):
        return {'source':'artifact','version':version,'artifacts':{'amd64':{'url':'https://example.test/runtime.tgz','sha256':ns['digest_file'](archive)}}}

    def data(self,tools):return {'user':'test','snapshot':'20261031T000000Z','architecture':'amd64','git_packages':[],'artifact_mirror':'','tools':tools}

class PhpBuildPrograms:
    def setUp(self):
        import os
        self.temporary=tempfile.TemporaryDirectory()
        self.root=Path(self.temporary.name);self.calls=[];self.installed={'php8.5-cli':'8.5.1','php8.5-fpm':'8.5.1'};self.module_failure=False;self.rollback_failure=False;self.active=False;self.fpm_invalid=False
        self.package_bytes={'8.5.1':b'old exact php deb','8.5.2':b'new exact php deb'};self.extensions={'core','curl'};owner=self
        for directory in ('etc/apt/sources.list.d','etc/apt/preferences.d','etc/php/8.5/mods-available','etc/php/8.5/cli/conf.d','etc/php/8.5/fpm/conf.d','etc/php/8.5/fpm/pool.d','usr/sbin','var/cache/orbit/php-packages'):(self.root/directory).mkdir(parents=True,exist_ok=True)
        (self.root/'etc/os-release').write_text('ID=ubuntu\nVERSION_CODENAME=resolute\n')
        (self.root/'etc/php/8.5/fpm/php-fpm.conf').write_text('[global]\ninclude=pool.d/*.conf\n')
        for sapi in ('cli','fpm'):(self.root/f'etc/php/8.5/{sapi}/conf.d/99-cve-2026-109618-upload-progress.ini').write_text('session.upload_progress.enabled = 0\n')
        class Host(ns['RecipeHost']):
            def run(self,argv,required=True,timeout=600,environment=None):
                owner.calls.append(argv);output='';code=0
                if argv==['dpkg','--print-architecture']:output='amd64\n'
                elif argv[0]=='dpkg-query':output=''.join(name+'\tamd64\t'+version+'\tinstall ok installed\n' for name,version in owner.installed.items())
                elif argv[:2]==['dpkg','--verify']:pass
                elif argv[0]=='curl':
                    target=Path(argv[argv.index('--output')+1]);target.write_bytes(owner.package_bytes['8.5.2'])
                elif argv[:2]==['dpkg-deb','--field']:
                    path=Path(argv[2]);version=next(version for version,content in owner.package_bytes.items() if path.read_bytes()==content)
                    name=path.name.split('_')[0] if '_' in path.name else path.stem
                    output=f'Package: {name}\nVersion: {version}\nArchitecture: amd64\n'
                elif argv[0]=='apt-get':
                    if 'install' not in argv:raise RuntimeError(argv)
                    for argument in argv[argv.index('--')+1:]:
                        path=Path(argument);version=next(version for version,content in owner.package_bytes.items() if path.read_bytes()==content)
                        if version=='8.5.1' and owner.rollback_failure:code=1;continue
                        name=path.name.split('_')[0];owner.installed[name]=version
                        if name.endswith('-fpm'):(owner.root/'etc/php/8.5/fpm/pool.d/www.conf').write_text('default')
                elif argv[:2]==['dpkg','--remove']:
                    for name in argv[3:]:owner.installed.pop(name,None)
                elif argv[0] in ('phpenmod','phpdismod'):pass
                elif argv[0].endswith('php8.5') and argv[1]=='-m':output='\n'.join(owner.extensions if not owner.module_failure else {'core'})
                elif argv[0].endswith('php-fpm8.5') and argv[1]=='-t':code=1 if owner.fpm_invalid else 0
                elif argv[0]=='systemctl':
                    if argv[1]=='is-active':code=0 if owner.active else 3
                    elif argv[1]=='list-units':output=''
                    elif argv[1] not in ('restart','reload'):raise RuntimeError(argv)
                else:raise RuntimeError(argv)
                if required and code:raise ns['RecipeRefusal']('command')
                return {'code':code,'stdout':output,'stderr':''}
        self.host=Host(self.root,os.getuid(),os.getgid())
        self.input={'versions':{'8.5':{'packages':[self.pin(name,'8.5.1') for name in self.installed],'extensions':sorted(self.extensions)}},'mirror':'','pcov':False,'opentelemetry':False,'extras':'refuse','fpm':'none','repack':{'package':'dpkg-repack','version':'1.54'},'release':{'id':'ubuntu','codenames':['resolute']}}

    def pin(self,name,version):
        import hashlib
        return {'name':name,'version':version,'architecture':'amd64','url':f'https://example.test/{name}.deb','sha256':hashlib.sha256(self.package_bytes[version]).hexdigest()}

    def upgrade(self):
        self.input['versions']['8.5']['packages']=[self.pin(name,'8.5.2') for name in self.installed]
        for name in self.installed:
            pin=self.pin(name,'8.5.1');(self.root/'var/cache/orbit/php-packages'/f"{name}_{pin['sha256']}.deb").write_bytes(self.package_bytes['8.5.1'])

class RecipeApplyPrograms:
    def setUp(self):
        import os
        self.temporary=tempfile.TemporaryDirectory()
        self.root=Path(self.temporary.name);self.calls=[];self.installed={'curl':'8.0'};self.active={'curl-daemon.service','consumer.service'};self.failed_unit=None;self.plan='';self.compare={};owner=self
        for directory in ('etc/apt/sources.list.d','etc/apt/preferences.d','usr/sbin','usr/local/bin','etc/apparmor.d'):(self.root/directory).mkdir(parents=True,exist_ok=True)
        (self.root/'etc/os-release').write_text('ID=ubuntu\nVERSION_CODENAME=resolute\n')
        (self.root/'etc/apt/sources.list.d/ubuntu.sources').write_text(ns['rolling_source']())
        class Host(ns['RecipeHost']):
            def run(self,argv,required=True,timeout=600,environment=None):
                owner.calls.append(argv);output='';code=0
                if argv==['dpkg','--print-architecture']:output='amd64\n'
                elif argv[:2]==['dpkg-query','-W']:output=''.join(name+'\tamd64\t'+version+'\tinstall ok installed\n' for name,version in owner.installed.items())
                elif argv[:2]==['dpkg-query','-L']:output='/usr/lib/systemd/system/curl-daemon.service\n' if argv[2]=='curl' else ''
                elif argv[:2]==['dpkg','--compare-versions']:
                    code=owner.compare.get(tuple(argv[2:]),0 if (argv[2]>argv[4] if argv[3]=='gt' else argv[2]<argv[4]) else 1)
                elif argv[:2]==['dpkg','--audit']:pass
                elif argv[0]=='apt-get':
                    if argv[1]=='install':
                        if '--simulate' in argv:output=owner.plan
                        else:
                            for value in argv[argv.index('--')+1:]:
                                if '=' in value:name,version=value.split('=',1);owner.installed[name]=version
                    elif argv[1] not in ('update','check'):raise RuntimeError(argv)
                elif argv[:2]==['systemctl','list-units']:output=''.join(unit+' loaded active running\n' for unit in owner.active)
                elif argv[:2]==['systemctl','show']:
                    unit=argv[-1];output='LoadState=loaded\nActiveState=active\nResult=success\nControlGroup=/system.slice/'+unit+'\nJob=\nRequiredBy='+('consumer.service' if unit=='curl-daemon.service' else '')+'\nBoundBy=\nConsistsOf=\n'
                elif argv[:2]==['systemctl','is-active']:code=3 if argv[-1]==owner.failed_unit else 0
                elif argv[:2]==['systemctl','restart']:pass
                elif argv[0]=='systemd-run':pass
                elif argv[0]=='apt-cache':
                    if argv[2:3]==['curl:amd64']:output='curl:\n  Version table:\n *** 9.0 500\n        500 http://archive.ubuntu.com/ubuntu resolute/main amd64 Packages\n        100 /var/lib/dpkg/status\n'
                    else:output='  500 http://archive.ubuntu.com/ubuntu resolute/main amd64 Packages\n      release o=Ubuntu,a=resolute,n=resolute,c=main,b=amd64\n'
                else:raise RuntimeError(argv)
                if required and code:raise ns['RecipeRefusal']('command')
                return {'code':code,'stdout':output,'stderr':''}
        self.host=Host(self.root,os.getuid(),os.getgid());self.account={'name':'test','uid':os.getuid(),'gid':os.getgid(),'home':self.root/'home/test'}
        self.data={'user':'test','snapshot':'20261031T000000Z','architecture':'amd64','git_packages':[],'artifact_mirror':'','tools':{'curl':{'source':'ubuntu','version':'8.1','package':'curl','artifacts':{}}}}

class RecipeBrowserPrograms:
    def setUp(self):
        import os
        self.temporary=tempfile.TemporaryDirectory()
        self.root=Path(self.temporary.name);self.calls=[];self.replace_failure=False;owner=self
        for directory in ('opt/orbit','etc/apparmor.d'):(self.root/directory).mkdir(parents=True,exist_ok=True)
        class Host(ns['RecipeHost']):
            def run(self,argv,required=True,timeout=600,environment=None):
                owner.calls.append(argv)
                if argv[0]!='apparmor_parser':raise RuntimeError(argv)
                if '--replace' in argv and owner.replace_failure:
                    owner.replace_failure=False;raise ns['RecipeRefusal']('browser-policy')
                return {'code':0,'stdout':'','stderr':''}
        self.host=Host(self.root,os.getuid(),os.getgid())

    def archive(self,version='130.0.1.2',binary=b'verified Chrome'):
        import zipfile,hashlib
        path=self.root/(version+'.zip')
        with zipfile.ZipFile(path,'w') as archive:
            entry=zipfile.ZipInfo('chrome-headless-shell-linux64/chrome-headless-shell');entry.external_attr=(0o100755<<16);archive.writestr(entry,binary)
        pin={'source':'artifact','version':version,'artifacts':{'amd64':{'url':'https://storage.googleapis.com/chrome-for-testing-public/'+version+'/linux64/chrome-headless-shell-linux64.zip','sha256':hashlib.sha256(path.read_bytes()).hexdigest()}}}
        return pin,path

class RecipeDeliveryPolicy:
    def setUp(self):
        import os,hashlib
        self.temporary=tempfile.TemporaryDirectory()
        self.root=Path(self.temporary.name);self.calls=[];owner=self
        for directory in ('run/lock','usr/local/bin','home/test/.local/share'):(self.root/directory).mkdir(parents=True,exist_ok=True)
        (self.root/'usr/local/bin/orbit-agent').write_bytes(b'frozen observer binary')
        (self.root/'usr/local/bin/orbit-0.12.0').write_bytes(b'\x7fELFfixture');(self.root/'usr/local/bin/orbit-0.12.0').chmod(0o755)
        (self.root/'usr/local/bin/orbit').symlink_to('orbit-0.12.0')
        class Host(ns['RecipeHost']):
            def run(self,argv,required=True,timeout=600,environment=None):
                owner.calls.append(argv)
                if argv[0]!='sudo' or argv[-2]!=str(owner.root/'usr/local/bin/orbit-0.12.0') or argv[-1]!='--version':raise RuntimeError(argv)
                return {'code':0,'stdout':'Orbit 0.12.0\n','stderr':''}
        self.host=Host(self.root,os.getuid(),os.getgid());self.account={'name':'test','uid':os.getuid(),'gid':os.getgid(),'home':self.root/'home/test'}
        self.input={'user':'test','identity':'sha256:'+'f'*64,'agent_sha256':hashlib.sha256(b'frozen observer binary').hexdigest(),'cli_version':'0.12.0'}
`;
