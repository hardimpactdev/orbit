"""Publication revision regressions for the canonical Caddy section merge."""

import importlib.util
import pathlib
import unittest

SOURCE = (
    pathlib.Path(__file__).resolve().parents[1]
    / "extensions/behaviors/operations/routes/publication-command/scripts/caddy_sections.py"
)
SPEC = importlib.util.spec_from_file_location("caddy_sections", SOURCE)
MODULE = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(MODULE)
HEADER = MODULE.MARKER + "{\n    admin off\n}\n"


def config(*blocks):
    return HEADER + ("\n" + "\n".join(blocks) if blocks else "")


def site(name, domain, destination):
    return f"# orbit: routes {name}\nhttps://{domain} {{\n    reverse_proxy {destination}\n}}\n"


class CaddySectionsTest(unittest.TestCase):
    def test_forward_and_inverse_preserve_unrelated_revisions(self):
        source = site("app-instance-1", "app.test", "10.44.0.1")
        destination = site("app-instance-1", "app.test", "10.44.0.2")
        survivor = site("app-instance-2", "other.test", "10.44.0.3")
        revised = site("app-instance-2", "other.test", "10.44.0.4")
        addition = site("app-instance-3", "new.test", "10.44.0.5")
        before, after = config(source, survivor), config(destination, survivor)
        current = config(source, revised, addition)
        merged = MODULE.merge(before, after, current)
        self.assertEqual(merged, config(destination, revised, addition))
        self.assertEqual(MODULE.merge(after, before, merged), current)
        self.assertEqual(MODULE.merge(before, after, merged), merged)

    def test_unrelated_deletion_is_not_resurrected(self):
        source = site("moving", "app.test", "a")
        destination = site("moving", "app.test", "b")
        survivor = site("deleted", "other.test", "c")
        self.assertEqual(
            MODULE.merge(
                config(source, survivor), config(destination, survivor), config(source)
            ),
            config(destination),
        )

    def test_same_scope_multiple_route_blocks_are_retained(self):
        a = site("app-instance-1", "a.test", "a")
        b = site("app-instance-1", "b.test", "a")
        next_a = site("app-instance-1", "a.test", "b")
        self.assertEqual(
            MODULE.merge(config(a, b), config(next_a, b), config(a, b)),
            config(next_a, b),
        )

    def test_owned_conflict_is_refused_before_candidate(self):
        a, b, c = [site("moving", "app.test", x) for x in ("a", "b", "c")]
        with self.assertRaisesRegex(
            MODULE.PublicationConflict, "owned_revision_conflict"
        ):
            MODULE.merge(config(a), config(b), config(c))

    def test_add_and_remove_groups_are_idempotent(self):
        a = site("new", "app.test", "a")
        self.assertEqual(MODULE.merge(config(), config(a), config()), config(a))
        self.assertEqual(MODULE.merge(config(a), config(), config()), config())
        self.assertEqual(MODULE.merge(config(a), config(), config(a)), config())

    def test_new_groups_follow_the_admitted_render_order(self):
        blocks = [site(name, name + ".test", "a") for name in ("three", "one", "four", "two")]
        self.assertEqual(MODULE.merge(config(), config(*blocks), config()), config(*blocks))

    def test_global_change_and_foreign_file_are_refused(self):
        for current in (
            "foreign\n",
            config().replace("admin off", "admin localhost:2019"),
        ):
            with self.assertRaises(MODULE.PublicationConflict):
                MODULE.merge(config(), config(), current)


if __name__ == "__main__":
    unittest.main()
