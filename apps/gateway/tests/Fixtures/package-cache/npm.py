import base64
import hashlib
import io
import json
import os
from pathlib import Path
import runpy
import subprocess
import sys
import tarfile
import tempfile
import threading
import unittest
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

cache = runpy.run_path(sys.argv[1])
seed = runpy.run_path(sys.argv[2])['seed']


def package(name='@acme/demo', version='1.2.3'):
    stream = io.BytesIO()
    with tarfile.open(fileobj=stream, mode='w:gz') as archive:
        for filename, data in {'package/package.json': json.dumps({'name': name, 'version': version, 'scripts': {'postinstall': 'exit 33'}}).encode(), 'package/proof.txt': b'installed'}.items():
            member = tarfile.TarInfo(filename)
            member.size = len(data)
            member.mode = 0o644
            archive.addfile(member, io.BytesIO(data))
    return stream.getvalue()


def artifact(data, name='@acme/demo', version='1.2.3'):
    url = 'https://registry.npmjs.org/' + name + '/-/' + name.split('/')[-1] + '-' + version + '.tgz'
    integrity = 'sha512-' + base64.b64encode(hashlib.sha512(data).digest()).decode()
    return dict(manager='npm', package=name, version=version, url=url, integrity=integrity,
                key='npm/' + hashlib.sha256((url + ' ' + integrity).encode()).hexdigest() + '.tgz')


class NpmCacheTest(unittest.TestCase):
    def test_native_npm_and_bun_hits_and_private_writes(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            data = package()
            spec = artifact(data)
            shared = root / 'shared'
            home = root / 'home'
            cache['fill'](shared, [spec], 100000, lambda url: io.BytesIO(data))
            seed(shared, home, [spec])
            for manager in ['npm', 'bun']:
                checkout = root / manager
                checkout.mkdir()
                manifest = dict(name='test', version='1.0.0', dependencies={'@acme/demo': '1.2.3'})
                (checkout / 'package.json').write_text(json.dumps(manifest))
                env = {**os.environ, 'HOME': str(home), 'npm_config_cache': str(home / '.npm'), 'BUN_INSTALL_CACHE_DIR': str(home / '.bun/install/cache')}
                if manager == 'npm':
                    lock = dict(name='test', version='1.0.0', lockfileVersion=3, packages={'': manifest, 'node_modules/@acme/demo': dict(version='1.2.3', resolved=spec['url'], integrity=spec['integrity'])})
                    (checkout / 'package-lock.json').write_text(json.dumps(lock))
                    command = ['npm', 'ci', '--offline', '--ignore-scripts', '--no-audit', '--no-fund']
                else:
                    lock = dict(lockfileVersion=1, configVersion=1, workspaces={'': {'name': 'test', 'dependencies': {'@acme/demo': '1.2.3'}}}, packages={'@acme/demo': ['@acme/demo@1.2.3', '', {}, spec['integrity']]})
                    (checkout / 'bun.lock').write_text(json.dumps(lock))
                    command = ['bun', 'install', '--frozen-lockfile', '--ignore-scripts']
                result = subprocess.run(command, cwd=checkout, env=env, capture_output=True, timeout=30)
                self.assertEqual(result.returncode, 0, result.stderr.decode())
                installed = checkout / 'node_modules/@acme/demo/proof.txt'
                self.assertEqual(installed.read_text(), 'installed')
                installed.write_text('private change')
                self.assertEqual((shared / spec['key']).read_bytes(), data)

    def test_integrity_and_path_traversal_are_refused(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            spec = artifact(package())
            with self.assertRaises(ValueError):
                cache['fill'](root / 'shared', [spec], 100000, lambda url: io.BytesIO(b'wrong'))
            self.assertFalse((root / 'shared' / spec['key']).exists())
            stream = io.BytesIO()
            with tarfile.open(fileobj=stream, mode='w:gz') as archive:
                member = tarfile.TarInfo('package/../../escape')
                member.size = 1
                archive.addfile(member, io.BytesIO(b'x'))
            data = stream.getvalue()
            spec = artifact(data)
            cache['fill'](root / 'shared', [spec], 100000, lambda url: io.BytesIO(data))
            with self.assertRaises(tarfile.FilterError):
                seed(root / 'shared', root / 'home', [spec])
            self.assertFalse((root / 'home/.bun/install/cache/@acme/demo@1.2.3@@@1').exists())

    def test_misses_download_from_a_registry_without_writing_shared_state(self):
        data = package(name='demo')
        spec = artifact(data, name='demo')
        requests = []
        class Registry(BaseHTTPRequestHandler):
            def do_GET(self):
                requests.append(self.path)
                body = data if self.path.endswith('.tgz') else json.dumps({'name': 'demo', 'dist-tags': {'latest': '1.2.3'}, 'versions': {'1.2.3': {'name': 'demo', 'version': '1.2.3', 'dist': {'tarball': url, 'integrity': spec['integrity']}}}}).encode()
                self.send_response(200)
                self.send_header('Content-Type', 'application/octet-stream' if self.path.endswith('.tgz') else 'application/json')
                self.send_header('Content-Length', str(len(body)))
                self.end_headers()
                self.wfile.write(body)
            def log_message(self, *args):
                pass
        with ThreadingHTTPServer(('127.0.0.1', 0), Registry) as registry, tempfile.TemporaryDirectory() as temporary:
            url = 'http://127.0.0.1:' + str(registry.server_port) + '/demo/-/demo-1.2.3.tgz'
            threading.Thread(target=registry.serve_forever, daemon=True).start()
            root = Path(temporary)
            cache['initialize'](root / 'shared')
            for manager in ['npm', 'bun']:
                checkout = root / manager
                home = root / (manager + '-home')
                checkout.mkdir()
                home.mkdir()
                (checkout / 'package.json').write_text(json.dumps(dict(name='test', version='1.0.0', dependencies={'demo': '1.2.3'})))
                (checkout / '.npmrc').write_text('registry=http://127.0.0.1:' + str(registry.server_port) + '/\n')
                env = {**os.environ, 'HOME': str(home), 'npm_config_cache': str(home / '.npm'), 'BUN_INSTALL_CACHE_DIR': str(home / '.bun/install/cache')}
                if manager == 'npm':
                    (checkout / 'package-lock.json').write_text(json.dumps(dict(lockfileVersion=3, packages={'': {'name': 'test', 'version': '1.0.0', 'dependencies': {'demo': '1.2.3'}}, 'node_modules/demo': dict(version='1.2.3', resolved=url, integrity=spec['integrity'])})))
                    command = ['npm', 'ci', '--ignore-scripts', '--no-audit', '--no-fund']
                else:
                    command = ['bun', 'install', '--ignore-scripts']
                seed(root / 'shared', home, [spec])
                result = subprocess.run(command, cwd=checkout, env=env, capture_output=True, timeout=30)
                self.assertEqual(result.returncode, 0, result.stderr.decode())
                self.assertEqual((checkout / 'node_modules/demo/proof.txt').read_text(), 'installed')
            self.assertGreaterEqual(requests.count('/demo/-/demo-1.2.3.tgz'), 2)
            self.assertFalse((root / 'shared' / spec['key']).exists())
            registry.shutdown()


unittest.main(argv=[sys.argv[0]])
