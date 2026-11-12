"""Execute the production byte rule and its real Ansible task, unprivileged and entirely under a temp directory."""
import json
import os
import shutil
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
SOURCE = ROOT / 'swamp/ansible/instance_mail_line.py'


class MailLineTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='orbit-mail-line-')
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.envfile = self.root / '.env'
        self.calls = self.root / 'calls.json'
        self.calls.write_text('[]')
        (self.root / 'file-actions.json').write_text('[]')
        self.bin = self.root / 'bin'
        self.bin.mkdir()
        fake = self.bin / 'incus'
        fake.write_text('''#!/usr/bin/python3
import json, os, subprocess, sys
from pathlib import Path
root = Path(os.environ['MAIL_FIXTURE'])
args = sys.argv[1:]
actions = json.loads((root / 'file-actions.json').read_text())
if 'file' in args and 'delete' in args:
    if args[-1] != 'fixture/srv/workspace/.env.orbit-mail':
        sys.exit(92)
    actions.append({'action': 'delete', 'path': args[-1], 'stopped': (root / 'stopped').exists()})
    (root / 'file-actions.json').write_text(json.dumps(actions))
    if (root / 'delete-error').exists():
        sys.stderr.write((root / 'delete-error').read_text())
        sys.exit(1)
    try:
        (root / '.env.orbit-mail').unlink()
    except FileNotFoundError:
        sys.stderr.write('Error: file does not exist\\n')
        sys.exit(1)
    sys.exit(0)
actions.append({'action': 'exec', 'stopped': (root / 'stopped').exists()})
(root / 'file-actions.json').write_text(json.dumps(actions))
if (root / 'stopped').exists():
    sys.exit(93)
if 'exec' not in args:
    sys.exit(91)
# The production helper uses the default guest identity (root), never the app user's argv.
if '--project' in args and args != ['--project', 'fixture', 'exec', 'fixture', '--', *args[args.index('--') + 1:]]:
    sys.exit(94)
if args[:1] == ['--project'] and any(x in args[:args.index('--')] for x in ['--user', '--group', '--cwd']):
    sys.exit(95)
args = args[args.index('--') + 1:]
args = [x.replace('/srv/workspace/.env', str(root / '.env')).replace('/fixture/app', str(root / 'app')).replace('/fixture/php', str(root / 'php')) for x in args]
if args[:2] == ['systemctl', 'try-restart']:
    calls = json.loads((root / 'calls.json').read_text())
    if args[2] in ['orbit-frankenphp.service', 'orbit-process-42.service']:
        calls.append(args[2])
    (root / 'calls.json').write_text(json.dumps(calls))
    sys.exit(0)
sys.exit(subprocess.run(args).returncode)
''')
        fake.chmod(0o755)
        # Kill the actual replacement shell at its first command after cat has staged all bytes.
        copy = self.bin / 'cp'
        copy.write_text('''#!/usr/bin/python3
import json, os, signal, sys
from pathlib import Path
root = Path(os.environ['MAIL_FIXTURE'])
(root / 'metadata-copy.json').write_text(json.dumps(sys.argv[1:]))
if (root / 'interrupt-after-stage').exists():
    (root / 'staged-names.json').write_text(json.dumps([Path(sys.argv[-1]).name]))
    (root / 'stopped').touch()
    os.kill(os.getppid(), signal.SIGKILL)
    sys.exit(1)
os.execv('/usr/bin/cp', ['cp', *sys.argv[1:]])
''')
        copy.chmod(0o755)
        self.env = {**os.environ, 'PATH': str(self.bin) + ':' + os.environ['PATH'], 'MAIL_FIXTURE': str(self.root),
                    'ANSIBLE_DISPLAY_FAILED_STDERR': '1', 'ANSIBLE_NOCOLOR': '1', 'ANSIBLE_STDOUT_CALLBACK': 'default', 'ANSIBLE_COLLECTIONS_PATH': str(self.root / 'collections'),
                    'ORBIT_RUN': str(self.root)}

    def run_rule(self, data=None):
        if data is not None:
            self.envfile.write_bytes(data)
        result = subprocess.run([sys.executable, '-c', SOURCE.read_text(), 'fixture', 'fixture'], env=self.env,
                                capture_output=True, check=True)
        return json.loads(result.stdout)

    def test_exact_lf_crlf_and_no_final_newline_preserve_every_other_byte(self):
        for ending in [b'\n', b'\r\n', b'']:
            before = (b'\xef\xbb\xbf# fixture\r\n\r\nAPP_URL=hand-choice\nDB_HOST=hand-choice\n'
                      b'VITE_APP_NAME="${APP_NAME}"\nMAIL_PASSWORD="quoted"\nMAIL_MAILER=log' + ending +
                      (b'# last comment\nODD=last' if ending else b''))
            result = self.run_rule(before)
            self.assertEqual(result, {'status': 'changed', 'lines': [7]})
            self.assertTrue(self.envfile.read_bytes() == before.replace(b'MAIL_MAILER=log', b'MAIL_MAILER=smtp'))
            stamp = self.envfile.stat().st_mtime_ns
            self.assertEqual(self.run_rule(), {'status': 'already_smtp'})
            self.assertEqual(self.envfile.stat().st_mtime_ns, stamp)

    def test_all_nonexact_forms_duplicates_and_multiline_quote_guards_are_preserved(self):
        cases = [
            (b' MAIL_MAILER=log', [1]), (b'MAIL_MAILER=log ', [1]), (b'export MAIL_MAILER=log', [1]),
            (b'MAIL_MAILER="log"', [1]), (b"MAIL_MAILER='log'", [1]), (b'MAIL_MAILER=log # comment', [1]),
            (b'MAIL_MAILER=LOG', [1]), (b'MAIL_MAILER = log', [1]), (b'"MAIL_MAILER"=log', [1]),
            (b'MAIL_MAILER=log\nMAIL_MAILER=log', [1, 2]),
            (b'MAIL_MAILER=log\nexport MAIL_MAILER=smtp', [1, 2]),
            (b'MAIL_MAILER=log\n"MAIL_MAILER"=smtp', [1, 2]),
            (b'OTHER.KEY="first\nMAIL_MAILER=log\nlast"', [2]),
            (b'\xef\xbb\xbf"OTHER"="first\nMAIL_MAILER=log\nlast"', [2]),
            (b"OTHER='first\nMAIL_MAILER=log\nlast'", [2]),
            (b'\xef\xbb\xbfMAIL_MAILER=log', [1]),
            (b'MAIL_MAILER=log\r', [1]),
            (b'MAIL_MAILER=log\n\x0bMAIL_MAILER=ses\n', [1, 2]),
            (b'\x0bMAIL_MAILER=ses\nMAIL_MAILER=log\n', [1, 2]),
            (b'MAIL_MAILER=log\n\x00MAIL_MAILER=ses\n', [1, 2]),
            (b'MAIL_MAILER=log\n\rMAIL_MAILER=ses\n', [1, 2]),
            (b'MAIL_MAILER=log\nexport\x0cMAIL_MAILER=ses\n', [1, 2]),
            (b'# mentions MAIL_MAILER\nMAIL_MAILER=log\n', [1, 2]),
            (b'OTHER=MAIL_MAILER\nMAIL_MAILER=log\n', [1, 2]),
            (b'OTHER=MAIL_MAILER-MAIL_MAILER\n', [1, 1]),
        ]
        for before, numbers in cases:
            self.envfile.write_bytes(before)
            stamp = self.envfile.stat().st_mtime_ns
            self.assertEqual(self.run_rule(), {'status': 'left_as_written', 'lines': numbers})
            self.assertTrue(self.envfile.read_bytes() == before)
            self.assertEqual(self.envfile.stat().st_mtime_ns, stamp)
            self.assertEqual(json.loads(self.calls.read_text()), [])

    def test_missing_key_missing_file_symlink_and_already_smtp_write_nothing(self):
        self.assertEqual(self.run_rule(), {'status': 'not_present'})
        before = b'# MAIL_MAILER=log\nDB_HOST=hand-choice'
        self.assertEqual(self.run_rule(before), {'status': 'left_as_written', 'lines': [1]})
        self.assertTrue(self.envfile.read_bytes() == before)
        self.assertEqual(self.run_rule(b'# no key here\nDB_HOST=hand-choice'), {'status': 'not_present'})
        self.assertEqual(self.run_rule(b'MAIL_MAILER=smtp\r\n'), {'status': 'already_smtp'})
        target = self.root / 'target'
        target.write_bytes(b'MAIL_MAILER=log\n')
        self.envfile.unlink()
        self.envfile.symlink_to(target)
        self.assertEqual(self.run_rule(), {'status': 'left_as_written', 'lines': []})
        self.assertTrue(target.read_bytes() == b'MAIL_MAILER=log\n')
        self.assertTrue(self.envfile.is_symlink())

    def test_atomic_replacement_preserves_owner_group_and_mode_without_backup(self):
        self.envfile.write_bytes(b'MAIL_MAILER=log\n')
        groups = os.getgroups()
        group = next((x for x in groups if x != os.getgid()), os.getgid())
        os.chown(self.envfile, os.getuid(), group)
        self.envfile.chmod(0o640)
        before = self.envfile.stat()
        self.assertEqual(self.run_rule()['status'], 'changed')
        after = self.envfile.stat()
        self.assertEqual((after.st_uid, after.st_gid, after.st_mode), (before.st_uid, before.st_gid, before.st_mode))
        self.assertNotEqual(after.st_ino, before.st_ino)
        self.assertEqual(list(self.root.glob('.env.*')), [])

    def test_real_incus_missing_delete_contract_and_first_run(self):
        for force in [[], ['--force']]:
            result = subprocess.run(['incus', '--project', 'fixture', 'file', 'delete', *force,
                                     'fixture/srv/workspace/.env.orbit-mail'], env=self.env, capture_output=True)
            self.assertEqual((result.returncode, result.stdout, result.stderr),
                             (1, b'', b'Error: file does not exist\n'))
        self.assertEqual(self.run_rule(b'MAIL_MAILER=log\n'), {'status': 'changed', 'lines': [1]})
        self.assertEqual(list(self.root.glob('.env.*')), [])

    def test_cleanup_rejects_every_other_delete_error_without_exposing_content(self):
        before = b'MAIL_MAILER=log\nMAIL_PASSWORD=fixture-canary\n'
        self.envfile.write_bytes(before)
        for error in ['Error: Permission denied\n', 'Error: file does not exist\nextra\n',
                      'Error: file does not exist', 'MAIL_PASSWORD=fixture-canary\n']:
            (self.root / 'delete-error').write_text(error)
            result = subprocess.run([sys.executable, '-c', SOURCE.read_text(), 'fixture', 'fixture'],
                                    env=self.env, capture_output=True)
            self.assertEqual(result.returncode, 1)
            public = json.loads(result.stdout)
            self.assertEqual(public['error_code'], 'env.configuration_invalid')
            self.assertEqual(public.get('error_detail'), 'Error: Permission denied' if error == 'Error: Permission denied\n' else None)
            self.assertNotIn(b'fixture-canary', result.stdout + result.stderr)
            self.assertEqual(result.stderr, b'')
            self.assertTrue(self.envfile.read_bytes() == before)
            self.assertEqual(json.loads(self.calls.read_text()), [])

    def test_atomic_replacement_preserves_distinct_acl_and_user_xattr(self):
        self.envfile.write_bytes(b'MAIL_MAILER=log\n')
        def assert_copy_issued():
            self.assertEqual(json.loads((self.root / 'metadata-copy.json').read_text()),
                             ['--attributes-only', '--preserve=mode,ownership,timestamps,xattr', '--',
                              str(self.envfile), str(self.root / '.env.orbit-mail')])
        if not shutil.which('setfacl') or not shutil.which('getfacl'):
            self.assertEqual(self.run_rule()['status'], 'changed')
            assert_copy_issued()
            self.skipTest('setfacl/getfacl unavailable; metadata copy command executed and asserted')
        self.envfile.write_bytes(b'MAIL_MAILER=log\n')
        # The source ACL differs from the directory default and the initially staged inode.
        named_uid = os.getuid() + 1
        try:
            subprocess.run(['setfacl', '-m', f'd:u:{named_uid}:r--', str(self.root)], check=True, capture_output=True)
            subprocess.run(['setfacl', '-m', f'u:{named_uid}:rw-', str(self.envfile)], check=True, capture_output=True)
            os.setxattr(self.envfile, 'user.orbit-mail-fixture', b'nonempty-private-metadata')
        except (OSError, subprocess.CalledProcessError) as error:
            self.assertEqual(self.run_rule()['status'], 'changed')
            assert_copy_issued()
            self.skipTest(f'temp filesystem lacks ACL/xattr support: {type(error).__name__}; copy command executed')
        def metadata():
            info = self.envfile.stat()
            acl = subprocess.run(['getfacl', '-cp', str(self.envfile)], check=True, capture_output=True).stdout
            attrs = {key: os.getxattr(self.envfile, key) for key in os.listxattr(self.envfile)}
            return (info.st_uid, info.st_gid, info.st_mode, acl, attrs)
        before = metadata()
        self.assertEqual(self.run_rule()['status'], 'changed')
        assert_copy_issued()
        self.assertTrue(metadata() == before, 'ACL/xattr/ownership/mode changed after atomic rename')
        self.assertEqual(list(self.root.glob('.env.*')), [])

    def test_workflow_reports_failed_tasks_to_stderr_and_keeps_content_private(self):
        import yaml
        workflow = yaml.safe_load((ROOT / 'swamp/workflows/workflow-operation-instance-mail-update.yaml').read_text())
        step = next(step for step in workflow['jobs'][0]['steps'] if step['name'] == 'Apply development mail')
        self.assertEqual(step['task']['inputs']['env']['ANSIBLE_DISPLAY_FAILED_STDERR'], '1')
        tasks = yaml.safe_load((ROOT / 'swamp/ansible/tasks/instance-mail-line.yml').read_text())
        self.assertTrue(tasks[0]['no_log'])
        self.assertNotIn('stderr', tasks[1]['ansible.builtin.assert']['fail_msg'])

    def ansible(self, path_status='applied', php=True, failure=False, probe_preserved=False):
        import yaml
        tasks = self.root / 'tasks'
        tasks.mkdir(exist_ok=True)
        shutil.copy(SOURCE, self.root / SOURCE.name)
        shutil.copy(ROOT / 'swamp/ansible/tasks/instance-mail-line.yml', tasks)
        (tasks / 'instance-mail.yml').write_text('---\n- name: Fixture shared path boundary\n  ansible.builtin.set_fact:\n    mail_result: ' + json.dumps({'status': path_status, **({'reason': 'mail_port_in_use'} if path_status == 'skipped' else {})}) + '\n')
        if failure:
            (tasks / 'instance-mail.yml').write_text('---\n- name: Fixture mail path failure\n  ansible.builtin.fail: {msg: instance.host_unavailable}\n')
        wrapper = yaml.safe_load((ROOT / 'swamp/ansible/instance-mail-update.yml').read_text())[1]
        wrapper.update({'hosts': 'fixture_host', 'connection': 'local', 'become': False})
        wrapper['vars'] = {'ansible_python_interpreter': sys.executable, 'container': {'project': 'fixture', 'name': 'fixture'},
                           'mail_refresh': {'php': '/fixture/php' if php else None, 'application': '/fixture/app',
                                            'units': ['orbit-frankenphp.service', 'orbit-process-42.service', 'orbit-process-stopped.service']},
                           'as_root': ['incus', 'exec', 'fixture', '--'], 'as_orbit': ['incus', 'exec', 'fixture', '--']}
        wrapper['vars']['intent'] = {}
        if probe_preserved:
            wrapper['post_tasks'] = [
                {'name': 'Probe the real preserved-line rule independently of the skipped path',
                 'ansible.builtin.include_tasks': 'tasks/instance-mail-line.yml'},
                {'name': 'Keep only the public preserved-line receipt', 'ansible.builtin.copy':
                 {'content': '{{ mailer_result | to_json }}', 'dest': str(self.root / 'preserved-result.json'), 'mode': '0600'},
                 'changed_when': False},
            ]
        play = self.root / 'play.yml'
        plays = [wrapper]
        if probe_preserved:
            # A second play in the third launch exercises the production failure/rescue path.
            import copy
            wrapper['post_tasks'].append({'name': 'Retain the original skip receipt', 'ansible.builtin.copy':
                {'src': str(self.root / 'result.json'), 'dest': str(self.root / 'skip-result.json'),
                 'remote_src': True, 'mode': '0600'}})
            failed = copy.deepcopy(wrapper)
            failed.pop('post_tasks')
            failed['pre_tasks'] = [
                {'name': 'Fixture an Incus permission failure', 'ansible.builtin.copy':
                 {'content': 'Error: Permission denied\n', 'dest': str(self.root / 'delete-error'), 'mode': '0600'}},
                {'name': 'Fixture the ready path', 'ansible.builtin.set_fact': {'mail_result': {'status': 'applied'}}},
            ]
            failed['tasks'][0]['block'].pop(0)
            plays.append(failed)
        play.write_text(yaml.safe_dump(plays, sort_keys=False))
        executable = shutil.which('ansible-playbook')
        self.assertIsNotNone(executable, 'Pinned Ansible must be on PATH')
        result = subprocess.run([executable, '-i', 'fixture_host,', '--tags', 'mail', str(play)], env=self.env, capture_output=True, check=False)
        self.assertEqual(result.returncode == 0, not (failure or probe_preserved), result.stdout.decode() + result.stderr.decode())
        self.assertNotIn(b'MAIL_PASSWORD=', result.stdout + result.stderr)
        self.assertNotIn(b'fixture-canary', result.stdout + result.stderr)
        if probe_preserved:
            self.assertIn(b'env.configuration_invalid: Error: Permission denied', result.stderr)
            self.assertEqual(json.loads((self.root / 'result.json').read_text()), {'error_code': 'env.configuration_invalid'})
            return json.loads((self.root / 'skip-result.json').read_text())
        return json.loads((self.root / 'result.json').read_text())

    def test_real_ansible_step_refreshes_only_changed_file_and_skips_conflict(self):
        before = b'MAIL_MAILER=log\nMAIL_PASSWORD=fixture-canary\n'
        self.envfile.write_bytes(before)
        (self.root / 'app').mkdir()
        (self.root / 'app/artisan').touch()
        php = self.root / 'php'
        php.write_text('#!/bin/sh\nprintf called >> "' + str(self.root / 'config-clear') + '"\nexit 1\n')
        php.chmod(0o755)
        self.assertEqual(self.ansible()['mailer'], {'status': 'changed', 'lines': [1]})
        self.assertTrue((self.root / 'config-clear').exists())
        self.assertEqual(json.loads(self.calls.read_text()), ['orbit-frankenphp.service', 'orbit-process-42.service'])
        self.assertEqual(self.ansible('unchanged')['mailer'], {'status': 'already_smtp'})
        self.assertEqual(json.loads(self.calls.read_text()), ['orbit-frankenphp.service', 'orbit-process-42.service'])
        self.assertEqual((self.root / 'config-clear').read_text(), 'called')
        preserved = b'# MAIL_MAILER is mentioned here\n' + before
        self.envfile.write_bytes(preserved)
        self.assertNotIn('mailer', self.ansible('skipped', probe_preserved=True))
        self.assertEqual(json.loads((self.root / 'preserved-result.json').read_text()),
                         {'status': 'left_as_written', 'lines': [1, 2]})
        self.assertTrue(self.envfile.read_bytes() == preserved)
        self.assertEqual(json.loads(self.calls.read_text()), ['orbit-frankenphp.service', 'orbit-process-42.service'])

    def test_failed_transfer_preserves_file_and_returns_only_a_code(self):
        before = b'MAIL_MAILER=log\n'
        self.envfile.write_bytes(before)
        (self.bin / 'incus').write_text('#!/bin/sh\nexit 1\n')
        result = subprocess.run([sys.executable, '-c', SOURCE.read_text(), 'fixture', 'fixture'],
                                env=self.env, capture_output=True, check=False)
        self.assertEqual(result.returncode, 1)
        self.assertEqual(json.loads(result.stdout), {'error_code': 'env.configuration_invalid'})
        self.assertEqual(result.stderr, b'')
        self.assertTrue(self.envfile.read_bytes() == before)

    def test_interruption_after_staging_cleans_the_fixed_name_even_with_stopped_guest(self):
        before = b'MAIL_MAILER=log\nMAIL_PASSWORD=fixture-canary\n'
        self.envfile.write_bytes(before)
        (self.root / 'interrupt-after-stage').touch()
        result = subprocess.run([sys.executable, '-c', SOURCE.read_text(), 'fixture', 'fixture'],
                                env=self.env, capture_output=True, check=False)
        self.assertEqual(result.returncode, 1)
        self.assertEqual(json.loads(result.stdout), {'error_code': 'env.configuration_invalid'})
        self.assertEqual(result.stderr, b'')
        self.assertTrue((self.root / 'stopped').exists())
        self.assertTrue(self.envfile.read_bytes() == before)
        self.assertEqual(list(self.root.glob('.env.*')), [])
        self.assertEqual(json.loads((self.root / 'staged-names.json').read_text()), ['.env.orbit-mail'])
        actions = json.loads((self.root / 'file-actions.json').read_text())
        self.assertEqual(actions[0]['action'], 'delete')
        self.assertEqual(actions[-1], {'action': 'delete', 'path': 'fixture/srv/workspace/.env.orbit-mail', 'stopped': True})
        self.assertEqual(json.loads(self.calls.read_text()), [])

    def test_next_run_removes_a_leftover_before_even_inspecting_a_stopped_guest(self):
        before = b'MAIL_MAILER=log\n'
        self.envfile.write_bytes(before)
        (self.root / '.env.orbit-mail').write_bytes(b'opaque prior staging')
        (self.root / 'stopped').touch()
        result = subprocess.run([sys.executable, '-c', SOURCE.read_text(), 'fixture', 'fixture'],
                                env=self.env, capture_output=True, check=False)
        self.assertEqual(result.returncode, 1)
        self.assertEqual(json.loads(result.stdout), {'error_code': 'env.configuration_invalid'})
        self.assertEqual(result.stderr, b'')
        self.assertEqual(list(self.root.glob('.env.*')), [])
        self.assertTrue(self.envfile.read_bytes() == before)
        actions = json.loads((self.root / 'file-actions.json').read_text())
        self.assertEqual(actions[0], {'action': 'delete', 'path': 'fixture/srv/workspace/.env.orbit-mail', 'stopped': True})
        self.assertEqual(actions[1]['action'], 'exec')
        (self.root / 'stopped').unlink()
        self.assertEqual(self.run_rule()['status'], 'changed')
        self.assertEqual(list(self.root.glob('.env.*')), [])


if __name__ == '__main__':
    unittest.main()
