"""The actual Node-side parser and endpoint policy; no Ansible installation is needed."""
import importlib.util
import json
import tempfile
import unittest
from pathlib import Path
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
                                                                      'private_domains': ['orbit', 'private.test']}),
                                         development_mail=options.get('development_mail'), node_services=options.get('node_services'))


    def test_loopback_requires_exact_attached_key_pair_and_port(self):
        target = {'host_env_key': 'DB_HOST', 'port_env_key': 'DB_PORT', 'port': 3308}
        for host in ('127.0.0.1', 'localhost'):
            text = f'DB_CONNECTION=mysql\nDB_HOST={host}\nDB_PORT=3308\n'
            self.assertEqual(self.inspect(text, node_services=[target]), [])
            self.assertEqual(len(self.inspect(text)), 1)
            for altered in (dict(target, port=3309), dict(target, host_env_key='OTHER_HOST'), dict(target, port_env_key='OTHER_PORT')):
                self.assertEqual(len(self.inspect(text, node_services=[altered])), 1)
        self.assertEqual(len(self.inspect('DB_CONNECTION=mysql\nDB_HOST=::1\nDB_PORT=3308', node_services=[target])), 1)
        self.assertEqual(len(self.inspect('DB_CONNECTION=mysql\nDB_HOST=127.0.0.2\nDB_PORT=3308', node_services=[target])), 1)

    def test_database_urls_refuse_without_exposing_credentials_or_bypassing_attached_keys(self):
        target = {'host_env_key': 'DB_HOST', 'port_env_key': 'DB_PORT', 'port': 3308}
        for key in ('DB_URL', 'DATABASE_URL', 'OTHER_DB_URL'):
            for prefix in ('', 'DB_CONNECTION=mysql\nDB_HOST=127.0.0.1\nDB_PORT=3308\n'):
                text = ('OTHER_DB_CONNECTION=mysql\n' if key == 'OTHER_DB_URL' else '') + prefix + key + '=mysql://fixture:private-url-password@127.0.0.1:3309/app\n'
                errors = self.inspect(text, node_services=[target])
                self.assertTrue(any(key in error and 'URL' in error for error in errors))
                self.assertNotIn('private-url-password', str(errors))
                self.assertNotIn('mysql://', str(errors))
        self.assertEqual(self.inspect('DB_CONNECTION=sqlite\nDB_URL=sqlite:///fixture.sqlite\n'), [])

    def test_development_mail_satisfies_only_the_ipv4_forwarded_endpoints(self):
        endpoint = {'host': '10.253.0.1', 'port': 2025, 'docker': True}
        for host in ('127.0.0.1', 'localhost'):
            for port in (1025, 2525):
                for quote in ('', "'", '"'):
                    with self.subTest(host=host, port=port, quote=quote):
                        text = f'MAIL_MAILER={quote}smtp{quote}\nMAIL_HOST={quote}{host}{quote}\nMAIL_PORT={quote}{port}{quote}\n'
                        self.assertEqual(self.inspect(text, development_mail=endpoint), [])
                        refused = self.inspect(text)
                        self.assertEqual(len(refused), 1)
                        self.assertIn('no eligible main Mailpit on its Node', refused[0])
                        self.assertIn(f'no mail forwarder on {host}:{port}', refused[0])

        for quote in ("'", '"'):
            for host in ('127.0.0.1', 'localhost'):
                for port in ('1025', '2525'):
                    for altered in (' ' + host, host + ' ', '\t' + host, host + '\t',
                                    'LOCALHOST', 'LocalHost', 'localhost.', '[127.0.0.1]'):
                        with self.subTest(host=altered, port=port, quote=quote):
                            text = f'MAIL_MAILER=smtp\nMAIL_HOST={quote}{altered}{quote}\nMAIL_PORT={quote}{port}{quote}\n'
                            refused = self.inspect(text, development_mail=endpoint)
                            self.assertEqual(len(refused), 1)
                            self.assertIn('the host belongs to the Node or container loopback and has no exact attached target', refused[0])
                    for altered in (' ' + port, port + ' ', '\t' + port, port + '\t', '0' + port):
                        with self.subTest(host=host, port=altered, quote=quote):
                            text = f'MAIL_MAILER=smtp\nMAIL_HOST={quote}{host}{quote}\nMAIL_PORT={quote}{altered}{quote}\n'
                            refused = self.inspect(text, development_mail=endpoint)
                            self.assertEqual(len(refused), 1)
                            self.assertTrue('the service port is missing or is not a literal port' in refused[0]
                                            or 'the host belongs to the Node or container loopback' in refused[0])
        for quote in ('', "'", '"'):
            text = f' MAIL_MAILER = smtp \n MAIL_HOST = {quote}127.0.0.1{quote} \n MAIL_PORT = {quote}1025{quote} \n'
            self.assertEqual(self.inspect(text, development_mail=endpoint), [])

    def test_ipv6_mail_is_refused_even_with_the_development_mail_path(self):
        for port in (1025, 2525):
            text = f'MAIL_MAILER=smtp\nMAIL_HOST="::1"\nMAIL_PORT="{port}"\n'
            refused = self.inspect(text, development_mail={'host': '10.253.0.1', 'port': 1025})
            self.assertEqual(len(refused), 1)
            self.assertIn('listens only on 127.0.0.1, so ::1 cannot reach it', refused[0])
            self.assertIn('no eligible main Mailpit', self.inspect(text)[0])

    def test_mail_outside_the_forwarded_tuple_keeps_the_original_verdict(self):
        endpoint = {'host': '10.253.0.1', 'port': 1025}
        for host, port in [('127.0.0.1', 587), ('localhost', 587), ('::1', 587),
                           ('127.0.0.2', 1025), ('mail.example.test', 1025), ('127.0.0.1:1025', 1025)]:
            text = f'MAIL_MAILER=smtp\nMAIL_HOST="{host}"\nMAIL_PORT="{port}"\n'
            self.assertEqual(self.inspect(text, development_mail=endpoint), self.inspect(text))
            self.assertEqual(self.inspect(text, [{'host': host, 'port': port}], development_mail=endpoint),
                             self.inspect(text, [{'host': host, 'port': port}]))
        text = 'MAIL_MAILER=smtp\nMAIL_URL=smtp://private@example.test:1025\n'
        self.assertEqual(self.inspect(text, development_mail=endpoint), self.inspect(text))

    def test_log_mail_is_unselected_with_or_without_an_admitted_mail_path(self):
        for quote in ('', "'", '"'):
            text = f'MAIL_MAILER={quote}log{quote}\nMAIL_HOST="127.0.0.1"\nMAIL_PORT="1025"\n'
            for endpoint in (None, {'host': '10.253.0.1', 'port': 1025}):
                self.assertEqual(self.inspect(text, development_mail=endpoint), [])

    def test_other_services_keep_the_main_refusal_bytes(self):
        fixture = json.loads((Path(__file__).parent / 'fixtures/migrate-env-services.json').read_text())
        for endpoint in (None, {'host': '10.253.0.1', 'port': 1025}):
            self.assertEqual(self.inspect(fixture['env'], development_mail=endpoint), fixture['refusals'])

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


    def test_current_stock_laravel_environment_passes_without_attached_targets(self):
        stock = Path(__file__).parent / 'fixtures/laravel-stock.env.example'
        self.assertEqual(self.inspect(stock.read_text()), [])

    def test_real53_unused_redis_client_and_endpoint_declarations_do_not_select_redis(self):
        text = ('CACHE_STORE=database\nSESSION_DRIVER=database\nQUEUE_CONNECTION=database\n'
                'BROADCAST_CONNECTION=log\nMAIL_MAILER=log\nDB_CONNECTION=sqlite\n'
                'REDIS_CLIENT=phpredis\nREDIS_HOST=127.0.0.1\nREDIS_PORT=6379\nREDIS_PASSWORD=\n')
        self.assertEqual(self.inspect(text), [])
        selected = text.replace('CACHE_STORE=database', 'CACHE_STORE=redis')
        self.assertIn('REDIS_HOST=127.0.0.1:6379', self.inspect(selected)[0])
        self.assertEqual(self.inspect(selected, [{'host': '127.0.0.1', 'port': 6379}]), [])
        self.assertEqual(len(self.inspect(selected, [{'host': '127.0.0.1', 'port': 6380}])), 1)

    def test_client_libraries_and_endpoint_settings_alone_never_select_services(self):
        for client in ('phpredis', 'predis'):
            with self.subTest(client=client):
                text = (f'REDIS_CLIENT={client}\nREDIS_HOST=127.0.0.1\nREDIS_PORT=6379\n'
                        'REDIS_PASSWORD=private-password\nREDIS_URL=redis://private-password@localhost:6379\n'
                        'MEMCACHED_HOST=127.0.0.1\nMEMCACHED_PORT=11211\nMEMCACHED_PASSWORD=private-password\n')
                self.assertEqual(self.inspect(text), [])

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
        for selector in ('CACHE_STORE=redis', 'CACHE_DRIVER=redis', 'SESSION_DRIVER=redis', 'QUEUE_CONNECTION=redis',
                         'BROADCAST_CONNECTION=redis', 'BROADCAST_DRIVER=redis',
                         'DB_CONNECTION=redis'):
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


"""Fast unprivileged fixtures of the exact shipped Node-service converger."""
import importlib.util
import os
import sys
import unittest
from pathlib import Path

import yaml

ROOT = Path(__file__).resolve().parents[1]
SPEC = importlib.util.spec_from_file_location('targets', ROOT / 'ansible/instance_node_services.py')
TARGETS = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(TARGETS)


class NodeServiceTest(unittest.TestCase):
    def setUp(self):
        temporary = tempfile.TemporaryDirectory()
        self.addCleanup(temporary.cleanup)
        self.root = Path(temporary.name)
        self.guest = self.root / 'guest'
        proxy = self.guest / 'usr/lib/systemd/systemd-socket-proxyd'
        proxy.parent.mkdir(parents=True)
        proxy.touch(mode=0o755)
        self.target = {'id': 7, 'process_id': 19, 'process_name': 'fixture-db', 'host': '10.253.0.1', 'port': 3308, 'docker': True,
                       'host_env_key': 'DB_HOST', 'port_env_key': 'DB_PORT'}
        self.container = {'name': 'instance-7', 'project': 'orbit-instances', 'acl': 'instance-7',
                          'network': 'orbitinst0', 'bridge_cidr': '10.253.0.0/24',
                          'bridge_admitted_at': 1800000000000000, 'node_services': [self.target]}
        self.acl = {'description': 'fixture', 'ingress': [{'action': 'allow'}], 'egress': [{'action': 'reject'}]}
        self.calls = []
        self.active = set()
        self.enabled = set()
        self.failure = None
        self.power = 'Running'
        self.foreign = False
        self.reachable = True
        self.proxy_failed = False
        self.connection_open = False
        self.fragments = {}
        self.dropins = {}

    def guest_run(self, argv, content=None):
        self.calls.append(argv)
        if argv[:2] == ['systemctl', 'show']:
            return '\n\n'.join('Id=' + name + '\nActiveState=' +
                                ('failed' if self.proxy_failed and name.endswith('.service') else 'active' if name in self.active else 'inactive')
                                + '\nSubState=running\nMainPID=' + ('1234' if name.endswith('.service') and name in self.active else '0')
                                + '\nJob=\nFragmentPath=' + self.fragments.get(name, '')
                                + '\nDropInPaths=' + self.dropins.get(name, '')
                                + '\nUnitFileState=' + ('enabled' if name in self.enabled else 'disabled')
                                + '\nListen=127.0.0.1:' + name.split('-')[-1].split('.')[0] + ' (Stream)'
                                for name in argv[argv.index('--') + 1:])
        if argv[0] == 'ss':
            return 'LISTEN 0 128 0.0.0.0:3308 0.0.0.0:*\n' if self.foreign else ''
        if argv[:2] == ['systemctl', 'enable']:
            self.active.update(argv[4:])
            self.enabled.update(argv[4:])
        elif argv[:2] == ['systemctl', 'start']:
            if not self.proxy_failed:
                self.active.update(argv[3:])
        elif argv[:2] == ['systemctl', 'disable']:

            self.active.difference_update(argv[4:])
            self.enabled.difference_update(argv[4:])
        elif argv[:2] == ['systemctl', 'stop']:
            self.active.difference_update(argv[3:])
        elif argv[:2] not in ( ['systemctl', 'try-restart'], ['systemctl', 'daemon-reload'], ['systemctl', 'reset-failed']):
            self.fail(str(argv))
        return ''

    def node_run(self, argv, content=None, public_guest=False):
        self.calls.append(argv[:6])
        if argv[:2] == ['incus', 'query']:
            return json.dumps({'status': self.power} if '/instances/' in argv[-1] else self.acl)
        if argv[:4] == ['incus', 'network', 'acl', 'edit']:
            self.assertEqual(argv[-2:], ['--project', 'default'])
            self.acl = json.loads(content)
            return ''
        if argv[0] == 'python3':
            self.assertIn('--intent', argv)
            self.assertNotIn('--reconcile', argv)
            self.assertEqual(json.loads(content)['endpoints'], [{'host': '10.253.0.1', 'port': 3308, 'docker': True}])
            if self.failure == 'network':
                raise RuntimeError('instance.bridge_busy')
            return ''
        self.assertEqual(argv[:2], ['incus', 'exec'])
        self.assertNotIn('/srv/workspace', argv)
        real_stat = Path.stat
        real_atomic = TARGETS.atomic

        def owned(path, *args, **kwargs):
            values = list(real_stat(path, *args, **kwargs))
            values[4] = values[5] = 0
            return os.stat_result(values)

        def chown(path, uid, gid, **kwargs):
            self.assertEqual((uid, gid), (0, 0))
            self.assertFalse(kwargs['follow_symlinks'])

        def write(path, value, mode):
            if path.name == self.failure:
                raise RuntimeError('fixture failed write')
            self.assertEqual(mode, 0o644)
            real_atomic(path, value, mode)

        fixture = self

        class Connection:
            def __enter__(self):
                fixture.connection_open = True
                return self

            def __exit__(self, *_):
                fixture.connection_open = False

            def getsockname(self):
                return ('127.0.0.1', 40000)

        def connect(address, timeout):
            self.assertEqual(timeout, 2)
            self.assertEqual(address, ('127.0.0.1', 3308))
            return Connection()

        def sockets(pid, root):
            self.assertEqual(pid, 1234)
            self.assertEqual(root, self.guest)
            if not self.connection_open:
                return {}
            rows = {'10': (('127.0.0.1', 3308), ('127.0.0.1', 40000))}
            if self.reachable:
                rows['11'] = (('10.253.0.2', 50000), ('10.253.0.1', 3308))
            return rows

        with patch.object(Path, 'stat', owned), patch.object(TARGETS.os, 'chown', side_effect=chown), patch.object(TARGETS, 'atomic', side_effect=write), \
                patch.object(TARGETS, 'tcp_sockets', side_effect=sockets):
            return json.dumps(TARGETS.guest(argv[-1], json.loads(content), self.guest, self.guest_run, connect))

    def apply(self):
        return TARGETS.apply(self.container, {'node_services': 'shipped source', 'bridge': 'shipped bridge'}, self.node_run, self.root / 'state')

    def test_exact_network_first_private_units_no_workspace_and_repeat(self):
        self.assertEqual(self.apply(), {'status': 'applied', 'ports': [3308]})
        rule = self.acl['egress'][-1]
        self.assertEqual(rule, {'action': 'allow', 'destination': '10.253.0.1/32', 'protocol': 'tcp',
                                'destination_port': '3308', 'state': 'enabled', 'description': 'Orbit Node service:3308'})
        network = next(i for i, argv in enumerate(self.calls) if argv[:4] == ['incus', 'network', 'acl', 'edit'])
        self.assertLess(network, self.calls.index(['systemctl', 'daemon-reload']))
        self.assertEqual(self.acl['egress'][0], {'action': 'reject'})
        for path in (self.guest / 'etc/systemd/system').iterdir():
            self.assertEqual(path.stat().st_mode & 0o777, 0o644)
            self.assertIn(TARGETS.MARKER, path.read_text())
        service = (self.guest / 'etc/systemd/system/orbit-node-service-3308.service').read_text()
        self.assertIn('--connections-max=256 10.253.0.1:3308', service)
        self.assertIn('DynamicUser=yes', service)
        self.assertEqual((self.guest / 'etc/systemd/system/orbit-node-service-3308.socket').read_text().count('ListenStream=127.0.0.1:3308'), 1)
        self.calls.clear()
        self.assertEqual(self.apply(), {'status': 'unchanged', 'ports': [3308]})
        self.assertFalse(any(argv[:2] in (['systemctl', 'enable'], ['systemctl', 'try-restart'], ['systemctl', 'daemon-reload']) for argv in self.calls))
        self.assertFalse((self.guest / 'srv').exists())

    def test_stopped_conflict_and_failed_network_do_not_write_units(self):
        self.power = 'Stopped'
        with self.assertRaisesRegex(RuntimeError, 'process.target_inactive'):
            self.apply()
        self.power = 'Running'
        self.foreign = True
        with self.assertRaisesRegex(RuntimeError, 'instance.node_service_port_in_use'):
            self.apply()
        self.foreign = False
        self.failure = 'network'
        with self.assertRaisesRegex(RuntimeError, 'instance.bridge_busy'):
            self.apply()
        self.assertFalse((self.guest / 'etc').exists())

    def test_partial_write_retries_activation_and_disabled_active_socket_is_repaired(self):
        self.failure = 'orbit-node-service-3308.socket'
        with self.assertRaisesRegex(RuntimeError, 'fixture failed write'):
            self.apply()
        self.assertTrue((self.root / 'state/instance-7/activation.json').exists())
        self.failure = None
        self.assertEqual(self.apply()['status'], 'applied')
        self.enabled.clear()
        self.assertEqual(self.apply()['status'], 'applied')
        self.assertEqual(self.enabled, {'orbit-node-service-3308.socket'})
        self.assertEqual(self.apply()['status'], 'unchanged')

    def test_failed_target_is_fatal_and_retry_keeps_pending(self):
        self.reachable = False
        with self.assertRaisesRegex(RuntimeError, 'instance.node_service_unreachable'):
            self.apply()
        self.assertTrue((self.root / 'state/instance-7/activation.json').exists())
        self.reachable = True
        self.proxy_failed = False
        self.connection_open = False
        self.fragments = {}
        self.dropins = {}
        self.assertEqual(self.apply()['status'], 'applied')

    def test_detach_keeps_shared_port_then_removes_only_owned_access_without_dnat(self):
        self.apply()
        self.container.update(node_service_withdraw=True, node_service_removed_ports=[3308])
        self.failure = 'network'  # withdrawal must not resolve Docker or unrelated targets.
        self.assertEqual(self.apply()['status'], 'unchanged')  # another attachment still uses the port.
        self.container['node_services'] = []
        self.calls.clear()
        self.assertEqual(self.apply(), {'status': 'applied', 'ports': []})
        self.assertEqual(self.acl['egress'], [{'action': 'reject'}])
        self.assertFalse(list((self.guest / 'etc/systemd/system').iterdir()))
        self.assertFalse(self.active)
        self.assertEqual(self.apply()['status'], 'unchanged')

    def test_kernel_probe_reads_only_established_sockets_owned_by_the_proxy(self):
        descriptors = self.guest / 'proc/1234/fd'
        descriptors.mkdir(parents=True)
        (descriptors / '3').symlink_to('socket:[10]')
        (descriptors / '4').symlink_to('socket:[11]')
        table = self.guest / 'proc/net/tcp'
        table.parent.mkdir(parents=True)
        table.write_text('header\n'
                         '0: 0100007F:0CEC 0100007F:9C40 01 0:0 0:0 0 0 0 10\n'
                         '1: 0200FD0A:C350 0100FD0A:0CEC 01 0:0 0:0 0 0 0 11\n'
                         '2: 0100007F:0CEC 00000000:0000 0A 0:0 0:0 0 0 0 10\n'
                         '3: 0200FD0A:C351 0100FD0A:0CEC 01 0:0 0:0 0 0 0 999\n')
        self.assertEqual(TARGETS.tcp_sockets(1234, self.guest), {
            '10': (('127.0.0.1', 3308), ('127.0.0.1', 40000)),
            '11': (('10.253.0.2', 50000), ('10.253.0.1', 3308))})

    def test_failed_proxy_refuses_before_quiesce_with_public_identity_and_retains_pending(self):
        self.proxy_failed = True
        self.reachable = True  # The backend and PID 1 listening socket are not proof of a working proxy.
        with self.assertRaises(TARGETS.TargetFailure) as error:
            self.apply()
        self.assertEqual(str(error.exception), 'instance.node_service_unreachable')
        self.assertEqual(error.exception.evidence()['node_service_target'],
                         {'id': 7, 'process_id': 19, 'process_name': 'fixture-db', 'port': 3308})
        self.assertTrue((self.root / 'state/instance-7/activation.json').exists())
        migrate = yaml.safe_load((ROOT / 'workflows/workflow-operation-instance-migrate.yaml').read_text())
        steps = {step['name']: step for job in migrate['jobs'] for step in job['steps']}
        self.assertIn({'step': 'container', 'condition': {'type': 'succeeded'}}, steps['node-runtime']['dependsOn'])
        self.assertIn({'step': 'node-runtime', 'condition': {'type': 'succeeded'}}, steps['quiesce']['dependsOn'])
        self.assertFalse(any('quiesce' in command for command in self.calls))
        self.proxy_failed = False
        self.assertEqual(self.apply()['status'], 'applied')

    def test_effective_vendor_runtime_fragments_and_dropins_are_not_shadowed(self):
        name = 'orbit-node-service-3308.service'
        for prefix in ('/usr/lib/systemd/system/', '/run/systemd/system/'):
            self.fragments[name] = prefix + name
            with self.assertRaisesRegex(TARGETS.TargetFailure, 'instance.node_service_unit_conflict'):
                self.apply()
            self.assertFalse((self.guest / 'etc').exists())
        self.fragments.clear()
        for name in ('orbit-node-service-3308.socket', 'orbit-node-service-3308.service'):
            self.dropins[name] = '/run/systemd/system/' + name + '.d/override.conf'
            with self.assertRaisesRegex(TARGETS.TargetFailure, 'instance.node_service_unit_conflict'):
                self.apply()
            self.dropins.clear()
        self.assertFalse(any(command[:4] == ['incus', 'network', 'acl', 'edit'] for command in self.calls))

    def test_exact_incus_type_wide_dropin_is_allowed_but_modified_content_refuses(self):
        path = self.guest / TARGETS.LXC_DROPIN.lstrip('/')
        path.parent.mkdir(parents=True)
        path.write_text(TARGETS.LXC_CONTENT)
        self.dropins['orbit-node-service-3308.service'] = TARGETS.LXC_DROPIN
        real_stat = Path.stat

        def owned(path, *args, **kwargs):
            values = list(real_stat(path, *args, **kwargs))
            values[4] = values[5] = 0
            return os.stat_result(values)

        with patch.object(Path, 'stat', owned):
            self.assertEqual(self.apply()['status'], 'applied')
            path.write_text(TARGETS.LXC_CONTENT + 'ExecStart=/foreign\n')
            with self.assertRaisesRegex(TARGETS.TargetFailure, 'instance.node_service_unit_conflict'):
                self.apply()

    def test_public_failure_boundary_keeps_only_allowlisted_codes(self):
        for code in ('instance.host_unavailable', 'process.target_inactive', 'instance.node_service_unreachable', 'private-output'):
            with self.subTest(code=code), self.assertRaises(RuntimeError) as error:
                TARGETS.command([sys.executable, '-c', 'raise SystemExit(' + repr(code) + ')'], public_guest=True)
            self.assertEqual(str(error.exception), code if code in TARGETS.PUBLIC else 'instance.node_service_apply_failed')

    def test_real_ansible_phase_accepts_minimal_intent_without_workspace_fields(self):
        import shutil
        import subprocess
        import sys
        workflow = yaml.safe_load((ROOT / 'workflows/workflow-operation-instance-node-service.yaml').read_text())
        phase = workflow['jobs'][0]['steps'][1]['task']['inputs']['run']
        self.assertIn('--tags node-service \"$ORBIT_ANSIBLE/instance-container.yml\"', phase)
        executable = shutil.which('ansible-playbook')
        self.assertIsNotNone(executable, 'Pinned Ansible is required')
        directory = self.root / 'phase'
        directory.mkdir()
        for file in ('instance_node_services.py', 'instance_bridge.py'):
            shutil.copyfile(ROOT / 'ansible' / file, directory / file)
        self.container['node_services'] = []
        play = [{'hosts': 'localhost', 'connection': 'local', 'gather_facts': False,
                 'vars': {'container': self.container, 'phase': 'node-service', 'ansible_python_interpreter': sys.executable},
                 'tasks': [{'name': 'Run the dedicated phase', 'ansible.builtin.import_tasks': str(ROOT / 'ansible/tasks/instance-container-phase.yml')},
                           {'name': 'Require the unchanged public result', 'tags': ['always'],
                            'ansible.builtin.assert': {'that': ["node_services_result == {'status': 'unchanged', 'ports': []}"]}}]}]
        path = directory / 'phase.yml'
        path.write_text(yaml.safe_dump(play))
        result = subprocess.run([executable, '-i', 'localhost,', '--tags', 'node-service', str(path)],
                                check=False, text=True, capture_output=True, timeout=15,
                                env=dict(os.environ, ANSIBLE_STDOUT_CALLBACK='default', ANSIBLE_NOCOLOR='1'))
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertIn('changed=0', result.stdout)
        self.assertNotIn('config:clear', result.stdout)

    def test_real_create_and_migrate_failure_receipts_keep_target_and_block_later_work(self):
        import shutil
        import subprocess
        target = next(task for task in yaml.safe_load((ROOT / 'ansible/tasks/instance-container-phase.yml').read_text())
                      if task['name'] == 'Prepare attached Node services before application work')
        identity = {'id': 7, 'process_id': 19, 'process_name': 'fixture-db', 'port': 3308}
        evidence = {'error_code': 'instance.node_service_unreachable', 'node_service_target': identity}
        executable = shutil.which('ansible-playbook')
        self.assertIsNotNone(executable)
        for kind in ('create', 'migrate'):
            with self.subTest(kind=kind):
                directory = self.root / kind
                directory.mkdir()
                # Only the Node helper is a stand-in. Evaluate the actual phase and failure receipt tasks.
                (directory / 'instance_node_services.py').write_text(
                    'import sys; print(' + repr(json.dumps(evidence)) + '); '
                    'raise SystemExit("instance.node_service_unreachable")')
                (directory / 'instance_bridge.py').write_text('')
                playbook = 'instance-container.yml' if kind == 'create' else 'instance-migrate.yml'
                play = yaml.safe_load((ROOT / 'ansible' / playbook).read_text())[1]
                rescue = next(task for task in play['tasks'][0]['rescue'] if task['name'] == 'Write the failure')
                sentinel = directory / 'quiesce-or-workspace'
                fixture = [{'hosts': 'localhost', 'connection': 'local', 'gather_facts': False,
                            'vars': {'container': self.container, 'phase': 'container',
                                     'ansible_python_interpreter': sys.executable,
                                     'migrate_preflight_passed': True},
                            'tasks': [{'name': 'Run the production target phase and receipt',
                                       'block': [target, {'name': 'Later workspace or quiesce',
                                                         'ansible.builtin.copy': {'content': '', 'dest': str(sentinel), 'mode': '0600'}}],
                                       'rescue': [rescue]}]}]
                path = directory / 'proof.yml'
                path.write_text(yaml.safe_dump(fixture))
                result = subprocess.run([executable, '-i', 'localhost,', str(path)], check=False,
                                        text=True, capture_output=True, timeout=15,
                                        env=dict(os.environ, ORBIT_RUN=str(directory),
                                                 ANSIBLE_STDOUT_CALLBACK='default', ANSIBLE_NOCOLOR='1'))
                self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
                receipt = json.loads((directory / 'result.json').read_text())
                self.assertEqual(receipt['error_code'], evidence['error_code'])
                if kind == 'create':
                    self.assertEqual(receipt['node_service_target'], identity)
                else:
                    self.assertEqual(json.loads(receipt['error_message'].split(': ', 1)[1]), identity)
                    self.assertFalse(receipt['preflight_refused'])
                self.assertFalse(sentinel.exists())
                self.assertNotIn('PRIVATE', json.dumps(receipt))

    def test_no_attachment_is_noop_and_phase_before_application_work(self):
        self.container['node_services'] = []
        self.assertEqual(self.apply(), {'status': 'unchanged', 'ports': []})
        self.assertEqual(self.calls, [])
        tasks = yaml.safe_load((ROOT / 'ansible/tasks/instance-container-phase.yml').read_text())
        target = next(task for task in tasks if task.get('name') == 'Prepare attached Node services before application work')
        self.assertEqual(target['block'][0]['name'], 'Apply and probe attached Node services')
        self.assertIn('container', target['tags'])
        migrate = (ROOT / 'ansible/tasks/instance-migrate-phase.yml').read_text()
        self.assertLess(migrate.index('Prepare the move\'s container'), migrate.index('Check the way to each database server'))


if __name__ == '__main__':
    unittest.main()
