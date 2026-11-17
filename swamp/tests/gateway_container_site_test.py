import importlib.machinery
import importlib.util
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[2]
LOADER = importlib.machinery.SourceFileLoader("gateway_site", str(ROOT / "bin/gateway-container-site"))
SPEC = importlib.util.spec_from_loader(LOADER.name, LOADER)
site = importlib.util.module_from_spec(SPEC)
LOADER.exec_module(site)

CONFIG = """# Managed by Orbit: Node Caddy build
# orbit: gateway gateway.orbit
gateway.orbit, 10.44.0.2 {
    bind 10.44.0.2
    @orbit_outside not remote_ip 10.44.0.0/24
    abort @orbit_outside
    encode zstd gzip
    @gateway path /api/*
    handle @gateway {
        php_fastcgi unix//run/php/orbit-gateway.sock
    }
}

# orbit: websocket reverb.orbit
reverb.orbit {
    respond "unrelated"
}
"""


class GatewaySiteTest(unittest.TestCase):
    def setUp(self):
        temp = tempfile.TemporaryDirectory()
        self.addCleanup(temp.cleanup)
        self.base = Path(temp.name)
        names = {
            "CADDY": self.base, "CONFIG": self.base / "Caddyfile", "MODES": self.base / "modes",
            "SELECTION": self.base / "selected", "PREPARED": self.base / "prepared",
        }
        self.addCleanup(patch.stopall)
        patch.multiple(site, **names).start()
        site.MODES.mkdir()
        (site.MODES / "container.caddy").write_text("reverse_proxy example:8080\n")
        site.CONFIG.write_text(CONFIG)
        site.CONFIG.chmod(0o640)

    def test_prepare_preserves_listener_guard_and_other_sites(self):
        with patch.object(site, "reload_config"):
            site.apply("prepare")
            site.apply("prepare")
            site.apply("container")
            site.apply("host")
        result = site.CONFIG.read_text()
        self.assertIn("abort @orbit_outside", result)
        self.assertEqual(result.split("# orbit: websocket")[1], CONFIG.split("# orbit: websocket")[1])
        self.assertEqual(result.count("    import "), 1)
        self.assertIn("php_fastcgi", site.SELECTION.read_text())
        self.assertEqual(site.CONFIG.stat().st_mode & 0o777, 0o640)

    def test_failed_reload_restores_last_serving_mode(self):
        with patch.object(site, "reload_config"):
            site.apply("prepare")
        with patch.object(site, "reload_config", side_effect=[RuntimeError("reload failed"), None]) as reload:
            with self.assertRaisesRegex(RuntimeError, "reload failed"):
                site.apply("container")
        self.assertEqual(reload.call_count, 2)
        self.assertEqual(site.SELECTION.readlink(), site.MODES / "host.caddy")

    def test_failed_initial_prepare_restores_original_site_and_mode(self):
        with patch.object(site, "reload_config", side_effect=[RuntimeError("validate failed"), None]):
            with self.assertRaisesRegex(RuntimeError, "validate failed"):
                site.apply("prepare")
        self.assertEqual(site.CONFIG.read_text(), CONFIG)
        self.assertEqual(site.CONFIG.stat().st_mode & 0o777, 0o640)
        self.assertFalse(site.SELECTION.is_symlink())
        self.assertFalse(site.PREPARED.exists())
