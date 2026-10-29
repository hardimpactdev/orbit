"""Collection behavior and local TLS trust tests; no fleet access or credentials."""
import copy
import os
import contextlib
import http.server
import socket
import ssl
import subprocess
import tempfile
import threading
from unittest.mock import patch
from pathlib import Path
import unittest

SOURCE = Path(__file__).resolve().parents[1] / 'collection.py'
module = {}
exec(compile(SOURCE.read_text().rsplit('run_program(main)', 1)[0], str(SOURCE), 'exec'), module)
Refusal, reconcile, Crypto, Collection = (module[name] for name in ('Refusal', 'reconcile', 'Crypto', 'Collection'))


def config():
    return {'organization_id': 'org', 'collection_id': 'collection', 'ledger': [], 'wanted': [
        {'source': 'database-connection:1', 'fingerprint': 'hash-one', 'cipher': {'name': 'one', 'notes': 'managed', 'fields': []}}
    ]}


class Remote:
    def __init__(self):
        self.keys = {'org': b'k' * 64}
        self.ciphers = []
        self.mutations = []
        self.lose_create = False
        self.fail_delete = False
        self.fail_write = False

    def open(self):
        return {'email': 'fixture@example.test', 'organizations': [{'id': 'org', 'name': 'Orbit'}],
            'collections': [{'id': 'collection', 'name': 'Database', 'organization_id': 'org', 'read_only': False}],
            'ciphers': copy.deepcopy(self.ciphers)}

    def write(self, item, identity=None):
        if self.fail_write:
            raise Refusal('vault.unreachable')
        self.mutations.append(('write', item['source'], identity))
        identity = identity or 'cipher-' + str(len(self.ciphers) + 1)
        remote = {'id': identity, 'revision': 'revision-1', 'source': item['source'],
            'organization_id': 'org', 'collection_ids': ['collection'], 'deleted': False}
        self.ciphers = [cipher for cipher in self.ciphers if cipher['id'] != identity] + [remote]
        if self.lose_create:
            self.lose_create = False
            raise Refusal('vault.unreachable')
        return remote

    def delete(self, identity):
        if self.fail_delete:
            raise Refusal('vault.unreachable')
        self.mutations.append(('delete', identity))
        self.ciphers = [cipher for cipher in self.ciphers if cipher['id'] != identity]


class VaultTests(unittest.TestCase):
    def test_crypto_round_trip_and_mac_refusal(self):
        crypto, key = Crypto(), bytes(range(64))
        encoded = crypto.encrypt('fixture password', key)
        self.assertEqual(crypto.decrypt(encoded, key), b'fixture password')
        with self.assertRaises(Refusal):
            crypto.decrypt(encoded, b'x' * 64)

    def test_setup_resolves_unique_organization_and_writable_collection(self):
        remote = Remote()
        result = reconcile('setup', {'organization': 'Orbit', 'collection': 'collection'}, remote)
        self.assertEqual(result['backend'], 'configured')
        self.assertEqual(result['organization_id'], 'org')

    def test_setup_refuses_a_move_with_published_items(self):
        with self.assertRaises(Refusal) as failure:
            reconcile('setup', {'organization': 'Orbit', 'collection': 'Database', 'published': True,
                'previous': {'organization_id': 'org', 'collection_id': 'old'}}, Remote())
        self.assertEqual(failure.exception.code, 'vault.items_published')

    def test_sync_creates_and_then_leaves_unchanged_items(self):
        remote, wanted = Remote(), config()
        first = reconcile('sync', wanted, remote)
        wanted['ledger'] = [{'source': first['sources'][0], 'cipher_id': first['cipher_ids'][0],
            'fingerprint': first['fingerprints'][0], 'revision_date': first['revisions'][0]}]
        self.assertEqual(reconcile('sync', wanted, remote), first)
        self.assertEqual(len(remote.mutations), 1)

    def test_lost_create_reply_is_adopted_without_a_second_create(self):
        remote, wanted = Remote(), config()
        remote.lose_create = True
        with self.assertRaises(Refusal):
            reconcile('sync', wanted, remote)
        result = reconcile('sync', wanted, remote)
        self.assertEqual(len(remote.ciphers), 1)
        self.assertEqual(result['cipher_ids'], ['cipher-1'])
        self.assertEqual([entry[2] for entry in remote.mutations], [None, 'cipher-1'])

    def test_sync_removes_duplicates_and_orphans_and_preserves_unrelated_items(self):
        remote, wanted = Remote(), config()
        reconcile('sync', wanted, remote)
        base = remote.ciphers[0]
        remote.ciphers += [{**base, 'id': 'duplicate'}, {**base, 'id': 'orphan', 'source': 'gone'},
            {**base, 'id': 'unrelated', 'source': None}, {**base, 'id': 'foreign', 'collection_ids': ['other']}]
        reconcile('sync', wanted, remote)
        self.assertEqual(sorted(cipher['id'] for cipher in remote.ciphers), ['cipher-1', 'foreign', 'unrelated'])

    def test_changed_revision_and_changed_fingerprint_are_rewritten(self):
        for change in ('revision', 'fingerprint'):
            remote, wanted = Remote(), config()
            first = reconcile('sync', wanted, remote)
            wanted['ledger'] = [{'source': first['sources'][0], 'cipher_id': first['cipher_ids'][0],
                'fingerprint': 'changed' if change == 'fingerprint' else first['fingerprints'][0],
                'revision_date': 'changed' if change == 'revision' else first['revisions'][0]}]
            reconcile('sync', wanted, remote)
            self.assertEqual(len(remote.mutations), 2)

    def test_teardown_adopts_unknown_owned_items_and_never_deletes_unrelated_items(self):
        remote, wanted = Remote(), config()
        reconcile('sync', wanted, remote)
        remote.ciphers.append({**remote.ciphers[0], 'id': 'unrelated', 'source': None})
        self.assertEqual(reconcile('teardown', wanted, remote), {'backend': 'absent'})
        self.assertEqual([cipher['id'] for cipher in remote.ciphers], ['unrelated'])
        self.assertEqual(reconcile('teardown', wanted, remote), {'backend': 'absent'})

    def test_keep_items_does_no_remote_work(self):
        class NoRemote:
            def open(self):
                raise AssertionError('Unexpected network access')
        self.assertEqual(reconcile('teardown', {'keep_items': True}, NoRemote()), {'backend': 'absent'})

    def test_failed_write_and_delete_cannot_produce_a_success_receipt(self):
        remote = Remote(); remote.fail_write = True
        with self.assertRaises(Refusal):
            reconcile('sync', config(), remote)
        remote.fail_write = False
        reconcile('sync', config(), remote)
        remote.fail_delete = True
        with self.assertRaises(Refusal):
            reconcile('teardown', config(), remote)



class VaultTrustTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.directory = tempfile.TemporaryDirectory(prefix='vault-test-tls-')
        cls.root = Path(cls.directory.name)
        for name in ('orbit', 'foreign'):
            cls.openssl('req', '-x509', '-newkey', 'ec', '-pkeyopt', 'ec_paramgen_curve:P-256', '-nodes',
                        '-keyout', name + '-ca.key', '-out', name + '-ca.pem', '-days', '1', '-subj', '/CN=' + name,
                        '-addext', 'basicConstraints=critical,CA:TRUE', '-addext', 'keyUsage=critical,keyCertSign,cRLSign')
            cls.openssl('req', '-new', '-newkey', 'ec', '-pkeyopt', 'ec_paramgen_curve:P-256', '-nodes',
                        '-keyout', name + '-server.key', '-out', name + '-server.csr', '-subj', '/CN=vault.orbit',
                        '-addext', 'subjectAltName=DNS:vault.orbit', '-addext', 'basicConstraints=critical,CA:FALSE',
                        '-addext', 'keyUsage=critical,digitalSignature', '-addext', 'extendedKeyUsage=serverAuth')
            cls.openssl('x509', '-req', '-in', name + '-server.csr', '-CA', name + '-ca.pem', '-CAkey',
                        name + '-ca.key', '-CAcreateserial', '-out', name + '-server.pem', '-days', '1', '-copy_extensions', 'copy')

    @classmethod
    def tearDownClass(cls):
        cls.directory.cleanup()

    @classmethod
    def openssl(cls, *args):
        subprocess.run(['openssl', *args], cwd=cls.root, check=True, stdout=subprocess.DEVNULL, stderr=subprocess.PIPE)

    @contextlib.contextmanager
    def server(self, ca):
        requests = []

        class Handler(http.server.BaseHTTPRequestHandler):
            def do_GET(self):
                requests.append(self.path)
                if self.path == '/redirect':
                    self.send_response(302)
                    self.send_header('Location', '/redirect-target')
                else:
                    self.send_response(200)
                self.end_headers()
                self.wfile.write(b'{}')

            def log_message(self, *_args):
                pass

        server = http.server.HTTPServer(('127.0.0.1', 0), Handler)
        context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
        context.load_cert_chain(self.root / (ca + '-server.pem'), self.root / (ca + '-server.key'))
        server.socket = context.wrap_socket(server.socket, server_side=True)
        thread = threading.Thread(target=lambda: server.serve_forever(poll_interval=0.01), daemon=True)
        thread.start()
        original = socket.getaddrinfo

        def local(host, *args, **kwargs):
            self.assertEqual(host, 'vault.orbit')
            return original('127.0.0.1', *args, **kwargs)

        try:
            with patch('socket.getaddrinfo', side_effect=local), patch('urllib.request.getproxies', return_value={}):
                yield Collection({'url': 'https://vault.orbit:' + str(server.server_port),
                                  'ca_file': str(self.root / 'orbit-ca.pem')}), requests
        finally:
            server.shutdown()
            thread.join()
            server.server_close()

    def test_trusts_only_the_explicit_orbit_ca(self):
        with patch.dict(os.environ, {'SSL_CERT_FILE': str(self.root / 'foreign-ca.pem')}):
            with self.server('orbit') as (collection, requests):
                self.assertEqual(collection.request('GET', '/api/sync'), {})
                self.assertEqual(requests, ['/api/sync'])
            with self.server('foreign') as (collection, requests):
                with self.assertRaises(Refusal) as failure:
                    collection.request('GET', '/api/sync')
                self.assertEqual(failure.exception.code, 'vault.unreachable')
                self.assertEqual(requests, [])

    def test_refuses_non_orbit_origins_before_network_access(self):
        for url in ('https://example.test', 'https://vault.orbit.example', 'https://orbit', 'http://vault.orbit',
                    'https://user:pass@vault.orbit', 'https://vault.orbit/path', 'https://vault.orbit?query=1'):
            with self.subTest(url=url), patch('socket.getaddrinfo', side_effect=AssertionError('Unexpected network access')):
                with self.assertRaises(Refusal) as failure:
                    Collection({'url': url, 'ca_file': str(self.root / 'orbit-ca.pem')}).request('GET', '/api/sync')
                self.assertEqual(failure.exception.code, 'vault.unreachable')

    def test_refuses_redirect_without_sending_another_request(self):
        with self.server('orbit') as (collection, requests):
            with self.assertRaises(Refusal) as failure:
                collection.request('GET', '/redirect')
            self.assertEqual(failure.exception.code, 'vault.unreachable')
            self.assertEqual(requests, ['/redirect'])


if __name__ == '__main__':
    unittest.main()
