"""Direct owner-only store program tests: verified downloads, immutable publication, adoption and locking."""
import base64
import fcntl
import hashlib
import io
import json
import os
import subprocess
import tarfile
import tempfile
import time
import unittest
from pathlib import Path
from unittest.mock import patch

from harness import assemble, library, result


def archive(files):
    stream = io.BytesIO()
    with tarfile.open(fileobj=stream, mode='w:gz') as tar:
        for name, contents in files.items():
            data = contents.encode()
            member = tarfile.TarInfo(name)
            member.size = len(data)
            member.mode = 0o755
            tar.addfile(member, io.BytesIO(data))
    return stream.getvalue()


class VpStoreTest(unittest.TestCase):
    def setUp(self):
        directory = tempfile.TemporaryDirectory(prefix='orbit-vp-store-')
        self.addCleanup(directory.cleanup)
        self.home = Path(directory.name)
        self.payload = {'store': str(self.home), 'vp_version': '0.3.3', 'node_version': '24.22.0',
                        'package_manager': 'npm', 'package_manager_version': '11.12.0'}
        self.lib = library('lib/vp-store.py')
        original_system = self.lib['platform'].system
        original_machine = self.lib['platform'].machine
        self.addCleanup(setattr, self.lib['platform'], 'system', original_system)
        self.addCleanup(setattr, self.lib['platform'], 'machine', original_machine)

    def seed(self, payload=None):
        payload = payload or self.payload
        _, node, pm = self.lib['store_paths'](payload)
        (node / 'bin').mkdir(parents=True, exist_ok=True)
        (node / 'bin' / 'node').write_text('#!/bin/sh\necho v24.22.0\n')
        (node / 'bin' / 'node').chmod(0o755)
        package = pm / payload['package_manager']
        (package / 'bin').mkdir(parents=True, exist_ok=True)
        (package / 'bin' / payload['package_manager']).write_text('#!/bin/sh\nexit 0\n')
        (package / 'bin' / payload['package_manager']).chmod(0o755)
        (package / 'package.json').write_text(json.dumps({'name': payload['package_manager'], 'version': payload['package_manager_version']}))
        # Model existing owner-only recipe/manual content independently of the runner's umask.
        for directory in self.home.rglob('*'):
            if directory.is_dir():
                directory.chmod(0o755)
        return node, pm

    def test_read_only_inspection_adopts_manual_and_recipe_content_without_a_lock_or_network(self):
        self.seed()
        before = sorted(str(path.relative_to(self.home)) for path in self.home.rglob('*'))
        self.assertEqual(result('tools.inspect-vp-store', {'environments': [self.payload]}), ('inspected', {'installed': [True]}))
        with (self.home / '.held-lock').open('w') as lock:
            fcntl.flock(lock, fcntl.LOCK_EX)
            self.assertEqual(result('tools.install-vp-environment', self.payload), ('installed', {'verified': {}}))
        (self.home / '.held-lock').unlink()
        self.assertEqual(before, sorted(str(path.relative_to(self.home)) for path in self.home.rglob('*')))
        self.assertFalse((self.home / '.orbit-store.lock').exists())

    def test_missing_install_waits_then_reports_lock_timeout_without_touching_runtime(self):
        with (self.home / '.orbit-store.lock').open('w') as lock:
            fcntl.flock(lock, fcntl.LOCK_EX)
            start = time.monotonic()
            self.assertEqual(result('tools.install-vp-environment', {**self.payload, 'lock_timeout': 0.2}), ('lock_timeout', {}))
            self.assertGreaterEqual(time.monotonic() - start, 0.2)
        self.assertFalse((self.home / 'js_runtime').exists())

    def test_deploy_lock_is_recipe_compatible_under_a_group_writable_umask(self):
        node = archive({'node-v24.22.0-linux-x64/bin/node': '#!/bin/sh\nexit 0\n'})
        npm = archive({'package/package.json': '{"name":"npm","version":"11.12.0","bin":{"npm":"bin/npm-cli.js"}}',
                       'package/bin/npm-cli.js': '#!/usr/bin/env node\n'})
        # Supply verified fixture archives at the download boundary of the actual program.
        replace = {"data, source, integrity = node_archive(payload['node_version'])":
                   'data, source, integrity = ' + repr((node, 'node-v24.22.0-linux-x64', 'verified-node')),
                   'data, integrity = manager_archive(payload)': 'data, integrity = ' + repr((npm, 'verified-npm'))}
        previous = os.umask(0o002)
        try:
            self.assertEqual(result('tools.install-vp-environment', self.payload, replace=replace)[0], 'installed')
        finally:
            os.umask(previous)
        lock = self.home / '.orbit-store.lock'
        self.assertEqual(lock.stat().st_mode & 0o777, 0o644)
        self.assertEqual(result('tools.inspect-vp-store', {'environments': [self.payload]}), ('inspected', {'installed': [True]}))

    def test_install_and_cleanup_refuse_a_symlinked_store_lock(self):
        outside = self.home / 'unrelated'
        outside.write_text('keep')
        (self.home / '.orbit-store.lock').symlink_to(outside)
        self.assertEqual(result('tools.install-vp-environment', self.payload), ('failed', {}))
        self.assertEqual(result('tools.prune-vp-store', {'store': str(self.home), 'references': []}), ('failed', {}))
        self.assertEqual(outside.read_text(), 'keep')

    def test_waiting_installer_rechecks_after_lock_and_does_not_download_twice(self):
        from harness import REMOTE
        with (self.home / '.orbit-store.lock').open('w') as lock:
            fcntl.flock(lock, fcntl.LOCK_EX)
            process = subprocess.Popen(['python3', '-I', '-c', (REMOTE / 'bootstrap.py').read_text(), 'tools.install-vp-environment'], stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
            process.stdin.write(assemble('tools.install-vp-environment').encode() + b'\0' + json.dumps(self.payload).encode())
            process.stdin.flush()
            time.sleep(0.2)
            self.seed()
            fcntl.flock(lock, fcntl.LOCK_UN)
            stdout, stderr = process.communicate(timeout=5)
        self.assertEqual(process.returncode, 0, stderr)
        self.assertEqual(json.loads(stdout)['outcome'], 'installed')
        self.assertFalse((self.home / '.orbit-runtimes.json').exists())

    def test_checks_official_node_sums_and_npm_integrity_then_publishes_side_by_side(self):
        old = {**self.payload, 'node_version': '22.18.0', 'package_manager_version': '10.9.0'}
        old_node, old_pm = self.seed(old)
        previous_umask = os.umask(0o002)
        self.addCleanup(os.umask, previous_umask)
        data = archive({'node-v24.22.0-linux-x64/bin/node': '#!/bin/sh\nexit 0\n'})
        npm = archive({'package/package.json': json.dumps({'name': 'npm', 'version': '11.12.0', 'bin': {'npm': 'bin/npm-cli.js'}}),
                       'package/bin/npm-cli.js': '#!/usr/bin/env node\n'})
        integrity = 'sha512-' + base64.b64encode(hashlib.sha512(npm).digest()).decode()
        calls = []
        def download(url, config=None):
            calls.append((url, config))
            if url.endswith('SHASUMS256.txt'):
                return (hashlib.sha256(data).hexdigest() + '  node-v24.22.0-linux-x64.tar.gz\n').encode()
            if url.endswith('.tar.gz'):
                return data
            if url.endswith('/npm/11.12.0'):
                return json.dumps({'name': 'npm', 'version': '11.12.0', 'dist': {'integrity': integrity, 'tarball': 'https://private.example/npm.tgz'}}).encode()
            return npm
        self.lib['download'] = download
        self.lib['platform'].system = lambda: 'Linux'
        self.lib['platform'].machine = lambda: 'x86_64'
        ledger = self.lib['install']({**self.payload, 'npmrc': 'registry=https://private.example/\n//private.example/:_authToken=secret'})
        self.assertTrue(self.lib['installed'](self.payload))
        self.assertTrue(old_node.exists())
        self.assertTrue(old_pm.exists())
        for directory in self.home.rglob('*'):
            if directory.is_dir():
                self.assertEqual(directory.stat().st_mode & 0o022, 0, str(directory))
        self.assertEqual((self.home / '.orbit-runtimes.json').stat().st_mode & 0o777, 0o644)
        self.assertIn('js_runtime/node/24.22.0', ledger)
        self.assertIn(('https://private.example/npm/11.12.0', {'registry': 'https://private.example/', '//private.example/:_authToken': 'secret'}), calls)
        before = list(calls)
        self.lib['install'](self.payload)
        self.assertEqual(calls, before)

    def test_partial_install_keeps_node_receipt_through_registry_failure_retry_and_collection(self):
        for manager, version in [('npm', '11.12.0'), ('pnpm', '10.17.0')]:
            for failure in ['403', 'integrity']:
                with self.subTest(manager=manager, failure=failure):
                    self.assert_partial_install_retry(manager, version, failure)

    def assert_partial_install_retry(self, manager, version, failure):
        from urllib.error import HTTPError
        self.lib['platform'].system = lambda: 'Linux'
        self.lib['platform'].machine = lambda: 'x86_64'
        node_data = archive({'node-v24.22.0-linux-x64/bin/node': '#!/bin/sh\nexit 0\n'})
        node_receipt = 'sha256-' + hashlib.sha256(node_data).hexdigest()
        home = self.home / (manager + '-' + failure)
        home.mkdir(mode=0o755)
        payload = {**self.payload, 'store': str(home), 'package_manager': manager,
                   'package_manager_version': version, 'npmrc': 'registry=https://private.example/'}
        manager_data = archive({'package/package.json': json.dumps({'name': manager, 'version': version,
                                'bin': {manager: 'bin/cli.js'}}), 'package/bin/cli.js': '#!/usr/bin/env node\n'})
        integrity = 'sha512-' + base64.b64encode(hashlib.sha512(manager_data).digest()).decode()
        failing = True
        node_downloads = []
        def download(url, config=None):
            if url.startswith('https://nodejs.org/'):
                node_downloads.append(url)
                if url.endswith('SHASUMS256.txt'):
                    return (hashlib.sha256(node_data).hexdigest() + '  node-v24.22.0-linux-x64.tar.gz\n').encode()
                return node_data
            if failing and failure == '403':
                error = HTTPError(url, 403, 'Forbidden', {}, None)
                error.close()
                raise error
            if url.endswith('/' + manager + '/' + version):
                return json.dumps({'name': manager, 'version': version,
                                   'dist': {'integrity': integrity, 'tarball': 'https://private.example/runtime.tgz'}}).encode()
            return b'corrupt' if failing else manager_data
        self.lib['download'] = download
        with self.assertRaises(HTTPError if failure == '403' else ValueError):
            self.lib['install'](payload)
        _, node, pm = self.lib['store_paths'](payload)
        receipt = home / '.orbit-runtimes.json'
        self.assertTrue((node / 'bin' / 'node').exists())
        self.assertFalse(pm.exists())
        self.assertEqual(json.loads(receipt.read_text()), {'js_runtime/node/24.22.0': node_receipt})
        self.assertFalse(self.lib['installed'](payload))
        failing = False
        ledger = self.lib['install'](payload)
        self.assertTrue(self.lib['installed'](payload))
        self.assertEqual(len(node_downloads), 2, 'Retry must reuse Node without downloading or adopting it')
        self.assertEqual(ledger, {'js_runtime/node/24.22.0': node_receipt,
                                f'package_manager/{manager}/{version}': integrity})
        self.assertEqual(json.loads(receipt.read_text()), ledger)
        outcome, data = result('tools.prune-vp-store', {'store': str(home), 'references': []})
        self.assertEqual(outcome, 'pruned')
        self.assertCountEqual(data['removed'], list(ledger))
        self.assertFalse(node.exists())
        self.assertFalse(pm.exists())
        self.assertEqual(json.loads(receipt.read_text()), {})

    def test_rejects_checksum_and_integrity_mismatch_before_publication(self):
        self.lib['download'] = lambda url: b'0' * 64 + b'  node-v24.22.0-linux-x64.tar.gz\n' if url.endswith('.txt') else b'corrupt'
        self.lib['platform'].system = lambda: 'Linux'
        self.lib['platform'].machine = lambda: 'x86_64'
        with self.assertRaisesRegex(ValueError, 'checksum mismatch'):
            self.lib['install'](self.payload)
        self.assertFalse((self.home / 'js_runtime').exists())
        with self.assertRaisesRegex(ValueError, 'integrity mismatch'):
            self.lib['verify_integrity'](b'corrupt', 'sha512-' + base64.b64encode(b'wrong').decode())

    def test_refuses_archive_traversal_and_in_place_repair(self):
        target = self.home / 'extracted'
        target.mkdir()
        with self.assertRaisesRegex(ValueError, 'Unsafe runtime archive'):
            self.lib['extract'](archive({'../../outside': 'bad'}), target)
        with self.assertRaisesRegex(ValueError, 'incomplete installed runtime'):
            self.lib['publish'](self.home, target, b'', 'ignored')
        self.assertEqual(list(target.iterdir()), [])

    def test_prunes_only_owned_unreferenced_versions_and_keeps_shared_references(self):
        node, pm = self.seed()
        other = {**self.payload, 'node_version': '22.18.0', 'package_manager_version': '10.9.0'}
        other_node, other_pm = self.seed(other)
        ledger = {str(path.relative_to(self.home)): 'verified' for path in (node, pm, other_node, other_pm)}
        (self.home / '.orbit-runtimes.json').write_text(json.dumps(ledger))
        self.assertEqual(result('tools.prune-vp-store', {'store': str(self.home), 'references': [self.payload, other, self.payload]})[1], {'removed': []})
        removed = result('tools.prune-vp-store', {'store': str(self.home), 'references': [other]})[1]['removed']
        self.assertEqual(set(removed), {str(node.relative_to(self.home)), str(pm.relative_to(self.home))})
        self.assertTrue(other_node.exists())
        self.assertTrue(other_pm.exists())
        self.assertEqual((self.home / '.orbit-runtimes.json').stat().st_mode & 0o777, 0o644)
        self.assertFalse(list(self.home.glob('.orbit-ledger-*')))

    def test_registry_auth_is_bound_to_host_path_and_https_redirects(self):
        request = self.lib['urllib'].request
        with patch.object(request, 'build_opener') as opener:
            opener.return_value.open.return_value.__enter__.return_value.read.return_value = b'{}'
            config = {'//registry.test/:_authToken': 'secret'}
            for url, expected in [('https://registry.test/npm', 'Bearer secret'), ('https://registry.test.evil/npm', None)]:
                self.lib['download'](url, config)
                sent = opener.return_value.open.call_args.args[0]
                self.assertEqual(sent.get_header('Authorization'), expected)
            self.lib['download']('https://registry.test/private/npm', {'//registry.test/private/:_authToken': 'private'})
            handler = opener.call_args.args[0]
            with self.assertRaisesRegex(ValueError, 'redirect refused'):
                handler.redirect_request(request.Request('https://registry.test/private/npm'), None, 302, '', {}, 'http://registry.test/private/npm')

    def test_rejects_group_writable_store_paths(self):
        self.seed()
        self.home.chmod(0o775)
        self.assertEqual(result('tools.inspect-vp-store', {'environments': [self.payload]}), ('invalid', {}))

    def test_rejects_runtime_root_symlinks(self):
        (self.home / 'js_runtime').symlink_to('/tmp')
        self.assertEqual(result('tools.inspect-vp-store', {'environments': [self.payload]}), ('invalid', {}))
