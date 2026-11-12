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
    (root / '.env.orbit-mail').unlink(missing_ok=True)
    sys.exit(0)
actions.append({'action': 'exec', 'stopped': (root / 'stopped').exists()})
(root / 'file-actions.json').write_text(json.dumps(actions))
if (root / 'stopped').exists():
    sys.exit(93)
if 'exec' not in args:
    sys.exit(91)
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
        chown = self.bin / 'chown'
        chown.write_text('''#!/usr/bin/python3
import json, os, signal, sys
from pathlib import Path
root = Path(os.environ['MAIL_FIXTURE'])
if (root / 'interrupt-after-stage').exists():
    (root / 'staged-names.json').write_text(json.dumps([Path(sys.argv[-1]).name]))
    (root / 'stopped').touch()
    os.kill(os.getppid(), signal.SIGKILL)
    sys.exit(1)
os.execv('/usr/bin/chown', ['chown', *sys.argv[1:]])
''')
        chown.chmod(0o755)
        self.env = {**os.environ, 'PATH': str(self.bin) + ':' + os.environ['PATH'], 'MAIL_FIXTURE': str(self.root),
                    'ANSIBLE_NOCOLOR': '1', 'ANSIBLE_STDOUT_CALLBACK': 'default', 'ANSIBLE_COLLECTIONS_PATH': str(self.root / 'collections'),
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

    def ansible(self, path_status='applied', php=True, failure=False, probe_preserved=False, migrate=False):
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
        wrapper['vars']['intent'] = {'migrate': {}} if migrate else {}
        if probe_preserved:
            wrapper['post_tasks'] = [
                {'name': 'Probe the real preserved-line rule independently of the skipped path',
                 'ansible.builtin.include_tasks': 'tasks/instance-mail-line.yml'},
                {'name': 'Keep only the public preserved-line receipt', 'ansible.builtin.copy':
                 {'content': '{{ mailer_result | to_json }}', 'dest': str(self.root / 'preserved-result.json'), 'mode': '0600'},
                 'changed_when': False},
            ]
        play = self.root / 'play.yml'
        play.write_text(yaml.safe_dump([wrapper], sort_keys=False))
        executable = shutil.which('ansible-playbook')
        self.assertIsNotNone(executable, 'Pinned Ansible must be on PATH')
        result = subprocess.run([executable, '-i', 'fixture_host,', '--tags', 'mail', str(play)], env=self.env, capture_output=True, check=False)
        self.assertEqual(result.returncode == 0, not failure, result.stdout.decode() + result.stderr.decode())
        self.assertNotIn(b'MAIL_PASSWORD=', result.stdout + result.stderr)
        return json.loads((self.root / ('mail-result.json' if migrate else 'result.json')).read_text())

    def test_real_ansible_step_refreshes_only_changed_file_and_skips_conflict(self):
        before = b'MAIL_MAILER=log\nMAIL_PASSWORD=fixture-canary\n'
        source = self.root / 'copied-source'
        source.write_bytes(before)
        self.envfile.write_bytes(b'MAIL_MAILER=ses\n')
        shutil.copyfile(source, self.envfile)
        (self.root / 'app').mkdir()
        (self.root / 'app/artisan').touch()
        php = self.root / 'php'
        php.write_text('#!/bin/sh\nprintf called >> "' + str(self.root / 'config-clear') + '"\nexit 1\n')
        php.chmod(0o755)
        self.assertEqual(self.ansible(migrate=True)['mailer'], {'status': 'changed', 'lines': [1]})
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

    def test_migrate_orders_advisory_mail_after_copy_and_before_application_start(self):
        import yaml
        workflow = yaml.safe_load((ROOT / 'swamp/workflows/workflow-operation-instance-migrate.yaml').read_text())
        steps = {step['name']: step for step in workflow['jobs'][0]['steps']}
        mail = steps['Apply development mail']
        self.assertTrue(mail['allowFailure'])
        self.assertEqual(mail['dependsOn'], [{'step': 'files', 'condition': {'type': 'succeeded'}}])
        for name in ['agent', 'container-processes']:
            self.assertIn({'step': 'Apply development mail', 'condition': {'type': 'always'}}, steps[name]['dependsOn'])


if __name__ == '__main__':
    unittest.main()
