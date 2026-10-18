import hashlib
import base64
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

    def test_repository_id_archive_is_not_a_starting_url(self):
        url = 'https://api.github.com/repositories/7769432/zipball/' + 'a' * 40
        with patch('urllib.request.build_opener') as opener:
            with self.assertRaisesRegex(ValueError, 'Rejected archive URL'):
                cache['download'](url, 'sentinel')
            opener.assert_not_called()

    def test_failure_reasons_do_not_expose_unexpected_exception_text(self):
        for error in [ValueError('secret-sentinel'), OSError('secret-sentinel')]:
            with self.subTest(error=type(error).__name__), tempfile.TemporaryDirectory() as temporary:
                def fetch(url):
                    raise error
                result = cache['fill'](Path(temporary) / 'cache', [self.artifact()], 100000, fetch)
                self.assertEqual(list(result['failures'].values()), [type(error).__name__])
                self.assertNotIn('secret-sentinel', json.dumps(result))

    def test_rejected_redirect_is_recorded_as_a_safe_reason(self):
        def fetch(url):
            return cache['PublicRedirect']().redirect_request(urllib.request.Request(url), None, 301, '', {}, 'https://evil.test/secret-sentinel')
        with tempfile.TemporaryDirectory() as temporary:
            result = cache['fill'](Path(temporary) / 'cache', [self.artifact()], 100000, fetch)
            self.assertEqual(list(result['failures'].values()), ['Rejected archive redirect'])

    def test_oversized_archive_has_a_distinct_safe_reason(self):
        with tempfile.TemporaryDirectory() as temporary, patch.dict(cache['fill'].__globals__, MAX_ARCHIVE=3):
            result = cache['fill'](Path(temporary) / 'cache', [self.artifact()], 100000, lambda url: io.BytesIO(b'archive'))
            self.assertEqual(list(result['failures'].values()), ['Archive exceeds size limit'])

    def test_moved_github_repository_redirect_keeps_the_locked_commit(self):
        commit = '301c07936b16d88628b126b01d082ba153cf4c40'
        original = 'https://api.github.com/repos/vlucas/phpdotenv/zipball/' + commit
        moved = 'https://api.github.com/repositories/7769432/zipball/' + commit
        codeload = 'https://codeload.github.com/phpdotenv/phpdotenv/legacy.zip/' + commit
        redirect = cache['PublicRedirect']()
        request = urllib.request.Request(original, headers={'Authorization': 'Bearer sentinel'})
        moved_request = redirect.redirect_request(request, None, 301, '', {}, moved)
        final = redirect.redirect_request(moved_request, None, 302, '', {}, codeload)
        self.assertEqual(final.full_url, codeload)
        self.assertEqual(final.get_header('Authorization'), 'Bearer sentinel')

    def test_redirect_rejects_other_commits_and_non_archive_destinations(self):
        original = 'https://api.github.com/repos/vlucas/phpdotenv/zipball/' + 'a' * 40
        for destination in [
                'https://api.github.com/repositories/7769432/zipball/' + 'b' * 40,
                'https://codeload.github.com/phpdotenv/phpdotenv/zip/' + 'b' * 40,
                'https://api.github.com/repos/acme/demo/zipball/' + 'b' * 40,
                'https://api.github.com/repos/acme/demo/zipball/' + 'a' * 40,
                'https://api.github.com/repositories/7769432/contents/secret',
                'https://api.github.com.evil.test/repositories/7769432/zipball/' + 'a' * 40,
                'http://api.github.com/repositories/7769432/zipball/' + 'a' * 40,
                'https://registry.npmjs.org/demo/-/demo-1.0.0.tgz']:
            with self.subTest(destination=destination), self.assertRaises(ValueError):
                cache['PublicRedirect']().redirect_request(urllib.request.Request(original), None, 301, '', {}, destination)

    def test_failed_artifact_does_not_block_later_archives_or_leave_temporary_files(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary) / 'cache'
            bad = self.artifact(checksum=False)
            url = bad['url'].replace('a' * 40, 'b' * 40)
            good = {**bad, 'url': url, 'key': 'composer/acme/demo/' + hashlib.sha1(url.encode()).hexdigest() + '.zip'}
            def fetch(url):
                if url == bad['url']:
                    raise urllib.error.HTTPError(url, 429, 'Too Many Requests', {}, None)
                return io.BytesIO(b'archive')

            result = cache['fill'](root, [bad, good], 100000, fetch)

            self.assertEqual(result['failures'], {bad['key']: 'HTTP 429'})
            self.assertEqual(result['hashes'], {good['key']: 'sha256-' + hashlib.sha256(b'archive').hexdigest()})
            self.assertEqual(json.loads((root / '.inventory.json').read_text()), {good['key']: hashlib.sha256(b'archive').hexdigest()})
            self.assertEqual(list(root.glob('.download-*')), [])

    def test_missing_auth_reports_github_misses_and_retains_verified_hits(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary) / 'shared'
            hit = self.artifact()
            cache['fill'](root, [hit], 100000, lambda url: io.BytesIO(b'archive'))
            url = hit['url'].replace('a' * 40, 'b' * 40)
            miss = {**hit, 'url': url, 'key': 'composer/acme/demo/' + hashlib.sha1(url.encode()).hexdigest() + '.zip'}
            npm_url = 'https://registry.npmjs.org/demo/-/demo-1.0.0.tgz'
            integrity = 'sha512-' + base64.b64encode(hashlib.sha512(b'archive').digest()).decode()
            npm = dict(manager='npm', package='demo', version='1.0.0', url=npm_url, integrity=integrity,
                       key='npm/' + hashlib.sha256((npm_url + ' ' + integrity).encode()).hexdigest() + '.tgz')
            def fetch(url):
                if cache['github_url'](url):
                    return cache['download'](url)
                return io.BytesIO(b'archive')
            result = cache['fill'](root, [hit, miss, npm], 100000, fetch)
            hashes = result['hashes']
            self.assertEqual(result['failures'], {miss['key']: 'GitHub installation token unavailable'})
            self.assertEqual(set(hashes), {hit['key'], npm['key']})
            self.assertFalse((root / miss['key']).exists())
            self.assertEqual((root / hit['key']).read_bytes(), b'archive')

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
            self.assertEqual(cache['fill'](root, [], 100000, fetch), {'hashes': {}, 'failures': {}})
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
        for change, limit, reason in [({}, 1, 'Cache full'), ({'integrity': 'sha1-' + '0' * 40}, 100000, 'Archive checksum mismatch'),
                                      ({'url': 'http://127.0.0.1/secret'}, 100000, 'Composer key does not match the archive'),
                                      ({'key': '../escape'}, 100000, 'Invalid Composer cache key')]:
            with self.subTest(change=change), tempfile.TemporaryDirectory() as temporary:
                root = Path(temporary) / 'cache'
                artifact = {**self.artifact(), **change}
                result = cache['fill'](root, [artifact], limit, lambda url: io.BytesIO(b'archive'))
                self.assertEqual(result, {'hashes': {}, 'failures': {artifact['key']: reason}})
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
