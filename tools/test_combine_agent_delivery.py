import hashlib
import json
from pathlib import Path
import tempfile
import unittest

from combine_agent_delivery import combine


class CombineDeliveryTest(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name)
        self.sources = [self.root / "linux", self.root / "macos"]
        for directory, platforms in zip(self.sources, [("linux-x86_64", "linux-aarch64"), ("macos-aarch64", "macos-x86_64")]):
            directory.mkdir()
            manifest = {"commit": "a" * 40, "version": "0.5.0", "artifacts": {}}
            for platform in platforms:
                name = f"orbit-agent-{platform}"
                content = platform.encode()
                (directory / name).write_bytes(content)
                (directory / name).chmod(0o755)
                manifest["artifacts"][platform] = {"path": name, "sha256": hashlib.sha256(content).hexdigest()}
            (directory / "manifest.json").write_text(json.dumps(manifest))
        self.destination = self.root / "combined"

    def test_combines_the_exact_tested_bytes_and_executable_modes(self):
        combine(self.destination, self.sources)
        manifest = json.loads((self.destination / "manifest.json").read_text())
        self.assertEqual(set(manifest["artifacts"]), {"linux-x86_64", "linux-aarch64", "macos-aarch64", "macos-x86_64"})
        self.assertEqual(len((self.destination / "SHA256SUMS").read_text().splitlines()), 4)
        for platform, artifact in manifest["artifacts"].items():
            path = self.destination / artifact["path"]
            self.assertEqual(path.read_bytes(), platform.encode())
            self.assertEqual(path.stat().st_mode & 0o777, 0o755)

    def test_refuses_mixed_commits_versions_digests_paths_and_platforms_before_writing(self):
        path = self.sources[1] / "manifest.json"
        original = path.read_text()
        for fault in ["commit", "version", "digest", "path", "missing", "duplicate"]:
            with self.subTest(fault=fault):
                manifest = json.loads(original)
                if fault in ["commit", "version"]:
                    manifest[fault] = "different"
                elif fault in ["digest", "path"]:
                    manifest["artifacts"]["macos-aarch64"]["sha256" if fault == "digest" else "path"] = "wrong"
                elif fault == "missing":
                    manifest["artifacts"] = {}
                else:
                    manifest["artifacts"]["linux-x86_64"] = manifest["artifacts"].pop("macos-aarch64")
                path.write_text(json.dumps(manifest))
                with self.assertRaises(ValueError):
                    combine(self.destination, self.sources)
                self.assertFalse(self.destination.exists())


if __name__ == "__main__":
    unittest.main()
