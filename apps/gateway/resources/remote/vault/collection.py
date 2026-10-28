# Program: vault.collection
# Uses: lib/program.py
# Outcomes: succeeded failed
# Timeout: 900
# Input: fixed action plus Gateway-hydrated configuration; no credentials are returned.
import base64
import ctypes
import ctypes.util
import hashlib
import hmac
import json
import os
import ssl
import signal
import urllib.error
import urllib.parse
import urllib.request


class Refusal(Exception):
    def __init__(self, code):
        self.code = code


def refuse(code):
    raise Refusal(code)


def value(obj, name, default=None):
    return obj.get(name, obj.get(name[0].upper() + name[1:], default))


def decode(data):
    try:
        return base64.b64decode(data, validate=True)
    except (ValueError, TypeError):
        refuse('vault.login_failed')


class Crypto:
    """libcrypto keeps raw AES and RSA keys in memory, outside argv and files."""
    def __init__(self):
        self.lib = ctypes.CDLL(ctypes.util.find_library('crypto'))
        ptr, integer, size = ctypes.c_void_p, ctypes.c_int, ctypes.c_size_t
        signatures = {
            'EVP_CIPHER_CTX_new': (ptr, []), 'EVP_CIPHER_CTX_free': (None, [ptr]),
            'EVP_aes_256_cbc': (ptr, []),
            'EVP_CipherInit_ex': (integer, [ptr, ptr, ptr, ptr, ptr, integer]),
            'EVP_CipherUpdate': (integer, [ptr, ptr, ctypes.POINTER(integer), ptr, integer]),
            'EVP_CipherFinal_ex': (integer, [ptr, ptr, ctypes.POINTER(integer)]),
            'd2i_AutoPrivateKey': (ptr, [ptr, ctypes.POINTER(ptr), ctypes.c_long]),
            'EVP_PKEY_CTX_new': (ptr, [ptr, ptr]), 'EVP_PKEY_CTX_free': (None, [ptr]),
            'EVP_PKEY_free': (None, [ptr]), 'EVP_PKEY_decrypt_init': (integer, [ptr]),
            'EVP_PKEY_CTX_set_rsa_padding': (integer, [ptr, integer]),
            'EVP_PKEY_decrypt': (integer, [ptr, ptr, ctypes.POINTER(size), ptr, size]),
        }
        for name, (result, args) in signatures.items():
            fn = getattr(self.lib, name)
            fn.restype, fn.argtypes = result, args

    def aes(self, data, key, iv, encrypt):
        if len(key) != 32 or len(iv) != 16:
            refuse('vault.login_failed')
        ctx = self.lib.EVP_CIPHER_CTX_new()
        if not ctx:
            refuse('vault.login_failed')
        try:
            output = ctypes.create_string_buffer(len(data) + 32)
            first, last = ctypes.c_int(), ctypes.c_int()
            if self.lib.EVP_CipherInit_ex(ctx, self.lib.EVP_aes_256_cbc(), None, key, iv, int(encrypt)) != 1:
                refuse('vault.login_failed')
            if self.lib.EVP_CipherUpdate(ctx, output, ctypes.byref(first), data, len(data)) != 1:
                refuse('vault.login_failed')
            if self.lib.EVP_CipherFinal_ex(ctx, ctypes.byref(output, first.value), ctypes.byref(last)) != 1:
                refuse('vault.login_failed')
            return output.raw[:first.value + last.value]
        finally:
            self.lib.EVP_CIPHER_CTX_free(ctx)

    def decrypt(self, encrypted, key):
        if not isinstance(encrypted, str) or not encrypted.startswith('2.') or len(key) != 64:
            refuse('vault.login_failed')
        parts = encrypted[2:].split('|')
        if len(parts) != 3:
            refuse('vault.login_failed')
        iv, data, mac = map(decode, parts)
        if not hmac.compare_digest(hmac.digest(key[32:], iv + data, 'sha256'), mac):
            refuse('vault.login_failed')
        return self.aes(data, key[:32], iv, False)

    def encrypt(self, plain, key):
        if len(key) != 64:
            refuse('vault.login_failed')
        iv = os.urandom(16)
        data = self.aes(plain.encode(), key[:32], iv, True)
        mac = hmac.digest(key[32:], iv + data, 'sha256')
        return '2.' + '|'.join(base64.b64encode(part).decode() for part in (iv, data, mac))

    def rsa(self, encrypted, private_key):
        if not isinstance(encrypted, str) or not encrypted.startswith('4.'):
            refuse('vault.login_failed')
        data = decode(encrypted[2:])
        raw = ctypes.create_string_buffer(private_key)
        pointer = ctypes.cast(raw, ctypes.c_void_p)
        key = self.lib.d2i_AutoPrivateKey(None, ctypes.byref(pointer), len(private_key))
        if not key:
            refuse('vault.login_failed')
        ctx = self.lib.EVP_PKEY_CTX_new(key, None)
        try:
            if not ctx or self.lib.EVP_PKEY_decrypt_init(ctx) != 1 or self.lib.EVP_PKEY_CTX_set_rsa_padding(ctx, 4) != 1:
                refuse('vault.login_failed')
            length = ctypes.c_size_t()
            if self.lib.EVP_PKEY_decrypt(ctx, None, ctypes.byref(length), data, len(data)) != 1:
                refuse('vault.login_failed')
            output = ctypes.create_string_buffer(length.value)
            if self.lib.EVP_PKEY_decrypt(ctx, output, ctypes.byref(length), data, len(data)) != 1:
                refuse('vault.login_failed')
            return output.raw[:length.value]
        finally:
            if ctx:
                self.lib.EVP_PKEY_CTX_free(ctx)
            self.lib.EVP_PKEY_free(key)


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


class Collection:
    def __init__(self, configuration, send=None, crypto=None):
        self.config = configuration
        self.crypto = crypto or Crypto()
        self.token = None
        self.keys = {}
        self.user = None
        self.send = send or self.request

    def request(self, method, path, body=None, form=False, missing=False):
        url = self.config['url'].rstrip('/') + path
        origin = urllib.parse.urlsplit(self.config['url'])
        if origin.scheme != 'https' or not origin.hostname or not origin.hostname.endswith('.orbit') or origin.path not in ('', '/') or origin.query or origin.fragment or origin.username or origin.password:
            refuse('vault.unreachable')
        headers = {'Accept': 'application/json', 'Bitwarden-Client-Name': 'cli', 'Bitwarden-Client-Version': '2026.6.0', 'Device-Type': '25'}
        if self.token:
            headers['Authorization'] = 'Bearer ' + self.token
        encoded = None
        if body is not None:
            encoded = urllib.parse.urlencode(body).encode() if form else json.dumps(body).encode()
            headers['Content-Type'] = 'application/x-www-form-urlencoded' if form else 'application/json'
        context = ssl.create_default_context(cafile=self.config['ca_file'])
        opener = urllib.request.build_opener(urllib.request.HTTPSHandler(context=context), NoRedirect())
        def timeout(_signum, _frame):
            raise TimeoutError()
        previous = signal.signal(signal.SIGALRM, timeout)
        signal.setitimer(signal.ITIMER_REAL, 15)
        try:
            with opener.open(urllib.request.Request(url, data=encoded, headers=headers, method=method), timeout=15) as response:
                raw = response.read()
                return json.loads(raw) if raw else {}
        except urllib.error.HTTPError as error:
            if missing and error.code == 404:
                return None
            if path == '/identity/connect/token' and error.code in (400, 401):
                refuse('vault.login_failed')
            # Vaultwarden returns 400 for an already deleted cipher as well as 404.
            if missing and error.code == 400 and b"doesn't exist" in error.read().lower():
                return None
            refuse('vault.unreachable')
        except (OSError, ValueError, urllib.error.URLError):
            refuse('vault.unreachable')
        finally:
            signal.setitimer(signal.ITIMER_REAL, 0)
            signal.signal(signal.SIGALRM, previous)

    def open(self):
        config = self.config
        token = self.send('POST', '/identity/connect/token', {
            'grant_type': 'client_credentials', 'scope': 'api', 'client_id': config['client_id'],
            'client_secret': config['client_secret'], 'deviceType': 25,
            'deviceIdentifier': config['device_identifier'], 'deviceName': 'Orbit Gateway',
        }, form=True)
        self.token = token.get('access_token')
        if not isinstance(self.token, str) or not self.token:
            refuse('vault.login_failed')
        snapshot = self.send('GET', '/api/sync?excludeDomains=true')
        profile = value(snapshot, 'profile', {})
        email, self.user = value(profile, 'email'), value(profile, 'id')
        if not isinstance(email, str) or not isinstance(self.user, str):
            refuse('vault.unreachable')
        kdf = self.send('POST', '/identity/accounts/prelogin', {'email': email})
        iterations = value(kdf, 'kdfIterations')
        if value(kdf, 'kdf') != 0 or type(iterations) is not int or not 1 <= iterations <= 2_000_000:
            refuse('vault.kdf_unsupported')
        master = hashlib.pbkdf2_hmac('sha256', config['master_password'].encode(), email.strip().lower().encode(), iterations, 32)
        stretched = hmac.digest(master, b'enc\1', 'sha256') + hmac.digest(master, b'mac\1', 'sha256')
        user_key = self.crypto.decrypt(value(profile, 'key'), stretched)
        private_key = self.crypto.decrypt(value(profile, 'privateKey'), user_key)
        organizations = []
        for organization in value(profile, 'organizations', []):
            if value(organization, 'status') != 2:
                continue
            identity, name = value(organization, 'id'), value(organization, 'name')
            if not isinstance(identity, str) or not isinstance(name, str):
                continue
            self.keys[identity] = self.crypto.rsa(value(organization, 'key'), private_key)
            organizations.append({'id': identity, 'name': name})
        collections = []
        for collection in value(snapshot, 'collections', []):
            organization = value(collection, 'organizationId')
            if organization in self.keys:
                collections.append({'id': value(collection, 'id'), 'organization_id': organization,
                    'name': self.crypto.decrypt(value(collection, 'name'), self.keys[organization]).decode(),
                    'read_only': value(collection, 'readOnly') is True})
        ciphers = []
        for cipher in value(snapshot, 'ciphers', []):
            source = None
            organization = value(cipher, 'organizationId')
            key = self.keys.get(organization)
            if key is not None:
                try:
                    if value(cipher, 'key'):
                        key = self.crypto.decrypt(value(cipher, 'key'), key)
                    for field in value(cipher, 'fields', []):
                        if self.crypto.decrypt(value(field, 'name'), key) == b'orbit.source':
                            source = self.crypto.decrypt(value(field, 'value'), key).decode()
                            break
                except (Refusal, UnicodeError):
                    pass
            ciphers.append({'id': value(cipher, 'id'), 'source': source, 'organization_id': organization,
                'collection_ids': value(cipher, 'collectionIds', []), 'revision': value(cipher, 'revisionDate'),
                'deleted': value(cipher, 'deletedDate') is not None})
        return {'email': email, 'organizations': organizations, 'collections': collections, 'ciphers': ciphers}

    def delete(self, identity):
        self.send('DELETE', '/api/ciphers/' + urllib.parse.quote(identity, safe=''), missing=True)

    def write(self, item, identity=None):
        key = self.keys[self.config['organization_id']]
        cipher = item['cipher']
        body = {'type': 2, 'organizationId': self.config['organization_id'], 'folderId': None,
            'name': self.crypto.encrypt(cipher['name'], key), 'notes': self.crypto.encrypt(cipher['notes'], key),
            'favorite': False, 'reprompt': 0, 'secureNote': {'type': 0},
            'fields': [{'type': 1 if field['hidden'] else 0, 'name': self.crypto.encrypt(field['name'], key),
                'value': self.crypto.encrypt(field['value'], key), 'linkedId': None} for field in cipher['fields']],
            'lastKnownRevisionDate': None, 'encryptedFor': self.user}
        if identity is None:
            result = self.send('POST', '/api/ciphers/create', {'cipher': body, 'collectionIds': [self.config['collection_id']]})
        else:
            result = self.send('PUT', '/api/ciphers/' + urllib.parse.quote(identity, safe=''), body, missing=True)
        if not result or not isinstance(value(result, 'id'), str):
            refuse('vault.item_missing' if identity else 'vault.unreachable')
        return {'id': value(result, 'id'), 'revision': value(result, 'revisionDate') or ''}


def reconcile(action, config, remote):
    if action == 'teardown' and config.get('keep_items'):
        return {'backend': 'absent'}
    snapshot = remote.open()
    if action == 'setup':
        organizations = [item for item in snapshot['organizations'] if config['organization'] in (item['id'], item['name'])]
        if len(organizations) != 1:
            refuse('vault.organization_missing')
        organization = organizations[0]
        collections = [item for item in snapshot['collections'] if item['organization_id'] == organization['id'] and config['collection'] in (item['id'], item['name'])]
        if len(collections) != 1:
            refuse('vault.collection_missing')
        collection = collections[0]
        if collection['read_only']:
            refuse('vault.collection_read_only')
        previous = config.get('previous')
        if previous and config.get('published') and (previous['organization_id'] != organization['id'] or previous['collection_id'] != collection['id']):
            refuse('vault.items_published')
        return {'backend': 'configured', 'email': snapshot['email'], 'organization_id': organization['id'],
            'organization_name': organization['name'], 'collection_id': collection['id'], 'collection_name': collection['name']}
    if config['organization_id'] not in remote.keys:
        refuse('vault.organization_missing')
    ciphers = [item for item in snapshot['ciphers'] if item['organization_id'] == config['organization_id'] and config['collection_id'] in item['collection_ids']]
    if action == 'teardown':
        identities = {item['id'] for item in ciphers if item['source'] is not None}
        # Do not delete an unrelated item even if the old ledger now names it.
        identities.update(item['cipher_id'] for item in config.get('ledger', []) if any(cipher['id'] == item['cipher_id'] and cipher['source'] == item['source'] for cipher in ciphers))
        for identity in sorted(identities):
            remote.delete(identity)
        return {'backend': 'absent'}
    wanted = {item['source']: item for item in config['wanted']}
    ledger = {item['source']: item for item in config['ledger']}
    kept = {}
    for cipher in ciphers:
        source = cipher['source']
        if source is None:
            continue
        if cipher['deleted'] or source not in wanted:
            remote.delete(cipher['id'])
            continue
        previous = ledger.get(source)
        if source not in kept or (previous and previous['cipher_id'] == cipher['id']):
            if source in kept:
                remote.delete(kept[source]['id'])
            kept[source] = cipher
        else:
            remote.delete(cipher['id'])
    result = {'backend': 'synced', 'sources': [], 'cipher_ids': [], 'revisions': [], 'fingerprints': []}
    errors = []
    for source, item in wanted.items():
        try:
            existing, previous = kept.get(source), ledger.get(source)
            if existing and previous and previous['cipher_id'] == existing['id'] and previous['fingerprint'] == item['fingerprint'] and previous['revision_date'] == existing['revision']:
                published = existing
            else:
                published = remote.write(item, existing['id'] if existing else None)
            result['sources'].append(source)
            result['cipher_ids'].append(published['id'])
            result['revisions'].append(published['revision'] or '')
            result['fingerprints'].append(item['fingerprint'])
        except Refusal as failure:
            errors.append(failure.code)
    if errors:
        refuse('vault.sync_failed')
    return result


def main(payload):
    try:
        action, config = payload['action'], payload['configuration']
        if action not in ('setup', 'sync', 'teardown'):
            refuse('vault.action_invalid')
        return 'succeeded', reconcile(action, config, Collection(config))
    except Refusal as failure:
        return 'failed', {'error_code': failure.code}
    except Exception:
        # Never print exception text: HTTP clients and crypto libraries can include secret input.
        return 'failed', {'error_code': 'vault.unreachable'}


run_program(main)
