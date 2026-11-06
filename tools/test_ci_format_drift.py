"""The Project check fails on formatter drift, so main stays formatter-clean."""
import runpy
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
review = runpy.run_path(str(ROOT / 'bin/review-check'))


class FormatDriftTest(unittest.TestCase):
    def test_a_clean_commit_that_the_formatters_change_is_drift(self):
        self.assertTrue(review['format_drift'](True, True))

    def test_a_clean_commit_that_the_formatters_leave_alone_is_not_drift(self):
        self.assertFalse(review['format_drift'](True, False))

    def test_a_formatter_edit_of_work_in_progress_is_not_drift(self):
        self.assertFalse(review['format_drift'](False, True))
        self.assertFalse(review['format_drift'](False, False))


if __name__ == '__main__':
    unittest.main()
