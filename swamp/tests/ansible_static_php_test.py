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


class StaticPhpCheckTest(unittest.TestCase):
    def setUp(self):
        self.folder = tempfile.TemporaryDirectory()
        self.addCleanup(self.folder.cleanup)

    def binary(self, name, version='8.5.12', extensions=('Core', 'pdo_pgsql', 'redis'), zend=('Zend OPcache',), code=0, expect=None):
        """A stand-in that checks its arguments, runs the probe script it is given, and prints a version and extensions."""
        path = Path(self.folder.name) / name
        lines = '\n'.join([version, *extensions, *zend])
        path.write_text(f'''#!/bin/sh
[ "$*" = "{expect} $(echo "$*" | awk '{{print $NF}}')" ] || {{ echo "unexpected arguments: $*" >&2; exit 64; }}
grep -q get_loaded_extensions "$(echo "$*" | awk '{{print $NF}}')" || exit 65
printf '%s\\n' "{lines}"
exit {code}
''')
        path.chmod(path.stat().st_mode | stat.S_IEXEC)
        return str(path)

    def check(self, php=None, frankenphp=None):
        return static_php.check(php or self.binary('php', expect='-n'), frankenphp or self.binary('frankenphp', expect='php-cli'),
                                '8.5.12', EXTENSIONS)

    def test_matching_build_passes(self):
        self.assertEqual(self.check(), [])

    def test_a_missing_and_an_extra_extension_fail_for_their_binary(self):
        frankenphp = self.binary('frankenphp', extensions=('Core', 'pdo_pgsql', 'apcu'), expect='php-cli')
        self.assertEqual(self.check(frankenphp=frankenphp), ['frankenphp lacks redis.', 'frankenphp also loads apcu.'])

    def test_another_php_version_fails(self):
        php = self.binary('php', version='8.5.11', expect='-n')
        self.assertEqual(self.check(php=php), ['php reports PHP 8.5.11, expected 8.5.12.'])

    def test_a_binary_that_fails_stops_the_check(self):
        with self.assertRaisesRegex(static_php.StaticPhpError, 'exited 3'):
            self.check(php=self.binary('php', code=3, expect='-n'))

    def test_the_folder_install_checks_each_static_php_part(self):
        tasks = yaml.safe_load((ROOT / 'ansible/tasks/instance-folder.yml').read_text())
        build = next(task for task in tasks if task['name'] == 'Build the folder version of {{ folder_name }}')['block']
        names = [task['name'] for task in build]
        check = build[names.index('Check each static PHP build against its extensions')]
        # It runs on the unpacked part, after the programs are known to be executable and before the version is whole.
        self.assertLess(names.index('Require that every program is an executable file'), names.index(check['name']))
        self.assertLess(names.index(check['name']), names.index('Make the version whole'))
        self.assertEqual(check['orbit_static_php'], {'php': '{{ folder_parts }}/{{ item.name }}/bin/php',
                                                     'frankenphp': '{{ folder_parts }}/{{ item.name }}/bin/frankenphp',
                                                     'php_version': '{{ item.static_php.php_version }}',
                                                     'extensions': '{{ item.static_php.extensions }}'})
        self.assertEqual(check['loop'], "{{ folder.parts | selectattr('static_php', 'defined') | list }}")

if __name__ == '__main__':
    unittest.main()
