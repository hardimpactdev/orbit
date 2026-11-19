"""Run the static PHP extension check against stand-in binaries that print what PHP prints for its probe."""
import importlib.util
import stat
import tempfile
import unittest
from pathlib import Path

import yaml

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('orbit_static_php', ROOT / 'ansible/library/orbit_static_php.py')
static_php = importlib.util.module_from_spec(spec)
spec.loader.exec_module(static_php)

EXTENSIONS = ['core', 'pdo_pgsql', 'redis', 'zend opcache']
MODULES = '/opt/orbit-runtime/1/php/8.5/modules'
PCOV_ARGS = f'-n -d extension={MODULES}/pcov.so'


class StaticPhpCheckTest(unittest.TestCase):
    def setUp(self):
        self.folder = tempfile.TemporaryDirectory()
        self.addCleanup(self.folder.cleanup)

    def binary(self, name, version='8.5.13', extensions=('Core', 'pdo_pgsql', 'redis'), zend=('Zend OPcache',), code=0, expect=None,
               ini='', modules=None, module_startup=''):
        """A stand-in that checks its arguments, runs the probe script it is given, and prints what PHP prints for it.

        `modules` maps the arguments of a run with CLI-only extensions to the modules that run loads as well. Such a run first
        prints `module_startup`, as PHP prints a startup warning."""
        path = Path(self.folder.name) / name
        cases = [(expect, (), '')] + [(args, loads, module_startup) for args, loads in (modules or {}).items()]
        branches = ''.join(f'''  "{args} $script") printf '%s\\n' "{startup}" "{static_php.MARKER}" "{version}" "{ini}" {" ".join(f'"{e}"' for e in [*extensions, *loads, *zend])} ;;
''' for args, loads, startup in cases)
        path.write_text(f'''#!/bin/sh
script=$(echo "$*" | awk '{{print $NF}}')
grep -q get_loaded_extensions "$script" || exit 65
case "$*" in
{branches}  *) echo "unexpected arguments: $*" >&2; exit 64 ;;
esac
exit {code}
''')
        path.chmod(path.stat().st_mode | stat.S_IEXEC)
        return str(path)

    def php(self, **kwargs):
        """The CLI, which loads PCOV from the build's modules when it is given."""
        return self.binary('php', expect='-n', modules={PCOV_ARGS: ('pcov',)}, **kwargs)

    def check(self, php=None, frankenphp=None):
        return static_php.check(php or self.php(), frankenphp or self.binary('frankenphp', expect='php-cli'),
                                '8.5.13', EXTENSIONS, MODULES, ['pcov'])

    def test_matching_build_passes(self):
        self.assertEqual(self.check(), [])

    def test_only_the_cli_loads_a_cli_only_extension(self):
        # A CLI that cannot load pcov.so prints PHP's startup warning and lacks the extension.
        php = self.binary('php', expect='-n', modules={PCOV_ARGS: ()}, module_startup="Warning: PHP Startup: Unable to load dynamic library 'pcov.so'")
        self.assertEqual(self.check(php=php), [f"php with {MODULES}/pcov.so printed at startup: Warning: PHP Startup: Unable to load dynamic library 'pcov.so'",
                                               f'php with {MODULES}/pcov.so lacks pcov.'])
        # A FrankenPHP with PCOV built in fails: the server must never load it.
        frankenphp = self.binary('frankenphp', extensions=('Core', 'pdo_pgsql', 'redis', 'pcov'), expect='php-cli')
        self.assertEqual(self.check(frankenphp=frankenphp), ['frankenphp also loads pcov.'])
        # So does a CLI with PCOV built in: then `php -n` loads it with no ini.
        static = self.binary('php', extensions=('Core', 'pdo_pgsql', 'redis', 'pcov'), expect='-n', modules={PCOV_ARGS: ()})
        self.assertEqual(self.check(php=static), ['php also loads pcov.'])

    def test_no_cli_only_extension_needs_no_third_run(self):
        php = self.binary('php', expect='-n')
        self.assertEqual(static_php.check(php, self.binary('frankenphp', expect='php-cli'), '8.5.13', EXTENSIONS, MODULES, []), [])

    def test_frankenphp_must_read_no_configuration(self):
        # `frankenphp php-cli` takes the SAPI name `cli`, so in a container it reads php-cli.ini and loads PCOV; it has no `-n`.
        frankenphp = self.binary('frankenphp', expect='php-cli', ini='/etc/orbit/php/php-cli.ini, /etc/orbit/php/conf.d/99-orbit-runtime.ini')
        self.assertEqual(self.check(frankenphp=frankenphp), [
            "frankenphp read /etc/orbit/php/php-cli.ini, /etc/orbit/php/conf.d/99-orbit-runtime.ini; run the check where the build's config path does not exist."])

    def test_the_probe_runs_in_an_empty_folder(self):
        # `frankenphp php-cli` also looks for php-cli.ini in its working folder.
        frankenphp = Path(self.folder.name) / 'frankenphp-cwd'
        frankenphp.write_text(f'''#!/bin/sh
[ "$(ls -A)" = "probe.php" ] || exit 66
printf '%s\\n' "{static_php.MARKER}" 8.5.13 "" Core pdo_pgsql redis "Zend OPcache"
''')
        frankenphp.chmod(frankenphp.stat().st_mode | stat.S_IEXEC)
        self.assertEqual(self.check(frankenphp=str(frankenphp)), [])

    def test_the_probe_prints_the_ini_files_php_read(self):
        self.assertIn('php_ini_loaded_file()', static_php.PROBE)
        self.assertIn('php_ini_scanned_files()', static_php.PROBE)

    def test_a_missing_and_an_extra_extension_fail_for_their_binary(self):
        frankenphp = self.binary('frankenphp', extensions=('Core', 'pdo_pgsql', 'apcu'), expect='php-cli')
        self.assertEqual(self.check(frankenphp=frankenphp), ['frankenphp lacks redis.', 'frankenphp also loads apcu.'])

    def test_frankenphp_s_own_module_is_no_extension(self):
        frankenphp = self.binary('frankenphp', extensions=('Core', 'pdo_pgsql', 'redis', 'frankenphp-cli'), expect='php-cli')
        php = self.binary('php-with-sapi-module', extensions=('Core', 'pdo_pgsql', 'redis', 'frankenphp-cli'), expect='-n',
                          modules={PCOV_ARGS: ('pcov',)})
        self.assertEqual(self.check(frankenphp=frankenphp), [])
        # Only that exact module: another one whose name starts the same is an extension like any other.
        other = self.binary('frankenphp-other', extensions=('Core', 'pdo_pgsql', 'redis', 'frankenphp-cli', 'frankenphp-extra'), expect='php-cli')
        self.assertEqual(self.check(frankenphp=other), ['frankenphp also loads frankenphp-extra.'])
        # Only FrankenPHP has it: the CLI that reports one has an extra module.
        self.assertEqual(self.check(php=php), ['php also loads frankenphp-cli.', f'php with {MODULES}/pcov.so also loads frankenphp-cli.'])

    def test_another_php_version_fails(self):
        php = self.php(version='8.5.12')
        self.assertEqual(self.check(php=php), ['php reports PHP 8.5.12, expected 8.5.13.', f'php with {MODULES}/pcov.so reports PHP 8.5.12, expected 8.5.13.'])

    def test_a_binary_that_fails_stops_the_check(self):
        with self.assertRaisesRegex(static_php.StaticPhpError, 'exited 3'):
            self.check(php=self.php(code=3))

    def test_the_folder_install_checks_each_static_php_part(self):
        tasks = yaml.safe_load((ROOT / 'ansible/tasks/instance-folder.yml').read_text())
        build = next(task for task in tasks if task['name'] == 'Build the folder version')['block']
        names = [task['name'] for task in build]
        check = build[names.index('Check each static PHP build against its extensions')]
        # It runs on the unpacked part, after the programs are known to be executable and before the version is whole.
        self.assertLess(names.index('Require that every program is an executable file'), names.index(check['name']))
        self.assertLess(names.index(check['name']), names.index('Make the version whole'))
        self.assertEqual(check['orbit_static_php'], {'php': '{{ folder_parts }}/{{ item.name }}/bin/php',
                                                     'frankenphp': '{{ folder_parts }}/{{ item.name }}/bin/frankenphp',
                                                     'php_version': '{{ item.static_php.php_version }}',
                                                     'extensions': '{{ item.static_php.extensions }}',
                                                     'modules': '{{ folder_parts }}/{{ item.name }}/modules',
                                                     'cli_extensions': '{{ item.static_php.cli_extensions }}'})
        self.assertEqual(check['loop'], "{{ folder.parts | selectattr('static_php', 'defined') | list }}")

if __name__ == '__main__':
    unittest.main()
