"""Run the production connection-key rule against a fake `incus` under a temp directory, unprivileged."""
import json
import os
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
SOURCE = ROOT / 'swamp/ansible/instance_env_keys.py'
PLAYBOOK = ROOT / 'swamp/ansible/instance-connection-environment.yml'
WORKFLOW = ROOT / 'swamp/workflows/workflow-operation-instance-connection-environment.yaml'
SECRET = 'pw-Secret$1"x'
LINES = {
    'DB_CONNECTION': 'DB_CONNECTION="mysql"',
    'DB_HOST': 'DB_HOST="10.44.0.7"',
    'DB_PORT': 'DB_PORT="3306"',
    'DB_DATABASE': 'DB_DATABASE="servauto"',
    'DB_USERNAME': 'DB_USERNAME="servauto_app_default"',
    'DB_PASSWORD': 'DB_PASSWORD="pw-Secret\\$1\\"x"',
}


class EnvKeysTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='orbit-env-keys-')
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        (self.root / 'app').mkdir()
        self.envfile = self.root / 'app/.env'
        self.bin = self.root / 'bin'
        self.bin.mkdir()
        self.calls = self.root / 'calls.json'
        self.calls.write_text('[]')
        fake = self.bin / 'incus'
        fake.write_text('''#!/usr/bin/python3
import json, os, subprocess, sys
from pathlib import Path
root = Path(os.environ['KEYS_FIXTURE'])
args = sys.argv[1:]
calls = json.loads((root / 'calls.json').read_text())
calls.append(args)
(root / 'calls.json').write_text(json.dumps(calls))
if args[:3] != ['--project', 'fixture', 'exec'] and args[:4] != ['--project', 'fixture', 'file', 'delete']:
    sys.exit(91)
if 'delete' in args:
    if args[-1] != 'fixture/srv/workspace/app/.env.orbit-keys':
        sys.exit(92)
    try:
        (root / 'app/.env.orbit-keys').unlink()
    except FileNotFoundError:
        sys.stderr.write('Error: file does not exist\\n')
        sys.exit(1)
    sys.exit(0)
args = [x.replace('/srv/workspace/app', str(root / 'app')) for x in args[args.index('--') + 1:]]
sys.exit(subprocess.run(args).returncode)
''')
        fake.chmod(0o755)
        self.env = {**os.environ, 'PATH': str(self.bin) + ':' + os.environ['PATH'], 'KEYS_FIXTURE': str(self.root)}

    def run_rule(self, data=None, lines=None, check=True):
        if data is not None:
            self.envfile.write_bytes(data)
        result = subprocess.run([sys.executable, '-c', SOURCE.read_text(), 'fixture', 'fixture', '/srv/workspace/app/.env'],
                                input=json.dumps({'lines': lines or LINES}).encode(), env=self.env, capture_output=True, check=False)
        self.assertNotIn(SECRET.encode(), result.stdout + result.stderr)
        self.assertNotIn(b'Secret', result.stdout + result.stderr)
        for call in json.loads(self.calls.read_text()):
            self.assertFalse(any('Secret' in argument for argument in call))
        if check:
            self.assertEqual(result.returncode, 0, result.stdout)
        return json.loads(result.stdout)

    def test_hand_set_keys_are_replaced_and_every_other_byte_stays(self):
        before = (b'\xef\xbb\xbf# servauto\nAPP_NAME="Servauto"\nAPP_KEY=base64:abc=\n\nDB_CONNECTION=mysql\nDB_HOST=127.0.0.1\r\n'
                  b'DB_PORT=3308\nDB_DATABASE=servauto\nDB_USERNAME=root\nDB_PASSWORD=old-secret\n# DB_HOST=commented\n'
                  b'MULTI="first\nDB_HOST=inside\nlast"\nVITE_APP_NAME="${APP_NAME}"\nLAST=no-newline')
        result = self.run_rule(before)
        self.assertEqual(result['status'], 'changed')
        self.assertEqual(result['written'], sorted(LINES))
        self.assertEqual(result['added'] + result['kept'] + result['unchanged'], [])
        expected = (b'\xef\xbb\xbf# servauto\nAPP_NAME="Servauto"\nAPP_KEY=base64:abc=\n\nDB_CONNECTION="mysql"\nDB_HOST="10.44.0.7"\r\n'
                    b'DB_PORT="3306"\nDB_DATABASE="servauto"\nDB_USERNAME="servauto_app_default"\nDB_PASSWORD="pw-Secret\\$1\\"x"\n'
                    b'# DB_HOST=commented\nMULTI="first\nDB_HOST=inside\nlast"\nVITE_APP_NAME="${APP_NAME}"\nLAST=no-newline')
        self.assertEqual(self.envfile.read_bytes(), expected)
        stamp = self.envfile.stat().st_mtime_ns
        again = self.run_rule()
        self.assertEqual((again['status'], again['unchanged']), ('unchanged', sorted(LINES)))
        self.assertEqual(self.envfile.stat().st_mtime_ns, stamp)
        self.assertFalse((self.root / 'app/.env.orbit-keys').exists())

    def test_missing_keys_are_added_at_the_end(self):
        result = self.run_rule(b'APP_NAME=x\nDB_HOST=old')
        self.assertEqual(result['written'], ['DB_HOST'])
        self.assertEqual(result['added'], sorted(set(LINES) - {'DB_HOST'}))
        data = self.envfile.read_bytes()
        self.assertTrue(data.startswith(b'APP_NAME=x\nDB_HOST="10.44.0.7"\n'))
        self.assertTrue(data.endswith(b'DB_USERNAME="servauto_app_default"\n'))
        self.assertEqual(data.count(b'DB_CONNECTION='), 1)

    def test_a_reference_value_stays_and_is_named(self):
        before = b'DB_HOST=old\nDB_PASSWORD="${MYSQL_PASSWORD}"\nexport DB_PORT=3308\nDB_DATABASE=a\nDB_DATABASE=b\n'
        result = self.run_rule(before, {k: LINES[k] for k in ['DB_HOST', 'DB_PASSWORD', 'DB_PORT', 'DB_DATABASE']})
        self.assertEqual(result['kept'], ['DB_PASSWORD'])
        self.assertEqual(sorted(result['written']), ['DB_DATABASE', 'DB_HOST', 'DB_PORT'])
        self.assertEqual(self.envfile.read_bytes(),
                         b'DB_HOST="10.44.0.7"\nDB_PASSWORD="${MYSQL_PASSWORD}"\nDB_PORT="3306"\nDB_DATABASE="servauto"\nDB_DATABASE="servauto"\n')

    def test_an_escaped_quote_on_a_continuation_line_does_not_end_the_value(self):
        before = b'KEY="multi\nline with \\"esc\nDB_HOST=inner\nend"\nDB_HOST=old\n'
        result = self.run_rule(before, {'DB_HOST': LINES['DB_HOST']})
        self.assertEqual(result['written'], ['DB_HOST'])
        self.assertEqual(self.envfile.read_bytes(), before.replace(b'DB_HOST=old', b'DB_HOST="10.44.0.7"'))

    def test_an_escaped_dollar_brace_that_orbit_wrote_is_a_value_not_a_reference(self):
        line = 'DB_PASSWORD="a\\${b"'
        self.run_rule(b'DB_PASSWORD=old\n', {'DB_PASSWORD': line})
        again = self.run_rule(lines={'DB_PASSWORD': line})
        self.assertEqual((again['unchanged'], again['kept']), (['DB_PASSWORD'], []))
        self.assertEqual(self.envfile.read_bytes(), line.encode() + b'\n')

    def test_multiline_values_are_grouped_as_phpdotenv_groups_them(self):
        for opener in [b'\xef\xbb\xbfA', b'1A', b'\xc3\xa9A', b'\x0bA', b'export\tA']:
            before = opener + b'="x\nDB_HOST=inner\n"\nDB_HOST=old\n'
            result = self.run_rule(before, {'DB_HOST': LINES['DB_HOST']})
            self.assertEqual(result['written'], ['DB_HOST'], opener)
            self.assertEqual(self.envfile.read_bytes(), before.replace(b'DB_HOST=old', b'DB_HOST="10.44.0.7"'), opener)

    def test_a_key_on_the_first_line_keeps_the_bom_and_quoted_names_count(self):
        result = self.run_rule(b'\xef\xbb\xbfDB_HOST=old\n"DB_PORT"=1\n', {k: LINES[k] for k in ['DB_HOST', 'DB_PORT']})
        self.assertEqual(sorted(result['written']), ['DB_HOST', 'DB_PORT'])
        self.assertEqual(self.envfile.read_bytes(), b'\xef\xbb\xbfDB_HOST="10.44.0.7"\nDB_PORT="3306"\n')

    def test_a_bare_carriage_return_file_is_refused(self):
        before = b'A=1\rDB_HOST=old\r'
        self.assertEqual(self.run_rule(before, check=False)['error_detail'], 'line endings')
        self.assertEqual(self.envfile.read_bytes(), before)

    def test_owner_group_and_mode_survive_the_replace(self):
        self.envfile.write_bytes(b'DB_HOST=old\n')
        self.envfile.chmod(0o640)
        before = self.envfile.stat()
        self.run_rule()
        after = self.envfile.stat()
        self.assertEqual((before.st_uid, before.st_gid, before.st_mode), (after.st_uid, after.st_gid, after.st_mode))
        self.assertNotEqual(before.st_ino, after.st_ino)

    def test_unsafe_files_and_input_write_nothing(self):
        for data, detail in [(b'DB_HOST="open\nnever closes\n', 'line 1'), (b'DB_HOST="a\nb"\n', 'line 1')]:
            result = self.run_rule(data, check=False)
            self.assertEqual(result, {'error_code': 'env.configuration_invalid', 'error_detail': detail})
            self.assertEqual(self.envfile.read_bytes(), data)
        self.envfile.unlink()
        self.assertEqual(self.run_rule(check=False)['error_detail'], 'file absent')
        (self.root / 'app/target').write_bytes(b'DB_HOST=x\n')
        self.envfile.symlink_to(self.root / 'app/target')
        self.assertEqual(self.run_rule(check=False)['error_detail'], 'file link')
        self.assertEqual((self.root / 'app/target').read_bytes(), b'DB_HOST=x\n')
        self.envfile.unlink()
        self.envfile.write_bytes(b'A=1\n')
        for lines in [{'DB_HOST': 'OTHER="x"'}, {'db_host': 'db_host=x'}, {'DB_HOST': 'DB_HOST="x"\nEVIL=1'}, {'DB_HOST': 'DB_HOST="x"\r'}]:
            self.assertEqual(self.run_rule(lines=lines, check=False)['error_detail'], 'input')
        self.assertEqual(self.envfile.read_bytes(), b'A=1\n')

    def test_playbook_keeps_values_out_of_argv_and_logs(self):
        playbook = PLAYBOOK.read_text()
        write = playbook[playbook.index('- name: Write the connection keys'):playbook.index('- name: Require a safe key result')]
        self.assertIn('no_log: true', write)
        self.assertIn("stdin: \"{{ lookup('ansible.builtin.env', 'ORBIT_CONNECTION_ENVIRONMENT') }}\"", write)
        self.assertEqual(playbook.count("'ORBIT_CONNECTION_ENVIRONMENT'"), 1)
        workflow = WORKFLOW.read_text()
        self.assertEqual(workflow.count('vault.get('), 1)
        self.assertIn('ORBIT_CONNECTION_ENVIRONMENT:', workflow)


if __name__ == '__main__':
    unittest.main()
