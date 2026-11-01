"""Rendered peer config only; no WireGuard, DNS or service effects."""

import importlib.util
import json
import sys
import tempfile
import types
import unittest
from pathlib import Path

MODULE = Path(__file__).with_name("publish.py")
KEY = "K" * 43 + "="


class PublishPeer(unittest.TestCase):
    def render(self):
        with tempfile.TemporaryDirectory() as folder:
            wg = Path(folder)
            (wg / "orbit.key").write_text(KEY + "\n")
            written = {}
            common = types.ModuleType("common")
            common.WG = wg
            common.publish = lambda path, value: written.update({path.name: value})
            common.finish = lambda action: None
            sys.modules["common"] = common
            try:
                spec = importlib.util.spec_from_file_location("publish_fixture", MODULE)
                module = importlib.util.module_from_spec(spec)
                spec.loader.exec_module(module)
                module.render({"peer": {"address": "10.44.0.129/24", "server_public_key": KEY, "subnet": "10.44.0.0/24",
                                        "dns_server": "10.44.0.1", "endpoint": "vpn.example:51820", "domains": ["orbit", "test"]}})
            finally:
                del sys.modules["common"]
            return written["orbit.conf"]

    def test_post_up_sets_only_the_dns_server_and_route_only_domains(self):
        hooks = [line for line in self.render().splitlines() if line.startswith("PostUp")]
        self.assertEqual(hooks, ["PostUp = resolvectl dns %i 10.44.0.1; resolvectl domain %i '~orbit' '~test'"])
        self.assertNotIn("default-route", json.dumps(hooks))


if __name__ == "__main__":
    unittest.main()
