import importlib.machinery
import importlib.util
import subprocess
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
            "HANDOVER": self.base / "operation",
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

    def test_failed_reload_preserves_the_forward_selection(self):
        with patch.object(site, "reload_config"):
            site.apply("prepare")
        with (
            patch.object(site, "reload_config", side_effect=[RuntimeError("reload failed"), None]) as reload,
            self.assertRaisesRegex(RuntimeError, "reload failed"),
        ):
            site.apply("container")
        self.assertEqual(reload.call_count, 1)
        self.assertEqual(site.SELECTION.readlink(), site.MODES / "container.caddy")

        with patch.object(site, "reload_config") as reload:
            site.HANDOVER.touch()
            for mode in ("host", "prepare"):
                with self.assertRaisesRegex(ValueError, "--recover"):
                    site.apply(mode)
            site.PREPARED.with_name("gateway-containers.json").write_text('{"version":2}')
            site.HANDOVER.unlink()
            with self.assertRaisesRegex(ValueError, "forward container"):
                site.apply("host")
            site.CONFIG.write_text(CONFIG)
            with self.assertRaisesRegex(ValueError, "restore the managed"):
                site.apply("prepare")
            reload.assert_not_called()
            self.assertEqual(site.SELECTION.readlink(), site.MODES / "container.caddy")

    def test_host_and_container_modes_keep_metrics_authorized_across_publication(self):
        script = r"""
require 'apps/gateway/app/Infrastructure/Metrics/MetricsFootprint.php';
require 'apps/gateway/app/Infrastructure/Metrics/MetricsPublicationRenderer.php';
echo (new App\Infrastructure\Metrics\MetricsPublicationRenderer('/home/orbit/orbit/apps/gateway'))
    ->caddy('10.44.0.9', '10.44.0.2', prepared: $argv[1] === 'prepared');
"""
        def render(mode):
            body = subprocess.check_output(["php", "-r", script, mode], cwd=ROOT, text=True)
            return "# orbit: metrics metrics.orbit\n" + body
        legacy = render("host")
        prepared = render("prepared").replace("/etc/caddy/orbit-gateway-modes", str(site.MODES))
        self.assertIn("env REMOTE_ADDR {remote_host}", legacy)
        self.assertIn("forward_auth unix//run/php/orbit-gateway.sock", legacy)
        site.CONFIG.write_text(CONFIG + "\n" + legacy)
        auth = site.MODES / "metrics-auth.caddy"
        with patch.object(site, "reload_config"):
            site.apply("prepare")
            self.assertTrue(site.CONFIG.read_text().endswith(prepared))
            self.assertIn("transport fastcgi", auth.read_text())
            site.apply("container")
            self.assertIn("forward_auth {http.vars.gateway_release_upstream}", auth.read_text())
            self.assertIn("header_up X-Forwarded-For {remote_host}", auth.read_text())
            self.assertIn("uri /api/v1/metrics/grafana/authorize", auth.read_text())
            self.assertNotIn("fastcgi", auth.read_text())
            self.assertTrue(site.PREPARED.with_name("gateway-metrics-prepared").exists())
            self.assertTrue(site.CONFIG.read_text().endswith(prepared))
            self.assertIn("reverse_proxy http://10.44.0.9:", prepared)
            self.assertIn("bind 10.44.0.2", prepared)
            site.CONFIG.write_text(CONFIG + "\n" + legacy.replace("env REMOTE_ADDR {remote_host}", "env REMOTE_ADDR 0.0.0.0"))
            with self.assertRaisesRegex(ValueError, "unrecognized Gateway authorization"):
                site.apply("container")


    def test_failed_initial_prepare_restores_original_site_and_mode(self):
        with (
            patch.object(site, "reload_config", side_effect=[RuntimeError("validate failed"), None]),
            self.assertRaisesRegex(RuntimeError, "validate failed"),
        ):
            site.apply("prepare")
        self.assertEqual(site.CONFIG.read_text(), CONFIG)
        self.assertEqual(site.CONFIG.stat().st_mode & 0o777, 0o640)
        self.assertFalse(site.SELECTION.is_symlink())
        self.assertFalse(site.PREPARED.exists())


if __name__ == "__main__":
    unittest.main()
