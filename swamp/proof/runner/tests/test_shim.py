"""The ufw shim as a shell script, with a stub for the real ufw."""
import os
import shutil
import subprocess
import tempfile
import unittest
from pathlib import Path

SHIM = Path(__file__).resolve().parents[4] / 'tools/swamp-proof-guest/ufw-shim.sh'


class ShimTest(unittest.TestCase):
    def setUp(self):
        self.directory = Path(tempfile.mkdtemp())
        self.addCleanup(shutil.rmtree, self.directory)
        self.state = self.directory / 'state'
        self.state.mkdir()
        self.log = self.directory / 'calls'
        real = self.directory / 'ufw.real'
        real.write_text(f'#!/bin/sh\necho "$@" >> {self.log}\n')
        real.chmod(0o755)
        self.env = {**os.environ, 'PROOF_UFW_DIR': str(self.state), 'PROOF_UFW_REAL': str(real)}

    def ufw(self, *words):
        return subprocess.run(['sh', str(SHIM), *words], env=self.env, capture_output=True, text=True, timeout=20)

    def fault(self, word, after, seen=0):
        (self.state / 'fault').write_text(f'WORD={word}\nAFTER={after}\nSEEN={seen}\n')

    def calls(self):
        return self.log.read_text().splitlines() if self.log.exists() else []

    def test_without_state_every_call_goes_to_the_real_ufw(self):
        self.assertEqual(self.ufw('insert', '2', 'allow', 'port', '80').returncode, 0)
        self.assertEqual(self.calls(), ['insert 2 allow port 80'])

    def test_writes_fail_after_the_counted_call_with_the_word(self):
        self.fault('delete', 1)
        self.assertEqual(self.ufw('allow', '80').returncode, 0)             # no word: not counted
        self.assertEqual(self.ufw('--force', 'delete', '3').returncode, 0)  # the counted call goes through
        failed = self.ufw('insert', '2', 'allow', '80')
        self.assertEqual((failed.returncode, failed.stderr.strip()), (1, 'ufw: injected fault'))
        self.assertEqual(self.calls(), ['allow 80', '--force delete 3'])

    def test_reads_pass_even_after_the_fault_tripped(self):
        self.fault('allow', 1, seen=1)
        for words in (('status', 'numbered'), ('status', 'verbose'), ('show', 'added'), ('--version',)):
            self.assertEqual(self.ufw(*words).returncode, 0)
        self.assertEqual(self.ufw('allow', '80').returncode, 1)

    def test_the_second_call_with_the_word_trips_a_count_of_two(self):
        self.fault('allow', 2)
        self.assertEqual([self.ufw('allow', str(port)).returncode for port in (1, 2, 3)], [0, 0, 1])

    @unittest.skipUnless(shutil.which('flock'), 'flock is not installed here')
    def test_a_write_waits_for_the_lock_of_busy_node_and_a_read_does_not(self):
        stall = self.state / 'stall'
        stall.touch()
        holder = subprocess.Popen(['flock', '-x', str(stall), 'sleep', '3'])
        self.addCleanup(holder.wait)
        self.assertEqual(self.ufw('status', 'numbered').returncode, 0)
        self.assertEqual(self.calls(), ['status numbered'])
        write = subprocess.Popen(['sh', str(SHIM), 'allow', '80'], env=self.env)
        with self.assertRaises(subprocess.TimeoutExpired):
            write.wait(1)
        self.assertEqual(write.wait(10), 0)
        self.assertEqual(self.calls(), ['status numbered', 'allow 80'])


if __name__ == '__main__':
    unittest.main()
