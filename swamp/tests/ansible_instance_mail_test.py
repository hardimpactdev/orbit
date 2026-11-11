"""Current-file mail editing and its reusable Ansible phase, with no live Incus or mail."""
import json
import os
import shutil
import subprocess
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
POLICY = ROOT / 'apps/gateway/app/Domain/Instances/Environment/DevelopmentMailEnvironment.php'
EDITOR = ROOT / 'swamp/ansible/instance_mail.php'



class MailFile(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='orbit-mail-test-')
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.env = self.root / '.env'
        self.plan = {'application': str(self.root), 'backup': str(self.root / 'state/environment.backup'),
                     'endpoint': {'host': '10.253.0.1', 'port': 1025}, 'owned': [], 'units': [], 'laravel': False}
        self.code = POLICY.read_text().replace('<?php', '', 1) + '\n' + EDITOR.read_text().replace('<?php', '', 1)

    def apply(self, success=True):
        result = subprocess.run(['php', '-r', self.code], input=json.dumps(self.plan), text=True, capture_output=True, check=False)
        self.assertEqual(result.returncode, 0 if success else 1, result.stderr)
        self.assertEqual(result.stderr, '')
        return json.loads(result.stdout)

    def test_only_mail_value_spans_change_and_nonmail_bytes_survive(self):
        for ending in [b'\n', b'\r\n']:
            for final in [True, False]:
                with self.subTest(ending=ending, final=final):
                    lines = [b'# deliberately odd order', b'DB_HOST="hand-changed.invalid"', b'',
                             b'MAIL_PORT = 2525 # keep this comment', b'APP_URL=\'https://hand.test\'',
                             b'VITE_APP_NAME="${APP_NAME}"', b'  export MAIL_HOST = "localhost" # old',
                             b'MAIL_MAILER=log', b'MAIL_USERNAME="stale"', b'MAIL_PASSWORD=secret-fixture',
                             b'MAIL_ENCRYPTION=tls', b'MAIL_SCHEME=smtps', b'# last comment']
                    before = ending.join(lines) + (ending if final else b'')
                    self.env.write_bytes(before)
                    self.env.chmod(0o640)
                    result = self.apply()
                    expected = before.replace(b'MAIL_PORT = 2525', b'MAIL_PORT = "1025"').replace(
                        b'MAIL_HOST = "localhost"', b'MAIL_HOST = "10.253.0.1"').replace(b'MAIL_MAILER=log', b'MAIL_MAILER="smtp"')
                    for old, new in [(b'MAIL_USERNAME="stale"', b'MAIL_USERNAME=""'), (b'MAIL_PASSWORD=secret-fixture', b'MAIL_PASSWORD=""'),
                                     (b'MAIL_ENCRYPTION=tls', b'MAIL_ENCRYPTION=""'), (b'MAIL_SCHEME=smtps', b'MAIL_SCHEME=""')]:
                        expected = expected.replace(old, new)
                    self.assertEqual(self.env.read_bytes(), expected)
                    self.assertEqual(result['changed_key_count'], 7)
                    backup = Path(self.plan['backup'])
                    self.assertEqual(backup.read_bytes(), before)
                    self.assertEqual(backup.stat().st_mode & 0o777, 0o600)
                    self.assertEqual(self.env.stat().st_mode & 0o777, 0o640)
                    self.assertEqual(backup.stat().st_uid, self.env.stat().st_uid)
                    self.plan['owned'] = result['mail_keys_taken_over']
                    stamp, backup_stamp = self.env.stat().st_mtime_ns, backup.stat().st_mtime_ns
                    self.assertEqual(self.apply()['changed_key_count'], 0)
                    self.assertEqual(self.env.read_bytes(), expected)
                    self.assertEqual(self.env.stat().st_mtime_ns, stamp)
                    self.assertEqual(backup.stat().st_mtime_ns, backup_stamp)

    def test_append_block_keeps_every_original_byte_and_one_backup(self):
        before = b'# comment\r\nAPP_URL=hand-edited\r\nDB_HOST=changed-with-no-final-newline'
        self.env.write_bytes(before)
        result = self.apply()
        self.assertTrue(self.env.read_bytes().startswith(before + b'\r\nMAIL_MAILER='))
        self.assertNotIn(b'\n', self.env.read_bytes().replace(b'\r\n', b''))
        self.assertEqual(result['changed_key_count'], 7)
        self.plan['owned'] = result['mail_keys_taken_over']
        self.assertEqual(self.apply()['changed_key_count'], 0)
        self.assertEqual(len(list((self.root / 'state').iterdir())), 1)
        # A later changed run replaces that one backup.
        changed = self.env.read_bytes().replace(b'MAIL_PORT="1025"', b'MAIL_PORT=2525').replace(b'MAIL_HOST="10.253.0.1"', b'MAIL_HOST=localhost')
        self.env.write_bytes(changed)
        self.apply()
        self.assertEqual(Path(self.plan['backup']).read_bytes(), changed)

    def test_own_choices_are_byte_identical_and_make_no_backup(self):
        for choice in ['MAIL_HOST=smtp.real.test\nMAIL_MAILER=log', 'MAIL_MAILER=ses\nMAIL_HOST=localhost',
                       'MAIL_HOST="${SMTP_HOST}"', 'MAIL_URL="smtp://secret-fixture@provider.test"',
                       'MAIL_HOST=smtp.real.test\nMAIL_HOST=localhost\nMAIL_PORT=1025']:
            with self.subTest(choice=choice):
                before = ('# own\nDB_HOST=hand\n' + choice).encode()
                self.env.write_bytes(before)
                result = self.apply()
                self.assertEqual(result['reason'], 'own_mail_settings_kept')
                self.assertEqual(result['mail_keys_taken_over'], [])
                self.assertEqual(result['changed_key_count'], 0)
                self.assertEqual(self.env.read_bytes(), before)
                self.assertFalse(Path(self.plan['backup']).exists())

    def test_multiline_nonmail_and_failed_render_preserve_prior_content(self):
        before = b'OTHER="first\nMAIL_HOST=real-inside-a-value.test\nlast"\nMAIL_HOST=localhost\nMAIL_PORT=1025\n'
        self.env.write_bytes(before)
        self.apply()
        self.assertTrue(self.env.read_bytes().startswith(before[:before.index(b'\nMAIL_HOST=localhost')]))
        backup = Path(self.plan['backup']).read_bytes()
        broken = b'# keep\nMAIL_HOST="unterminated\nDB_HOST=hand'
        self.env.write_bytes(broken)
        self.assertEqual(self.apply(success=False), {'error_code': 'env.configuration_invalid'})
        self.assertEqual(self.env.read_bytes(), broken)
        self.assertEqual(Path(self.plan['backup']).read_bytes(), backup)

    @unittest.skipUnless(shutil.which('ansible-playbook'), 'pinned Ansible is required')
    def test_reusable_phase_changes_only_its_running_units_and_repeat_restarts_nothing(self):
        self.env.write_bytes(b'# hand edited\nDB_HOST=custom\nMAIL_HOST=localhost\nMAIL_PORT=2525\n')
        self.plan.update({'project': 'fixture-project', 'name': 'fixture-container', 'units': ['orbit-php.service', 'orbit-worker.service', 'orbit-stopped.service'], 'laravel': True})
        binary = self.root / 'bin'
        binary.mkdir()
        fake = binary / 'incus'
        fake.write_text("""#!/usr/bin/env python3
import json, os, sys, subprocess
from pathlib import Path
args = sys.argv[1:]
root = Path(os.environ['MAIL_FIXTURE'])
if args[0] == 'query':
    print(json.dumps({'status': 'Stopped' if os.environ.get('MAIL_FIXTURE_STOPPED') else 'Running'}))
    sys.exit(0)
assert args[0] == 'exec'
cmd = args[args.index('--') + 1:]
if cmd[0] == 'php':
    result = subprocess.run(cmd, input=sys.stdin.read(), capture_output=True, text=True, check=False)
    (root / 'php-error').write_text(result.stderr)
    sys.stdout.write(result.stdout)
    sys.exit(result.returncode)
if cmd[0] == 'runuser':
    with (root / 'calls').open('a') as f: f.write('config-clear\\n')
    sys.exit(0)
assert cmd[0] == 'systemctl'
action, unit = cmd[1], cmd[-1]
assert unit in ['orbit-php.service', 'orbit-worker.service', 'orbit-stopped.service']
with (root / 'calls').open('a') as f: f.write(action + ':' + unit + '\\n')
if action == 'is-active':
    print('inactive' if 'stopped' in unit else 'active')
    sys.exit(3 if 'stopped' in unit else 0)
if action == 'show': print('ActiveState=active\\nJob=')
""")
        fake.chmod(0o755)
        shutil.copy(EDITOR, self.root / 'instance_mail.php')
        play = self.root / 'phase.json'
        result = self.root / 'result.json'
        # Use the real task file and real Ansible/Jinja, only the privileged Incus boundary is fake.
        def run(stopped=False):
            play.write_text(json.dumps([{'hosts': 'localhost', 'connection': 'local', 'gather_facts': False,
                'vars': {'mail': self.plan, 'ansible_python_interpreter': shutil.which('python3'),
                         'playbook_dir': str(ROOT / 'swamp/ansible')},
                'tasks': [{'ansible.builtin.include_tasks': str(ROOT / 'swamp/ansible/tasks/instance-mail.yml')},
                          {'ansible.builtin.copy': {'content': '{{ mail_result | to_json }}', 'dest': str(result), 'mode': '0600'}, 'changed_when': False}]}]))
            env = {**os.environ, 'PATH': str(binary) + os.pathsep + os.environ['PATH'], 'MAIL_FIXTURE': str(self.root),
                   'ORBIT_MAIL_RESOLVER': POLICY.read_text(), 'ANSIBLE_COLLECTIONS_PATH': str(self.root / 'empty-collections'),
                   'ANSIBLE_STDOUT_CALLBACK': 'default'}
            if stopped:
                env['MAIL_FIXTURE_STOPPED'] = '1'
            return subprocess.run(['ansible-playbook', '-i', 'localhost,', str(play)], env=env, text=True, capture_output=True, timeout=45, check=False)
        first = run()
        self.assertEqual(first.returncode, 0, first.stdout + first.stderr + (self.root / 'php-error').read_text())
        calls = (self.root / 'calls').read_text()
        self.assertEqual(calls.count('config-clear'), 1)
        self.assertIn('restart:orbit-php.service', calls)
        self.assertIn('restart:orbit-worker.service', calls)
        self.assertNotIn('restart:orbit-stopped.service', calls)
        self.plan['owned'] = json.loads(result.read_text())['mail_keys_taken_over']
        before, backup = self.env.read_bytes(), Path(self.plan['backup']).read_bytes()
        second = run()
        self.assertEqual(second.returncode, 0, second.stdout + second.stderr)
        self.assertIn('changed=0', second.stdout)
        self.assertEqual((self.root / 'calls').read_text(), calls)
        self.assertEqual(self.env.read_bytes(), before)
        self.assertEqual(Path(self.plan['backup']).read_bytes(), backup)
        stopped = run(stopped=True)
        self.assertNotEqual(stopped.returncode, 0)
        self.assertIn('process.target_inactive', stopped.stdout + stopped.stderr)
        self.assertEqual((self.root / 'calls').read_text(), calls)
        self.assertEqual(self.env.read_bytes(), before)


if __name__ == '__main__':
    unittest.main()
