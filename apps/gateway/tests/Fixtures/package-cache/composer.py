import hashlib
import io
import json
from pathlib import Path
import runpy
import sys
import tempfile
import unittest
from unittest.mock import patch
import zipfile
import urllib.request
import subprocess

cache = runpy.run_path(sys.argv[1])
seed = runpy.run_path(sys.argv[2])['seed']


class ComposerCacheTest(unittest.TestCase):
    def artifact(self, data=b'archive', checksum=True):
        url = 'https://api.github.com/repos/acme/demo/zipball/' + 'a' * 40
        return dict(manager='composer', package='acme/demo', version='1.0.0', url=url,
                    integrity='sha1-' + hashlib.sha1(data).hexdigest() if checksum else None,
                    key='composer/acme/demo/' + hashlib.sha1(url.encode()).hexdigest() + '.zip')

    def test_github_downloads_are_authenticated_and_credentials_never_reach_npm(self):
        with patch('urllib.request.build_opener') as opener:
            github = 'https://codeload.github.com/phpstan/phpstan/legacy.zip/' + 'a' * 40
            cache['download'](github, 'ghs_cache_sentinel')
            request = opener.return_value.open.call_args.args[0]
            self.assertEqual(request.get_header('Authorization'), 'Bearer ghs_cache_sentinel')
            cache['download']('https://registry.npmjs.org/demo/-/demo-1.0.0.tgz', 'ghs_cache_sentinel')
            request = opener.return_value.open.call_args.args[0]
            self.assertIsNone(request.get_header('Authorization'))
        with self.assertRaises(ValueError):
            cache['download'](github)
        redirect = cache['PublicRedirect']()
        with self.assertRaises(ValueError):
            redirect.redirect_request(urllib.request.Request(github), None, 302, '', {}, 'https://registry.npmjs.org/demo/-/demo-1.0.0.tgz')

    def test_additive_integrity_and_private_copy(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary) / 'shared'
            home = Path(temporary) / 'home'
            artifact = self.artifact()
            calls = []
            def fetch(url):
                calls.append(url)
                return io.BytesIO(b'archive')
            first = cache['fill'](root, [artifact], 100000, fetch)
            target = root / artifact['key']
            stamp = target.stat().st_mtime_ns
            self.assertEqual(cache['fill'](root, [artifact], 100000, fetch), first)
            self.assertEqual(cache['fill'](root, [], 100000, fetch), {})
            self.assertEqual(target.stat().st_mtime_ns, stamp)
            self.assertEqual(len(calls), 1)
            seed(root, home)
            private = home / '.cache/composer/files' / artifact['key'].removeprefix('composer/')
            private.write_bytes(b'poison')
            self.assertEqual(target.read_bytes(), b'archive')
            self.assertNotEqual(private.stat().st_ino, target.stat().st_ino)
            target.chmod(0o644)
            target.write_bytes(b'corrupt')
            cache['fill'](root, [artifact], 100000, fetch)
            self.assertEqual(target.read_bytes(), b'archive')
            self.assertEqual(next((root / 'quarantine').iterdir()).read_bytes(), b'corrupt')

    def test_rejects_bad_integrity_limit_and_unsafe_url(self):
        for change, limit in [({}, 1), ({'integrity': 'sha1-' + '0' * 40}, 100000),
                              ({'url': 'http://127.0.0.1/secret'}, 100000),
                              ({'key': '../escape'}, 100000)]:
            with self.subTest(change=change), tempfile.TemporaryDirectory() as temporary:
                root = Path(temporary) / 'cache'
                artifact = {**self.artifact(), **change}
                with self.assertRaises(ValueError):
                    cache['fill'](root, [artifact], limit, lambda url: io.BytesIO(b'archive'))
                self.assertFalse((root / self.artifact()['key']).exists())
                self.assertFalse((root / '.inventory.json').exists())

    def test_composer_consumes_seeded_archive_and_downloads_a_miss(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            archive = root / 'demo.zip'
            with zipfile.ZipFile(archive, 'w') as package:
                package.writestr('demo/composer.json', json.dumps({'name': 'acme/demo', 'version': '1.0.0'}))
                package.writestr('demo/proof.txt', 'installed')
            data = archive.read_bytes()
            artifact = self.artifact(data)
            shared = root / 'shared'
            home = root / 'home'
            cache['fill'](shared, [artifact], 100000, lambda url: io.BytesIO(data))
            seed(shared, home)
            for case in ['hit', 'miss']:
                checkout = root / case
                checkout.mkdir()
                url = artifact['url'] if case == 'hit' else archive.as_uri()
                (checkout / 'composer.json').write_text(json.dumps({'require': {'acme/demo': '1.0.0'}}))
                (checkout / 'composer.lock').write_text(json.dumps({'content-hash': 'fixture', 'packages': [dict(name='acme/demo', version='1.0.0', type='library', dist=dict(type='zip', url=url, shasum=hashlib.sha1(data).hexdigest()))], 'packages-dev': []}))
                env = {**__import__('os').environ, 'COMPOSER_CACHE_DIR': str(home / '.cache/composer'), 'COMPOSER_HOME': str(home / '.composer'), 'COMPOSER_ALLOW_SUPERUSER': '1'}
                result = subprocess.run(['composer', 'install', '--no-interaction', '--no-plugins', '--no-scripts', '--no-progress'], cwd=checkout, env=env, capture_output=True)
                self.assertEqual(result.returncode, 0, result.stderr.decode())
                self.assertEqual((checkout / 'vendor/acme/demo/proof.txt').read_text(), 'installed')
            self.assertEqual((shared / artifact['key']).read_bytes(), data)


unittest.main(argv=[sys.argv[0]])
