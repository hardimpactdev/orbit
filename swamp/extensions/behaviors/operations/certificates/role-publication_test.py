"""Role certificate publication uses the canonical Caddy lock and a real key pair."""
import os,pathlib,re,subprocess,tempfile,types,unittest
HERE=pathlib.Path(__file__).parent
BASE=(HERE/'retained_role_policy.py').read_text().split('# BEGIN HOST\n')[1].split('# END PAIR')[0].replace('from role_paths import KEY_ROOT, role_paths\n','')
EXT=re.search(r'String.raw`(.*?)`;', (HERE/'role-publication.ts').read_text(), re.S)[1]
class RoleCertificateTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.temp=tempfile.TemporaryDirectory();cls.fixture=pathlib.Path(cls.temp.name)
        subprocess.run(['openssl','req','-new','-x509','-newkey','rsa:2048','-nodes','-keyout',str(cls.fixture/'leaf.key'),'-out',str(cls.fixture/'leaf.pem'),'-subj','/CN=role.test','-days','397'],check=True,capture_output=True)
    @classmethod
    def tearDownClass(cls):cls.temp.cleanup()
    def setUp(self):
        self.tmp=tempfile.TemporaryDirectory();self.addCleanup(self.tmp.cleanup);self.root=pathlib.Path(self.tmp.name);self.ns={'__name__':'fixture'};exec(BASE+EXT,self.ns)
        self.ns['ROOT']=self.root/'caddy';self.ns['ROOT'].mkdir();self.ns['OWNER_UID']=os.getuid();self.ns['grp']=types.SimpleNamespace(getgrnam=lambda _:types.SimpleNamespace(gr_gid=os.getgid()))
        self.reloads=[];self.ns['certificate_active']=lambda:True;self.ns['certificate_reload']=lambda:self.reloads.append(True) or True
        self.input=dict(operation='install',directory='/etc/caddy/orbit-fixture-cert-current',certificate_file='fixture.pem',key_file='fixture.key',certificate=(self.fixture/'leaf.pem').read_text(),key=(self.fixture/'leaf.key').read_text(),service='caddy')
        self.current=self.ns['ROOT']/'orbit-fixture-cert-current'
    def install(self):return self.ns['role_certificate'](self.input)
    def test_first_install_validates_pair_and_repeats_without_reload(self):
        self.assertEqual(dict(changed=True),self.install());self.assertEqual(1,len(self.reloads));self.assertEqual(0o750,self.current.stat().st_mode&0o777);self.assertEqual(0o640,(self.current/'fixture.key').stat().st_mode&0o777);self.assertEqual(dict(changed=False),self.install());self.assertEqual(1,len(self.reloads))
    def test_invalid_pair_never_publishes_or_reloads(self):
        self.input['key']='not a key'
        with self.assertRaises(Exception):self.install()
        self.assertFalse(self.current.exists());self.assertEqual([],self.reloads)
    def test_changed_pair_rolls_back_both_bytes_when_active_reload_fails(self):
        self.install();previous={p.name:p.read_bytes() for p in self.current.iterdir()};self.input['certificate']+='\n'
        responses=iter([False,True]);self.ns['certificate_reload']=lambda:next(responses)
        with self.assertRaises(Exception):self.install()
        self.assertEqual(previous,{p.name:p.read_bytes() for p in self.current.iterdir()});self.assertEqual([],list(self.ns['ROOT'].glob('*.candidate.*')))
    def test_unknown_file_and_symlink_pair_are_not_overwritten_or_removed(self):
        self.install();(self.current/'foreign').write_text('keep')
        with self.assertRaises(Exception):self.install()
        with self.assertRaises(Exception):self.ns['role_certificate'](dict(operation='remove',directory=self.input['directory']))
        self.assertEqual('keep',(self.current/'foreign').read_text())
    def test_remove_owned_pair_is_repeatable_and_never_reloads(self):
        self.install();count=len(self.reloads)
        for _ in range(2):self.assertEqual({},self.ns['role_certificate'](dict(operation='remove',directory=self.input['directory'])))
        self.assertFalse(self.current.exists());self.assertEqual(count,len(self.reloads))
    def test_inactive_caddy_stays_inactive_after_publication(self):
        self.ns['certificate_active']=lambda:False;self.install();self.assertEqual([],self.reloads)
    def test_scope_traversal_and_wrong_filenames_refuse_before_publication(self):
        for changed in [dict(directory='/etc/caddy/../foreign-cert-current'),dict(certificate_file='../key.pem'),dict(service='other')]:
            original=self.input;self.input={**original,**changed}
            with self.assertRaises(Exception):self.install()
            self.input=original
        self.assertFalse(self.current.exists())
if __name__=='__main__':unittest.main()
