"""Project command shell, cwd and immutable seed contract."""
import hashlib
import json
import os
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

SCRIPT = Path(__file__).parent / 'scripts/run_project_step.py'


class ProjectStep(unittest.TestCase):
    def run_step(self, directory, command):
        return subprocess.run([sys.executable, str(SCRIPT)], input=json.dumps({
            'command':command,'sha256':hashlib.sha256(command.encode()).hexdigest(),
            'directory':str(directory),'instance_id':17,'instance_name':'proof',
            'seed_path':'/captured/default','seed_commit':'a'*40,
        }), text=True, capture_output=True, check=False)

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

    def test_failed_command_keeps_only_a_bounded_redacted_stderr_tail(self):
        with tempfile.TemporaryDirectory(dir=os.environ.get('TMPDIR', '/tmp')) as folder:
            path = Path(folder)
            (path / '.env').write_text('APP_KEY="private-output-sentinel"\n')
            command = 'printf "private-output-sentinel public-tail"; printf "private-output-sentinel failure-tail" >&2; exit 7'
            result = self.run_step(path, command)
            self.assertEqual(result.returncode, 7)
            output = json.loads(result.stdout)
            self.assertEqual(set(output), {'exitCode', 'stderr'})
            self.assertEqual(output['exitCode'], 7)
            self.assertEqual(output['stderr'], '[REDACTED] failure-tail')
            self.assertNotIn('public-tail', result.stdout)
            self.assertNotIn('private-output-sentinel', result.stdout)

    def test_secret_straddling_the_tail_boundary_never_appears_even_partly(self):
        secret = 'straddle-secret-0123456789abcdef'
        with tempfile.TemporaryDirectory(dir=os.environ.get('TMPDIR', '/tmp')) as folder:
            path = Path(folder)
            (path / '.env').write_text(f'APP_KEY="{secret}"\n')
            # Put the secret where a plain 4096-byte cut lands inside it, after redaction shortens the buffer.
            for offset in range(len(secret) + 12):
                command = (f'printf "%s" "{secret}" >&2; printf "%0{4096 - offset}d" 0 >&2; '
                           f'printf "\\n{secret}\\n" >&2; exit 3')
                output = json.loads(self.run_step(path, command).stdout)
                tail = output['stderr']
                self.assertLessEqual(len(tail.encode()), 4096)
                for start in range(len(secret) - 8 + 1):
                    self.assertNotIn(secret[start:start + 8], tail, f'offset {offset}')

    def test_many_fragment_lines_are_dropped_and_the_tail_stays_bounded(self):
        secret = 'unique-private-fragment-0123456789'
        with tempfile.TemporaryDirectory(dir=os.environ.get('TMPDIR', '/tmp')) as folder:
            path = Path(folder)
            (path / '.env').write_text(f'APP_KEY="{secret}"\n')
            command = f'for i in $(seq 512); do printf "x{secret[3:11]}\\n" >&2; done; printf "last-public-line\\n" >&2; exit 7'
            output = json.loads(self.run_step(path, command).stdout)
            self.assertLessEqual(len(output['stderr'].encode()), 4096)
            self.assertNotIn(secret[3:11], output['stderr'])
            self.assertIn('last-public-line', output['stderr'])

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

if __name__ == '__main__':
    unittest.main()
