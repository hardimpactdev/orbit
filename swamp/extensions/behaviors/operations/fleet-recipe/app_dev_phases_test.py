"""AppDev public preflight and canonical supplier ordering without host effects."""

import importlib.util
import sys
import types
import unittest
from pathlib import Path
from unittest.mock import patch

ROOT = Path(__file__).resolve().parent


def load(name, file):
    spec = importlib.util.spec_from_file_location(name, ROOT / file)
    result = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(result)
    return result


common = load("phase_common", "role_phases/common.py")
sys.modules["phase_common"] = common
storage = load("phase_storage", "role_phases/storage.py")
hibernation = load("phase_hibernation", "role_phases/hibernation.py")
prerequisites = load("phase_prerequisites", "role_phases/prerequisites.py")
tmpfiles = load("phase_tmpfiles", "role_phases/tmpfiles.py")


class AppDevPhases(unittest.TestCase):
    def test_storage_uses_observed_home_and_group_with_custom_root_preserved(self):
        for root, expected in [
            (None, "/home/developer/apps"),
            ("/srv/apps", "/srv/apps"),
        ]:
            captured = []
            supplier = types.SimpleNamespace(
                main=lambda data, captured=captured: captured.append(data) or {}
            )
            with patch.object(storage, "module", return_value=supplier):
                result = storage.converge(
                    {
                        "intent": {
                            "storage": {
                                "root": root,
                                "user": "developer",
                                "create_missing": True,
                                "protected_roots": ["/etc"],
                                "managed_checkouts": [],
                            }
                        }
                    },
                    {
                        "user": "developer",
                        "group": "real-group",
                        "home": "/home/developer",
                    },
                )
            self.assertEqual(result, {"ready": True, "root": expected})
            self.assertEqual(
                captured,
                [
                    {
                        "root": expected,
                        "user": "developer",
                        "group": "real-group",
                        "create_missing": True,
                    }
                ],
            )

    def test_protected_hidden_ancestor_and_managed_roots_refuse_before_filesystem(self):
        for root in [
            "/etc/apps",
            "/home",
            "/home/developer",
            "/home/developer/.config/apps",
            "/srv/checkout",
            "/srv/checkout/apps",
        ]:
            with self.subTest(root=root), patch.object(storage, "module") as supplier:
                with self.assertRaisesRegex(ValueError, "node.storage_root_unusable"):
                    storage.converge(
                        {
                            "intent": {
                                "storage": {
                                    "root": root,
                                    "user": "developer",
                                    "create_missing": True,
                                    "protected_roots": ["/etc"],
                                    "managed_checkouts": ["/srv/checkout"],
                                }
                            }
                        },
                        {
                            "user": "developer",
                            "group": "real-group",
                            "home": "/home/developer",
                        },
                    )
                supplier.assert_not_called()

    def test_filesystem_refusal_is_not_readiness(self):
        with (
            patch.object(
                storage,
                "module",
                return_value=types.SimpleNamespace(
                    main=lambda data: {"error_code": "node.storage_root_unusable"}
                ),
            ),
            self.assertRaisesRegex(ValueError, "node.storage_root_unusable"),
        ):
            storage.converge(
                {
                    "intent": {
                        "storage": {
                            "root": None,
                            "user": "developer",
                            "create_missing": False,
                            "protected_roots": [],
                            "managed_checkouts": [],
                        }
                    }
                },
                {
                    "user": "developer",
                    "group": "developer",
                    "home": "/home/developer",
                },
            )

    def test_hibernation_failure_precedes_caddy_ordering(self):
        calls = []

        def supplier(name):
            calls.append(name)
            return types.SimpleNamespace(
                main=lambda data: {"error_code": "hibernation.node_failed"}
            )

        with (
            patch.object(hibernation, "module", supplier),
            patch.object(
                hibernation.grp,
                "getgrnam",
                return_value=types.SimpleNamespace(gr_gid=111),
            ),
            self.assertRaisesRegex(ValueError, "hibernation.node_failed"),
        ):
            hibernation.converge({})
        self.assertEqual(calls, ["hibernation"])

    def test_appdev_keeps_composer_php_javascript_and_defers_ordering_to_hibernation(
        self,
    ):
        calls = []
        policy = types.SimpleNamespace(
            caddy=lambda data: calls.append(("caddy", data)),
            prerequisites=lambda data: calls.append(("prerequisites", data)),
        )
        with (
            patch.object(prerequisites, "module", return_value=policy),
            patch.object(
                prerequisites,
                "execute",
                side_effect=lambda name, data, action: calls.append((action, data)),
            ),
        ):
            prerequisites.converge(
                {
                    "intent": {
                        "role": "app-dev",
                        "caddy_package": {},
                        "prerequisites": {
                            "releases": ["fixture"],
                            "packages": ["caddy", "composer"],
                        },
                        "php": {"fpm": "shared"},
                        "javascript": {"fixture": True},
                    },
                    "prepared_tools": False,
                },
                {"user": "developer", "group": "developer", "home": "/home/developer"},
            )
        self.assertEqual(
            [name for name, _ in calls],
            ["caddy", "prerequisites", "php-build", "javascript"],
        )
        self.assertTrue(calls[1][1]["apps_directory"])
        self.assertTrue(calls[1][1]["composer_home"])
        self.assertEqual(calls[2][1], {"fpm": "shared"})

    def test_tmpfiles_uses_existing_footprint_action_and_refusal_propagates(self):
        with (
            patch.object(
                tmpfiles, "execute", side_effect=ValueError("node.tmpfiles_invalid")
            ) as execute,
            self.assertRaisesRegex(ValueError, "node.tmpfiles_invalid"),
        ):
            tmpfiles.converge(
                {
                    "intent": {
                        "footprint": {"payloads": {"tmpfiles": {"rule": "admitted"}}}
                    }
                }
            )
        execute.assert_called_once_with(
            "canonical_footprint", {"rule": "admitted"}, "node.publish-tmpfiles"
        )


if __name__ == "__main__":
    unittest.main()
