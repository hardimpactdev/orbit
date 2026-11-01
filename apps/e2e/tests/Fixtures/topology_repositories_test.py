"""Pure fixture-service and stock Caddy parser regressions; no listener or system service effects."""
import importlib.util
import json
import subprocess
import tempfile
import unittest
from pathlib import Path
from unittest.mock import MagicMock, patch

SOURCE = Path(__file__).resolve().parents[2] / 'resources/guest/topology-repositories.py'
spec = importlib.util.spec_from_file_location('topology_repositories', SOURCE)
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)


class RepositoryServiceTest(unittest.TestCase):
    def test_stock_caddy_adapts_one_isolated_tls_listener_and_denies_pushes(self):
        with tempfile.TemporaryDirectory() as folder:
            config = Path(folder) / 'Caddyfile'
            config.write_text(module.caddyfile('10.232.14.10'))
            result = subprocess.run(['/usr/bin/caddy', 'adapt', '--config', str(config), '--adapter', 'caddyfile'],
                                    capture_output=True, text=True, check=False)
            self.assertEqual(result.returncode, 0, result.stderr)
            adapted = json.loads(result.stdout)
            server, = adapted['apps']['http']['servers'].values()
            self.assertEqual(server['listen'], ['10.232.14.10:443'])
            self.assertEqual(adapted['admin'], {'disabled': True})
            raw = json.dumps(server)
            self.assertIn('git-receive-pack', raw)
            self.assertIn('403', raw)
            self.assertIn('fastcgi', raw)
            self.assertIn('GIT_PROJECT_ROOT', raw)
            self.assertIn('GIT_HTTP_EXPORT_ALL', raw)
            self.assertNotIn('/etc/caddy/Caddyfile', raw)

    def test_missing_prerequisite_refuses_without_commands_or_files(self):
        with patch.object(module.os, 'access', return_value=False), patch.object(module, 'run') as run:
            with self.assertRaisesRegex(RuntimeError, 'e2e.fixture_repository_prerequisite_missing:/usr/bin/caddy'):
                module.configure('10.232.14.10')
            run.assert_not_called()

    def test_occupied_listener_refuses_before_certificate_or_service_changes(self):
        with tempfile.TemporaryDirectory() as folder:
            probe = MagicMock()
            probe.__enter__.return_value.bind.side_effect = OSError('in use')
            with patch.object(module, 'UNITS', Path(folder)), patch.object(module.os, 'access', return_value=True), \
                 patch.object(module.socket, 'socket', return_value=probe), patch.object(module, 'run') as run:
                with self.assertRaisesRegex(RuntimeError, 'e2e.fixture_repository_listener_occupied'):
                    module.configure('10.232.14.10')
                run.assert_not_called()
                self.assertEqual(list(Path(folder).iterdir()), [])

    def test_foreign_unit_is_never_stopped_or_overwritten(self):
        with tempfile.TemporaryDirectory() as folder:
            unit = Path(folder) / 'swamp-proof-repositories.service'
            unit.write_text('[Service]\nExecStart=/foreign\n')
            with patch.object(module, 'UNITS', Path(folder)), patch.object(module.os, 'access', return_value=True), \
                 patch.object(module, 'run') as run:
                with self.assertRaisesRegex(RuntimeError, 'e2e.fixture_repository_foreign_file'):
                    module.configure('10.232.14.10')
                run.assert_not_called()
                self.assertEqual(unit.read_text(), '[Service]\nExecStart=/foreign\n')


if __name__ == '__main__':
    unittest.main()
