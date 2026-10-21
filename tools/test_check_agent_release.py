import copy
import json
from pathlib import Path
import unittest

from check_agent_release import validate_pin, validate_release


class AgentReleaseTest(unittest.TestCase):
    def setUp(self):
        self.version = "0.4.3"
        self.pin = {"source": "artifact", "version": self.version, "artifacts": {}}
        self.release = {"tag_name": "agent-v0.4.3", "draft": False, "prerelease": False,
                        "assets": [{"name": "SHA256SUMS"}]}
        self.sums = ""
        for architecture, target, digest in [("amd64", "x86_64", "a" * 64), ("arm64", "aarch64", "b" * 64)]:
            name = f"orbit-agent-{self.version}-linux-{target}"
            self.pin["artifacts"][architecture] = {
                "url": f"https://github.com/hardimpactdev/orbit/releases/download/agent-v{self.version}/{name}",
                "sha256": digest,
            }
            self.release["assets"].append({"name": name})
            self.sums += f"{digest}  {name}\n"

    def test_accepts_complete_published_pin(self):
        validate_pin(self.version, self.pin)
        validate_release(self.version, self.pin, self.release, self.sums)

    def test_accepts_the_unchanged_recipe_pins(self):
        root = Path(__file__).resolve().parents[1]
        recipe = json.loads((root / "apps/gateway/resources/machine-software/recipe.json").read_text())
        validate_pin(self.version, recipe["tools"]["orbit-agent"])

    def test_rejects_changed_release_paths_and_non_github_urls(self):
        original = self.pin["artifacts"]["amd64"]["url"]
        for url in [original.replace("https:", "http:"), original.replace("github.com", "example.com"),
                    original.replace("agent-v0.4.3", "agent-v0.4.2"), original + "?asset=1",
                    original + "#asset", original.replace("github.com", "user@github.com")]:
            with self.subTest(url=url):
                pin = copy.deepcopy(self.pin)
                pin["artifacts"]["amd64"]["url"] = url
                with self.assertRaises(ValueError):
                    validate_pin(self.version, pin)

    def test_rejects_the_1305_delivery_gap(self):
        self.pin["version"] = "0.4.2"
        with self.assertRaisesRegex(ValueError, "not pinned"):
            validate_pin(self.version, self.pin)

    def test_rejects_wrong_urls_invalid_digests_and_missing_architectures(self):
        for field, value in [("url", "https://example.com/agent"), ("sha256", "bad")]:
            with self.subTest(field=field):
                pin = copy.deepcopy(self.pin)
                pin["artifacts"]["amd64"][field] = value
                with self.assertRaises(ValueError):
                    validate_pin(self.version, pin)
        del self.pin["artifacts"]["arm64"]
        with self.assertRaises(ValueError):
            validate_pin(self.version, self.pin)

    def test_rejects_unpublished_or_incomplete_releases(self):
        for field, value in [("draft", True), ("prerelease", True), ("tag_name", "agent-v0.4.2"),
                             ("assets", [{"name": "SHA256SUMS"}])]:
            with self.subTest(field=field):
                release = {**self.release, field: value}
                with self.assertRaises(ValueError):
                    validate_release(self.version, self.pin, release, self.sums)

    def test_rejects_wrong_missing_duplicate_and_malformed_checksums(self):
        for sums in [self.sums.replace("a" * 64, "c" * 64), self.sums.splitlines()[0],
                     self.sums + self.sums, "malformed", ""]:
            with self.subTest(sums=sums):
                with self.assertRaises(ValueError):
                    validate_release(self.version, self.pin, self.release, sums)


if __name__ == "__main__":
    unittest.main()
