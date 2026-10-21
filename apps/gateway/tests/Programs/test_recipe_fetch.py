"""Exercise bounded APT fetch retries against a local server. No host sources or packages change."""
import hashlib
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
import subprocess
import socket
import struct
import tempfile
import threading
import unittest
from unittest.mock import patch

from test_recipe_versions import recipe


class RecipeFetchTest(unittest.TestCase):
    def test_snapshot_lookup_and_install_share_bounded_transport_options(self):
        with tempfile.TemporaryDirectory() as directory, patch.object(recipe, 'run') as run, patch.object(recipe.os, 'chown'):
            options = recipe.snapshot_options({'snapshot': '20261021T000000Z'}, Path(directory))
            with recipe.temporary_indexes(recipe.snapshot_source('20261021T000000Z')) as lookup:
                for selected in (options, lookup):
                    config = subprocess.run(['apt-config', *selected, 'dump'], capture_output=True, text=True, check=True).stdout
                    self.assertIn('Acquire::Retries "0";', config)
                    self.assertIn('Acquire::https::Timeout "15";', config)
                    self.assertNotIn('AllowInsecureRepositories "true"', config)
            self.assertIn('APT::Update::Error-Mode=any', run.call_args.args[0])

    def test_fetch_retries_every_5xx_including_empty_responses_but_not_4xx_or_digest_failures(self):
        for statuses, payload, success, count in (([503, 200], b'index', True, 2),
                                                  ([501, 200], b'index', True, 2),
                                                  ([505], b'index', False, 4),
                                                  ([0], b'index', False, 4),
                                                  ([503], b'', False, 4),
                                                  ([408], b'index', False, 1),
                                                  ([429], b'index', False, 1),
                                                  ([503], b'index', False, 4),
                                                  ([404], b'index', False, 1),
                                                  ([200], b'tampered', False, 1)):
            with self.subTest(statuses=statuses, payload=payload):
                calls = []

                class Handler(BaseHTTPRequestHandler):
                    def do_GET(self, statuses=statuses, calls=calls, payload=payload):
                        code = statuses[min(len(calls), len(statuses) - 1)]
                        calls.append(self.path)
                        if code == 0:
                            self.connection.setsockopt(socket.SOL_SOCKET, socket.SO_LINGER, struct.pack('ii', 1, 0))
                            self.connection.close()
                            return
                        self.send_response(code)
                        self.send_header('Content-Length', str(len(payload)))
                        self.end_headers()
                        self.wfile.write(payload)

                    def log_message(self, *args):
                        pass

                with ThreadingHTTPServer(('127.0.0.1', 0), Handler) as server, tempfile.TemporaryDirectory() as directory:
                    thread = threading.Thread(target=server.serve_forever, daemon=True)
                    thread.start()
                    try:
                        argv = ['/usr/lib/apt/apt-helper', *recipe.apt_fetch_options(),
                                'download-file', f'http://127.0.0.1:{server.server_port}/Packages.gz',
                                str(Path(directory) / 'Packages.gz'),
                                'SHA256:' + hashlib.sha256(b'index').hexdigest()]
                        with patch.object(recipe, 'time') as clock, patch.object(recipe, 'run', wraps=recipe.run) as execute:
                            sleep = clock.sleep
                            if success:
                                recipe.apt_fetch(argv, timeout=10)
                            else:
                                with self.assertRaises(recipe.RecipeFailure) as failure:
                                    recipe.apt_fetch(argv, timeout=10)
                                if statuses == [503]:
                                    self.assertIn('503', failure.exception.result.stderr)
                            self.assertEqual([call.args[0] for call in sleep.call_args_list], [10, 20, 30][:count - 1])
                        self.assertEqual(execute.call_count, count)
                        if statuses == [0]:
                            self.assertGreaterEqual(len(calls), count)
                        else:
                            self.assertEqual(len(calls), count)
                    finally:
                        server.shutdown()
                        thread.join()

    def test_real_index_refresh_recovers_from_503_and_rejects_actual_signature_failures(self):
        for mode, expected_attempts in (('recover', 2), ('unavailable', 4), ('unsigned', 1), ('signature', 1), ('mixed', 1)):
            with self.subTest(mode=mode):
                fixtures = Path(__file__).resolve().parents[1] / 'Fixtures/machine-software'
                requests = []

                class Handler(BaseHTTPRequestHandler):
                    def do_GET(self, mode=mode, requests=requests, fixtures=fixtures):
                        requests.append(self.path)
                        if self.path.endswith('/InRelease'):
                            failed = mode in ('unavailable', 'mixed') or (mode == 'recover' and len(requests) == 1)
                            code, content = (503, b'') if failed else (200, (fixtures / 'apt-snapshot/InRelease').read_bytes())
                            if mode == 'unsigned':
                                code, content = 200, b'unsigned release'
                            elif mode == 'signature' or self.path.startswith('/bad/'):
                                code, content = 200, (fixtures / 'apt-snapshot/InRelease').read_bytes().replace(b'Origin: Ubuntu', b'Origin: Tampered')
                        elif self.path.endswith('/Packages.gz'):
                            code, content = 200, (fixtures / 'Packages.gz').read_bytes()
                        else:
                            code, content = 404, b''
                        self.send_response(code)
                        self.send_header('Content-Length', str(len(content)))
                        self.end_headers()
                        self.wfile.write(content)

                    def log_message(self, *args):
                        pass

                with ThreadingHTTPServer(('127.0.0.1', 0), Handler) as server:
                    thread = threading.Thread(target=server.serve_forever, daemon=True)
                    thread.start()
                    root = f'http://127.0.0.1:{server.server_port}'
                    source = (f'Types: deb\nURIs: {root}/good/\nSuites: resolute\nComponents: main\n'
                              f'Signed-By: {fixtures}/apt-snapshot/keyring.gpg\nCheck-Valid-Until: no\n')
                    if mode == 'mixed':
                        source += '\n' + source.replace('/good/', '/bad/')
                    try:
                        with patch.object(recipe, 'time') as clock, patch.object(recipe, 'run', wraps=recipe.run) as execute:
                            if mode == 'recover':
                                with recipe.temporary_indexes(source) as options:
                                    lists = Path(next(option.split('=', 1)[1] for option in options if option.startswith('Dir::State::lists=')))
                                    self.assertTrue(list(lists.glob('*Packages*')))
                            else:
                                with self.assertRaises(recipe.RecipeFailure) as failure:
                                    with recipe.temporary_indexes(source):
                                        self.fail('Invalid metadata must not validate')
                                if mode == 'unavailable':
                                    self.assertIn('503', failure.exception.result.stderr)
                            self.assertEqual(execute.call_count, expected_attempts)
                            self.assertEqual([call.args[0] for call in clock.sleep.call_args_list], [10, 20, 30][:expected_attempts - 1])
                    finally:
                        server.shutdown()
                        thread.join()

    def test_connection_resets_retry_but_mixed_digest_failures_do_not(self):
        for reason, attempts in (('Connection reset by peer', 4), ('404 Not Found', 1),
                                 ('503 Service Unavailable\nE: Hash Sum mismatch', 1),
                                 ("503 Service Unavailable\nE: The repository 'https://other.invalid resolute InRelease' is not signed.", 1)):
            result = subprocess.CompletedProcess([], 100, '', 'E: Failed to fetch https://snapshot.ubuntu.com/index ' + reason)
            with self.subTest(reason=reason), patch.object(recipe, 'run', side_effect=recipe.RecipeFailure(result=result)) as run, \
                 patch.object(recipe, 'time'):
                with self.assertRaises(recipe.RecipeFailure):
                    recipe.package_command(['apt-get', 'update'], [])
                self.assertEqual(run.call_count, attempts)


if __name__ == '__main__':
    unittest.main()
