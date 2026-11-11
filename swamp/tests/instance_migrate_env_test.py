"""The actual Node-side parser and endpoint policy; no Ansible installation is needed."""
import importlib.util
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

MODULE = Path(__file__).resolve().parents[1] / 'ansible/library/orbit_migrate_env.py'
SPEC = importlib.util.spec_from_file_location('orbit_migrate_env', MODULE)
guard = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(guard)


class InstanceMigrateEnvTest(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory()
        self.addCleanup(self.directory.cleanup)
        self.root = Path(self.directory.name)

    def inspect(self, text, targets=(), path='.env', **options):
        env = self.root / path
        env.parent.mkdir(parents=True, exist_ok=True)
        env.write_text(text)
        return guard.inspect_environment(str(self.root), targets, options.get('node_addresses', ['10.44.0.5']),
                                         options.get('proxy_policy', {'private_v4': ['10.0.0.0/8', '127.0.0.0/8'],
                                                                      'private_domains': ['orbit', 'private.test']}))

    def test_servauto_mysql_requires_exact_attached_endpoint(self):
        text = 'DB_CONNECTION=mysql\nDB_HOST=127.0.0.1\nDB_PORT=3308\nDB_PASSWORD=never-show-this\n'
        refused = self.inspect(text)
        self.assertEqual(len(refused), 1)
        self.assertIn('DB_HOST=127.0.0.1:3308', refused[0])
        self.assertIn(guard.RECOVERY, refused[0])
        self.assertNotIn('never-show-this', repr(refused))
        self.assertEqual(self.inspect(text, [{'host': '127.0.0.1', 'port': 3308}]), [])
        self.assertEqual(len(self.inspect(text, [{'host': '127.0.0.1', 'port': 3306}])), 1)

    def test_mailpit_and_old_fleet_mail_are_refused(self):
        for host in ('127.0.0.1', '10.6.0.7'):
            with self.subTest(host=host):
                refused = self.inspect(f'MAIL_MAILER=smtp\nMAIL_HOST={host}\nMAIL_PORT=1025\n')
                self.assertEqual(len(refused), 1)
                self.assertIn(f'MAIL_HOST={host}:1025', refused[0])

    def test_unused_mail_and_sqlite_hosts_are_skipped(self):
        for mailer in ('log', 'array', 'sendmail'):
            self.assertEqual(self.inspect(f'MAIL_MAILER={mailer}\nMAIL_HOST=127.0.0.1\n'), [])
        self.assertEqual(self.inspect('DB_CONNECTION=sqlite\nDB_HOST=127.0.0.1\n'), [])

    def test_prefixed_keys_driver_and_default_port(self):
        refused = self.inspect('FOOD_CATALOG_DB_CONNECTION=pgsql\nFOOD_CATALOG_DB_HOST=localhost\n')
        self.assertIn('FOOD_CATALOG_DB_HOST=localhost:5432', refused[0])
        self.assertEqual(self.inspect('FOOD_CATALOG_DB_CONNECTION=sqlite\nFOOD_CATALOG_DB_HOST=localhost\n'), [])
        self.assertEqual(self.inspect('FOOD_CATALOG_DB_CONNECTION=pgsql\nFOOD_CATALOG_DB_HOST=localhost\n',
                                      [{'host': 'localhost', 'port': 5432}]), [])

    def test_arbitrary_attached_prefix_uses_its_connection_driver(self):
        for driver, port in (('mysql', 3306), ('pgsql', 5432), ('redis', 6379)):
            with self.subTest(driver=driver):
                text = f'ANALYTICS_CONNECTION={driver}\nANALYTICS_HOST=localhost\n'
                self.assertIn(f'ANALYTICS_HOST=localhost:{port}', self.inspect(text)[0])
                self.assertEqual(self.inspect(text, [{'host': 'localhost', 'port': port}]), [])
        self.assertEqual(self.inspect('ANALYTICS_CONNECTION=sqlite\nANALYTICS_HOST=localhost\n'), [])

    def test_memcached_and_prefixed_mail_conventions(self):
        self.assertIn('MEMCACHED_HOST=localhost:11211', self.inspect('CACHE_STORE=memcached\nMEMCACHED_HOST=localhost\n')[0])
        self.assertEqual(self.inspect('CATALOG_MAIL_MAILER=log\nCATALOG_MAIL_HOST=localhost\n'), [])
        self.assertIn('CATALOG_MAIL_HOST=localhost:2525', self.inspect('CATALOG_MAIL_MAILER=smtp\nCATALOG_MAIL_HOST=localhost\nCATALOG_MAIL_PORT=2525\n')[0])

    def test_ipv6_loopback_canonical_target_and_ipv4_mapped_loopback(self):
        refused = self.inspect('CACHE_STORE=redis\nREDIS_HOST="::1"\n')
        self.assertIn('REDIS_HOST=[::1]:6379', refused[0])
        self.assertEqual(self.inspect('CACHE_STORE=redis\nREDIS_HOST="::1"\n', [{'host': '0:0:0:0:0:0:0:1', 'port': 6379}]), [])
        self.assertEqual(len(self.inspect('CACHE_STORE=redis\nREDIS_HOST=::ffff:127.0.0.1\n')), 1)

    def test_quotes_spaces_export_comments_and_crlf(self):
        refused = self.inspect(' export DB_CONNECTION = "mysql"\r\nDB_HOST = " 127.0.0.2 " # local\r\nDB_PORT = \'3308\'\r\n')
        self.assertEqual(len(refused), 1)
        self.assertIn('DB_HOST=127.0.0.2:3308', refused[0])

    def test_every_copied_nested_env_is_checked_including_ignored_and_symlinked_apps(self):
        self.inspect('DB_CONNECTION=sqlite\n', path='.env')
        self.inspect('MAIL_MAILER=smtp\nMAIL_HOST=localhost\nMAIL_PORT=1025\n', path='apps/private/.env')
        external = tempfile.TemporaryDirectory()
        self.addCleanup(external.cleanup)
        Path(external.name, '.env').write_text('DB_CONNECTION=mysql\nDB_HOST=127.0.0.1\nDB_PORT=3308\n')
        self.root.joinpath('linked-app').symlink_to(external.name, target_is_directory=True)
        self.root.joinpath('cycle').symlink_to(self.root, target_is_directory=True)
        self.root.joinpath('.git').mkdir()
        self.root.joinpath('.git/.env').write_text('MAIL_HOST=localhost\n')
        found = guard.inspect_environment(str(self.root), [], [])
        self.assertEqual(len(found), 2)
        self.assertTrue(any('apps/private/.env' in line for line in found))
        self.assertTrue(any('linked-app/.env' in line for line in found))

    def test_node_addresses_and_unspecified_hosts_are_refused_even_inside_allowed_ranges(self):
        for host in ('10.44.0.5', '::ffff:10.44.0.5', '127.34.5.6', 'localhost.', '0.0.0.0', '::'):
            with self.subTest(host=host):
                self.assertEqual(len(self.inspect(f'SERVICE_CONNECTION=mysql\nSERVICE_HOST={host}\nSERVICE_PORT=8080\n')), 1)

    def test_direct_services_require_exact_admission_even_for_fleet_and_wireguard_addresses(self):
        for host in ('10.44.0.9', '203.0.113.7'):
            for stem, port in (('DB', 3306), ('REDIS', 6379), ('MAIL', 2525), ('MEMCACHED', 11211), ('SERVICE', 8080)):
                with self.subTest(host=host, stem=stem):
                    selector = {'DB': 'DB_CONNECTION=mysql', 'REDIS': 'CACHE_STORE=redis', 'MAIL': 'MAIL_MAILER=smtp',
                                'MEMCACHED': 'CACHE_STORE=memcached', 'SERVICE': 'SERVICE_CONNECTION=mysql'}[stem]
                    text = selector + '\n' + f'{stem}_HOST={host}\n{stem}_PORT={port}\n'
                    found = self.inspect(text)
                    self.assertEqual(len(found), 1)
                    self.assertIn('no exact endpoint admitted', found[0])
                    self.assertEqual(self.inspect(text, [{'host': host, 'port': port}]), [])
                    self.assertEqual(len(self.inspect(text, [{'host': host, 'port': port + 1}])), 1)
        self.assertEqual(self.inspect('SERVICE_CONNECTION=mysql\nSERVICE_HOST=example.test.\nSERVICE_PORT=8080\n',
                                      [{'host': 'EXAMPLE.test', 'port': 8080}]), [])
        self.assertEqual(len(self.inspect('SERVICE_CONNECTION=mysql\nSERVICE_HOST=example.test\nSERVICE_PORT=8080\n')), 1)

    def test_bare_stock_hosts_and_nonredis_or_unknown_selectors_do_not_select_a_service(self):
        declarations = 'REDIS_HOST=127.0.0.1\nREDIS_PORT=6379\nMEMCACHED_HOST=127.0.0.1\n'
        self.assertEqual(self.inspect(declarations), [])
        settings = 'CACHE_STORE=file\nSESSION_DRIVER=database\nQUEUE_CONNECTION=sync\n'
        self.assertEqual(self.inspect(settings + declarations), [])
        self.assertEqual(len(self.inspect(settings.replace('CACHE_STORE=file', 'CACHE_STORE=redis') + declarations)), 1)
        for selector in ('CACHE_STORE=${CUSTOM_STORE}', 'SESSION_DRIVER=custom', 'QUEUE_CONNECTION=custom'):
            with self.subTest(selector=selector):
                self.assertEqual(self.inspect(selector + '\n' + declarations), [])


    def test_primary_cache_selector_wins_over_an_unselected_legacy_alias(self):
        declarations = 'REDIS_HOST=127.0.0.1\n'
        self.assertEqual(self.inspect('CACHE_STORE=file\nCACHE_DRIVER=redis\n' + declarations), [])
        self.assertEqual(len(self.inspect('CACHE_DRIVER=redis\n' + declarations)), 1)


    def test_mapped_node_addresses_are_normalized_for_local_detection(self):
        refused = self.inspect('DB_CONNECTION=mysql\nDB_HOST=10.44.0.5\nDB_PORT=3308\n',
                               node_addresses=['::ffff:10.44.0.5'])
        self.assertEqual(len(refused), 1)

    def test_unknown_driver_and_generic_host_port_declarations_do_not_select_a_service(self):
        declarations = 'DB_HOST=localhost\nSERVICE_HOST=localhost\nSERVICE_PORT=1234\nAPP_HOST=localhost\n'
        self.assertEqual(self.inspect(declarations), [])
        self.assertEqual(self.inspect('DB_CONNECTION=custom\n' + declarations), [])
        self.assertEqual(self.inspect('DB_CONNECTION=${PRIVATE}\n' + declarations), [])


    def test_secrets_and_substitutions_are_never_returned(self):
        for value in ('"${DB_PASSWORD}"', '"mysql://user:never-show-this@localhost"',
                      '"localhost never-show-this"', '"`cat secret`"', '"unclosed never-show-this'):
            with self.subTest(value=value):
                refused = self.inspect(f'DB_CONNECTION=mysql\nDB_PASSWORD=never-show-this\nDB_HOST={value}\nDB_PORT=3308\n')
                self.assertEqual(len(refused), 1)
                self.assertNotIn('never-show-this', repr(refused))
                self.assertNotIn('DB_PASSWORD', repr(refused))
        refused = self.inspect('DB_HOST=localhost\nDB_PORT="${DB_PASSWORD}"\nDB_PASSWORD=never-show-this\n')
        self.assertNotIn('never-show-this', repr(refused))
        self.assertNotIn('DB_PASSWORD', repr(refused))

    def test_malformed_private_quoting_cannot_hide_service_keys(self):
        found = self.inspect('PRIVATE="never-show-this\nMAIL_HOST=127.0.0.1\nMAIL_PORT=1025\n')
        self.assertEqual(len(found), 1)
        self.assertIn('.env:1: malformed or unsupported dotenv syntax', found[0])
        self.assertNotIn('never-show-this', repr(found))

    def test_named_env_redis_selectors_keep_the_endpoint_check(self):
        declarations = 'REDIS_HOST=127.0.0.1\n'
        for selector in ('CACHE_STORE=redis', 'SESSION_DRIVER=redis', 'QUEUE_CONNECTION=redis',
                         'BROADCAST_CONNECTION=redis', 'BROADCAST_DRIVER=redis',
                         'DB_CONNECTION=redis', 'REDIS_CLIENT=phpredis', 'REDIS_CLIENT=predis'):
            with self.subTest(selector=selector):
                text = selector + '\n' + declarations
                found = self.inspect(text)
                self.assertEqual(len(found), 1)
                self.assertIn('REDIS_HOST=127.0.0.1:6379', found[0])
                self.assertNotIn('cannot prove Redis is unused', found[0])
                self.assertEqual(self.inspect(text, [{'host': '127.0.0.1', 'port': 6379}]), [])
        for selector in ('BROADCAST_CONNECTION=log\nBROADCAST_DRIVER=redis',
                         'CUSTOM_STORE=redis', 'REDIS_CLIENT=${PRIVATE}'):
            with self.subTest(selector=selector):
                self.assertEqual(self.inspect(selector + '\n' + declarations), [])


    def test_only_selected_database_consumers_add_network_connection_requirements(self):
        base = 'DB_CONNECTION=sqlite\nDB_HOST=127.0.0.1\n'
        for selector in ('SESSION_DRIVER=database\nSESSION_CONNECTION=mysql',
                         'QUEUE_CONNECTION=database\nDB_QUEUE_CONNECTION=pgsql',
                         'CACHE_STORE=database\nDB_CACHE_CONNECTION=mysql',
                         'CACHE_STORE=database\nDB_CACHE_LOCK_CONNECTION=pgsql'):
            with self.subTest(selector=selector):
                found = self.inspect(base + 'DB_PORT=3308\n' + selector + '\n')
                self.assertEqual(len(found), 1)
                self.assertIn('DB_HOST=127.0.0.1:3308', found[0])
        selected = base + 'SESSION_DRIVER=database\nSESSION_CONNECTION=pgsql\n'
        self.assertIn('DB_HOST=127.0.0.1:5432', self.inspect(selected)[0])
        self.assertEqual(self.inspect(base + 'SESSION_DRIVER=file\nSESSION_CONNECTION=mysql\n'), [])
        self.assertEqual(self.inspect(base + 'CACHE_STORE=file\nDB_CACHE_CONNECTION=mysql\n'), [])
        self.assertEqual(self.inspect(base + 'CUSTOM_CONNECTION=reporting\n'), [])
        self.assertEqual(self.inspect(base + 'SESSION_DRIVER=database\nSESSION_CONNECTION=${PRIVATE}\n'), [])


    def test_public_http_publishers_use_the_supplied_proxy_policy(self):
        for key in ('PUSHER', 'REVERB'):
            self.assertEqual(self.inspect(f'BROADCAST_CONNECTION={key.lower()}\n{key}_HOST=api-eu.pusher.com\n{key}_PORT=443\n'), [])
            self.assertEqual(len(self.inspect(f'BROADCAST_CONNECTION={key.lower()}\n{key}_HOST=127.0.0.1\n')), 1)
        self.assertEqual(self.inspect('BROADCAST_CONNECTION=pusher\nPUSHER_HOST=8.8.8.8\nPUSHER_PORT=8443\n'), [])
        self.assertEqual(len(self.inspect('BROADCAST_CONNECTION=pusher\nPUSHER_HOST=8.8.8.8\nPUSHER_PORT=8443\n',
                                         proxy_policy={'private_v4': ['8.8.0.0/16'], 'private_domains': []})), 1)
        for host in ('10.6.0.7', 'private.test', 'api.private.test', 'api.private.test.',
                     'localhost', '10.44.0.5', '::1', '2001:4860:4860::8888'):
            with self.subTest(host=host):
                self.assertEqual(len(self.inspect(f'BROADCAST_CONNECTION=pusher\nPUSHER_HOST={host}\nPUSHER_PORT=443\n')), 1)
        for key in ('DB', 'REDIS', 'MAIL', 'MEMCACHED'):
            with self.subTest(key=key):
                selector = {'DB': 'DB_CONNECTION=mysql', 'REDIS': 'CACHE_STORE=redis',
                            'MAIL': 'MAIL_MAILER=smtp', 'MEMCACHED': 'CACHE_STORE=memcached'}[key]
                self.assertEqual(len(self.inspect(selector + f'\n{key}_HOST=api-eu.pusher.com\n{key}_PORT=443\n{key}_SCHEME=https\n')), 1)
        self.assertEqual(self.inspect('API_HOST=api-eu.pusher.com\nAPI_PORT=443\n'), [])
        self.assertEqual(len(self.inspect('BROADCAST_CONNECTION=pusher\nPUSHER_HOST=api-eu.pusher.com\nPUSHER_PORT=443\n', proxy_policy=None)), 1)

    def test_known_listeners_and_browser_settings_are_not_outbound_services(self):
        for stem in ('REVERB_SERVER', 'SERVER', 'PI_SERVER', 'VITE_PUSHER', 'VITE_REVERB'):
            with self.subTest(stem=stem):
                self.assertEqual(self.inspect(f'{stem}_HOST=0.0.0.0\n{stem}_PORT=8080\n'), [])
        for stem in ('REVERB', 'PUSHER', 'DATABASE_SERVER'):
            with self.subTest(stem=stem):
                selector = f'BROADCAST_CONNECTION={stem.lower()}' if stem in ('REVERB', 'PUSHER') else 'DATABASE_SERVER_CONNECTION=mysql'
                self.assertEqual(len(self.inspect(selector + f'\n{stem}_HOST=0.0.0.0\n{stem}_PORT=8080\n')), 1)

    def test_active_mail_requires_explicit_port_instead_of_guessing_the_app_default(self):
        text = 'MAIL_MAILER=smtp\nMAIL_HOST=127.0.0.1\n'
        for port in (25, 2525):
            with self.subTest(port=port):
                found = self.inspect(text, [{'host': '127.0.0.1', 'port': port}])
                self.assertEqual(len(found), 1)
                self.assertIn('[unknown port]', found[0])
                self.assertIn('explicit mail port is required', found[0])
                self.assertEqual(self.inspect(text + f'MAIL_PORT={port}\n', [{'host': '127.0.0.1', 'port': port}]), [])

    def test_quoted_keys_and_escape_parity_reach_the_endpoint_check(self):
        text = 'DB_CONNECTION=mysql\nPRIVATE="path' + '\\' * 2 + '"\n"DB_HOST"=127.0.0.1\nDB_PORT=3308\n'
        found = self.inspect(text)
        self.assertEqual(len(found), 1)
        self.assertIn('DB_HOST=127.0.0.1:3308', found[0])
        self.assertNotIn('path', repr(found))

    def test_unreadable_syntax_reports_only_the_file_and_line(self):
        found = self.inspect('APP_NAME=app\nPRIVATE="never-show-this\\q"\nDB_HOST=127.0.0.1\n', path='apps/one/.env')
        self.assertEqual(len(found), 1)
        self.assertIn('apps/one/.env:2:', found[0])
        self.assertNotIn('PRIVATE', repr(found))
        self.assertNotIn('never-show-this', repr(found))

    def test_projected_target_address_does_not_cover_a_different_recorded_endpoint(self):
        targets = [{'host': '10.44.0.9', 'port': 3308}]
        found = self.inspect('DB_CONNECTION=mysql\nDB_HOST=192.168.50.9\nDB_PORT=3308\n', targets)
        self.assertEqual(len(found), 1)
        self.assertIn('DB_HOST=192.168.50.9:3308', found[0])
        self.assertEqual(self.inspect('DB_CONNECTION=mysql\nDB_HOST=10.44.0.9\nDB_PORT=3308\n', targets), [])

    def test_mixed_network_selections_cannot_use_only_the_default_drivers_port(self):
        for default, other in (('mysql', 'pgsql'), ('pgsql', 'mysql')):
            with self.subTest(default=default):
                text = f'DB_CONNECTION={default}\nSESSION_DRIVER=database\nSESSION_CONNECTION={other}\nDB_HOST=10.44.0.9\n'
                targets = [{'host': '10.44.0.9', 'port': port} for port in (3306, 5432)]
                for admitted in (targets[:1], targets):
                    found = self.inspect(text, admitted)
                    self.assertEqual(len(found), 1)
                    self.assertIn('[unknown port]', found[0])
                    self.assertIn('ambiguous', found[0])
                self.assertEqual(self.inspect(text + 'DB_PORT=3308\n', [{'host': '10.44.0.9', 'port': 3308}]), [])
        self.assertEqual(self.inspect('DB_CONNECTION=mysql\nSESSION_DRIVER=database\nSESSION_CONNECTION=mysql\nDB_HOST=10.44.0.9\n',
                                      [{'host': '10.44.0.9', 'port': 3306}]), [])

    def test_explicit_connection_evidence_overrides_listener_exemptions(self):
        for stem in ('SERVER', 'REVERB_SERVER', 'PI_SERVER', 'VITE_PUSHER', 'VITE_REVERB'):
            with self.subTest(stem=stem):
                text = f'{stem}_CONNECTION=mysql\n{stem}_HOST=10.44.0.9\n{stem}_PORT=3308\n'
                self.assertEqual(len(self.inspect(text)), 1)
                self.assertEqual(self.inspect(text, [{'host': '10.44.0.9', 'port': 3308}]), [])

    def test_traversal_stat_and_read_errors_return_safe_preflight_refusals(self):
        self.inspect('DB_CONNECTION=sqlite\n')
        denied = str(self.root / 'apps' / 'unreadable')

        def denied_walk(*args, **kwargs):
            kwargs['onerror'](PermissionError(13, 'private-exception-secret', denied))

        with patch.object(guard.os, 'walk', side_effect=denied_walk):
            found = guard.inspect_environment(str(self.root), [], [])
        self.assertEqual(found, [f'apps/unreadable: cannot inspect copied environments safely. {guard.RECOVERY}'])
        with patch.object(guard.os, 'walk', return_value=iter([(denied, [], [])])):
            found = guard.inspect_environment(str(self.root), [], [])
        self.assertEqual(found, [f'apps/unreadable: cannot inspect copied environments safely. {guard.RECOVERY}'])
        with patch('builtins.open', side_effect=PermissionError(13, 'private-exception-secret', str(self.root / '.env'))):
            found = guard.inspect_environment(str(self.root), [], [])
        self.assertEqual(found, [f'.env: cannot read .env safely. {guard.RECOVERY}'])
        self.assertNotIn('private-exception-secret', repr(found))
        found = guard.inspect_environment(str(self.root / 'missing'), [], [])
        self.assertEqual(found, [f'.: cannot inspect copied environments safely. {guard.RECOVERY}'])

    def test_multiline_private_values_do_not_forge_service_keys(self):
        self.assertEqual(self.inspect('PRIVATE="first\nMAIL_HOST=127.0.0.1\nMAIL_PORT=1025\nlast"\n'), [])


if __name__ == '__main__':
    unittest.main()
