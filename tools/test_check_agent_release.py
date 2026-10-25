import copy
import json
from pathlib import Path
import unittest

from check_agent_release import validate_manifest, validate_release


class AgentReleaseTest(unittest.TestCase):
    def setUp(self):
        self.commit = "c" * 40
        self.manifest = {"commit": self.commit, "version": "0.5.0", "artifacts": {}}
        self.release = {"tag_name": f"agent-v{self.commit}", "draft": False, "prerelease": False,
                        "assets": [{"name": "SHA256SUMS"}, {"name": "manifest.json"}]}
        self.sums = ""
        for architecture, target, digest in [("linux-x86_64", "x86_64", "a" * 64), ("linux-aarch64", "aarch64", "b" * 64), ("macos-aarch64", "aarch64", "d" * 64), ("macos-x86_64", "x86_64", "e" * 64)]:
            name = f"orbit-agent-{architecture}"
            self.manifest["artifacts"][architecture] = {
                "url": f"https://github.com/hardimpactdev/orbit/releases/download/agent-v{self.commit}/{name}",
                "sha256": digest,
            }
            self.release["assets"].append({"name": name})
            self.sums += f"{digest}  {name}\n"

    def test_accepts_complete_published_delivery(self):
        validate_manifest(self.manifest)
        validate_release(self.manifest, self.release, self.sums)

    def test_recipe_resolves_delivery_instead_of_carrying_published_pins(self):
        root = Path(__file__).resolve().parents[1]
        recipe = json.loads((root / "apps/gateway/resources/machine-software/recipe.json").read_text())
        self.assertIsNone(recipe["agent"])
        self.assertNotIn("orbit-agent", recipe["tools"])

    def test_rejects_changed_release_paths_and_non_github_urls(self):
        original = self.manifest["artifacts"]["linux-x86_64"]["url"]
        for url in [original.replace("https:", "http:"), original.replace("github.com", "example.com"),
                    original.replace(self.commit, "d" * 40), original + "?asset=1",
                    original + "#asset", original.replace("github.com", "user@github.com"),
                    original.replace("/hardimpactdev/orbit/", "/another/repository/")]:
            with self.subTest(url=url):
                manifest = copy.deepcopy(self.manifest)
                manifest["artifacts"]["linux-x86_64"]["url"] = url
                with self.assertRaises(ValueError):
                    validate_manifest(manifest)

    def test_rejects_a_version_in_place_of_the_source_commit(self):
        self.manifest["commit"] = "0.4.2"
        with self.assertRaisesRegex(ValueError, "full source commit"):
            validate_manifest(self.manifest)

    def test_rejects_wrong_urls_invalid_digests_and_missing_architectures(self):
        for field, value in [("url", "https://example.com/agent"), ("sha256", "bad")]:
            with self.subTest(field=field):
                manifest = copy.deepcopy(self.manifest)
                manifest["artifacts"]["linux-x86_64"][field] = value
                with self.assertRaises(ValueError):
                    validate_manifest(manifest)
        del self.manifest["artifacts"]["linux-aarch64"]
        with self.assertRaises(ValueError):
            validate_manifest(self.manifest)

    def test_rejects_unpublished_or_incomplete_releases(self):
        for field, value in [("draft", True), ("prerelease", True), ("tag_name", "agent-v0.4.2"),
                             ("assets", [{"name": "SHA256SUMS"}, {"name": "manifest.json"}])]:
            with self.subTest(field=field):
                release = {**self.release, field: value}
                with self.assertRaises(ValueError):
                    validate_release(self.manifest, release, self.sums)

    def test_rejects_wrong_missing_duplicate_and_malformed_checksums(self):
        for sums in [self.sums.replace("a" * 64, "c" * 64), self.sums.splitlines()[0],
                     self.sums + self.sums, "malformed", ""]:
            with self.subTest(sums=sums):
                with self.assertRaises(ValueError):
                    validate_release(self.manifest, self.release, sums)


if __name__ == "__main__":
    unittest.main()
