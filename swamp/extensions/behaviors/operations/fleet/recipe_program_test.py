"""Recipe policies using temporary files, fixed-command fakes and harmless subprocesses."""
import json
import os
import signal
import subprocess
import sys
import time
from pathlib import Path
import re
import tempfile
import unittest

PROGRAM=(Path(__file__).parent.parent/'fleet-recipe/scripts/recipe.py').read_text()
ns={'__name__':'recipe_policy_test'}
exec(compile(PROGRAM,'recipe.py','exec'),ns)

class RecipeIncusPolicy(unittest.TestCase):
    def setUp(self):
        self.temporary=tempfile.TemporaryDirectory();self.addCleanup(self.temporary.cleanup)
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
                else:raise AssertionError('Unexpected system command: '+str(argv))
                return {'code':0,'stdout':output,'stderr':''}
        self.host=Host(root)
        artifact={'url':'https://example.test/incus.deb','sha256':'a'*64}
        self.input={'user':'orbit','snapshot':'20261031T000000Z','architecture':'amd64','git_packages':[],'artifact_mirror':'','tools':{name:{'source':'artifact','version':'7.0.1','artifacts':{'amd64':artifact}} for name in self.installed}}
        self.host.path('/etc/apt/preferences.d/orbit-incus').write_bytes(ns['preferences'](self.input['tools']))

    def preflight(self):return ns['incus_preflight'](self.host,self.input)

    def test_current_owned_install_does_not_require_draining_active_instances(self):
        self.instances=[{'project':'other','name':'busy','type':'container','status':'Running'}]
        self.assertEqual(self.preflight(),{'ready':True})
        self.assertFalse(any(argv[0]=='incus' for argv in self.calls))

    def test_pin_change_requires_every_project_instance_stopped(self):
        self.installed['incus']='7.0.0'
        for status in ('Running','Frozen','Error','Starting','Stopping'):
            with self.subTest(status=status):
                self.instances=[{'project':'foreign','name':'busy','type':'virtual-machine','status':status}]
                with self.assertRaises(ns['RecipeRefusal']) as error:self.preflight()
                self.assertEqual(error.exception.code,'node.recipe_incus_busy')
        self.instances=[{'project':'foreign','name':'stopped','type':'virtual-machine','status':'Stopped'}]
        self.assertEqual(self.preflight(),{'ready':True})
        self.assertIn(['incus','list','--all-projects','--format=json'],self.calls)

    def test_source_pin_or_obsolete_agent_changes_also_require_draining(self):
        for cause in ('source','preference','agent'):
            with self.subTest(cause=cause):
                if cause=='source':self.host.path('/etc/apt/sources.list.d/incus.sources').write_text('Types: deb\nURIs: https://pkgs.zabbly.com/incus/lts-7.0\nSuites: resolute\nComponents: main\n')
                elif cause=='preference':self.host.path('/etc/apt/preferences.d/orbit-incus').write_text('old')
                else:self.installed['incus-agent']='7.0.1'
                self.instances=[{'project':'default','name':'busy','type':'container','status':'Running'}]
                with self.assertRaises(ns['RecipeRefusal']):self.preflight()

    def test_fresh_install_needs_no_incus_daemon(self):
        self.installed={}
        self.assertEqual(self.preflight(),{'ready':True})
        self.assertFalse(any(argv[0]=='incus' for argv in self.calls))

    def test_malformed_inventory_platform_and_docker_provider_refuse(self):
        self.installed['incus']='old';self.instances=[{'status':'Stopped'}]
        with self.assertRaises(ns['RecipeRefusal']):self.preflight()
        self.host.path('/etc/os-release').write_text('ID=ubuntu\nID=ubuntu\nVERSION_CODENAME=resolute\n')
        with self.assertRaises(ns['RecipeRefusal']):self.preflight()
        self.host.path('/etc/os-release').write_text('ID=ubuntu\nVERSION_CODENAME=resolute\n')
        self.installed['docker-ce']='1'
        with self.assertRaises(ns['RecipeRefusal']) as error:self.preflight()
        self.assertEqual(error.exception.code,'node.recipe_docker_provider')

    def test_mixed_provider_and_symlink_preference_refuse_without_mutation(self):
        path=self.host.path('/etc/apt/sources.list.d/incus.sources')
        content='Types: deb\nURIs: https://pkgs.zabbly.com/incus/lts-7.0 https://untrusted.test\nSuites: resolute\nComponents: main\n'
        path.write_text(content)
        with self.assertRaises(ns['RecipeRefusal']):self.preflight()
        self.assertEqual(path.read_text(),content)
        path.unlink()
        preference=self.host.path('/etc/apt/preferences.d/orbit-incus');preference.unlink();preference.symlink_to('/dev/null')
        with self.assertRaises(ns['RecipeRefusal']):self.preflight()

    def test_provider_retirement_plan_preserves_unrelated_paragraphs_and_conflicting_backup(self):
        path=self.host.path('/etc/apt/sources.list.d/mixed.sources')
        other='Types: deb\nURIs: https://other.test\nSuites: other\nComponents: main\n'
        own='Types: deb\nURIs: https://pkgs.zabbly.com/incus/lts-7.0\nSuites: resolute\nComponents: main\n'
        path.write_text(other+'\n'+own)
        changes=ns['provider_changes'](self.host,'incus')
        self.assertEqual(changes[0][2],(other+'\n').encode())
        self.host.path('/etc/apt/mixed.sources.orbit-incus-backup').write_text('foreign')
        with self.assertRaises(ns['RecipeRefusal']):ns['provider_changes'](self.host,'incus')
        self.assertEqual(path.read_text(),other+'\n'+own)

class RecipeSystemPrograms(unittest.TestCase):
    def setUp(self):
        import os
        self.temporary=tempfile.TemporaryDirectory();self.addCleanup(self.temporary.cleanup)
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
                        else:raise AssertionError(argv)
                    elif strings[-3:-1]==['env','default']:
                        scope=next(value.split('=',1)[1] for value in strings if value.startswith('VP_HOME='))
                        (Path(scope)/'config.json').write_text(json.dumps({'defaultNodeVersion':strings[-1]}));output=''
                    else:raise AssertionError(argv)
                elif argv==['dpkg','--print-architecture']:output='amd64\n'
                elif argv[0]=='dpkg-query':output=''
                else:
                    if tuple(argv) not in owner.responses:raise AssertionError(argv)
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

    def test_archive_escape_link_pivot_and_expanded_bound_refuse_before_writes(self):
        cases=[('../escape',b'x','file'),('/escape',b'x','file'),('dir/link','../../escape','link')]
        for index,member in enumerate(cases):
            archive=self.archive('bad'+str(index)+'.tgz',[member]);output=self.root/('output'+str(index))
            with self.assertRaises(ns['RecipeRefusal']):ns['extract_archive'](archive,output)
            self.assertFalse(output.exists())
        archive=self.archive('pivot.tgz',[('dir','safe','link'),('dir/payload',b'bad','file')])
        with self.assertRaises(ns['RecipeRefusal']):ns['extract_archive'](archive,self.root/'pivot')
        original=ns['MAX_EXPANDED'];ns['MAX_EXPANDED']=1
        try:
            archive=self.archive('oversize.tgz',[('payload',b'too big','file')])
            with self.assertRaises(ns['RecipeRefusal']):ns['extract_archive'](archive,self.root/'oversize')
        finally:ns['MAX_EXPANDED']=original

    def test_archive_root_directory_marker_is_allowed_without_materializing_an_escape(self):
        archive=self.archive('root-marker.tgz',[('.',b'','directory'),('./tool',b'verified','file')])
        target=self.root/'root-marker';ns['extract_archive'](archive,target)
        self.assertEqual((target/'tool').read_bytes(),b'verified')

    def test_archive_preserves_safe_relative_links_and_clears_special_permissions(self):
        archive=self.archive('good.tgz',[('runtime/bin/node',b'ELF','file'),('runtime/bin/npm','../lib/npm.js','link'),('runtime/lib/npm.js',b'js','file'),('runtime/bin/second','runtime/bin/node','hardlink')])
        target=self.root/'unpacked';ns['extract_archive'](archive,target)
        self.assertEqual((target/'runtime/bin/npm').read_bytes(),b'js')
        self.assertEqual((target/'runtime/bin/second').read_bytes(),b'ELF')
        self.assertFalse((target/'runtime/bin/node').stat().st_mode&0o7022)

    def test_complete_javascript_install_uses_verified_archives_and_managed_scope(self):
        node=self.archive('node.tgz',[('node-v22.1.0-linux-x64/bin/node',b'ELF','file')])
        vp=self.archive('vp.tgz',[('vp',b'ELF','file')])
        pnpm=self.archive('pnpm.tgz',[('package/package.json',b'{"version":"10.1.0"}','file'),('package/bin/pnpm.cjs',b'js','file')])
        data=self.data({'node':self.pin('22.1.0',node),'vp-cli':self.pin('0.2.0',vp),'pnpm':self.pin('10.1.0',pnpm)})
        result=ns['install_javascript'](self.host,data,self.account,{'node':node,'vp-cli':vp,'pnpm':pnpm})
        self.assertEqual(result,{'changed':True,'retired':[]})
        scope=self.account['home']/'.local/share/vite-plus'
        self.assertFalse((scope/'js_runtime/node/22.1.0').is_symlink())
        self.assertEqual(json.loads((scope/'.orbit-recipe-node.json').read_text()),{'version':'22.1.0'})
        self.assertEqual((scope/'bin/node').readlink(),Path('vp'))
        self.assertIn('VP_HOME=',self.host.path('/usr/local/bin/node').read_text())
        self.assertFalse(any('orbit-agent' in str(argv) for argv in self.calls))
        self.assertEqual(ns['install_javascript'](self.host,data,self.account),{'changed':False,'retired':[]})

    def test_bad_checksum_fails_before_managed_runtime_or_launcher_is_published(self):
        archive=self.archive('bad-checksum.tgz',[('vp',b'ELF','file')]);pin=self.pin('0.2.0',archive);pin['artifacts']['amd64']['sha256']='a'*64
        with self.assertRaises(ns['RecipeRefusal']):ns['install_javascript'](self.host,self.data({'vp-cli':pin}),self.account,{'vp-cli':archive})
        self.assertFalse(self.host.path('/usr/local/bin/vp').exists())
        self.assertFalse((self.account['home']/'.local/share/vite-plus/bin/vp').exists())

    def test_existing_same_version_node_tree_must_match_verified_archive(self):
        archive=self.archive('node.tgz',[('node-v22.1.0-linux-x64/bin/node',b'ELF','file')])
        scope=self.account['home']/'.local/share/vite-plus';scope.mkdir()
        ns['publish_node'](self.host,scope,self.account,'22.1.0',archive)
        (scope/'js_runtime/node/22.1.0/bin/node').write_bytes(b'foreign')
        with self.assertRaises(ns['RecipeRefusal']):ns['publish_node'](self.host,scope,self.account,'22.1.0',archive)
        self.assertEqual((scope/'js_runtime/node/22.1.0/bin/node').read_bytes(),b'foreign')

    def test_existing_foreign_or_symlink_runtime_scope_refuses(self):
        scope=self.account['home']/'.local/share/vite-plus';scope.symlink_to(self.root)
        with self.assertRaises(ns['RecipeRefusal']):ns['runtime_scope'](self.host,self.account)
        scope.unlink();scope.mkdir();(scope/'js_runtime').symlink_to(self.root)
        archive=self.archive('node.tgz',[('node-v22.1.0-linux-x64/bin/node',b'ELF','file')])
        with self.assertRaises(ns['RecipeRefusal']):ns['publish_node'](self.host,scope,self.account,'22.1.0',archive)

    def test_foreign_file_is_not_replaced_by_owned_publication(self):
        import os
        target=self.host.path('/usr/local/bin/node');target.write_text('foreign')
        account={**self.account,'uid':self.uid+1}
        with self.assertRaises(ns['RecipeRefusal']):ns['publish_owned'](self.host,target,b'replacement',account)
        self.assertEqual(target.read_text(),'foreign')

class PhpBuildPrograms(unittest.TestCase):
    def setUp(self):
        import os
        self.temporary=tempfile.TemporaryDirectory();self.addCleanup(self.temporary.cleanup)
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
                    if 'install' not in argv:raise AssertionError(argv)
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
                    elif argv[1] not in ('restart','reload'):raise AssertionError(argv)
                else:raise AssertionError(argv)
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

    def test_exact_current_build_preserves_noop_and_runs_no_package_or_service_action(self):
        result=ns['install_php_build'](self.host,self.input)
        self.assertEqual(result['outcome'],'current')
        self.assertEqual(result['data']['changed'],[])
        self.assertFalse(any(argv[0] in ('apt-get','curl') or argv[:2] in (['systemctl','reload'],['systemctl','restart']) for argv in self.calls))

    def test_exact_upgrade_downloads_verifies_then_installs_and_keeps_disabled_default_pool(self):
        self.upgrade();result=ns['install_php_build'](self.host,self.input)
        self.assertEqual(result['outcome'],'applied')
        self.assertEqual(self.installed,{'php8.5-cli':'8.5.2','php8.5-fpm':'8.5.2'})
        self.assertFalse((self.root/'etc/php/8.5/fpm/pool.d/www.conf').exists())
        self.assertFalse((self.root/'usr/sbin/policy-rc.d').exists())
        self.assertTrue(all('--proto-redir' in argv for argv in self.calls if argv[0]=='curl'))
        self.assertEqual(result['data']['changed'],['php8.5-cli','php8.5-fpm'])

    def test_module_failure_restores_old_packages_and_original_ini(self):
        self.upgrade();self.module_failure=True
        ini=self.root/'etc/php/8.5/cli/conf.d/99-cve-2026-109618-upload-progress.ini';ini.write_text('old local bytes')
        with self.assertRaises(ns['RecipeRefusal']) as error:ns['install_php_build'](self.host,self.input)
        self.assertEqual(error.exception.code,'php.build_modules_mismatch');self.assertTrue(error.exception.restored)
        self.assertEqual(self.installed,{'php8.5-cli':'8.5.1','php8.5-fpm':'8.5.1'})
        self.assertEqual(ini.read_text(),'old local bytes')
        self.assertFalse((self.root/'usr/sbin/policy-rc.d').exists())

    def test_failed_package_rollback_is_distinct_and_never_claims_restoration(self):
        self.upgrade();self.module_failure=True;self.rollback_failure=True
        with self.assertRaises(ns['RecipeRefusal']) as error:ns['install_php_build'](self.host,self.input)
        self.assertEqual(error.exception.code,'php.build_rollback_failed');self.assertFalse(error.exception.restored)

    def test_foreign_source_and_extra_package_refuse_before_download_or_effect(self):
        source=self.root/'etc/apt/sources.list.d/php.sources';source.write_text('URIs: https://packages.sury.org/php\n')
        with self.assertRaises(ns['RecipeRefusal']) as error:ns['install_php_build'](self.host,self.input)
        self.assertEqual(error.exception.code,'php.build_conflicting_source');self.assertTrue(source.exists())
        self.assertFalse(any(argv[0]=='curl' for argv in self.calls))
        source.unlink();self.installed['php8.5-foreign']='8.5.1'
        with self.assertRaises(ns['RecipeRefusal']) as error:ns['install_php_build'](self.host,self.input)
        self.assertEqual(error.exception.code,'php.build_extra_packages')

    def test_conflicting_shared_pin_refuses_before_inventory_or_effect(self):
        import copy
        second=copy.deepcopy(self.input['versions']['8.5']);second['packages'][0]['sha256']='f'*64;self.input['versions']['8.6']=second
        with self.assertRaises(ns['RecipeRefusal']) as error:ns['install_php_build'](self.host,self.input)
        self.assertEqual(error.exception.code,'php.build_input_invalid');self.assertEqual(self.calls,[])

    def test_fpm_validation_failure_rolls_back_before_any_restart(self):
        self.upgrade();self.input['fpm']='all';self.active=True;self.fpm_invalid=True
        with self.assertRaises(ns['RecipeRefusal']) as error:ns['install_php_build'](self.host,self.input)
        self.assertEqual(error.exception.code,'php.build_fpm_invalid');self.assertTrue(error.exception.restored)
        self.assertFalse(any(argv[:2]==['systemctl','restart'] for argv in self.calls))

class RecipeApplyPrograms(unittest.TestCase):
    def setUp(self):
        import os
        self.temporary=tempfile.TemporaryDirectory();self.addCleanup(self.temporary.cleanup)
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
                    elif argv[1] not in ('update','check'):raise AssertionError(argv)
                elif argv[:2]==['systemctl','list-units']:output=''.join(unit+' loaded active running\n' for unit in owner.active)
                elif argv[:2]==['systemctl','show']:
                    unit=argv[-1];output='LoadState=loaded\nActiveState=active\nResult=success\nControlGroup=/system.slice/'+unit+'\nJob=\nRequiredBy='+('consumer.service' if unit=='curl-daemon.service' else '')+'\nBoundBy=\nConsistsOf=\n'
                elif argv[:2]==['systemctl','is-active']:code=3 if argv[-1]==owner.failed_unit else 0
                elif argv[:2]==['systemctl','restart']:pass
                elif argv[0]=='systemd-run':pass
                elif argv[0]=='apt-cache':
                    if argv[2:3]==['curl:amd64']:output='curl:\n  Version table:\n *** 9.0 500\n        500 http://archive.ubuntu.com/ubuntu resolute/main amd64 Packages\n        100 /var/lib/dpkg/status\n'
                    else:output='  500 http://archive.ubuntu.com/ubuntu resolute/main amd64 Packages\n      release o=Ubuntu,a=resolute,n=resolute,c=main,b=amd64\n'
                else:raise AssertionError(argv)
                if required and code:raise ns['RecipeRefusal']('command')
                return {'code':code,'stdout':output,'stderr':''}
        self.host=Host(self.root,os.getuid(),os.getgid());self.account={'name':'test','uid':os.getuid(),'gid':os.getgid(),'home':self.root/'home/test'}
        self.data={'user':'test','snapshot':'20261031T000000Z','architecture':'amd64','git_packages':[],'artifact_mirror':'','tools':{'curl':{'source':'ubuntu','version':'8.1','package':'curl','artifacts':{}}}}

    def test_package_apply_pins_snapshot_and_restarts_only_changed_package_units(self):
        result=ns['apply_packages'](self.host,self.data,self.account)
        self.assertEqual(result,{'changed_packages':['curl'],'changed_tools':['curl'],'restarted_services':['curl-daemon.service'],'newer_than_recipe':{},'healthy':True,'restart_units':['curl-daemon.service'],'health_units':['curl-daemon.service']})
        self.assertEqual(self.installed['curl'],'8.1')
        self.assertFalse(any(call[:2]==['systemctl','restart'] for call in self.calls))
        plan=ns['service_plan'](self.host,result)
        self.assertEqual(plan,{'restart_units':['curl-daemon.service'],'health_units':['consumer.service','curl-daemon.service']})
        self.assertFalse((self.root/'usr/sbin/policy-rc.d').exists())
        install=next(call for call in self.calls if call[:2]==['apt-get','install'])
        self.assertIn('--no-remove',install);self.assertNotIn('--allow-downgrades',install)
        self.assertEqual(install[-1],'curl=8.1')

    def test_current_package_runs_no_restart_and_preserves_already_healthy_units(self):
        self.installed['curl']='8.1';result=ns['apply_packages'](self.host,self.data,self.account)
        self.assertEqual(result['changed_packages'],[]);self.assertEqual(result['restarted_services'],[])
        self.assertFalse(any(call[:2]==['systemctl','restart'] for call in self.calls))

    def test_newer_trusted_ubuntu_is_preserved_instead_of_downgraded(self):
        self.installed['curl']='9.0';result=ns['apply_packages'](self.host,self.data,self.account)
        self.assertEqual(self.installed['curl'],'9.0')
        self.assertEqual(result['newer_than_recipe'],{'curl':{'installed':'9.0','version':'8.1'}})
        self.assertFalse(any(call[:2]==['apt-get','install'] for call in self.calls))

    def test_dependency_downgrade_and_malformed_simulation_refuse_before_install(self):
        self.plan='Inst unrelated [9.0] (8.0 Ubuntu:26.04/resolute [amd64])\n'
        with self.assertRaises(ns['RecipeRefusal']):ns['exact_deb_install'](self.host,[self.root/'incus.deb'],{}, {'unrelated':'9.0'},[])
        self.assertFalse(any(call[:2]==['apt-get','install'] and '--simulate' not in call for call in self.calls))
        self.plan='Inst ???\n'
        with self.assertRaises(ns['RecipeRefusal']):ns['exact_deb_install'](self.host,[self.root/'incus.deb'],{}, {},[])

    def test_exact_artifact_downgrade_does_not_permit_dependency_downgrade(self):
        self.plan='Inst incus [9.0] (8.0 local-deb [amd64])\n'
        ns['exact_deb_install'](self.host,[self.root/'incus.deb'],{}, {'incus':'9.0'},[])
        self.assertTrue(any(call[:2]==['apt-get','install'] and '--simulate' not in call for call in self.calls))

    def test_existing_restart_policy_is_preserved_and_refuses_before_source_mutation(self):
        path=self.root/'usr/sbin/policy-rc.d';path.write_text('operator policy')
        with self.assertRaises(ns['RecipeRefusal']):ns['apply_packages'](self.host,self.data,self.account)
        self.assertEqual(path.read_text(),'operator policy');self.assertFalse(any(call[0]=='apt-get' for call in self.calls))

    def test_dependency_health_failure_never_reports_success_and_policy_is_removed(self):
        self.failed_unit='consumer.service'
        # A completed inactive oneshot is allowed; active-state evidence must
        # remain active here, so this represents a broken long-lived dependent.
        result=ns['apply_packages'](self.host,self.data,self.account)
        plan=ns['service_plan'](self.host,result)
        with self.assertRaises(ns['RecipeRefusal']) as error:
            for unit in plan['health_units']:ns['service_health'](self.host,unit)
        self.assertEqual(error.exception.stage,'health:consumer.service')
        self.assertFalse((self.root/'usr/sbin/policy-rc.d').exists())

    def test_unsigned_or_mixed_package_origins_are_not_ubuntu_proof(self):
        global_text='  500 https://third-party.test/ubuntu resolute/main amd64 Packages\n      release o=Ubuntu,a=resolute,n=resolute,c=main,b=amd64\n'
        package_text='Version table:\n *** 9.0 500\n        500 https://third-party.test/ubuntu resolute/main amd64 Packages\n        100 /var/lib/dpkg/status\n'
        origin=ns['parse_ubuntu_origin'](global_text,package_text,'9.0',set())
        self.assertEqual(origin,{'trusted':False,'untrusted':True,'installed':True})
        origin=ns['parse_ubuntu_origin'](global_text,package_text,'9.0',{('https://third-party.test/ubuntu','resolute/main')})
        self.assertEqual(origin,{'trusted':True,'untrusted':False,'installed':True})

class RecipeBrowserPrograms(unittest.TestCase):
    def setUp(self):
        import os
        self.temporary=tempfile.TemporaryDirectory();self.addCleanup(self.temporary.cleanup)
        self.root=Path(self.temporary.name);self.calls=[];self.replace_failure=False;owner=self
        for directory in ('opt/orbit','etc/apparmor.d'):(self.root/directory).mkdir(parents=True,exist_ok=True)
        class Host(ns['RecipeHost']):
            def run(self,argv,required=True,timeout=600,environment=None):
                owner.calls.append(argv)
                if argv[0]!='apparmor_parser':raise AssertionError(argv)
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

    def test_browser_install_is_pinned_root_tree_and_exact_executable_policy(self):
        pin,archive=self.archive();ns['install_browser'](self.host,pin,archive)
        self.assertEqual(ns['browser_installed'](self.host,pin),pin['version'])
        policy=self.host.path(ns['BROWSER_POLICY']).read_text()
        self.assertIn('/opt/orbit/chrome-headless-shell/130.0.1.2/chrome-headless-shell-linux64/chrome-headless-shell flags=(unconfined)',policy)
        self.assertEqual(sum('--skip-kernel-load' in argv for argv in self.calls),1)
        self.assertFalse(any('systemctl' in argv for argv in self.calls))

    def test_browser_refuses_changed_binary_and_unrelated_policy(self):
        pin,archive=self.archive();ns['install_browser'](self.host,pin,archive)
        binary=self.host.path(ns['BROWSER_BASE']+'/'+pin['version'])/ns['BROWSER_EXECUTABLE'];binary.write_bytes(b'foreign')
        with self.assertRaises(ns['RecipeRefusal']):ns['browser_installed'](self.host,pin)
        policy=self.host.path(ns['BROWSER_POLICY']);policy.write_text('unrelated admin policy')
        with self.assertRaises(ns['RecipeRefusal']):ns['install_browser'](self.host,pin,archive)
        self.assertEqual(policy.read_text(),'unrelated admin policy')

    def test_browser_kernel_load_failure_restores_only_its_previous_policy(self):
        old,archive=self.archive();ns['install_browser'](self.host,old,archive)
        previous=self.host.path(ns['BROWSER_POLICY']).read_bytes();new,archive=self.archive('131.0.1.2',b'new verified Chrome')
        self.replace_failure=True
        with self.assertRaises(ns['RecipeRefusal']):ns['install_browser'](self.host,new,archive)
        self.assertEqual(self.host.path(ns['BROWSER_POLICY']).read_bytes(),previous)
        self.assertTrue(self.host.path(ns['BROWSER_BASE']+'/'+old['version']).is_dir())

    def test_browser_rejects_caller_controlled_artifact_url(self):
        pin,archive=self.archive();pin['artifacts']['amd64']['url']='https://unrelated.test/chrome.zip'
        with self.assertRaises(ns['RecipeRefusal']):ns['install_browser'](self.host,pin,archive)
        self.assertFalse(self.host.path(ns['BROWSER_BASE']).exists());self.assertEqual(self.calls,[])

class RecipeHistoricalOrigins(unittest.TestCase):
    def test_historical_lookup_failures_are_cached_and_three_timestamp_bound_is_kept(self):
        temporary=tempfile.TemporaryDirectory();self.addCleanup(temporary.cleanup);calls=[]
        class Host:
            def run(self,argv,required=True,timeout=600,environment=None):calls.append((argv,environment));raise ns['RecipeRefusal']('snapshot-unavailable')
        snapshots=ns['SnapshotOrigins'](Host(),Path(temporary.name))
        for timestamp in ('20261030T000000Z','20261030T010000Z','20261030T020000Z'):
            for _ in range(2):
                with self.assertRaises(ns['RecipeRefusal']):snapshots.origin('curl','9.0',timestamp)
        self.assertEqual(len(calls),3)
        with self.assertRaises(ns['RecipeRefusal']) as error:snapshots.origin('curl','9.0','20261030T030000Z')
        self.assertEqual(error.exception.stage,'snapshot-lookup-limit');self.assertEqual(len(calls),3)
        for argv,environment in calls:
            self.assertEqual(argv[:2],['apt-get','update']);self.assertIn('-c',argv)
            self.assertEqual(environment['APT_CONFIG'],argv[argv.index('-c')+1]);self.assertIn('Debug::NoLocking=true',argv)

    def test_package_install_time_is_taken_from_matching_installed_version_only(self):
        import os
        temporary=tempfile.TemporaryDirectory();self.addCleanup(temporary.cleanup);root=Path(temporary.name);(root/'var/log').mkdir(parents=True)
        (root/'var/log/dpkg.log').write_text('2026-10-30 00:00:00 install curl:amd64 <none> 8.0\n2026-10-31 01:02:03 upgrade curl:amd64 8.0 9.0\n2026-10-31 01:02:04 status installed curl:amd64 9.0\n')
        host=ns['RecipeHost'](root,os.getuid(),os.getgid());value=ns['install_snapshot'](host,'curl','9.0')
        self.assertRegex(value,r'^202610[0-9]{2}T[0-9]{6}Z$');self.assertNotEqual(value,'20261030T000000Z')
        self.assertIsNone(ns['install_snapshot'](host,'curl','10.0'))

class RecipeDeliveryPolicy(unittest.TestCase):
    def setUp(self):
        import os,hashlib
        self.temporary=tempfile.TemporaryDirectory();self.addCleanup(self.temporary.cleanup)
        self.root=Path(self.temporary.name);self.calls=[];owner=self
        for directory in ('run/lock','usr/local/bin','home/test/.local/share'):(self.root/directory).mkdir(parents=True,exist_ok=True)
        (self.root/'usr/local/bin/orbit-agent').write_bytes(b'frozen observer binary')
        (self.root/'usr/local/bin/orbit-0.12.0').write_bytes(b'\x7fELFfixture');(self.root/'usr/local/bin/orbit-0.12.0').chmod(0o755)
        (self.root/'usr/local/bin/orbit').symlink_to('orbit-0.12.0')
        class Host(ns['RecipeHost']):
            def run(self,argv,required=True,timeout=600,environment=None):
                owner.calls.append(argv)
                if argv[0]!='sudo' or argv[-2]!=str(owner.root/'usr/local/bin/orbit-0.12.0') or argv[-1]!='--version':raise AssertionError(argv)
                return {'code':0,'stdout':'Orbit 0.12.0\n','stderr':''}
        self.host=Host(self.root,os.getuid(),os.getgid());self.account={'name':'test','uid':os.getuid(),'gid':os.getgid(),'home':self.root/'home/test'}
        self.input={'user':'test','identity':'sha256:'+'f'*64,'agent_sha256':hashlib.sha256(b'frozen observer binary').hexdigest(),'cli_version':'0.12.0'}

    def test_exact_delivery_reads_bytes_and_cli_as_managed_account_without_agent_execution(self):
        result=ns['verify_recipe_delivery'](self.host,self.input,self.account)
        self.assertEqual(result,{key:self.input[key] for key in ('identity','agent_sha256','cli_version')})
        self.assertEqual(len(self.calls),1);self.assertEqual(self.calls[0][:4],['sudo','-n','-u','test'])

    def test_changed_observer_bytes_refuse_before_cli_execution(self):
        (self.root/'usr/local/bin/orbit-agent').write_bytes(b'wrong binary')
        with self.assertRaises(ns['RecipeRefusal']):ns['verify_recipe_delivery'](self.host,self.input,self.account)
        self.assertEqual(self.calls,[])

    def test_foreign_cli_link_refuses_without_executing_it(self):
        cli=self.root/'usr/local/bin/orbit';cli.unlink();cli.symlink_to('/tmp/foreign-executable')
        with self.assertRaises(ns['RecipeRefusal']):ns['verify_recipe_delivery'](self.host,self.input,self.account)
        self.assertEqual(self.calls,[])

if __name__=='__main__':unittest.main()
