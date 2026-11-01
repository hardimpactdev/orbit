"""Initialization passes private bytes only through stdin and emits fixed failures."""

import importlib.util
import json
import subprocess
import unittest
from pathlib import Path
from unittest.mock import patch

spec = importlib.util.spec_from_file_location(
    "analytics_initialize", Path(__file__).parent / "scripts/initialize.py"
)
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)


class Initialization(unittest.TestCase):
    def test_one_initialization_action_uses_stdin_without_private_argv_or_output(self):
        definition = {
            "image": "ghcr.io/plausible/community-edition:v3.3.0",
            "mounts": [],
        }
        for phase in ("createdb", "migrate"):
            with patch.object(
                module.subprocess,
                "run",
                return_value=subprocess.CompletedProcess([], 0),
            ) as run:
                result = module.initialize(
                    phase,
                    definition,
                    {"SECRET_KEY_BASE": "private-fixture"},
                    "11111111-1111-4111-8111-111111111111",
                )
            self.assertEqual(result, {"ready": True})
            run.assert_called_once()
            self.assertNotIn("private-fixture", json.dumps(run.call_args.args))
            self.assertIn("private-fixture", run.call_args.kwargs["input"])
            self.assertEqual(run.call_args.kwargs["stdout"], subprocess.DEVNULL)
            self.assertEqual(run.call_args.kwargs["stderr"], subprocess.DEVNULL)

    def test_invalid_environment_refuses_before_docker(self):
        with (
            patch.object(module.subprocess, "run") as run,
            self.assertRaises(ValueError),
        ):
            module.initialize(
                "createdb",
                {},
                {"KEY": "line\nvalue"},
                "11111111-1111-4111-8111-111111111111",
            )
        run.assert_not_called()

    def test_failed_docker_is_a_fixed_failure(self):
        with (
            patch.object(
                module.subprocess,
                "run",
                return_value=subprocess.CompletedProcess([], 1),
            ),
            self.assertRaisesRegex(RuntimeError, "^Initialization failed$"),
        ):
            module.initialize(
                "migrate",
                {"image": "fixture", "mounts": []},
                {},
                "11111111-1111-4111-8111-111111111111",
            )
