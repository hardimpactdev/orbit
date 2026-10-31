"""Peer configuration and rollback tests with harmless filesystem/tool fixtures."""

import importlib.util
import json
import os
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

SCRIPTS = (
    Path(__file__).resolve().parents[1]
    / "extensions/behaviors/operations/node-peer-command/scripts"
)


def action(name, **values):
    common_spec = importlib.util.spec_from_file_location(
        "common", SCRIPTS / "common.py"
    )
    common = importlib.util.module_from_spec(common_spec)
    common_spec.loader.exec_module(common)
    common.finish = lambda _: None
    for key, value in values.items():
        setattr(common, key, value)
    spec = importlib.util.spec_from_file_location(name, SCRIPTS / (name + ".py"))
    module = importlib.util.module_from_spec(spec)
    with patch.dict(sys.modules, {"common": common}):
        spec.loader.exec_module(module)
    return vars(module)


class PeerActions(unittest.TestCase):
    def test_restore_preserves_persistent_and_runtime_enable_and_mask_states(self):
        for enabled in (
            "enabled",
            "enabled-runtime",
            "masked",
            "masked-runtime",
            "disabled",
        ):
            with (
                self.subTest(enabled=enabled),
                tempfile.TemporaryDirectory() as scratch,
            ):
                calls = []
                loaded = action(
                    "restore",
                    FILES=(),
                    SERVICE="wg-quick@orbit.service",
                    WG=Path(scratch),
                    saved_directory=lambda _: Path(scratch),
                    command=lambda argv, calls=calls: calls.append(argv),
                )
                result = loaded["restore"](
                    {
                        "snapshot": {
                            "present": [],
                            "enabled": enabled,
                            "active": False,
                            "resolver": {},
                            "recorded_resolver": None,
                        }
                    }
                )
                self.assertTrue(result["restored"])
                if enabled != "disabled":
                    expected = [
                        "/usr/bin/systemctl",
                        "mask" if enabled.startswith("masked") else "enable",
                    ]
                    if enabled.endswith("-runtime"):
                        expected.append("--runtime")
                    self.assertIn([*expected, "wg-quick@orbit.service"], calls)
                self.assertNotIn(
                    ["/usr/bin/systemctl", "start", "wg-quick@orbit.service"], calls
                )

    def test_dns_handoff_clears_recorded_uplink_and_restores_its_exact_settings(self):
        from types import SimpleNamespace

        with tempfile.TemporaryDirectory() as scratch:
            directory = Path(scratch)
            (directory / "orbit.dns-link").write_text("eth0\n10.44.0.1\norbit\n")
            calls = []

            def command(argv):
                calls.append(argv)
                return SimpleNamespace(stdout="Link 2 (eth0): 9.9.9.9\n", returncode=0)

            saved = {
                "link": "eth0",
                "settings": {
                    "dns": ["10.44.0.1"],
                    "domain": ["~orbit"],
                    "default-route": ["yes"],
                },
            }
            record = action("record_dns", WG=directory, command=command)
            record["record"](
                {
                    "peer": {"dns_server": "10.44.0.1", "domains": ["orbit"]},
                    "snapshot": {"recorded_resolver": saved},
                }
            )
            self.assertIn(["/usr/bin/resolvectl", "dns", "eth0", ""], calls)
            self.assertIn(["/usr/bin/networkctl", "reconfigure", "eth0"], calls)
            restore = action(
                "restore",
                FILES=(),
                WG=directory,
                saved_directory=lambda _: directory,
                command=command,
            )
            restore["restore"](
                {
                    "snapshot": {
                        "present": [],
                        "active": False,
                        "enabled": "disabled",
                        "resolver": {},
                        "recorded_resolver": saved,
                    }
                }
            )
            self.assertIn(["/usr/bin/resolvectl", "dns", "eth0", "10.44.0.1"], calls)
            self.assertIn(["/usr/bin/resolvectl", "domain", "eth0", "~orbit"], calls)

    def test_generated_hook_delivers_real_resolver_routing_domain_argv(self):
        with tempfile.TemporaryDirectory() as scratch:
            directory = Path(scratch)
            (directory / "orbit.key").write_text("P" * 43 + "=\n")
            config = {}
            loaded = action(
                "publish",
                WG=directory,
                publish=lambda path, value: config.update({path.name: value}),
            )
            loaded["render"](
                {
                    "peer": {
                        "server_public_key": "H" * 43 + "=",
                        "endpoint": "192.0.2.1:51820",
                        "address": "10.44.0.2/24",
                        "subnet": "10.44.0.0/24",
                        "dns_server": "10.44.0.1",
                        "domains": ["orbit", "private.test"],
                    }
                }
            )
            tool = directory / "resolvectl"
            tool.write_text(
                "#!/usr/bin/env python3\nimport json,sys\nprint(json.dumps(sys.argv[1:]))\n"
            )
            tool.chmod(0o755)
            hook = next(
                line.removeprefix("PostUp = ")
                for line in config["orbit.conf"].splitlines()
                if line.startswith("PostUp = ")
            )
            result = subprocess.run(
                ["bash", "-c", hook.replace("%i", "orbit")],
                check=True,
                capture_output=True,
                text=True,
                env={**os.environ, "PATH": scratch + os.pathsep + os.environ["PATH"]},
            )
            self.assertEqual(
                [json.loads(line) for line in result.stdout.splitlines()],
                [
                    ["dns", "orbit", "10.44.0.1"],
                    ["domain", "orbit", "~orbit", "~private.test"],
                    ["default-route", "orbit", "no"],
                ],
            )
