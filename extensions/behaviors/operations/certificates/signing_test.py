"""Real disposable CA signing. No fleet secrets or persistent key material."""
import base64,hashlib,json,pathlib,re,subprocess,tempfile,unittest
PROGRAM=re.search(r'String.raw`(.*?)`;',pathlib.Path(__file__).with_name('signing.ts').read_text(),re.S)[1]
class SigningTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.tmp=tempfile.TemporaryDirectory();cls.root=pathlib.Path(cls.tmp.name)
        cls.command('req','-x509','-newkey','rsa:4096','-nodes','-keyout',str(cls.root/'root.key'),'-out',str(cls.root/'root.pem'),'-subj','/CN=Disposable Orbit CA','-days','500','-addext','basicConstraints=critical,CA:TRUE')
        cls.command('req','-new','-newkey','rsa:2048','-nodes','-keyout',str(cls.root/'leaf.key'),'-out',str(cls.root/'leaf.csr'),'-subj','/CN=private.test')
        cls.ca=dict(certificate=(cls.root/'root.pem').read_text(),private_key=(cls.root/'root.key').read_text())
    @classmethod
    def tearDownClass(cls):cls.tmp.cleanup()
    @staticmethod
    def command(*args):return subprocess.run(['openssl',*args],check=True,capture_output=True).stdout.decode()
    def setUp(self):
        self.ns={'__name__':'fixture'};exec(PROGRAM,self.ns)
        self.leaf=dict(node_id=9,scope='route-7-router',domain='private.test',root_hash=hashlib.sha256(self.ca['certificate'].encode()).hexdigest())
        self.request=dict(node_id=9,scope='route-7-router',state='request',request_base64=base64.b64encode((self.root/'leaf.csr').read_bytes()).decode())
    def sign(self,leaves=None,requests=None):return self.ns['sign'](dict(ca=self.ca,leaves=leaves or [self.leaf],requests=requests or [self.request]))
    def test_signs_public_bundle_with_the_existing_leaf_policy_and_local_leaf_key(self):
        bundle=self.sign();self.assertEqual(1,len(bundle['certificates']));signed=bundle['certificates'][0];self.assertEqual(9,signed['node_id']);self.assertEqual('route-7-router',signed['scope']);self.assertNotIn('PRIVATE KEY',json.dumps(bundle));cert=self.root/'signed.pem';cert.write_bytes(base64.b64decode(signed['certificate_base64']))
        self.command('verify','-CAfile',str(self.root/'root.pem'),str(cert));self.assertIn('DNS:private.test',self.command('x509','-in',str(cert),'-noout','-ext','subjectAltName'));self.assertIn('CA:FALSE',self.command('x509','-in',str(cert),'-noout','-ext','basicConstraints'));self.assertEqual(self.command('pkey','-in',str(self.root/'leaf.key'),'-pubout'),self.command('x509','-in',str(cert),'-pubkey','-noout'))
        import datetime
        dates=self.command('x509','-in',str(cert),'-noout','-dates').splitlines();parse=lambda v:datetime.datetime.strptime(v.split('=',1)[1],'%b %d %H:%M:%S %Y GMT');self.assertEqual(397,(parse(dates[1])-parse(dates[0])).days)
    def test_refuses_wrong_node_scope_domain_root_and_duplicate_request(self):
        for leaf in [dict(self.leaf,node_id=8),dict(self.leaf,scope='route-8'),dict(self.leaf,domain='other.test'),dict(self.leaf,root_hash='a'*64)]:
            with self.assertRaises(Exception):self.sign([leaf])
        with self.assertRaises(Exception):self.sign(requests=[self.request,self.request])
    def test_selects_admitted_domain_when_parent_has_two_versions_of_one_scope(self):
        result=self.sign(leaves=[dict(self.leaf,domain='previous.test'),self.leaf,dict(self.leaf,domain='next.test')])
        self.assertEqual(1,len(result['certificates']))
    def test_current_leaf_has_no_signature(self):
        self.assertEqual(dict(certificates=[]),self.sign(requests=[dict(node_id=9,scope='route-7-router',state='current')]))
    def test_refuses_corrupt_csr_and_non_rsa_2048_request(self):
        for request in [dict(self.request,request_base64=base64.b64encode(b'PRIVATE KEY').decode()),dict(self.request,request_base64='bad')]:
            with self.assertRaises(Exception):self.sign(requests=[request])
        self.command('req','-new','-newkey','rsa:3072','-nodes','-keyout',str(self.root/'wrong.key'),'-out',str(self.root/'wrong.csr'),'-subj','/CN=private.test')
        with self.assertRaises(Exception):self.sign(requests=[dict(self.request,request_base64=base64.b64encode((self.root/'wrong.csr').read_bytes()).decode())])
    def test_key_is_memory_only_and_no_ca_material_leaves_fixed_program(self):
        input=json.dumps(dict(ca=self.ca,leaves=[self.leaf],requests=[self.request])).encode()
        reply=subprocess.run(['python3','-I','-c',PROGRAM],input=input,capture_output=True,check=True)
        self.assertEqual(b'',reply.stderr);self.assertNotIn(self.ca['private_key'].encode(),reply.stdout);self.assertNotIn(b'PRIVATE KEY',reply.stdout);self.assertEqual(1,len(json.loads(reply.stdout)['certificates']))
if __name__=='__main__':unittest.main()
