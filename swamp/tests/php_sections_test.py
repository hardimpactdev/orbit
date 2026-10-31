"""Public pool section merge preserves unrelated local PHP settings."""

import importlib.util
import unittest
from pathlib import Path

SOURCE = (
    Path(__file__).resolve().parents[1]
    / "extensions/behaviors/operations/routes/publication-command/scripts/php_sections.py"
)
SPEC = importlib.util.spec_from_file_location("php_sections", SOURCE)
MODULE = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(MODULE)


def pool(number, value):
    return f"[orbit-instance-{number}]\nchdir = /srv/app-{number}\npm.max_children = {value}\n"


class PhpSections(unittest.TestCase):
    def test_forward_inverse_and_idempotent_keep_unrelated_current_text(self):
        a, b = pool(1, 5), pool(1, 7)
        old, revised, new = pool(2, 3), pool(2, 11), pool(3, 13)
        current = "; worker tuning\n" + a + revised + new
        desired = MODULE.merge(a + old, b + old, current)
        self.assertEqual(desired, "; worker tuning\n" + b + revised + new)
        self.assertEqual(MODULE.merge(b + old, a + old, desired), current)
        self.assertEqual(MODULE.merge(a + old, b + old, desired), desired)

    def test_unrelated_deletion_stays_deleted(self):
        self.assertEqual(
            MODULE.merge(pool(1, 5) + pool(2, 3), pool(1, 7) + pool(2, 3), pool(1, 5)),
            pool(1, 7),
        )

    def test_owned_conflict_is_refused(self):
        with self.assertRaisesRegex(ValueError, "owned_revision_conflict"):
            MODULE.merge(pool(1, 5), pool(1, 7), pool(1, 9))

    def test_final_pool_withdrawal_discards_header(self):
        self.assertEqual(MODULE.merge(pool(1, 5), "", "; managed\n" + pool(1, 5)), "")

    def test_foreign_duplicate_and_malformed_sections_refuse(self):
        for text in (
            "[foreign]\na=1\n",
            pool(1, 5) * 2,
            "key=value\n",
            "[orbit-instance-1]\nchdir = relative\n",
            "[orbit-instance-1]\nchdir = /a\nchdir = /b\n",
        ):
            with self.assertRaises(ValueError):
                MODULE.split(text)


if __name__ == "__main__":
    unittest.main()
