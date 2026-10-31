"""The frozen published agent never depends on the checkout's changing source."""
import importlib.machinery
import importlib.util
import json
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

loader = importlib.machinery.SourceFileLoader('ci_agent_delivery', str(Path(__file__).resolve().parents[1] / 'bin/ci-agent-delivery'))
spec = importlib.util.spec_from_loader(loader.name, loader)
delivery = importlib.util.module_from_spec(spec)
loader.exec_module(delivery)

class FrozenAgentTest(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name)
        self.manifest = json.loads((delivery.ROOT / 'apps/gateway/resources/agent/frozen-manifest.json').read_text())
        self.path = self.root / 'apps/gateway/resources/agent/frozen-manifest.json'
        self.path.parent.mkdir(parents=True)
        self.path.write_text(json.dumps(self.manifest))

    def frozen(self):
        with patch.object(delivery, 'ROOT', self.root):
            return delivery.frozen_release()

    def test_agent_source_changes_do_not_change_the_frozen_release(self):
        original = self.frozen()
        source = self.root / 'apps/agent/src/main.rs'
        source.parent.mkdir(parents=True)
        source.write_text('a different observer source tree')
        self.assertEqual(original, self.frozen())

    def test_mixed_published_releases_are_refused(self):
        self.manifest['artifacts']['linux-x86_64']['url'] = self.manifest['artifacts']['linux-x86_64']['url'].replace('cli-v0.7261.0', 'cli-v0.1.0')
        self.path.write_text(json.dumps(self.manifest))
        with self.assertRaises(ValueError):self.frozen()

    def test_only_the_fixed_public_repository_can_supply_the_frozen_agent(self):
        self.manifest['artifacts']['linux-x86_64']['url'] = self.manifest['artifacts']['linux-x86_64']['url'].replace('github.com', 'example.invalid')
        self.path.write_text(json.dumps(self.manifest))
        with self.assertRaises(ValueError):self.frozen()

if __name__ == '__main__':unittest.main()
