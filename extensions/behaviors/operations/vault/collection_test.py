"""Collection crypto, reconciliation and redaction on the actual worker program."""
import copy
from pathlib import Path
import unittest

source = Path(__file__).with_name('collection.ts').read_text().split('String.raw`', 1)[1].rsplit('`;', 1)[0]
program = {'__name__': 'test'}
exec(compile(source, 'swamp-vault-collection', 'exec'), program)

class Remote:
    def __init__(self, ciphers=None):
        self.keys = {'org': b'x' * 64}
        self.deleted = []
        self.written = []
        self.snapshot = {'email':'test@example.test','organizations':[{'id':'org','name':'Orbit'}],
            'collections':[{'id':'collection','name':'Test','organization_id':'org','read_only':False}], 'ciphers':ciphers or []}
    def open(self): return copy.deepcopy(self.snapshot)
    def delete(self, identity): self.deleted.append(identity)
    def write(self, item, identity=None):
        self.written.append((item['source'], identity))
        return {'id': identity or 'new', 'revision':'revision'}

class CollectionTest(unittest.TestCase):
    def config(self):
        return {'organization':'Orbit','collection':'Test','organization_id':'org','collection_id':'collection',
            'wanted':[{'source':'database:1','fingerprint':'wanted','cipher':{'secret':'sentinel'}}], 'ledger':[]}
    def cipher(self, identity, source):
        return {'id':identity,'source':source,'organization_id':'org','collection_ids':['collection'],'revision':'r1','deleted':False}
    def test_crypto_roundtrip_and_authenticated_tamper_refusal(self):
        crypto = program['Crypto']()
        key = b'k' * 64
        encrypted = crypto.encrypt('private-sentinel', key)
        self.assertNotIn('private-sentinel', encrypted)
        self.assertEqual(crypto.decrypt(encrypted, key), b'private-sentinel')
        with self.assertRaises(program['Refusal']): crypto.decrypt(encrypted, b'z' * 64)
    def test_setup_matches_one_writable_collection(self):
        remote = Remote()
        result = program['reconcile']('setup', self.config(), remote)
        self.assertEqual(result['backend'], 'configured')
        self.assertEqual(result['collection_id'], 'collection')
        remote.snapshot['collections'][0]['read_only'] = True
        with self.assertRaises(program['Refusal']) as error: program['reconcile']('setup', self.config(), remote)
        self.assertEqual(error.exception.code, 'vault.collection_read_only')
    def test_sync_adopts_lost_create_and_removes_only_owned_duplicates_or_orphans(self):
        remote = Remote([self.cipher('old','database:1'), self.cipher('duplicate','database:1'), self.cipher('orphan','database:gone'), self.cipher('unrelated',None)])
        result = program['reconcile']('sync', self.config(), remote)
        self.assertEqual(remote.written,[('database:1','old')])
        self.assertEqual(remote.deleted,['duplicate','orphan'])
        self.assertEqual(result['cipher_ids'],['old'])
        self.assertNotIn('sentinel',str(result))
    def test_teardown_preserves_foreign_items_even_when_the_old_ledger_names_them(self):
        remote=Remote([self.cipher('owned','database:1'),self.cipher('foreign',None)])
        config=self.config();config['ledger']=[{'source':'database:1','cipher_id':'foreign'}]
        self.assertEqual(program['reconcile']('teardown',config,remote),{'backend':'absent'})
        self.assertEqual(remote.deleted,['owned'])
    def test_unknown_input_and_secret_bearing_exceptions_have_only_bounded_refusals(self):
        self.assertEqual(program['main']({'action':'other','configuration':{'master_password':'sentinel'}}),('failed',{'error_code':'vault.action_invalid'}))
        self.assertEqual(program['main']({'action':'setup','configuration':{'master_password':'sentinel'}}),('failed',{'error_code':'vault.unreachable'}))

if __name__=='__main__': unittest.main()
