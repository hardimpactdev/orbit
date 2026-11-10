"""The actual Node-side parser and endpoint policy; no Ansible installation is needed."""
import importlib.util
from pathlib import Path
import tempfile
import unittest

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
                                         options.get('fleet_addresses', ['10.44.0.5', '203.0.113.7']),
                                         options.get('wireguard_ranges', ['10.44.0.0/24']))

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
        self.assertIn('MEMCACHED_HOST=localhost:11211', self.inspect('MEMCACHED_HOST=localhost\n')[0])
        self.assertEqual(self.inspect('CATALOG_MAIL_MAILER=log\nCATALOG_MAIL_HOST=localhost\n'), [])
        self.assertIn('CATALOG_MAIL_HOST=localhost:25', self.inspect('CATALOG_MAIL_MAILER=smtp\nCATALOG_MAIL_HOST=localhost\n')[0])

    def test_ipv6_loopback_canonical_target_and_ipv4_mapped_loopback(self):
        refused = self.inspect('REDIS_HOST="::1"\n')
        self.assertIn('REDIS_HOST=[::1]:6379', refused[0])
        self.assertEqual(self.inspect('REDIS_HOST="::1"\n', [{'host': '0:0:0:0:0:0:0:1', 'port': 6379}]), [])
        self.assertEqual(len(self.inspect('REDIS_HOST=::ffff:127.0.0.1\n')), 1)

    def test_quotes_spaces_export_comments_and_crlf(self):
        refused = self.inspect(' export DB_CONNECTION = "mysql"\r\nDB_HOST = " 127.0.0.2 " # local\r\nDB_PORT = \'3308\'\r\n')
        self.assertEqual(len(refused), 1)
        self.assertIn('DB_HOST=127.0.0.2:3308', refused[0])

    def test_every_copied_nested_env_is_checked_including_ignored_and_symlinked_apps(self):
        self.inspect('DB_CONNECTION=sqlite\n', path='.env')
        self.inspect('MAIL_HOST=localhost\nMAIL_PORT=1025\n', path='apps/private/.env')
        external = tempfile.TemporaryDirectory()
        self.addCleanup(external.cleanup)
        Path(external.name, '.env').write_text('DB_HOST=127.0.0.1\nDB_PORT=3308\n')
        self.root.joinpath('linked-app').symlink_to(external.name, target_is_directory=True)
        self.root.joinpath('cycle').symlink_to(self.root, target_is_directory=True)
        self.root.joinpath('.git').mkdir()
        self.root.joinpath('.git/.env').write_text('MAIL_HOST=localhost\n')
        found = guard.inspect_environment(str(self.root), [], [], [], [])
        self.assertEqual(len(found), 2)
        self.assertTrue(any('apps/private/.env' in line for line in found))
        self.assertTrue(any('linked-app/.env' in line for line in found))

    def test_node_addresses_and_unspecified_hosts_are_refused_even_inside_allowed_ranges(self):
        for host in ('10.44.0.5', '::ffff:10.44.0.5', '127.34.5.6', 'localhost.', '0.0.0.0', '::'):
            with self.subTest(host=host):
                self.assertEqual(len(self.inspect(f'SERVICE_HOST={host}\nSERVICE_PORT=8080\n')), 1)

    def test_known_fleet_and_wireguard_addresses_are_allowed(self):
        for host in ('10.44.0.9', '203.0.113.7'):
            self.assertEqual(self.inspect(f'SERVICE_HOST={host}\nSERVICE_PORT=8080\n'), [])
        self.assertEqual(self.inspect('SERVICE_HOST=example.test.\nSERVICE_PORT=8080\n',
                                      [{'host': 'EXAMPLE.test', 'port': 8080}]), [])
        self.assertEqual(len(self.inspect('SERVICE_HOST=example.test\nSERVICE_PORT=8080\n')), 1)

    def test_redis_skip_requires_all_nonnetwork_consumers_to_be_explicit(self):
        unused = 'CACHE_STORE=file\nSESSION_DRIVER=database\nQUEUE_CONNECTION=sync\nREDIS_HOST=127.0.0.1\n'
        self.assertEqual(self.inspect(unused), [])
        self.assertEqual(len(self.inspect(unused.replace('CACHE_STORE=file', 'CACHE_STORE=redis'))), 1)
        refused = self.inspect('REDIS_HOST=127.0.0.1\n')
        self.assertIn('Redis consumer drivers are unknown', refused[0])
        for wrong in ('CACHE_STORE=sqs\nSESSION_DRIVER=database\nQUEUE_CONNECTION=sync\n',
                      'CACHE_STORE=file\nSESSION_DRIVER=sync\nQUEUE_CONNECTION=sync\n',
                      'CACHE_STORE=file\nSESSION_DRIVER=database\nQUEUE_CONNECTION=cookie\n'):
            self.assertEqual(len(self.inspect(wrong + 'REDIS_HOST=127.0.0.1\n')), 1)

    def test_conflicting_cache_aliases_do_not_skip_redis(self):
        refused = self.inspect('CACHE_STORE=file\nCACHE_DRIVER=redis\nSESSION_DRIVER=file\nQUEUE_CONNECTION=sync\nREDIS_HOST=127.0.0.1\n')
        self.assertEqual(len(refused), 1)
        self.assertIn('cache driver aliases disagree', refused[0])

    def test_mapped_node_addresses_are_normalized_for_local_detection(self):
        refused = self.inspect('DB_CONNECTION=mysql\nDB_HOST=10.44.0.5\nDB_PORT=3308\n',
                               node_addresses=['::ffff:10.44.0.5'])
        self.assertEqual(len(refused), 1)

    def test_unknown_driver_missing_port_and_generic_host_port_pairs(self):
        refused = self.inspect('DB_HOST=localhost\nSERVICE_HOST=localhost\nSERVICE_PORT=1234\nAPP_HOST=localhost\n')
        self.assertEqual(len(refused), 2)
        self.assertIn('driver use is unknown', refused[0])
        self.assertIn('[unknown port]', refused[0])
        self.assertIn('SERVICE_HOST=localhost:1234', refused[1])

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
        self.assertIn('malformed dotenv quoting', found[0])
        self.assertNotIn('never-show-this', repr(found))

    def test_multiline_private_values_do_not_forge_service_keys(self):
        self.assertEqual(self.inspect('PRIVATE="first\nMAIL_HOST=127.0.0.1\nMAIL_PORT=1025\nlast"\n'), [])


if __name__ == '__main__':
    unittest.main()
