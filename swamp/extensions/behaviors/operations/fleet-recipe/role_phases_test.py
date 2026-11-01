"""Legacy phase ordering and refusal parity without host effects."""

import importlib.util
import json
import subprocess
import sys
import tempfile
import types
import unittest
from pathlib import Path
from unittest.mock import patch

ROOT = Path(__file__).resolve().parent


def load(name, file):
    spec = importlib.util.spec_from_file_location(name, file)
    result = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(result)
    return result


common = load("phase_common", ROOT / "role_phases/common.py")
sys.modules["phase_common"] = common
prerequisites = load("phase_prerequisites", ROOT / "role_phases/prerequisites.py")
observer = load("phase_observer", ROOT / "role_phases/observer.py")
php = load("phase_php_runtime", ROOT / "role_phases/php_runtime.py")
firewall = load("phase_firewall", ROOT / "role_phases/firewall.py")


class RolePhases(unittest.TestCase):
    def setUp(self):
        self.calls = []
        self.failure = None

    def record(self, name, data=None):
        self.calls.append((name, data))
        if name == self.failure:
            raise ValueError("fixture-refusal")
        return {"completed": True}

    def supplier(self, name):
        if name == "role_prerequisites":
            return types.SimpleNamespace(
                caddy=lambda data: self.record("caddy", data),
                prerequisites=lambda data: self.record("prerequisites", data),
            )
        return types.SimpleNamespace(converge_ordering=lambda: self.record("ordering"))

    def run_prerequisites(self, prepared=False):
        intent = {
            "role": "app-prod",
            "caddy_package": {"release": "fixture"},
            "prerequisites": {
                "packages": ["composer", "caddy"],
                "releases": ["fixture"],
            },
            "php": {"pin": "fixture"},
            "javascript": {"pin": "fixture"},
        }
        with (
            patch.object(prerequisites, "module", self.supplier),
            patch.object(
                prerequisites,
                "execute",
                lambda name, data=None, action=None: self.record(action or name, data),
            ),
        ):
            return prerequisites.converge(
                {"intent": intent, "prepared_tools": prepared},
                {"user": "orbit", "group": "orbit", "home": "/srv/orbit"},
            )

    def test_canonical_composer_scope_precedes_php_javascript_and_ordering(self):
        self.assertTrue(self.run_prerequisites()["ready"])
        self.assertEqual(
            [name for name, _ in self.calls],
            ["caddy", "prerequisites", "php-build", "javascript", "ordering"],
        )
        captured = self.calls[1][1]
        self.assertEqual(captured["packages"], ["composer"])
        self.assertTrue(captured["composer_home"])
        self.assertFalse(captured["apps_directory"])
        self.assertEqual(captured["home"], "/srv/orbit")

    def test_prepared_tools_keep_composer_preflight_php_and_ordering(self):
        self.run_prerequisites(prepared=True)
        self.assertEqual(
            [name for name, _ in self.calls],
            ["caddy", "prerequisites", "php-build", "ordering"],
        )
        self.assertFalse(self.calls[1][1]["install_packages"])

    def test_each_refusal_stops_all_later_prerequisite_effects(self):
        order = ["caddy", "prerequisites", "php-build", "javascript", "ordering"]
        for index, failure in enumerate(order):
            with self.subTest(failure=failure):
                self.calls = []
                self.failure = failure
                with self.assertRaisesRegex(ValueError, "fixture-refusal"):
                    self.run_prerequisites()
                self.assertEqual([name for name, _ in self.calls], order[: index + 1])

    def test_php_json_refusal_stops_later_configurations(self):
        with tempfile.TemporaryDirectory() as directory:
            source = Path(directory) / "canonical_php_runtime.py"
            source.write_text(
                'print(\'{"error_code":"php.runtime_configuration_failed"}\')\n'
            )
            with (
                patch.object(common, "DIRECTORY", Path(directory)),
                self.assertRaisesRegex(ValueError, "php.runtime_configuration_failed"),
            ):
                php.converge(
                    {
                        "intent": {
                            "footprint": {
                                "payloads": {
                                    "php-runtime": {
                                        "configurations": [
                                            {"version": "8.5"},
                                            {"version": "8.4"},
                                        ]
                                    }
                                }
                            }
                        }
                    }
                )

    def test_failing_canonical_action_retains_its_stderr(self):
        with tempfile.TemporaryDirectory() as directory:
            source = Path(directory) / "failure.py"
            source.write_text(
                'import sys\nprint("fixture-refusal",file=sys.stderr)\nsys.exit(7)\n'
            )
            with (
                patch.object(common, "DIRECTORY", Path(directory)),
                self.assertRaises(subprocess.CalledProcessError) as error,
            ):
                common.execute("failure")
            self.assertEqual(error.exception.returncode, 7)

    def test_cli_phase_preserves_canonical_refusal_envelope(self):
        import os

        for code in ["cli.foreign_binary", "cli.release_needed", "cli.update_busy"]:
            with self.subTest(code=code), tempfile.TemporaryDirectory() as directory:
                root = Path(directory)
                (root / "phase_common.py").write_text(
                    (ROOT / "role_phases/common.py").read_text()
                )
                (root / "phase_cli.py").write_text(
                    (ROOT / "role_phases/cli.py").read_text()
                )
                (root / "canonical_cli.py").write_text(
                    (ROOT / "scripts/cli.py")
                    .read_text()
                    .replace(
                        "def install(data):",
                        "def install(data):\n    refuse(" + repr(code) + ")",
                    )
                )
                result = subprocess.run(
                    [sys.executable, "-B", str(root / "phase_cli.py")],
                    env={
                        **os.environ,
                        "ORBIT_ROLE_INPUT": json.dumps(
                            {
                                "intent": {
                                    "cli": {
                                        "user": "orbit",
                                        "release": None,
                                        "profile": "",
                                    }
                                }
                            }
                        ),
                    },
                    capture_output=True,
                    text=True,
                    check=True,
                )
                self.assertEqual(json.loads(result.stdout), {"error_code": code})

    def test_observer_identity_refusal_precedes_files_and_service(self):
        secret = types.SimpleNamespace(
            verify=lambda expected: self.record("identity", expected)
        )
        self.failure = "identity"
        with (
            patch.object(observer, "module", lambda name: secret),
            self.assertRaisesRegex(ValueError, "fixture-refusal"),
        ):
            observer.converge({}, {}, "fixture-digest", False)
        self.assertEqual([name for name, _ in self.calls], ["identity"])

    def test_observer_uses_shared_home_policy_and_restarts_on_changes(self):
        files = []

        def supplier(name):
            if name == "observer_identity":
                return types.SimpleNamespace(verify=lambda expected: None)
            if name == "file":

                def publish(data):
                    files.append(data)
                    return {"changed": False}

                return types.SimpleNamespace(apply=publish)
            return types.SimpleNamespace(
                apply=lambda data: self.record("checkout", data)
            )

        data = {
            "observer_policy": {
                "protection": "ProtectHome=tmpfs",
                "binding": "BindReadOnlyPaths=-",
                "protected_prefixes": ["/home/", "/root/"],
            },
            "intent": {
                "footprint": {
                    "payloads": {
                        "agent": {
                            "configuration": "public",
                            "certificate": "public-ca",
                            "unit": "ProtectHome=yes\n",
                            "apps_root": None,
                            "checkouts": ["/srv/one", "/srv/two"],
                        }
                    }
                }
            },
        }
        for home, expected in [
            ("/home/orbit", "ProtectHome=tmpfs\nBindReadOnlyPaths=-/home/orbit/apps\n"),
            ("/srv/orbit", "ProtectHome=tmpfs\n"),
        ]:
            with self.subTest(home=home):
                self.calls = []
                with (
                    patch.object(observer, "module", supplier),
                    patch.object(
                        observer,
                        "control",
                        lambda *args: self.record("systemctl", args),
                    ),
                ):
                    observer.converge(data, {"home": home}, "fixture-digest", True)
                self.assertEqual(files[-1]["content"], expected)
                self.assertEqual(
                    [value for name, value in self.calls if name == "systemctl"],
                    [
                        ("daemon-reload",),
                        ("enable", "--now", "orbit-agent.service"),
                        ("restart", "orbit-agent.service"),
                        ("is-active", "--quiet", "orbit-agent.service"),
                    ],
                )

    def test_firewall_expands_before_pruning_and_failure_stops_pruning(self):
        intent = {
            "firewall": {
                "desired": [["ufw", "allow", "443"]],
                "remove": [["ufw", "delete", "22"]],
            }
        }
        with patch.object(
            firewall.subprocess, "run", lambda argv, **kwargs: self.record(argv[1])
        ):
            firewall.converge(intent)
        self.assertEqual([name for name, _ in self.calls], ["allow", "delete"])
        self.calls = []
        self.failure = "allow"
        with (
            patch.object(
                firewall.subprocess, "run", lambda argv, **kwargs: self.record(argv[1])
            ),
            self.assertRaises(ValueError),
        ):
            firewall.converge(intent)
        self.assertEqual([name for name, _ in self.calls], ["allow"])


if __name__ == "__main__":
    unittest.main()
