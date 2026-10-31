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
    def test_sync_adopts_lost_create_without_deleting_marker_only_copies(self):
        remote = Remote([self.cipher('old','database:1'), self.cipher('duplicate','database:1'), self.cipher('orphan','database:gone'), self.cipher('unrelated',None)])
        result = program['reconcile']('sync', self.config(), remote)
        self.assertEqual(remote.written,[('database:1','old')])
        self.assertEqual(remote.deleted,[])
        self.assertEqual(result['cipher_ids'],['old'])
        self.assertNotIn('sentinel',str(result))
    def test_sync_deletes_only_a_recorded_orphan_and_preserves_same_source_copies(self):
        remote = Remote([self.cipher('copy','database:1'), self.cipher('published','database:1'),
            self.cipher('orphan-copy','database:gone'), self.cipher('orphan','database:gone'), self.cipher('foreign',None)])
        config = self.config()
        config['ledger'] = [
            {'source':'database:1','cipher_id':'published','fingerprint':'old','revision_date':'old'},
            {'source':'database:gone','cipher_id':'orphan'}, {'source':'database:stale','cipher_id':'foreign'}]
        result = program['reconcile']('sync', config, remote)
        self.assertEqual(remote.deleted,['orphan'])
        self.assertEqual(remote.written,[('database:1','published')])
        self.assertEqual(result['cipher_ids'],['published'])
    def test_sync_shared_recorded_orphan_refuses_before_any_remote_mutation(self):
        shared = self.cipher('z-shared','database:gone'); shared['collection_ids'] = ['collection','other']
        remote = Remote([self.cipher('a-orphan','database:removed'), shared])
        config = self.config()
        config['ledger'] = [{'source':'database:removed','cipher_id':'a-orphan'}, {'source':'database:gone','cipher_id':'z-shared'}]
        with self.assertRaises(program['Refusal']) as error:
            program['reconcile']('sync',config,remote)
        self.assertEqual(error.exception.code,'vault.item_shared')
        self.assertEqual(remote.deleted,[])
        self.assertEqual(remote.written,[])
    def test_sync_missing_recorded_cipher_creates_instead_of_adopting_a_marker_copy(self):
        remote = Remote([self.cipher('user-copy','database:1')])
        config = self.config()
        config['ledger'] = [{'source':'database:1','cipher_id':'published','fingerprint':'old','revision_date':'old'}]
        result = program['reconcile']('sync',config,remote)
        self.assertEqual(remote.written,[('database:1',None)])
        self.assertEqual(remote.deleted,[])
        self.assertEqual(result['cipher_ids'],['new'])
    def test_sync_trashed_recorded_cipher_deletes_only_that_cipher_and_creates(self):
        for copy_first in (True, False):
            with self.subTest(copy_first=copy_first):
                copy = self.cipher('user-copy','database:1')
                trashed = self.cipher('published','database:1'); trashed['deleted'] = True
                remote = Remote([copy,trashed] if copy_first else [trashed,copy])
                config = self.config()
                config['ledger'] = [{'source':'database:1','cipher_id':'published','fingerprint':'old','revision_date':'old'}]
                result = program['reconcile']('sync',config,remote)
                self.assertEqual(remote.written,[('database:1',None)])
                self.assertEqual(remote.deleted,['published'])
                self.assertEqual(result['cipher_ids'],['new'])
    def test_teardown_deletes_only_exact_ledger_matches_in_the_configured_scope(self):
        other_org = self.cipher('other-org', 'database:1'); other_org['organization_id'] = 'other'
        other_collection = self.cipher('other-collection', 'database:1'); other_collection['collection_ids'] = ['other']
        clone = self.cipher('clone', 'database:1'); clone['collection_ids'] = ['collection', 'other']
        foreign = self.cipher('foreign', None); foreign['collection_ids'] = ['collection', 'other']
        remote = Remote([self.cipher('published', 'database:1'), clone,
            self.cipher('unrecorded', 'database:gone'), foreign,
            self.cipher('changed-source', 'database:2'), other_org, other_collection])
        config = self.config()
        config['ledger'] = [{'source': 'database:1', 'cipher_id': identity} for identity in
            ('published', 'foreign', 'changed-source', 'other-org', 'other-collection', 'missing', 'published')]
        self.assertEqual(program['reconcile']('teardown', config, remote), {'backend': 'absent'})
        self.assertEqual(remote.deleted, ['published'])
    def test_teardown_without_a_ledger_preserves_marked_items(self):
        remote = Remote([self.cipher('clone', 'database:1')])
        self.assertEqual(program['reconcile']('teardown', self.config(), remote), {'backend': 'absent'})
        self.assertEqual(remote.deleted, [])
    def test_shared_ledger_item_refuses_before_any_delete(self):
        shared = self.cipher('z-shared', 'database:2'); shared['collection_ids'] = ['collection', 'other']
        remote = Remote([self.cipher('a-published', 'database:1'), shared])
        config = self.config()
        config['ledger'] = [{'source': 'database:1', 'cipher_id': 'a-published'}, {'source': 'database:2', 'cipher_id': 'z-shared'}]
        with self.assertRaises(program['Refusal']) as error:
            program['reconcile']('teardown', config, remote)
        self.assertEqual(error.exception.code, 'vault.item_shared')
        self.assertEqual(remote.deleted, [])
    def test_unknown_input_and_secret_bearing_exceptions_have_only_bounded_refusals(self):
        self.assertEqual(program['main']({'action':'other','configuration':{'master_password':'sentinel'}}),('failed',{'error_code':'vault.action_invalid'}))
        self.assertEqual(program['main']({'action':'setup','configuration':{'master_password':'sentinel'}}),('failed',{'error_code':'vault.unreachable'}))

if __name__=='__main__': unittest.main()
