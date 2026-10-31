"""Real OpenSSL and filesystem certificate behavior, isolated from system paths."""
import datetime, getpass, hashlib, json, os, pathlib, re, subprocess, tempfile, types, unittest

PROGRAM = re.search(r'String.raw`(.*?)`;', pathlib.Path(__file__).with_name('system.ts').read_text(), re.S)[1]

class CertificateSystemTest(unittest.TestCase):
    program = PROGRAM

    def setUp(self):
        self.temp=tempfile.TemporaryDirectory();self.addCleanup(self.temp.cleanup);self.root=pathlib.Path(self.temp.name)
        self.ns={'__name__':'fixture'};exec(self.program,self.ns)
        self.ns['CADDY']=self.root/'caddy';self.ns['ANCHOR']=self.root/'anchor.pem';self.ns['BUNDLE']=self.root/'anchor.pem'
        original_chown=os.chown
        self.ns['os']=types.SimpleNamespace(**{name:getattr(os,name) for name in dir(os) if not name.startswith('__')})
        self.ns['os'].chown=lambda path,*ids,**kwargs:original_chown(path,os.getuid(),os.getgid(),**kwargs)
        self.ns['grp']=types.SimpleNamespace(getgrnam=lambda name:types.SimpleNamespace(gr_gid=os.getgid()))
        self.leaf={'scope':'route-42','home':str(self.root/'home'),'domain':'shop.test','version':'0123456789abcdef','root_hash':'','user':getpass.getuser()}
        self.account_home=self.root/'home';self.account_home.mkdir();self.account_uid=os.getuid();self.account_user=getpass.getuser();self.lookups=[]
        self.original_run=self.ns['run']
        def local_run(*args):
            if args[0]=='/usr/bin/getent':
                self.lookups.append(args)
                if args!=('/usr/bin/getent','passwd',self.account_user): raise ValueError('certificate.account_invalid')
                return f'{self.account_user}:x:{self.account_uid}:{os.getgid()}:fixture:{self.account_home}:/bin/sh\n'
            return self.original_run(*args)
        self.ns['run']=local_run
        self.run_command('openssl','req','-x509','-newkey','rsa:2048','-nodes','-keyout',str(self.root/'ca.key'),'-out',str(self.root/'anchor.pem'),'-subj','/CN=fixture CA','-days','400')
        self.leaf['root_hash']=hashlib.sha256((self.root/'anchor.pem').read_bytes()).hexdigest()
    def run_command(self,*args): return subprocess.run(args,check=True,capture_output=True).stdout.decode()
    def request(self):return self.ns['execute']('certificate.request-leaf',self.leaf)
    def sign(self, request):
        csr=self.root/'request.pem';csr.write_text(request['request'])
        ext=self.root/'extensions';ext.write_text('basicConstraints=critical,CA:FALSE\nkeyUsage=critical,digitalSignature,keyEncipherment\nextendedKeyUsage=serverAuth\nsubjectAltName=DNS:shop.test\n')
        cert=self.run_command('openssl','x509','-req','-in',str(csr),'-CA',str(self.root/'anchor.pem'),'-CAkey',str(self.root/'ca.key'),'-CAcreateserial','-days','398','-extfile',str(ext))
        return {**self.leaf,'certificate':cert,'root_certificate':(self.root/'anchor.pem').read_text()}
    def publish(self):
        signed=self.sign(self.request())
        original=self.ns['subprocess']
        class Commands:
            SubprocessError=subprocess.SubprocessError
            DEVNULL=subprocess.DEVNULL
            PIPE=subprocess.PIPE
            def run(_,argv,**kwargs):
                if argv[0]=='systemctl':return types.SimpleNamespace(returncode=3)
                return original.run(argv,**kwargs)
        self.ns['subprocess']=Commands()
        self.ns['execute']('certificate.publish-leaf',signed)
    def test_request_reuses_only_the_same_candidate_key(self):
        self.request();root,_=self.ns['roots'](self.leaf);key=root/'versions/0123456789abcdef.candidate/key.pem';before=key.read_bytes()
        second=self.request();self.assertIn('CERTIFICATE REQUEST',second['request']);self.assertEqual(before,key.read_bytes());self.assertEqual(0o600,key.stat().st_mode&0o777)
    def test_publish_inspect_and_current_leaf(self):
        self.publish();self.assertEqual({'present':True},self.ns['execute']('certificate.inspect-leaf',self.leaf));self.assertEqual({'state':'current'},self.request())
    def test_remove_clears_both_certificate_trees_and_is_repeatable(self):
        self.publish()
        for _ in range(2):self.assertEqual({},self.ns['execute']('certificate.remove-leaf',self.leaf))
        self.assertEqual({'present':False},self.ns['execute']('certificate.inspect-leaf',self.leaf))
    def test_foreign_root_is_refused_before_publication(self):
        signed=self.sign(self.request());signed['root_hash']='a'*64
        with self.assertRaises(ValueError):self.ns['execute']('certificate.publish-leaf',signed)
        self.assertEqual({'present':False},self.ns['execute']('certificate.inspect-leaf',self.leaf))
    def test_existing_committed_version_does_not_replace_key(self):
        self.publish();root,_=self.ns['roots'](self.leaf);before=(root/'current/key.pem').read_bytes();self.leaf['root_hash']='a'*64
        with self.assertRaises(ValueError):self.request()
        self.assertEqual(before,(root/'current/key.pem').read_bytes())

    def test_all_effects_resolve_home_from_the_admitted_user_when_home_is_absent(self):
        self.leaf.pop('home');self.publish()
        self.assertEqual({'present':True},self.ns['execute']('certificate.inspect-leaf',self.leaf))
        self.assertEqual({},self.ns['execute']('certificate.remove-leaf',self.leaf))
        self.assertEqual({'present':False},self.ns['execute']('certificate.inspect-leaf',self.leaf))
        self.assertEqual(5,len(self.lookups))
        self.assertTrue(all(call==('/usr/bin/getent','passwd',self.account_user) for call in self.lookups))
    def test_supplied_home_must_match_getent_before_every_effect(self):
        wrong={**self.leaf,'home':str(self.root/'foreign')}
        for action in ['certificate.request-leaf','certificate.publish-leaf','certificate.remove-leaf','certificate.inspect-leaf']:
            with self.assertRaisesRegex(ValueError,'certificate.home_mismatch'):self.ns['execute'](action,wrong)
        self.assertFalse((self.account_home/'.orbit').exists());self.assertFalse(self.ns['CADDY'].exists())
    def test_home_owner_and_symlink_are_checked_before_effects(self):
        self.account_uid=os.getuid()+1
        with self.assertRaisesRegex(ValueError,'certificate.home_unsafe'):self.request()
        self.account_uid=os.getuid();self.account_home.rmdir();target=self.root/'target';target.mkdir();self.account_home.symlink_to(target)
        with self.assertRaisesRegex(ValueError,'certificate.home_unsafe'):self.request()
        self.assertFalse((target/'.orbit').exists())
    def test_username_is_admitted_and_never_interpolated_into_a_shell(self):
        invalid={**self.leaf,'user':'orbit;id'}
        with self.assertRaisesRegex(ValueError,'certificate.account_invalid'):self.ns['execute']('certificate.request-leaf',invalid)
        self.assertEqual([],self.lookups)
    def test_fixed_getent_can_read_the_existing_account_without_writing_its_home(self):
        self.ns['run']=self.original_run
        uid,gid,home=self.ns['local_account']({'user':getpass.getuser()})
        self.assertEqual(os.getuid(),uid);self.assertTrue(pathlib.Path(home).is_dir())
        self.assertIsInstance(gid,int)

if __name__=='__main__':unittest.main()
