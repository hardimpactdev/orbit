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
            self.assertEqual(json.loads(answer.stdout), {'exitCode': 0, 'stdout': '', 'stderr': ''})

    def test_failure_and_undefined_variable_stop_remaining_command(self):
        with tempfile.TemporaryDirectory(dir=os.environ.get('TMPDIR', '/tmp')) as folder:
            path = Path(folder)
            for command in ['false; touch after', 'printf "$ORBIT_UNDEFINED_FIXTURE"; touch after']:
                self.assertNotEqual(self.run_step(path, command).returncode, 0)
                self.assertFalse((path/'after').exists())

    def test_failed_command_retains_both_bounded_redacted_tails(self):
        with tempfile.TemporaryDirectory(dir=os.environ.get('TMPDIR', '/tmp')) as folder:
            path = Path(folder)
            (path / '.env').write_text('APP_KEY="private-output-sentinel"\n')
            command = 'printf "%05000d" 0; printf "private-output-sentinel public-tail"; printf "private-output-sentinel failure-tail" >&2; exit 7'
            result = self.run_step(path, command)
            self.assertEqual(result.returncode, 7)
            output = json.loads(result.stdout)
            self.assertEqual(output['exitCode'], 7)
            self.assertLessEqual(len(output['stdout'].encode()), 4096)
            self.assertLessEqual(len(output['stderr'].encode()), 4096)
            self.assertTrue(output['stdout'].endswith('[REDACTED] public-tail'))
            self.assertEqual(output['stderr'], '[REDACTED] failure-tail')
            self.assertNotIn('private-output-sentinel', result.stdout)

    def test_redacts_escaped_terminal_quotes_and_truncates_unicode_by_bytes(self):
        with tempfile.TemporaryDirectory(dir=os.environ.get('TMPDIR', '/tmp')) as folder:
            path = Path(folder)
            (path / '.env').write_text('APP_KEY="private-quote-sentinel\\\""\n')
            result = self.run_step(path, 'printf \'private-quote-sentinel"\'; python3 -c \'print("é" * 5000)\'')
            output = json.loads(result.stdout)
            self.assertNotIn('private-quote-sentinel', result.stdout)
            self.assertLessEqual(len(output['stdout'].encode()), 4096)
            result = self.run_step(path, 'printf \'private-quote-sentinel"\'')
            self.assertEqual(json.loads(result.stdout)['stdout'], '[REDACTED]')

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
            command = 'test "$APP_KEY" = private-runtime-sentinel; test "$PWD" = '+str(root)+'; vp; cat apps/site/.env'
            value = {'command': command, 'sha256': hashlib.sha256(command.encode()).hexdigest(),
                     'directory': str(root), 'environment_directory': str(application), 'vp_home': str(selected), 'instance_id': 17, 'instance_name': 'proof', 'seed_path': '', 'seed_commit': ''}
            exists = Path.exists
            with patch.object(runner.pwd, 'getpwuid', return_value=account), patch.object(
                    Path, 'exists', lambda p: False if str(p) == '/opt/orbit/vite-plus/bin/vp' else exists(p)):
                result = runner.execute_command(value)
            self.assertEqual(result['exitCode'], 0)
            self.assertEqual(result['stdout'], 'selected-runtime-okAPP_KEY="[REDACTED]"\n')
            self.assertNotIn('private-runtime-sentinel', json.dumps(result))


if __name__ == '__main__':
    unittest.main()
