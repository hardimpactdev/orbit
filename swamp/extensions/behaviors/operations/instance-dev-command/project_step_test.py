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
            self.assertEqual(answer.stdout, '')

    def test_failure_and_undefined_variable_stop_remaining_command(self):
        with tempfile.TemporaryDirectory(dir=os.environ.get('TMPDIR', '/tmp')) as folder:
            path = Path(folder)
            for command in ['false; touch after', 'printf "$ORBIT_UNDEFINED_FIXTURE"; touch after']:
                self.assertNotEqual(self.run_step(path, command).returncode, 0)
                self.assertFalse((path/'after').exists())


if __name__ == '__main__':
    unittest.main()
