"""Project command shell, cwd and immutable seed contract."""
import hashlib
import importlib.util
import json
import os
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import patch

SCRIPT = Path(__file__).parent / 'scripts/run_project_step.py'
LIBRARY = Path(__file__).parent.parent / 'task-repository/scripts'


class ProjectStep(unittest.TestCase):
    def run_step(self, directory, command):
        return subprocess.run([sys.executable, str(SCRIPT)], input=json.dumps({
            'command':command,'sha256':hashlib.sha256(command.encode()).hexdigest(),
            'directory':str(directory),'instance_id':17,'instance_name':'proof',
            'seed_path':'/captured/default','seed_commit':'a'*40,
        }), text=True, capture_output=True, check=False,
            env=dict(os.environ, PYTHONPATH=str(LIBRARY)))

    def test_bash_features_and_captured_seed_at_checkout_root(self):
        with tempfile.TemporaryDirectory(dir=os.environ.get('TMPDIR', '/tmp')) as folder:
            path = Path(folder)
            answer = self.run_step(path, '[[ "$ORBIT_SEED_PATH" == /captured/default ]] && [[ "$ORBIT_SEED_COMMIT" == ' + 'a'*40 + ' ]] && printf "$PWD" > observed')
            self.assertEqual(answer.returncode, 0)
            self.assertEqual((path/'observed').read_text(), str(path))
            self.assertEqual(json.loads(answer.stdout), {'exitCode': 0, 'stderr': ''})

    def test_failure_and_undefined_variable_stop_remaining_command(self):
        with tempfile.TemporaryDirectory(dir=os.environ.get('TMPDIR', '/tmp')) as folder:
            path = Path(folder)
            for command in ['false; touch after', 'printf "$ORBIT_UNDEFINED_FIXTURE"; touch after']:
                self.assertNotEqual(self.run_step(path, command).returncode, 0)
                self.assertFalse((path/'after').exists())

    def test_failed_command_keeps_only_redacted_stderr(self):
        with tempfile.TemporaryDirectory(dir=os.environ.get('TMPDIR', '/tmp')) as folder:
            path = Path(folder)
            (path / '.env').write_text('APP_KEY="private-output-sentinel"\n')
            command = 'printf "%05000d" 0; printf "private-output-sentinel public-tail"; printf "private-output-sentinel failure-tail" >&2; exit 7'
            result = self.run_step(path, command)
            self.assertEqual(result.returncode, 7)
            output = json.loads(result.stdout)
            self.assertEqual(output['exitCode'], 7)
            self.assertEqual(set(output), {'exitCode', 'stderr'})
            self.assertLessEqual(len(output['stderr'].encode()), 4096)
            self.assertEqual(output['stderr'], '[REDACTED] failure-tail')
            self.assertNotIn('public-tail', result.stdout)
            self.assertNotIn('private-output-sentinel', result.stdout)

    def test_stdout_is_never_kept_on_success_or_failure(self):
        with tempfile.TemporaryDirectory() as folder:
            for code in [0, 7]:
                result = self.run_step(Path(folder), f'printf unclassified-private-stdout; exit {code}')
                self.assertEqual(result.returncode, code)
                self.assertEqual(json.loads(result.stdout), {'exitCode': code, 'stderr': ''})

    def test_redaction_cannot_reveal_a_secret_split_at_the_old_capture_boundary(self):
        secret = 'boundary-secret-' + ''.join(f'{n:02x}' for n in range(249))
        with tempfile.TemporaryDirectory() as folder:
            path = Path(folder)
            (path / '.env').write_text(f'APP_KEY="{secret}"\n')
            # The old 4096+max-secret window starts inside the first secret.
            # Replacing the later complete secret then shrinks that window enough
            # for the unmatched first suffix to enter its final 4096-byte slice.
            for offset in [1, 128, 300, 511]:
                filler = 4096 - len(secret) + offset
                command = f'printf "%s" "$APP_KEY" >&2; printf "%0{filler}d" 0 >&2; printf "%s" "$APP_KEY" >&2; exit 7'
                result = self.run_step(path, command)
                self.assertEqual(result.returncode, 7)
                output = json.loads(result.stdout)
                tail = output['stderr']
                self.assertLessEqual(len(tail.encode()), 4096)
                for start in range(len(secret) - 7):
                    self.assertNotIn(secret[start:start + 8], tail, f'capture offset {offset}')

    def test_partial_known_secret_lines_are_dropped(self):
        with tempfile.TemporaryDirectory() as folder:
            path = Path(folder)
            secret = 'unique-private-fragment-0123456789'
            (path / '.env').write_text(f'APP_KEY="{secret}"\n')
            result = self.run_step(path, 'printf "safe diagnostic\\n%.12s\\n" "$APP_KEY" >&2; exit 7')
            self.assertEqual(result.returncode, 7)
            output = json.loads(result.stdout)
            self.assertNotIn(secret[:12], output['stderr'])
            self.assertIn('safe diagnostic', output['stderr'])

    def test_dropping_many_fragment_lines_cannot_expand_the_four_kib_output(self):
        with tempfile.TemporaryDirectory() as folder:
            path = Path(folder)
            (path / '.env').write_text('APP_KEY="unique-private-fragment-0123456789"\n')
            result = self.run_step(path, 'for ((i=0; i<512; i++)); do printf "unique-p\\n" >&2; done; exit 7')
            output = json.loads(result.stdout)
            self.assertEqual(output, {'exitCode': 7, 'stderr': ''})

    def test_capture_cap_never_publishes_its_partial_first_line(self):
        with tempfile.TemporaryDirectory() as folder:
            path = Path(folder)
            secret = 'capture-boundary-' + '0123456789abcdef' * 32
            (path / '.env').write_text(f'APP_KEY="{secret}"\n')
            filler = 1048576 - 2 * len(secret) + 100
            command = f'printf "%s" "$APP_KEY" >&2; printf "%0{filler}d" 0 >&2; printf "%s\\nsafe diagnostic\\n" "$APP_KEY" >&2; exit 7'
            result = self.run_step(path, command)
            self.assertEqual(result.returncode, 7)
            self.assertEqual(json.loads(result.stdout), {'exitCode': 7, 'stderr': 'safe diagnostic\n'})

    def test_second_redaction_of_short_values_stays_inside_the_final_byte_bound(self):
        with tempfile.TemporaryDirectory() as folder:
            path = Path(folder)
            (path / '.env').write_text('APP_KEY="R"\n')
            result = self.run_step(path, 'for ((i=0; i<5000; i++)); do printf "R\\n" >&2; done; exit 7')
            self.assertEqual(result.returncode, 7)
            self.assertLessEqual(len(json.loads(result.stdout)['stderr'].encode()), 4096)

    def test_invalid_utf8_never_joins_fragments_into_a_secret_after_redaction(self):
        with tempfile.TemporaryDirectory() as folder:
            path = Path(folder)
            secret = 'unique-private-fragment-0123456789'
            (path / '.env').write_text(f'APP_KEY="{secret}"\n')
            for key in [secret, 'abc\ufffddef']:
                with self.subTest(key=key):
                    (path / '.env').write_text(f'APP_KEY="{key}"\n')
                    command = 'python3 -c \'import os; value=os.environ["APP_KEY"].encode(); os.write(2,b"\\xff".join(value[n:n+7] for n in range(0,len(value),7)))\'; exit 7'
                    if key == 'abc\ufffddef':
                        command = 'python3 -c \'import os; os.write(2,b"abc\\xffdef")\'; exit 7'
                    result = self.run_step(path, command)
                    self.assertEqual(result.returncode, 7)
                    output = json.loads(result.stdout)
                    self.assertNotIn(key, output['stderr'])
                    self.assertLessEqual(len(output['stderr'].encode()), 4096)

    def test_redacts_escaped_terminal_quotes_and_truncates_unicode_by_bytes(self):
        with tempfile.TemporaryDirectory(dir=os.environ.get('TMPDIR', '/tmp')) as folder:
            path = Path(folder)
            (path / '.env').write_text('APP_KEY="private-quote-sentinel\\\""\n')
            result = self.run_step(path, 'printf \'private-quote-sentinel"\' >&2; python3 -c \'import sys; print("é" * 5000, file=sys.stderr)\'')
            output = json.loads(result.stdout)
            self.assertNotIn('private-quote-sentinel', result.stdout)
            self.assertLessEqual(len(output['stderr'].encode()), 4096)
            result = self.run_step(path, 'printf \'private-quote-sentinel"\' >&2')
            self.assertEqual(json.loads(result.stdout)['stderr'], '[REDACTED]')

    def test_owned_dotenv_and_managed_vp_are_the_command_environment(self):
        sys.path.insert(0, str(LIBRARY))
        spec = importlib.util.spec_from_file_location('project_step', SCRIPT)
        runner = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(runner)
        with tempfile.TemporaryDirectory() as folder:
            root = Path(folder)
            home = root / 'account'
            binary = home / '.vite-plus/bin/vp'
            binary.parent.mkdir(parents=True)
            binary.write_text('#!/bin/sh\nprintf runtime-ok')
            binary.chmod(0o755)
            application = root / 'apps/site'
            application.mkdir(parents=True)
            (application / '.env').write_text('APP_KEY="private-runtime-sentinel"\n')
            selected = root / 'selected-vp'
            (selected / 'bin').mkdir(parents=True)
            selected_binary = selected / 'bin/vp'
            selected_binary.write_text('#!/bin/sh\nprintf selected-runtime-ok')
            selected_binary.chmod(0o755)
            account = SimpleNamespace(pw_dir=str(home), pw_name='fixture', pw_uid=os.geteuid(), pw_gid=os.getegid())
            command = 'test "$APP_KEY" = private-runtime-sentinel; test "$PWD" = '+str(root)+'; vp > observed-vp; cat apps/site/.env >&2'
            value = {'command': command, 'sha256': hashlib.sha256(command.encode()).hexdigest(),
                     'directory': str(root), 'environment_directory': str(application), 'vp_home': str(selected), 'instance_id': 17, 'instance_name': 'proof', 'seed_path': '', 'seed_commit': ''}
            exists = Path.exists
            with patch.object(runner.pwd, 'getpwuid', return_value=account), patch.object(
                    Path, 'exists', lambda p: False if str(p) == '/opt/orbit/vite-plus/bin/vp' else exists(p)):
                result = runner.execute_command(value)
            self.assertEqual(result['exitCode'], 0)
            self.assertEqual((root / 'observed-vp').read_text(), 'selected-runtime-ok')
            self.assertEqual(result['stderr'], 'APP_KEY="[REDACTED]"\n')
            self.assertNotIn('stdout', result)
            self.assertNotIn('private-runtime-sentinel', json.dumps(result))


if __name__ == '__main__':
    unittest.main()
