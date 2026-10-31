"""Run the shipped script actions rather than an embedded-program adapter."""

import importlib.util
import unittest
from pathlib import Path

spec = importlib.util.spec_from_file_location(
    "repository_actions",
    Path(__file__).parent.parent / "task-repository/actions_test.py",
)
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)
ScriptActions = module.ScriptActions
if __name__ == "__main__":
    unittest.main()
