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

    def dpkg(self, *versions):
        """dpkg-query answers in order; None means not installed."""
        answers = iter(versions)
        def query(argv, **kwargs):
            self.assertEqual(argv[:2], ['dpkg-query', '-W'])
            version = next(answers)
            return subprocess.CompletedProcess(argv, 0 if version else 1,
                                               'install ok installed ' + version if version else '', '')
        return patch.object(module.subprocess, 'run', side_effect=query)

    def test_missing_fcgiwrap_installs_the_exact_pin_without_recommends(self):
        with self.dpkg(None, module.FCGIWRAP), patch.object(module.os, 'access', return_value=True), \
             patch.object(module, 'run') as run:
            module.ensure_fcgiwrap()
        self.assertEqual([call.args[0] for call in run.call_args_list], [
            ['apt-get', 'update'],
            ['apt-get', 'install', '-y', '--no-install-recommends', 'fcgiwrap=1.1.0-16build3'],
        ])
        self.assertEqual(run.call_args.kwargs['env']['DEBIAN_FRONTEND'], 'noninteractive')

    def test_pinned_fcgiwrap_is_not_reinstalled(self):
        with self.dpkg(module.FCGIWRAP, module.FCGIWRAP), patch.object(module.os, 'access', return_value=True), \
             patch.object(module, 'run') as run:
            module.ensure_fcgiwrap()
        run.assert_not_called()

    def test_other_fcgiwrap_version_refuses_without_changing_it(self):
        with self.dpkg('1.1.0-99', '1.1.0-99'), patch.object(module, 'run') as run:
            with self.assertRaisesRegex(RuntimeError, 'e2e.fixture_repository_fcgiwrap_version_mismatch'):
                module.ensure_fcgiwrap()
        run.assert_not_called()

    def test_fixture_caddy_keeps_state_out_of_the_product_caddy_home(self):
        unit = module.units()['swamp-proof-repositories.service']
        self.assertIn('StateDirectory=swamp-proof-repositories', unit)
        self.assertIn('XDG_DATA_HOME=/var/lib/swamp-proof-repositories', unit)
        self.assertNotIn('/var/lib/caddy', unit)


if __name__ == '__main__':
    unittest.main()
