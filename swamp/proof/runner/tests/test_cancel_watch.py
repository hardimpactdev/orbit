"""CancelWatch judges a waiting task cancel from samples: the CLI's exit, then one atomic Gateway snapshot."""
import sys
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
sys.path.insert(0, str(Path(__file__).resolve().parents[4] / 'tools/swamp-proof-guest'))
from clone_lifecycle import CancelWatch, ProofFailure  # noqa: E402

RUNNING, SUCCEEDED, FAILED = {'status': 'running'}, {'status': 'succeeded'}, {'status': 'failed'}


def names(events):
    return [name for name, _data in events]


class CancelWatchTest(unittest.TestCase):
    def test_everything_ends_between_two_samples(self):
        # Sequence (a): one sample sees the copy, then the removal and both Operations end before the next snapshot. The proof accepts it.
        watch = CancelWatch(10)
        self.assertEqual(watch.sample(False, RUNNING, RUNNING, 1, 100), [])
        self.assertEqual(names(watch.sample(False, SUCCEEDED, SUCCEEDED, 0, 300)), ['copy record gone', 'destroy ended', 'cancel ended'])
        self.assertTrue(watch.ended)
        # A poll after the terminal snapshot still sees the CLI running: only then is its exit strictly after the end.
        self.assertEqual(watch.sample(False, SUCCEEDED, SUCCEEDED, 0, 400), [])
        self.assertEqual(names(watch.sample(True, SUCCEEDED, SUCCEEDED, 0, 500)), ['cli task:cancel exited'])
        self.assertEqual(watch.cli_order, 'after the cancel ended')
        self.assertTrue(watch.done)

    def test_exit_just_before_the_end_commits_claims_no_order(self):
        # The poll before the terminal snapshot sees the CLI running; it exits, then the end commits, and the next poll sees the exit.
        # Nothing tells that exit from one after the end, so no order is claimed.
        watch = CancelWatch(10)
        watch.sample(False, SUCCEEDED, SUCCEEDED, 0, 300)
        self.assertEqual(names(watch.sample(True, SUCCEEDED, SUCCEEDED, 0, 500)), ['cli task:cancel exited'])
        self.assertEqual(watch.cli_order, 'not distinguished from the cancel end')
        self.assertTrue(watch.done)

    def test_cli_exit_before_the_cancel_ends_fails(self):
        # Sequence (b): the CLI exits early, and the removal and destroy end during the later reads. The exit is read before the snapshot,
        # and the snapshot still shows the cancel running, so the proof rejects it.
        watch = CancelWatch(10)
        with self.assertRaisesRegex(ProofFailure, 'the CLI exited while cancel 10 was still running'):
            watch.sample(True, SUCCEEDED, RUNNING, 0, 100)
        self.assertNotIn('cli', watch.seen)

    def test_exit_and_end_in_one_sample_claim_no_order(self):
        watch = CancelWatch(10)
        events = watch.sample(True, SUCCEEDED, SUCCEEDED, 0, 100)
        self.assertEqual(names(events), ['copy record gone', 'destroy ended', 'cancel ended', 'cli task:cancel exited'])
        self.assertEqual(watch.cli_order, 'not distinguished from the cancel end')

    def test_cancel_ended_before_its_destroy_or_with_the_copy_fails(self):
        for destroy, runtime in ((RUNNING, 0), (SUCCEEDED, 1), (FAILED, 1)):
            with self.subTest(destroy=destroy['status'], runtime=runtime), self.assertRaisesRegex(ProofFailure, 'cancel 10 ended'):
                CancelWatch(10).sample(False, destroy, SUCCEEDED, runtime, 100)


if __name__ == '__main__':
    unittest.main()
