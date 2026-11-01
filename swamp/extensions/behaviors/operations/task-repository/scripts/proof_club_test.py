"""Test the private file boundary without writing the host's fixed destination."""
import importlib.util
import os
import pwd
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

import yaml

spec = importlib.util.spec_from_file_location('proof_club', Path(__file__).with_name('proof_club.py'))
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)
store = module.store


class PrivateClubFileTest(unittest.TestCase):
    def test_delivery_refuses_an_account_other_than_orbit_before_file_effects(self):
        with patch.object(sys, 'argv', ['proof_club.py', 'root']), patch.object(module.os, 'geteuid', return_value=0), patch.object(module, 'store') as write, patch('builtins.print'):
            self.assertEqual(module.main(), 1)
        write.assert_not_called()

    def test_real_timeout_keeps_exit_and_prints_phase_budget_without_stdin(self):
        workflow = Path(__file__).resolve().parents[5] / 'workflows/workflow-operation-task-provision.yaml'
        steps = yaml.safe_load(workflow.read_text())['jobs'][0]['steps']
        action = next(step for step in steps if step['name'] == 'proof_club')['task']['inputs']['argv']
        self.assertEqual(action[:2], ['sh', '-c'])
        self.assertIn('/usr/bin/timeout 30', action[2])
        # Exercise the actual reporting shell and GNU timeout with a fast test budget.
        prefix = [*action[:2], action[2].replace('/usr/bin/timeout 30', '/usr/bin/timeout 0.01'), action[3]]
        expired = subprocess.run([*prefix, sys.executable, '-c', 'import time; time.sleep(1)'], input='private-test-material', capture_output=True, text=True, check=False, timeout=2)
        self.assertEqual(expired.returncode, 124)
        self.assertEqual(expired.stderr, 'proof_club: 30s budget exceeded\n')
        self.assertEqual(expired.stdout, '')
        refused = subprocess.run([*prefix, 'sh', '-c', 'exit 7'], capture_output=True, text=True, check=False, timeout=2)
        self.assertEqual(refused.returncode, 7)
        self.assertEqual(refused.stderr, '')

    def test_private_replacement_is_user_owned_and_never_follows_previous_file(self):
        user = pwd.getpwuid(os.getuid()).pw_name
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            destination = root / 'private/club-api-key'
            public = root / 'public'
            public.write_bytes(b'untouched')
            destination.parent.mkdir()
            destination.symlink_to(public)
            store(b'throwaway-test-material', user, destination)
            self.assertEqual(public.read_bytes(), b'untouched')
            self.assertFalse(destination.is_symlink())
            self.assertEqual(destination.read_bytes(), b'throwaway-test-material')
            self.assertEqual(destination.stat().st_mode & 0o777, 0o600)
            self.assertEqual(destination.stat().st_uid, os.getuid())
            self.assertEqual(list(destination.parent.iterdir()), [destination])

    def test_invalid_material_creates_no_file_or_directory(self):
        for material in (b'', b'\0', b'x' * 16385):
            with tempfile.TemporaryDirectory() as tmp:
                destination = Path(tmp) / 'private/club-api-key'
                with self.assertRaises(ValueError):
                    store(material, pwd.getpwuid(os.getuid()).pw_name, destination)
                self.assertFalse(destination.parent.exists())


if __name__ == '__main__':
    unittest.main()
