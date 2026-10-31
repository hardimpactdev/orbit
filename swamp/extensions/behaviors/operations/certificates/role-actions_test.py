"""Real Node-local role CSR -> canonical control signer -> Caddy pair transaction."""
import base64,hashlib,json,os,pathlib,re,subprocess,tempfile,types,unittest
HERE=pathlib.Path(__file__).parent
def program(file):return re.search(r'String.raw`(.*?)`;',file.read_text(),re.S)[1]
class RoleActionsTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.tmp=tempfile.TemporaryDirectory();cls.ca_path=pathlib.Path(cls.tmp.name)
        subprocess.run(['openssl','req','-x509','-newkey','rsa:4096','-nodes','-keyout',str(cls.ca_path/'ca.key'),'-out',str(cls.ca_path/'ca.pem'),'-subj','/CN=Disposable Role CA','-days','500','-addext','basicConstraints=critical,CA:TRUE'],check=True,capture_output=True)
        cls.ca=dict(certificate=(cls.ca_path/'ca.pem').read_text(),private_key=(cls.ca_path/'ca.key').read_text())
    @classmethod
    def tearDownClass(cls):cls.tmp.cleanup()
    def setUp(self):
        self.tmp=tempfile.TemporaryDirectory();self.addCleanup(self.tmp.cleanup);self.root=pathlib.Path(self.tmp.name)
        self.ns={'__name__':'fixture'};exec((HERE/'scripts/role_policy.py').read_text().split('ROLE_ACTION_INPUT = None')[0]+program(HERE/'role-publication.ts'),self.ns)
        self.ns['ROOT']=self.root/'caddy';self.ns['ROOT'].mkdir();self.ns['OWNER_UID']=os.getuid();self.ns['KEY_ROOT']=self.root/'orbit'/'role-certificates'
        self.ns['grp']=types.SimpleNamespace(getgrnam=lambda _:types.SimpleNamespace(gr_gid=os.getgid()))
        self.reloads=[];self.ns['certificate_active']=lambda:True;self.ns['certificate_reload']=lambda:self.reloads.append(True) or True
        self.leaf=dict(node_id=9,scope='role-gateway',domain='gateway.test',version='0123456789abcdef',root_hash=hashlib.sha256(self.ca['certificate'].encode()).hexdigest())
    def request(self):return self.ns['role_request'](self.leaf)
    def signed(self,request):
        csr=self.root/'request.pem';csr.write_bytes(base64.b64decode(request['request_base64']))
        extensions=self.root/'leaf.cnf';extensions.write_text('basicConstraints=critical,CA:FALSE\nkeyUsage=critical,digitalSignature,keyEncipherment\nextendedKeyUsage=serverAuth\nsubjectAltName=DNS:'+self.leaf['domain']+'\n')
        certificate=subprocess.run(['openssl','x509','-req','-in',str(csr),'-CA',str(self.ca_path/'ca.pem'),'-CAkey',str(self.ca_path/'ca.key'),'-set_serial','1','-days','397','-sha256','-extfile',str(extensions)],capture_output=True,check=True).stdout
        return dict(node_id=9,scope=self.leaf['scope'],certificate_base64=base64.b64encode(certificate).decode(),root_certificate_base64=base64.b64encode(self.ca['certificate'].encode()).decode())

    def publish(self,request,signed):return self.ns['role_publish'](dict(self.leaf,request=request,signed=signed))
    def current(self):return self.ns['role_paths'](self.leaf)[1]
    def test_all_fixed_role_paths_keep_private_keys_local_and_use_one_canonical_ca_policy(self):
        for role in ['gateway','metrics','websocket','analytics']:
            with self.subTest(role=role):
                self.leaf.update(scope='role-'+role,domain='analytics.orbit' if role=='analytics' else role+'.test')
                request=self.request();self.assertNotIn('PRIVATE KEY',json.dumps(request));self.assertEqual('request',request['state'])
                again=self.request();self.assertEqual(request,again)
                signed=self.signed(request);self.assertNotIn('PRIVATE KEY',json.dumps(signed));self.assertEqual(dict(applied=True),self.publish(request,signed))
                current=self.current();self.assertTrue((current/(role+'.key')).is_file());self.assertTrue((current/'root-ca.pem').is_file());self.assertEqual(0o640,(current/(role+'.key')).stat().st_mode&0o777)
                self.assertEqual(dict(state='current'),self.request());self.assertEqual(dict(applied=True),self.publish(request,signed))
                self.assertFalse((self.ns['KEY_ROOT']/self.leaf['scope']/self.leaf['version']).exists())
                self.assertEqual(3,len(list(current.iterdir())))
        self.assertEqual(4,len(self.reloads))
    def test_wrong_root_domain_and_key_never_publish_or_reload(self):
        request=self.request();signed=self.signed(request)
        self.leaf['root_hash']='a'*64
        with self.assertRaises(Exception):self.publish(request,signed)
        self.leaf['root_hash']=hashlib.sha256(self.ca['certificate'].encode()).hexdigest();self.leaf['domain']='other.test'
        with self.assertRaises(Exception):self.publish(request,signed)
        self.leaf['domain']='gateway.test'
        key=self.ns['KEY_ROOT']/self.leaf['scope']/self.leaf['version']/'key.pem'
        subprocess.run(['openssl','genrsa','-out',str(key),'2048'],check=True,capture_output=True)
        with self.assertRaises(Exception):self.publish(request,signed)
        self.assertFalse(self.current().exists());self.assertEqual([],self.reloads)
    def test_reload_failure_restores_entire_previous_pair_and_pending_key_can_retry(self):
        request=self.request();self.publish(request,self.signed(request));current=self.current();old={p.name:p.read_bytes() for p in current.iterdir()}
        self.leaf.update(version='fedcba9876543210',domain='next.test');request=self.request();signed=self.signed(request)
        responses=iter([False,True]);self.ns['certificate_reload']=lambda:next(responses)
        with self.assertRaises(Exception):self.publish(request,signed)
        self.assertEqual(old,{p.name:p.read_bytes() for p in current.iterdir()});self.assertTrue((self.ns['KEY_ROOT']/self.leaf['scope']/self.leaf['version']/'key.pem').exists())
        self.ns['certificate_reload']=lambda:True;self.assertEqual(dict(applied=True),self.publish(request,signed))
    def test_owned_existing_version_link_is_upgraded_atomically_at_same_path(self):
        request=self.request();signed=self.signed(request);self.publish(request,signed);current=self.current()
        versions=current.with_name(current.name.replace('-current','-versions'));versions.mkdir(mode=0o750);old=versions/('a'*16);current.rename(old)
        current.symlink_to('/etc/caddy/'+versions.name+'/'+old.name)
        self.leaf.update(version='fedcba9876543210',domain='new.test');request=self.request();self.publish(request,self.signed(request))
        self.assertFalse(current.is_symlink());self.assertTrue((current/'gateway.pem').is_file());self.assertTrue((old/'gateway.key').is_file())
    def test_foreign_current_link_and_pending_key_symlink_are_refused(self):
        self.current().symlink_to('/tmp/foreign')
        with self.assertRaises(Exception):self.request()
        self.current().unlink();request=self.request();key=self.ns['KEY_ROOT']/self.leaf['scope']/self.leaf['version']/'key.pem';outside=self.root/'outside';key.rename(outside);key.symlink_to(outside)
        with self.assertRaises(Exception):self.request()
        with self.assertRaises(Exception):self.publish(request,self.signed(request))
        self.assertTrue(outside.exists());self.assertEqual([],self.reloads)
    def test_current_marker_does_not_publish_missing_or_changed_pair(self):
        with self.assertRaises(Exception):self.publish(dict(state='current'),dict(skipped=True))
        request=self.request();self.publish(request,self.signed(request));self.leaf['domain']='changed.test'
        with self.assertRaises(Exception):self.publish(dict(state='current'),dict(skipped=True))
    def test_refuses_scope_path_injection_and_invalid_version(self):
        for changes in [dict(scope='role-../../foreign'),dict(domain='bad/name'),dict(version='../foreign')]:
            original=self.leaf;self.leaf={**original,**changes}
            with self.assertRaises(Exception):self.request()
            self.leaf=original
        self.assertFalse(self.ns['KEY_ROOT'].exists())
if __name__=='__main__':unittest.main()
