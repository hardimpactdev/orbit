"""Lint every registered release helper and its regression tests using the pinned CI toolchain."""
import subprocess
import tomllib
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
REGISTRATION = ROOT / ".github/ci/fixtures/swamp/tests/production_release_lint_test.py.toml"


class ProductionReleaseLintTest(unittest.TestCase):
    def test_release_helpers_and_tests_pass_ruff(self):
        inputs = tomllib.loads(REGISTRATION.read_text())["inputs"]
        sources = [path for path in inputs if path.endswith(".py") or path.startswith("bin/gateway-container-")]
        subprocess.run(["ruff", "check", *sources, str(Path(__file__))], cwd=ROOT, check=True)


if __name__ == "__main__":
    unittest.main()
