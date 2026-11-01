"""Check fixture setup on disposable directories; never change a managed store."""
import json
import os
import pwd
import runpy
import tempfile
import unittest
from pathlib import Path

HERE = Path(__file__).resolve().parent
INSPECT = runpy.run_path(str(HERE / 'vp_bin_775.py'))['inspect_store']


class VpBinFixture(unittest.TestCase):
    def test_changes_only_bin_mode_and_readback_does_not_repair_it(self):
        with tempfile.TemporaryDirectory() as directory:
            store = Path(directory) / 'store'
            (store / 'bin').mkdir(parents=True)
            binary = store / 'bin/vp'
            binary.write_text('#!/bin/sh\nexit 0\n')
            binary.chmod(0o755)
            before = store.stat(), binary.stat()
            account = pwd.getpwuid(os.getuid())
            self.assertEqual(INSPECT(account, [store], True)['bin_mode'], 0o775)
            self.assertEqual((store.stat(), binary.stat()), before)
            (store / 'bin').chmod(0o755)
            self.assertEqual(INSPECT(account, [store])['bin_mode'], 0o755)
            self.assertEqual((store / 'bin').stat().st_mode & 0o777, 0o755)

    def test_missing_store_is_a_precondition_failure_not_an_install(self):
        with tempfile.TemporaryDirectory() as directory:
            absent = Path(directory) / 'absent'
            with self.assertRaisesRegex(AssertionError, 'Topology prerequisite'):
                INSPECT(pwd.getpwuid(os.getuid()), [absent], True)
            self.assertFalse(absent.exists())

    def test_variant_exercises_vp_and_checks_the_original_failure(self):
        fixture = json.loads((HERE / 'dev-vp-bin-775.json').read_text())
        self.assertTrue(fixture['envelope']['deployment']['needs_vp'])
        commands = [entry['argv'] for entry in fixture['prepare']]
        self.assertTrue(any('--command=vp --version > .proof-vp-version' in argv for argv in commands))
        self.assertIn('--vp-bin-775', fixture['parity']['command'])
        self.assertIn('existing-vp-bin-remains-775', fixture['parity']['cases'])


if __name__ == '__main__':
    unittest.main()
