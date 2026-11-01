"""Router package and baseline ports exercise filesystem effects and fixed system argv."""
import hashlib, os, pathlib, tempfile, types, unittest
PROGRAM=(pathlib.Path(__file__).parent.parent/'fleet-recipe/scripts/role_prerequisites.py').read_text()
class PreparationTest(unittest.TestCase):
    def setUp(self):
        self.tmp=tempfile.TemporaryDirectory();self.addCleanup(self.tmp.cleanup);self.root=pathlib.Path(self.tmp.name)
        self.ns={'__name__':'fixture'};exec(PROGRAM,self.ns);self.calls=[];self.installed='2.11.4'
        self.ns['SETTING']=self.root/'sysctl.conf';self.ns['LEGACY']=[self.root/'caddy.gpg',self.root/'caddy.sources'];self.ns['ROOT_DIRS']=[self.root]
        self.ns['OS_RELEASE']=self.root/'os-release';self.ns['OS_RELEASE'].write_text('ID=ubuntu\nVERSION_CODENAME=noble\n')
        self.ns['DOCKER']=self.root/'docker';self.ns['COMPOSER']=self.root/'opt/composer'
        original=self.ns['preflight']
        self.ns['preflight']=lambda p,d,uid,gid=None,mode=None:original(p,d,os.getuid() if uid==0 else uid,os.getgid() if gid==0 else gid,mode)
        self.ns['os']=types.SimpleNamespace(**{n:getattr(os,n) for n in dir(os) if not n.startswith('__')});self.ns['os'].chown=lambda *args,**kwargs:None
        def run(*args,check=True,env=None):
            args=tuple(str(a) for a in args);self.calls.append(args)
            output=b'';code=0
            if args[:2]==('caddy','version'):
                if self.installed is None: raise FileNotFoundError()
                output=('v'+self.installed+' h1:fixture').encode()
            elif args[:2]==('dpkg','--compare-versions'):code=int(tuple(map(int,args[2].split('.')))<tuple(map(int,args[4].split('.'))))
            elif args[:2]==('dpkg','--print-architecture'):output=b'amd64\n'
            elif args[0]=='curl':pathlib.Path(args[args.index('--output')+1]).write_bytes(b'package')
            elif args[0]=='apt-get' and args[-1].endswith('.deb'):self.installed='2.11.4'
            elif args[0]=='dpkg-query':output=b'install ok installed'
            return types.SimpleNamespace(returncode=code,stdout=output)
        self.ns['run']=run
        self.package={'release':'2.11.4','minimum_version':'2.11.4','amd64_sha512':hashlib.sha512(b'package').hexdigest()}
    def caddy(self):return self.ns['execute']({'name':'caddy.install-package','input':self.package})
    def test_existing_new_enough_package_keeps_version_and_converges_setting(self):
        self.installed='2.12.0';result=self.caddy();self.assertEqual(result,{'changed':True,'version':'2.12.0'});self.assertFalse(any(c[0] in ['curl','apt-get'] for c in self.calls));self.assertEqual('net.ipv4.tcp_migrate_req = 1\n',self.ns['SETTING'].read_text());self.assertFalse(self.caddy()['changed'])
    def test_missing_caddy_installs_verified_https_pin_and_removes_legacy(self):
        self.installed=None
        for path in self.ns['LEGACY']:path.write_text('legacy')
        self.assertEqual('2.11.4',self.caddy()['version']);self.assertTrue(any(c[0]=='curl' and '--proto-redir' in c and '=https' in c for c in self.calls));self.assertTrue(any(c[0]=='apt-get' and '--no-remove' in c for c in self.calls));self.assertTrue(all(not p.exists() for p in self.ns['LEGACY']))
    def test_bad_digest_refuses_before_sysctl_or_apt(self):
        self.installed=None;self.package['amd64_sha512']='a'*128
        with self.assertRaises(Exception):self.caddy()
        self.assertFalse(self.ns['SETTING'].exists());self.assertFalse(any(c[0] in ['sysctl','apt-get'] for c in self.calls))
    def test_symlink_refuses_before_any_system_command(self):
        self.ns['SETTING'].symlink_to(self.root/'foreign')
        with self.assertRaises(Exception):self.caddy()
        self.assertEqual([],self.calls)
    def baseline(self):
        import grp,pwd
        account=pwd.getpwuid(os.getuid());home=self.root/'home';home.mkdir(exist_ok=True)
        return dict(user=account.pw_name,group=grp.getgrgid(os.getgid()).gr_name,home=str(home),releases=['noble'],packages=['docker.io','git'],apps_directory=True,composer_home=True)
    def test_docker_ce_substitutes_for_docker_io_and_creates_managed_homes(self):
        data=self.baseline();self.ns['DOCKER'].write_text('docker');self.ns['DOCKER'].chmod(0o755)
        self.ns['execute']({'name':'role.install-prerequisites','input':data})
        apt=[c for c in self.calls if c[0]=='apt-get' and 'install' in c][0];self.assertIn('git',apt);self.assertNotIn('docker.io',apt);self.assertTrue((pathlib.Path(data['home'])/'apps').is_dir());self.assertEqual('{"require":{}}\n',(self.ns['COMPOSER']/'composer.json').read_text());self.assertTrue(any(c[:2]==('sudo','-u') for c in self.calls))
    def test_unsupported_os_and_foreign_home_refuse_before_packages(self):
        data=self.baseline();self.ns['OS_RELEASE'].write_text('ID=debian\nVERSION_CODENAME=noble\n')
        with self.assertRaises(Exception):self.ns['prerequisites'](data)
        self.assertEqual([],self.calls);self.ns['OS_RELEASE'].write_text('ID=ubuntu\nVERSION_CODENAME=noble\n');(pathlib.Path(data['home'])/'apps').symlink_to(self.root)
        with self.assertRaises(Exception):self.ns['prerequisites'](data)
        self.assertEqual([],self.calls)
    def test_prepared_packages_skip_apt_but_verify_composer_scope(self):
        data=self.baseline();data['install_packages']=False
        self.ns['prerequisites'](data)
        self.assertFalse(any(c[0]=='apt-get' for c in self.calls))
        self.assertTrue(any(c[:2]==('sudo','-u') for c in self.calls))
        self.assertTrue((self.ns['COMPOSER']/'vendor/bin').is_dir())
    def test_foreign_composer_scope_refuses_before_apt_or_directory_writes(self):
        data=self.baseline();scope=self.ns['COMPOSER'];scope.mkdir(parents=True);(scope/'vendor').symlink_to(self.root)
        with self.assertRaisesRegex(Exception,'role.prerequisite_conflict'):self.ns['prerequisites'](data)
        self.assertEqual([],self.calls)
        self.assertFalse((pathlib.Path(data['home'])/'apps').exists())
if __name__=='__main__':unittest.main()
