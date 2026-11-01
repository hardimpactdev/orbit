"""Run the shipped phase driver with every Node command faked; checks order, privilege and the printed result."""
import contextlib
import io
import json
import runpy
import subprocess
import unittest
from pathlib import Path
from unittest.mock import patch

DRIVER = str(Path(__file__).with_name('phase.py'))
ROOT = ['/usr/bin/sudo', '-n', '--', '/usr/bin/env', 'DEBIAN_FRONTEND=noninteractive', 'LC_ALL=C']
REPOSITORY = {'name': 'vendor', 'packages': ['hello']}


class Phase(unittest.TestCase):
    def phase(self, phase, replies, kind='tool.install', manager='apt', repository=None, remove_repository=False, extra=(), setup=()):
        bundle = {'input': {'kind': kind, 'manager': manager, 'package': 'hello', 'user': 'orbit', 'platform': 'linux'},
                  'repository': repository, 'remove_repository': remove_repository, 'root': manager == 'apt',
                  'setup_packages': ['curl'], 'repository_body': 'REPOSITORY',
                  **{name: name.upper() for name in ('query', 'candidate', 'write', 'plan', 'remove')}}
        self.calls = []

        def run(argv, **kwargs):
            root = argv[:len(ROOT)] == ROOT
            argv = argv[len(ROOT):] if root else argv
            name = argv[3] if argv[:3] == ['/usr/bin/python3', '-I', '-c'] else argv[0].rsplit('/', 1)[-1]
            args = [json.loads(arg) if arg.startswith('{') else arg for arg in argv[4:]]
            self.calls.append((name, root, args, argv))
            reply = replies[name].pop(0) if isinstance(replies.get(name), list) else replies.get(name, {'ready': True})
            code = 1 if reply is None else 0
            return subprocess.CompletedProcess(argv, code, '' if reply is None else json.dumps(reply), 'boom')
        output = io.StringIO()
        stdin = io.StringIO('\x1e'.join([json.dumps(bundle), *setup]))
        with patch('subprocess.run', side_effect=run), patch('sys.argv', [DRIVER, phase, *extra]), patch('sys.stdin', stdin), \
                contextlib.redirect_stdout(output), contextlib.redirect_stderr(io.StringIO()), self.assertRaises(SystemExit) as exit:
            runpy.run_path(DRIVER, run_name='__main__')
        self.code = exit.exception.code
        return json.loads(output.getvalue())

    def names(self):
        return [(name, root) for name, root, *_ in self.calls]

    def test_set_up_installs_an_absent_manager_as_root_with_the_apt_lock_wait(self):
        ready = {'manager_version': 'vp 1', 'versions': [], 'complete': True}
        result = self.phase('setup', {'QUERY': [{'scope': 'absent'}, ready], 'apt-get': {}}, manager='vp', setup=['MANAGER', 'APT'])
        self.assertEqual(self.names(), [('QUERY', False), ('apt-get', True), ('apt-get', True), ('APT', True), ('MANAGER', True), ('QUERY', False)])
        self.assertEqual(self.calls[1][3][1:4], ['-o', 'DPkg::Lock::Timeout=300', 'update'])
        self.assertEqual(self.calls[2][3][-2:], ['--', 'curl'])
        self.assertIn('--no-remove', self.calls[2][3])
        self.assertEqual((result['facts'], self.code), (ready, 0))

    def test_failed_setup_exits_nonzero(self):
        self.assertEqual(self.phase('setup', {'QUERY': {'scope': 'absent'}, 'apt-get': [{}, None]}, manager='vp', setup=['MANAGER', '']), {'facts': None})
        self.assertEqual((self.names(), self.code), ([('QUERY', False), ('apt-get', True), ('apt-get', True)], 1))
        self.phase('setup', {'QUERY': {'scope': 'conflict'}}, manager='vp', setup=['MANAGER', ''])
        self.assertEqual((self.names(), self.code), ([('QUERY', False)], 1))

    def test_source_runs_as_root_and_check_reads_the_candidate_with_it(self):
        self.assertEqual(self.phase('source', {}, repository=REPOSITORY), {'ready': True})
        self.assertEqual((self.calls[0][1:3], self.code), ((True, ['prepare', REPOSITORY]), 0))
        self.phase('source', {'REPOSITORY': {'ready': False, 'error_code': 'tool.repository_key_mismatch'}}, repository=REPOSITORY)
        self.assertEqual(self.code, 1)
        facts = {'manager_version': 'apt 3', 'versions': ['1.0'], 'complete': True}
        result = self.phase('check', {'QUERY': facts, 'CANDIDATE': {'version': '2.0'}}, kind='tool.update', repository=REPOSITORY)
        self.assertEqual(self.names(), [('QUERY', False), ('CANDIDATE', False)])
        self.assertEqual((self.calls[1][2][0]['before'], self.calls[1][2][0]['repository']), (facts, REPOSITORY))
        self.assertEqual((result, self.code), ({'facts': facts, 'candidate': {'version': '2.0'}}, 0))

    def test_write_installs_the_decided_candidate_and_reads_it_back(self):
        after = {'manager_version': 'apt 3', 'versions': ['2.0'], 'complete': True}
        result = self.phase('write', {'QUERY': [{'versions': []}, after]}, extra=['{"version": "2.0"}'])
        self.assertEqual(self.names(), [('QUERY', False), ('WRITE', True), ('QUERY', False)])
        self.assertEqual(self.calls[1][2][0]['candidate'], {'version': '2.0'})
        self.assertEqual((result, self.code), ({'written': True, 'facts': after}, 0))
        self.assertFalse(self.phase('write', {'WRITE': None}, manager='composer', extra=['{}'])['written'])
        self.assertEqual((self.names()[1], self.code), (('WRITE', False), 1))

    def test_removal_phases_fail_natively_when_they_did_not_do_their_job(self):
        present = {'manager_version': 'apt 3', 'versions': ['1.0'], 'complete': True}
        absent = {**present, 'versions': []}
        self.assertEqual(self.phase('plan', {'QUERY': present, 'PLAN': {'packages': ['hello']}})['plan'], {'packages': ['hello']})
        self.assertEqual(self.names(), [('QUERY', False), ('PLAN', False)])
        self.phase('remove', {'QUERY': present})
        self.assertEqual((self.names(), self.code), ([('QUERY', False), ('REMOVE', True)], 0))
        self.phase('remove', {'QUERY': present, 'REMOVE': None}, manager='vp')
        self.assertEqual((self.names()[1], self.code), (('REMOVE', False), 1))
        self.assertEqual((self.phase('gone', {'QUERY': absent}), self.code), ({'facts': absent}, 0))
        self.phase('gone', {'QUERY': present})
        self.assertEqual(self.code, 1)
        self.phase('remove_source', {}, repository=REPOSITORY, remove_repository=True)
        self.assertEqual((self.calls[0][1:3], self.code), ((True, ['cleanup', REPOSITORY]), 0))
        self.phase('remove_source', {'REPOSITORY': {'ready': False, 'error_code': 'tool.repository_failed'}}, repository=REPOSITORY)
        self.assertEqual(self.code, 1)


if __name__ == '__main__':
    unittest.main()
