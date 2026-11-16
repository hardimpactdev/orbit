"""The in-copy publish check (swamp/ansible/task_copy_scan.py), on a made-up copy: every value here is invented for the test."""

import hashlib
import importlib.util
import json
import os
import subprocess
import tempfile
import unittest
from pathlib import Path

SCRIPT = Path(__file__).resolve().parents[1] / 'ansible' / 'task_copy_scan.py'
spec = importlib.util.spec_from_file_location('task_copy_scan', SCRIPT)
scan = importlib.util.module_from_spec(spec)
spec.loader.exec_module(scan)

CLUB = 'club-made-up-key-0123456789abcdef'
INNER_TOKEN = 'ORBIT_SWAMP_RUN_TOKEN'
INNER_VALUE = 'inner-run-token-made-up-9f8e7d6c5b4a'
WORKER = 'worker-access-token-made-up-1a2b3c4d5e'
WIREGUARD = 'mAdEuPwIrEgUaRdKeY0123456789abcdefghijklmn='
PUBLIC = '/home/orbit/orbit/swamp/public-path'
SHORT = 'short-value'
KEY_BODY = '\n'.join(['MIIEvQIBADANBgkqhkiG9w0BAQEFAASCBKcwggSjAgEAAoIBAQC' + str(n) * 13 for n in range(4)])


def git(work, *args, data=None):
    env = {'PATH': '/usr/bin:/bin', 'HOME': str(work), 'GIT_CONFIG_NOSYSTEM': '1', 'GIT_CONFIG_GLOBAL': '/dev/null',
           'GIT_AUTHOR_NAME': 't', 'GIT_AUTHOR_EMAIL': 't@example.test', 'GIT_COMMITTER_NAME': 't', 'GIT_COMMITTER_EMAIL': 't@example.test'}
    return subprocess.run(['git', '-C', str(work), *args], input=data, capture_output=True, check=True, env=env).stdout.decode().strip()


def commit(work, files, message='change'):
    for name, text in files.items():
        path = Path(work, name)
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(text)
    git(work, 'add', '-A')
    git(work, 'commit', '-q', '-m', message)
    return git(work, 'rev-parse', 'HEAD')


class CopyScanTest(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        base = Path(self.temporary.name)
        self.root = base / 'root'
        self.work = base / 'workspace'
        self.work.mkdir()
        git(self.work, 'init', '-q', '-b', 'main')
        self.start = commit(self.work, {'README.md': 'Orbit\nThe repository shows ' + PUBLIC + '\n'}, 'start')
        git(self.work, 'checkout', '-q', '-b', 'task-7')
        self.place('/run/orbit-club/swamp-club-api-key', CLUB + '\n')
        self.place('/etc/wireguard/wg-nested.conf', '[Interface]\nPrivateKey = ' + WIREGUARD + '\nAddress = 10.45.0.10/24\n')
        gateway = '/var/lib/incus/storage-pools/topology-seed/containers/nested-gateway/rootfs'
        self.place(gateway + '/var/lib/orbit-checkout-state/apps/gateway/.env',
                   f'APP_NAME=Orbit\n{INNER_TOKEN}={INNER_VALUE}\nSWAMP_DIR="{PUBLIC}"\nDB_PASSWORD={SHORT}\n')
        self.place(gateway + '/etc/orbit/swamp-role-worker/a/server.token', WORKER + '\n')

    def tearDown(self):
        self.temporary.cleanup()

    def place(self, path, text):
        target = Path(str(self.root) + path)
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_text(text)

    def run_check(self, head, nested=True, body=None):
        request = {'head': head, 'start': self.start, 'branch': 'task-7', 'nested': nested}
        if body is not None:
            Path(self.work, '.git/orbit/proposed-pr-bodies').mkdir(parents=True, exist_ok=True)
            Path(self.work, '.git/orbit/proposed-pr-bodies/task-7.md').write_text(body)
            request['proposed_body_path'] = '.git/orbit/proposed-pr-bodies/task-7.md'
        return scan.check(request, root=str(self.root), prefix=[], workspace=str(self.work))['scan']

    def assertNoValue(self, result):
        text = json.dumps(result)
        for value in (CLUB, INNER_VALUE, WORKER, WIREGUARD, SHORT):
            self.assertNotIn(value, text)

    def test_a_clean_change_passes_and_names_what_it_read(self):
        result = self.run_check(commit(self.work, {'docs/a.md': 'one line\n'}))

        self.assertEqual(result['secrets'], [])
        self.assertEqual(result['sources'], ['club-key', 'nested-gateway:env', 'nested-gateway:orbit-token', 'nested-gateway:swamp-token', 'operator-wireguard'])
        self.assertEqual(result['short_skipped'], {'nested-gateway:env': 2})
        self.assertNoValue(result)

    def test_each_secret_of_the_copy_is_named_by_path_line_and_name_never_by_value(self):
        head = commit(self.work, {'notes/leak.txt': f'first\nsecond {INNER_VALUE} here\n', 'notes/club.txt': CLUB + '\n',
                                  'notes/wg.txt': 'x\ny\n' + WIREGUARD + '\n', 'notes/worker.txt': WORKER})
        result = self.run_check(head)

        self.assertEqual(sorted((hit['path'], hit['line'], hit['secret']) for hit in result['secrets']), [
            ('notes/club.txt', 1, 'club-key:/run/orbit-club/swamp-club-api-key'),
            ('notes/leak.txt', 2, 'nested-gateway:env:/var/lib/orbit-checkout-state/apps/gateway/.env:' + INNER_TOKEN),
            ('notes/wg.txt', 3, 'operator-wireguard:/etc/wireguard/wg-nested.conf:PrivateKey'),
            ('notes/worker.txt', 1, 'nested-gateway:swamp-token:/etc/orbit/swamp-role-worker/a/server.token'),
        ])
        self.assertNoValue(result)

    def test_a_value_in_an_earlier_commit_message_or_a_file_name_counts_too(self):
        commit(self.work, {'tmp.txt': INNER_VALUE}, 'add')
        Path(self.work, 'tmp.txt').unlink()
        head = commit(self.work, {'other.txt': 'x\n'}, 'message holds ' + CLUB)
        result = self.run_check(head)

        kinds = sorted(hit['path'].split(' ')[0] for hit in result['secrets'])
        self.assertEqual(kinds, ['commit', 'tmp.txt'])
        named = self.run_check(commit(self.work, {f'dir-{WORKER}/a.txt': 'x\n'}))
        # The folder that holds the name is the hit; a path that holds a value is never printed.
        self.assertIn('a file name in .', [hit['path'] for hit in named['secrets']])
        self.assertNoValue(named)

    def test_short_values_and_values_the_start_tree_shows_are_no_hit(self):
        result = self.run_check(commit(self.work, {'a.txt': f'{SHORT}\n{PUBLIC}\n'}))

        self.assertEqual(result['secrets'], [])

    def test_a_private_key_block_with_a_real_body_is_refused_and_a_test_stub_is_not(self):
        stub = '-----BEGIN PRIVATE KEY-----\nMIIEabc\n-----END PRIVATE KEY-----\n'
        real = '-----BEGIN OPENSSH PRIVATE KEY-----\n' + KEY_BODY + '\n-----END OPENSSH PRIVATE KEY-----\n'
        self.assertEqual(self.run_check(commit(self.work, {'tests/stub.txt': stub}))['secrets'], [])
        result = self.run_check(commit(self.work, {'keys/id': 'header\n' + real}))

        self.assertEqual([(hit['path'], hit['line'], hit['secret']) for hit in result['secrets']], [('keys/id', 2, 'private-key-block')])

    def test_the_proposed_body_is_checked(self):
        result = self.run_check(commit(self.work, {'a.txt': 'x\n'}), body='Summary\nDocs-unaffected: x: ' + CLUB + '\n')

        self.assertEqual([(hit['path'], hit['line'], hit['secret']) for hit in result['secrets']], [('the proposed pull request body', 2, 'club-key:/run/orbit-club/swamp-club-api-key')])

    def test_the_scan_judges_no_workflow_change_not_even_of_a_main_the_copy_got_by_hand(self):
        # Operation 24405: main changed a workflow, the copy got it by hand, and its origin/main stayed the released main. The publish's
        # base read judges workflow changes against the real default head (task_copy_publish_base_test.py); the copy's refs cannot.
        git(self.work, 'checkout', '-q', 'main')
        commit(self.work, {'.github/workflows/ci.yml': 'on: push\n'}, 'main changes a workflow')
        git(self.work, 'checkout', '-q', 'task-7')
        git(self.work, 'merge', '-q', '--no-edit', 'main')
        result = self.run_check(commit(self.work, {'.github/workflows/x.yml': 'on: push\n'}))
        self.assertEqual(sorted(result), ['objects', 'objects_sha256', 'seconds', 'secrets', 'secrets_more', 'short_skipped', 'sources', 'values'])
        self.assertEqual(result['secrets'], [])

    def test_the_object_digest_is_the_one_of_the_publish_range(self):
        head = commit(self.work, {'a.txt': 'x\n'})
        ids = sorted({line.split(' ')[0] for line in git(self.work, 'rev-list', '--objects', head, '--not', self.start, '--').splitlines()})
        result = self.run_check(head)

        self.assertEqual((result['objects'], result['objects_sha256']), (len(ids), hashlib.sha256(''.join(i + '\n' for i in ids).encode()).hexdigest()))

    def test_a_nested_copy_without_its_inner_machines_is_unchecked_and_a_plain_copy_passes(self):
        head = commit(self.work, {'a.txt': 'x\n'})
        for path in Path(self.root, 'var').rglob('*'):
            if path.is_file():
                path.unlink()
        import shutil
        shutil.rmtree(Path(self.root, 'var'))
        with self.assertRaises(scan.Unchecked):
            self.run_check(head, nested=True)
        self.assertEqual(self.run_check(head, nested=False)['secrets'], [])

    def test_a_rewritten_object_file_of_the_agent_reads_as_an_error_never_as_other_content(self):
        import zlib
        head = commit(self.work, {'notes/leak.txt': INNER_VALUE + '\n'})
        blob = git(self.work, 'rev-parse', head + ':notes/leak.txt')
        loose = Path(self.work, '.git/objects', blob[:2], blob[2:])
        loose.chmod(0o644)
        clean = b'clean\n'
        loose.write_bytes(zlib.compress(b'blob ' + str(len(clean)).encode() + b'\0' + clean))

        with self.assertRaises(scan.Unchecked):
            self.run_check(head)

    def test_a_private_key_in_a_commit_message_and_odd_path_names_are_read(self):
        real = '-----BEGIN PRIVATE KEY-----\n' + KEY_BODY + '\n-----END PRIVATE KEY-----\n'
        head = commit(self.work, {'d\u00e9j\u00e0 vu/a\rb.txt': CLUB + '\n', '.github/workflows/caf\u00e9.yml': 'on: push\n'}, 'message\n\n' + real)
        result = self.run_check(head)

        self.assertEqual(sorted((hit['path'], hit['secret']) for hit in result['secrets']), [
            ('commit ' + head, 'private-key-block'), ('d\u00e9j\u00e0 vu/a\rb.txt', 'club-key:/run/orbit-club/swamp-club-api-key')])

    def test_a_link_in_place_of_a_source_is_not_followed(self):
        target = Path(str(self.root) + '/etc/wireguard/wg-nested.conf')
        moved = Path(self.temporary.name, 'elsewhere.conf')
        target.rename(moved)
        target.symlink_to(moved)
        result = self.run_check(commit(self.work, {'wg.txt': WIREGUARD + '\n'}))

        self.assertEqual(result['secrets'], [])
        self.assertNotIn('operator-wireguard', result['sources'])

    def test_an_error_prints_only_the_code_and_a_fixed_reason(self):
        out = subprocess.run(['python3', '-I', str(SCRIPT), json.dumps({'head': 'z', 'start': 'z'})], capture_output=True, text=True)

        self.assertEqual((out.returncode, json.loads(out.stdout)), (1, {'error_code': 'tasks.publish_secret_unchecked', 'reason': 'the request names no commit'}))


if __name__ == '__main__':
    unittest.main()
